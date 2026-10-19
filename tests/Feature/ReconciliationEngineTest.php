<?php

namespace Tests\Feature;

use App\Domain\Reconciliation\Services\ReconciliationService;
use App\Models\GiftReconciliation;
use App\Models\GuestWorkspace;
use App\Models\PayoutReconciliation;
use App\Models\ReconciliationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReconciliationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        config(['reconciliation.allow_arbitrary_uploads' => false]);
    }

    public function test_reference_reports_create_a_conservative_baseline_run_with_explainable_payout_results(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided'])->assertRedirect(route('try.show'));

        $run = ReconciliationRun::query()->with(['gifts', 'payouts'])->sole();

        $this->assertSame(ReconciliationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->sequence);
        $this->assertEquals([
            'create' => 1,
            'update' => 3,
            'unchanged' => 2,
            'held' => 6,
        ], $run->outcome_counts);
        $this->assertEquals([
            'balanced_sent' => 1,
            'pending' => 1,
            'unbalanced' => 1,
            'incomplete' => 0,
            'conflicting' => 0,
        ], $run->payout_counts);

        $payouts = $run->payouts->keyBy('payout_id');
        $this->assertSame(PayoutReconciliation::STATE_BALANCED_SENT, $payouts['P001']->state);
        $this->assertSame(84000, $payouts['P001']->component_total_minor);
        $this->assertSame(0, $payouts['P001']->difference_minor);
        $this->assertSame(PayoutReconciliation::STATE_UNBALANCED, $payouts['P002']->state);
        $this->assertSame(300, $payouts['P002']->difference_minor);
        $this->assertContains('PAYOUT_DIFFERENCE', $payouts['P002']->issue_codes);
        $this->assertSame(PayoutReconciliation::STATE_PENDING, $payouts['P003']->state);

        $gifts = $run->gifts->keyBy('transaction_id');
        $this->assertSame(GiftReconciliation::OUTCOME_CREATE, $gifts['D002']->outcome);
        $this->assertSame(GiftReconciliation::OUTCOME_UPDATE, $gifts['D003']->outcome);
        $this->assertSame(GiftReconciliation::OUTCOME_UNCHANGED, $gifts['D001']->outcome);
        $this->assertContains('DUPLICATE_CRM_TARGET', $gifts['D005']->issue_codes);
        $this->assertContains('UNMAPPED_CAMPAIGN', $gifts['D006']->issue_codes);
        $this->assertContains('AMBIGUOUS_CRM_CANDIDATE', $gifts['D007']->issue_codes);
        $this->assertContains('PAYOUT_DIFFERENCE', $gifts['D011']->issue_codes);
        $this->assertContains('PENDING_PAYOUT', $gifts['D012']->issue_codes);

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('Reconciliation outcomes', false)
            ->assertSee('Proposed creates', false)
            ->assertSee('Payout checks', false);
    }

    public function test_review_decisions_produce_the_declared_reference_outcomes_and_an_idempotent_immutable_run(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided']);
        $workspace = GuestWorkspace::query()->sole();
        $service = app(ReconciliationService::class);
        $decisions = [
            'campaign_mappings' => ['NEW-PROGRAM' => 'CAMPAIGN-NEW-PROGRAM'],
            'manual_matches' => ['D007' => 'CRM005'],
        ];

        $run = $service->run($workspace, $decisions);
        $reused = $service->run($workspace, [
            'manual_matches' => ['D007' => 'CRM005'],
            'campaign_mappings' => ['NEW-PROGRAM' => 'CAMPAIGN-NEW-PROGRAM'],
        ]);

        $this->assertNotNull($run);
        $this->assertSame($run->id, $reused?->id);
        $this->assertSame(2, $run->sequence);
        $this->assertDatabaseCount('reconciliation_runs', 2);
        $this->assertEquals([
            'create' => 2,
            'update' => 4,
            'unchanged' => 2,
            'held' => 4,
        ], $run->outcome_counts);

        $gifts = $run->gifts->keyBy('transaction_id');
        $this->assertSame(GiftReconciliation::OUTCOME_CREATE, $gifts['D006']->outcome);
        $this->assertSame(GiftReconciliation::OUTCOME_UPDATE, $gifts['D007']->outcome);
        $this->assertSame(GiftReconciliation::MATCH_MANUAL, $gifts['D007']->crm_match_state);
        $this->assertSame(
            ['D004', 'D005', 'D011', 'D012'],
            $run->gifts->where('outcome', GiftReconciliation::OUTCOME_HELD)->pluck('transaction_id')->sort()->values()->all(),
        );
        $this->assertSame('CAMPAIGN-NEW-PROGRAM', $gifts['D006']->expected_values['campaign_id']);
        $this->assertSame($decisions, $run->decisions);
    }
}
