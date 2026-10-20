<?php

namespace Tests\Feature;

use App\Models\CanonicalRecord;
use App\Models\CanonicalRecordVersion;
use App\Models\GuestWorkspace;
use App\Models\SourceOccurrence;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CanonicalMaterializationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        config(['reconciliation.allow_arbitrary_uploads' => true]);
    }

    public function test_reference_reports_materialize_expected_records_versions_and_source_occurrences(): void
    {
        $workspace = $this->startWorkspace('guided');

        $this->assertSame(12, $workspace->canonicalRecords()->where('record_type', CanonicalRecord::TYPE_GIFT)->count());
        $this->assertSame(16, $workspace->canonicalRecords()->where('record_type', CanonicalRecord::TYPE_PAYOUT_COMPONENT)->count());
        $this->assertSame(8, $workspace->canonicalRecords()->where('record_type', CanonicalRecord::TYPE_CRM_RECORD)->count());
        $this->assertDatabaseCount('canonical_record_versions', 36);
        $this->assertDatabaseCount('source_occurrences', 37);

        $gift = $workspace->canonicalRecords()
            ->where('record_type', CanonicalRecord::TYPE_GIFT)
            ->where('identity->transaction_id', 'D001')
            ->with(['activeVersion', 'versions.occurrences'])
            ->firstOrFail();
        $crmRecord = $workspace->canonicalRecords()
            ->where('record_type', CanonicalRecord::TYPE_CRM_RECORD)
            ->where('identity->crm_record_id', 'CRM001')
            ->with('activeVersion')
            ->firstOrFail();

        $this->assertSame(CanonicalRecord::RESOLUTION_ACTIVE, $gift->resolution_status);
        $this->assertSame('xgk_641ebf5e878ac380ff6a0cd02270922b91e3cd6bc830fde89b5e759e1157d740', $gift->activeVersion->normalized_data['external_gift_key']);
        $this->assertSame($gift->activeVersion->normalized_data['external_gift_key'], $crmRecord->activeVersion->normalized_data['external_gift_key']);
        $this->assertSame(
            [SourceOccurrence::KIND_IDENTICAL_DUPLICATE, SourceOccurrence::KIND_PRIMARY],
            $gift->versions->flatMap->occurrences->pluck('occurrence_kind')->sort()->values()->all(),
        );

        $donationBatch = $workspace->importBatches()->where('report_type', 'donations')->firstOrFail();
        $this->assertSame(13, $donationBatch->materialized_rows);
        $this->assertSame(1, $donationBatch->duplicate_rows);
        $this->assertSame(0, $donationBatch->stale_rows);
        $this->assertSame(0, $donationBatch->conflicting_rows);

        $duplicateCrmKey = 'xgk_51d8900fc5556b255c0c22da28e39e419a6e9edd9b1d524d9b97825119cd81d1';
        $this->assertSame(2, CanonicalRecordVersion::query()
            ->whereHas('record', fn ($query) => $query
                ->where('guest_workspace_id', $workspace->id)
                ->where('record_type', CanonicalRecord::TYPE_CRM_RECORD))
            ->where('normalized_data->external_gift_key', $duplicateCrmKey)
            ->count());
    }

    public function test_newer_revision_wins_independently_of_upload_order_and_stale_evidence_remains_traceable(): void
    {
        foreach ([['newer', 'older'], ['older', 'newer']] as $order) {
            if (session()->has(WorkspaceService::SESSION_SECRET_KEY)) {
                $this->flushSession();
            }

            $workspace = $this->startWorkspace('scenarios');

            foreach ($order as $label) {
                $newer = $label === 'newer';
                $this->uploadDonation(
                    $label.'.csv',
                    'D100',
                    $newer ? '20.00' : '10.00',
                    $newer ? '2026-09-20T10:00:00+00:00' : '2026-09-10T10:00:00+00:00',
                );
            }

            $gift = $workspace->canonicalRecords()
                ->where('record_type', CanonicalRecord::TYPE_GIFT)
                ->where('identity->transaction_id', 'D100')
                ->with(['activeVersion', 'versions'])
                ->firstOrFail();

            $this->assertSame(CanonicalRecord::RESOLUTION_ACTIVE, $gift->resolution_status);
            $this->assertSame(2000, $gift->activeVersion->normalized_data['gross_amount_minor']);
            $this->assertCount(2, $gift->versions);
            $this->assertSame(
                [CanonicalRecordVersion::STATE_ACTIVE, CanonicalRecordVersion::STATE_SUPERSEDED],
                $gift->versions->pluck('resolution_state')->sort()->values()->all(),
            );

            $olderBatch = $workspace->importBatches()->where('original_filename', 'older.csv')->firstOrFail();
            $this->assertSame(1, $olderBatch->stale_rows);
            $this->assertSame(SourceOccurrence::KIND_STALE_REVISION, $olderBatch->sourceOccurrences()->value('occurrence_kind'));
        }
    }

    public function test_different_values_at_the_same_revision_create_a_blocking_conflict(): void
    {
        $workspace = $this->startWorkspace('scenarios');
        $revision = '2026-09-20T10:00:00+00:00';

        $this->uploadDonation('first.csv', 'D200', '10.00', $revision);
        $this->uploadDonation('second.csv', 'D200', '12.00', $revision);

        $gift = $workspace->canonicalRecords()
            ->where('record_type', CanonicalRecord::TYPE_GIFT)
            ->where('identity->transaction_id', 'D200')
            ->with('versions')
            ->firstOrFail();

        $this->assertSame(CanonicalRecord::RESOLUTION_CONFLICTING, $gift->resolution_status);
        $this->assertNull($gift->active_version_id);
        $this->assertCount(2, $gift->versions);
        $this->assertSame(
            [CanonicalRecordVersion::STATE_CONFLICTING],
            $gift->versions->pluck('resolution_state')->unique()->values()->all(),
        );
        $this->assertSame(
            [SourceOccurrence::KIND_CONFLICT],
            SourceOccurrence::query()
                ->where('canonical_record_id', $gift->id)
                ->pluck('occurrence_kind')
                ->unique()
                ->values()
                ->all(),
        );
        $this->assertSame(2, $workspace->importBatches()->sum('conflicting_rows'));
    }

    private function startWorkspace(string $intent): GuestWorkspace
    {
        $this->post(route('try.start'), ['intent' => $intent])->assertRedirect(route('try.show'));
        $secret = session(WorkspaceService::SESSION_SECRET_KEY);

        return GuestWorkspace::query()
            ->where('session_binding_hash', hash('sha256', $secret))
            ->firstOrFail();
    }

    private function uploadDonation(string $filename, string $transactionId, string $gross, string $sourceUpdatedAt): void
    {
        $contents = implode("\n", [
            'transaction_id,gifted_at,gross_amount,currency,status,refunded_amount,campaign_code,donor_ref,donor_email,source_updated_at',
            "{$transactionId},2026-09-01T10:00:00+00:00,{$gross},GBP,paid,0.00,GENERAL,,,{$sourceUpdatedAt}",
            '',
        ]);

        $this->post(route('try.imports.store'), [
            'report_type' => 'donations',
            'file' => UploadedFile::fake()->createWithContent($filename, $contents),
        ])->assertRedirect();

    }
}
