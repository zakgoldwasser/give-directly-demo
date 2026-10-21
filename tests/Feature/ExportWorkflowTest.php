<?php

namespace Tests\Feature;

use App\Models\ExportBatch;
use App\Models\ReconciliationApproval;
use App\Models\ReconciliationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ExportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        Storage::fake('exports');
    }

    public function test_approved_actions_produce_an_idempotent_private_export_package(): void
    {
        $run = $this->completeGuidedReview();

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('Review the proposed destination changes', false)
            ->assertSee('Approve all remaining (6)', false);

        $this->post(route('try.approvals.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'));
        $this->post(route('try.approvals.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'));

        $this->assertDatabaseCount('reconciliation_approvals', 6);
        $this->assertSame(
            ['D002', 'D003', 'D006', 'D007', 'D008', 'D010'],
            ReconciliationApproval::query()->orderBy('transaction_id')->pluck('transaction_id')->all(),
        );

        $this->post(route('try.exports.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'));

        $batch = ExportBatch::query()->sole();
        $this->assertEquals([
            'creates' => 2,
            'updates' => 4,
            'unchanged' => 2,
            'held' => 4,
            'approved_actions' => 6,
        ], $batch->counts);
        Storage::disk('exports')->assertExists($batch->private_file_path);

        $firstBytes = Storage::disk('exports')->get($batch->private_file_path);
        $files = $this->readZip($batch);
        $this->assertSame(
            ['audit.csv', 'creates.csv', 'held.csv', 'manifest.json', 'updates.csv'],
            array_keys($files),
        );
        $this->assertStringContainsString("\n", $files['creates.csv']);
        $this->assertStringContainsString('D002', $files['creates.csv']);
        $this->assertStringContainsString('D006', $files['creates.csv']);
        $this->assertStringContainsString('D003', $files['updates.csv']);
        $this->assertStringContainsString('D007', $files['updates.csv']);
        $this->assertStringContainsString('D004', $files['held.csv']);
        $this->assertStringContainsString('D012', $files['held.csv']);
        $this->assertStringNotContainsString('@example.test', implode('', $files));
        $this->assertSame(3, substr_count($files['creates.csv'], "\n"));
        $this->assertSame(5, substr_count($files['updates.csv'], "\n"));
        $this->assertSame(5, substr_count($files['held.csv'], "\n"));
        $this->assertSame(9, substr_count($files['audit.csv'], "\n"));

        $manifest = json_decode($files['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($batch->id, $manifest['batch_id']);
        $this->assertSame($run->id, $manifest['run']['id']);
        $this->assertSame('generic_gift_v1', $manifest['profiles']['export']);
        $this->assertCount(4, $manifest['updates']);

        foreach (['creates.csv', 'updates.csv', 'held.csv', 'audit.csv'] as $filename) {
            $this->assertSame(hash('sha256', $files[$filename]), $manifest['files'][$filename]['sha256']);
        }

        $this->post(route('try.exports.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'));

        $this->assertDatabaseCount('export_batches', 1);
        $this->assertSame($firstBytes, Storage::disk('exports')->get($batch->private_file_path));

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('2 creates · 4 updates · 4 held exclusions', false)
            ->assertSee('Download ZIP', false);

        $this->get(route('try.exports.download', $batch))
            ->assertOk()
            ->assertDownload("reconciliation-{$batch->id}.zip");
        $this->assertNotNull($batch->refresh()->downloaded_at);
    }

    public function test_export_requires_current_run_approvals_and_changed_review_evidence_invalidates_them(): void
    {
        $run = $this->completeGuidedReview();

        $this->from(route('try.show'))
            ->post(route('try.exports.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'))
            ->assertSessionHasErrors('export');

        $this->post(route('try.approvals.store'), ['run_id' => $run->id]);
        $this->post(route('try.review.campaigns.store'), [
            'campaign_code' => 'NEW-PROGRAM',
            'campaign_id' => 'CAMPAIGN-REVISED',
        ]);
        $currentRun = ReconciliationRun::query()->orderByDesc('sequence')->firstOrFail();

        $this->assertNotSame($run->id, $currentRun->id);
        $this->assertSame(0, $currentRun->approvals()->count());

        $this->from(route('try.show'))
            ->post(route('try.exports.store'), ['run_id' => $run->id])
            ->assertRedirect(route('try.show'))
            ->assertSessionHasErrors('run_id');

        $this->assertDatabaseCount('export_batches', 0);
    }

    public function test_a_second_workspace_cannot_download_the_first_workspace_export(): void
    {
        $run = $this->completeGuidedReview();
        $this->post(route('try.approvals.store'), ['run_id' => $run->id]);
        $this->post(route('try.exports.store'), ['run_id' => $run->id]);
        $batch = ExportBatch::query()->sole();

        $this->post(route('try.start'), ['intent' => 'scenarios']);

        $this->get(route('try.exports.download', $batch))->assertNotFound();
    }

    public function test_one_change_can_be_excluded_while_other_approved_changes_are_exported_and_confirmed(): void
    {
        $run = $this->completeGuidedReview();
        $this->post(route('try.approvals.store'), ['run_id' => $run->id, 'transaction_id' => 'D003'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reconciliation_approvals', 1);
        $this->post(route('try.approvals.store'), [
            'run_id' => $run->id, 'transaction_id' => 'D003', 'decision' => 'exclude', 'reason' => 'Needs donor verification',
        ])->assertSessionHasNoErrors();
        $this->post(route('try.exports.store'), ['run_id' => $run->id])->assertSessionHasErrors('export');
        $this->post(route('try.approvals.store'), ['run_id' => $run->id])->assertSessionHasNoErrors();
        $this->get(route('try.show'))->assertSee('5 approved')->assertSee('1 excluded')->assertSee('Needs donor verification');
        $this->post(route('try.exports.store'), ['run_id' => $run->id])->assertSessionHasNoErrors();
        $batch = ExportBatch::query()->sole();
        $files = $this->readZip($batch);
        $this->assertStringNotContainsString('D003', $files['updates.csv']);
        $this->assertStringContainsString('D007', $files['updates.csv']);
        $this->assertStringContainsString('D003', $files['held.csv']);
        $this->assertStringContainsString('Needs donor verification', $files['held.csv']);
        $this->assertStringContainsString('Needs donor verification', $files['audit.csv']);
        $this->assertSame(3, $batch->counts['updates']);
        $this->assertSame(5, $batch->counts['approved_actions']);
        $this->assertSame(5, $batch->counts['held']);
        $this->post(route('try.exports.confirm', $batch))->assertSessionHasNoErrors();
        $confirmation = $batch->confirmations()->sole();
        $this->assertSame(5, $confirmation->results()->count());
        $this->assertFalse($confirmation->results()->where('transaction_id', 'D003')->exists());
        $this->post(route('try.approvals.store'), [
            'run_id' => $run->id, 'transaction_id' => 'D003', 'decision' => 'restore',
        ])->assertSessionHasErrors('approval');
        $this->post(route('try.exports.store'), ['run_id' => $run->id])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('export_batches', 1);
    }

    public function test_exclusion_needs_no_reason_requires_a_current_eligible_row_and_can_be_undone_before_export(): void
    {
        $run = $this->completeGuidedReview();
        $this->post(route('try.approvals.store'), [
            'run_id' => $run->id, 'transaction_id' => 'D003', 'decision' => 'exclude',
        ])->assertSessionHasNoErrors();
        $this->post(route('try.approvals.store'), [
            'run_id' => $run->id, 'transaction_id' => 'D004', 'decision' => 'exclude', 'reason' => 'Hold',
        ])->assertSessionHasErrors('transaction_id');
        $this->post(route('try.approvals.store'), [
            'run_id' => $run->id, 'transaction_id' => 'D003', 'decision' => 'exclude', 'reason' => 'Check details',
        ])->assertSessionHasNoErrors();
        $this->post(route('try.approvals.store'), [
            'run_id' => $run->id, 'transaction_id' => 'D003', 'decision' => 'restore',
        ])->assertSessionHasNoErrors();
        $this->get(route('try.show'))->assertSee('0 excluded')->assertSee('6 open');
        $this->post(route('try.approvals.store'), ['run_id' => $run->id])->assertSessionHasNoErrors();
        $this->post(route('try.exports.store'), ['run_id' => $run->id])->assertSessionHasNoErrors();
        $files = $this->readZip(ExportBatch::query()->sole());
        $this->assertStringContainsString('D003', $files['updates.csv']);
    }

    public function test_excluding_every_change_produces_only_audited_exclusions(): void
    {
        $run = $this->completeGuidedReview();
        foreach (['D002', 'D003', 'D006', 'D007', 'D008', 'D010'] as $transactionId) {
            $this->post(route('try.approvals.store'), [
                'run_id' => $run->id, 'transaction_id' => $transactionId, 'decision' => 'exclude', 'reason' => 'Defer this export',
            ])->assertSessionHasNoErrors();
        }
        $this->get(route('try.show'))->assertSee('0 approved')->assertSee('6 excluded')->assertSee('Create reviewed export package');
        $this->post(route('try.exports.store'), ['run_id' => $run->id])->assertSessionHasNoErrors();
        $batch = ExportBatch::query()->sole();
        $files = $this->readZip($batch);
        $this->assertSame(0, $batch->counts['approved_actions']);
        $this->assertSame(1, substr_count($files['creates.csv'], "\n"));
        $this->assertSame(1, substr_count($files['updates.csv'], "\n"));
        $this->assertSame(10, $batch->counts['held']);
    }

    private function completeGuidedReview(): ReconciliationRun
    {
        $this->post(route('try.start'), ['intent' => 'guided']);
        $this->post(route('try.review.campaigns.store'), [
            'campaign_code' => 'NEW-PROGRAM',
            'campaign_id' => 'CAMPAIGN-NEW-PROGRAM',
        ]);
        $this->post(route('try.review.matches.store'), [
            'transaction_id' => 'D007',
            'crm_record_id' => 'CRM005',
        ]);

        return ReconciliationRun::query()->orderByDesc('sequence')->firstOrFail();
    }

    /** @return array<string, string> */
    private function readZip(ExportBatch $batch): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('exports')->path($batch->private_file_path)));
        $files = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $contents = $zip->getFromIndex($index);
            $this->assertIsString($name);
            $this->assertIsString($contents);
            $files[$name] = $contents;
        }

        $zip->close();
        ksort($files, SORT_STRING);

        return $files;
    }
}
