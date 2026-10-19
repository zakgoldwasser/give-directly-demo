<?php

namespace Tests\Feature;

use App\Models\ConfirmationBatch;
use App\Models\ConfirmationResult;
use App\Models\ExportBatch;
use App\Models\ReconciliationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConfirmationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        Storage::fake('exports');
        config(['reconciliation.allow_arbitrary_uploads' => false]);
    }

    public function test_the_reviewed_export_is_confirmed_against_the_fixed_later_snapshot(): void
    {
        $export = $this->reviewedExport();

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('Simulate CRM confirmation', false);

        $this->post(route('try.exports.confirm', $export))
            ->assertRedirect(route('try.show'))
            ->assertSessionHas(
                'confirmation_completed',
                'Simulated CRM confirmation completed: 6 of 6 exported actions match.',
            );

        $batch = ConfirmationBatch::query()->sole();
        $this->assertSame(ConfirmationBatch::COVERAGE_COMPLETE, $batch->coverage_status);
        $this->assertEquals([
            ConfirmationResult::OUTCOME_MATCHES => 6,
            ConfirmationResult::OUTCOME_MISMATCHES => 0,
            ConfirmationResult::OUTCOME_DUPLICATE => 0,
            ConfirmationResult::OUTCOME_CANNOT_VERIFY => 0,
        ], $batch->counts);
        $this->assertTrue($batch->snapshot_generated_at->greaterThan($export->generated_at));
        $this->assertSame(
            ['D002', 'D003', 'D006', 'D007', 'D008', 'D010'],
            $batch->results()->orderBy('transaction_id')->pluck('transaction_id')->all(),
        );
        $this->assertSame(
            [ConfirmationResult::OUTCOME_MATCHES],
            $batch->results()->distinct()->pluck('outcome')->all(),
        );
        $this->assertStringNotContainsString('@example.test', $batch->results->toJson());

        $export->refresh();
        $this->assertSame(ExportBatch::STATUS_CHECKED, $export->status);
        $this->assertNotNull($export->checked_at);

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('6 of 6 match', false)
            ->assertSee('Snapshot agreement confirms the recorded values.', false)
            ->assertDontSee('Simulate CRM confirmation', false);

        $this->post(route('try.exports.confirm', $export))->assertRedirect(route('try.show'));
        $this->assertDatabaseCount('confirmation_batches', 1);
        $this->assertDatabaseCount('confirmation_results', 6);

        $this->get(route('try.exports.download', $export))->assertOk();
        $this->assertSame(ExportBatch::STATUS_CHECKED, $export->refresh()->status);
    }

    public function test_another_workspace_cannot_confirm_an_export(): void
    {
        $export = $this->reviewedExport();

        $this->post(route('try.start'), ['intent' => 'scenarios']);

        $this->post(route('try.exports.confirm', $export))->assertNotFound();
        $this->assertDatabaseCount('confirmation_batches', 0);
    }

    private function reviewedExport(): ExportBatch
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
        $run = ReconciliationRun::query()->orderByDesc('sequence')->firstOrFail();
        $this->post(route('try.approvals.store'), ['run_id' => $run->id]);
        $this->post(route('try.exports.store'), ['run_id' => $run->id]);

        return ExportBatch::query()->sole();
    }
}
