<?php

namespace Tests\Feature;

use App\Models\GiftReconciliation;
use App\Models\ReconciliationRun;
use App\Models\ReviewDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
    }

    public function test_reviewer_decisions_are_recorded_and_generate_new_immutable_runs(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided'])
            ->assertRedirect(route('try.show'));

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('Review held records', false)
            ->assertSee('NEW-PROGRAM', false)
            ->assertSee('D007', false)
            ->assertSee('CRM005', false);

        $this->post(route('try.review.campaigns.store'), [
            'campaign_code' => 'NEW-PROGRAM',
            'campaign_id' => 'CAMPAIGN-NEW-PROGRAM',
        ])->assertRedirect(route('try.show'));

        $campaignRun = ReconciliationRun::query()->orderByDesc('sequence')->firstOrFail();
        $this->assertSame(2, $campaignRun->sequence);
        $this->assertEquals([
            'create' => 2,
            'update' => 3,
            'unchanged' => 2,
            'held' => 5,
        ], $campaignRun->outcome_counts);

        $this->post(route('try.review.matches.store'), [
            'transaction_id' => 'D007',
            'crm_record_id' => 'CRM005',
        ])->assertRedirect(route('try.show'));

        $reviewedRun = ReconciliationRun::query()->with('gifts')->orderByDesc('sequence')->firstOrFail();
        $this->assertSame(3, $reviewedRun->sequence);
        $this->assertEquals([
            'create' => 2,
            'update' => 4,
            'unchanged' => 2,
            'held' => 4,
        ], $reviewedRun->outcome_counts);
        $this->assertSame(
            GiftReconciliation::MATCH_MANUAL,
            $reviewedRun->gifts->firstWhere('transaction_id', 'D007')?->crm_match_state,
        );

        $decisions = ReviewDecision::query()->orderBy('sequence')->get();
        $this->assertCount(2, $decisions);
        $this->assertSame(ReviewDecision::TYPE_CAMPAIGN_MAPPING, $decisions[0]->decision_type);
        $this->assertSame('NEW-PROGRAM', $decisions[0]->subject_key);
        $this->assertSame('CAMPAIGN-NEW-PROGRAM', $decisions[0]->selected_value);
        $this->assertSame(ReviewDecision::TYPE_CRM_MATCH, $decisions[1]->decision_type);
        $this->assertSame('D007', $decisions[1]->subject_key);
        $this->assertSame('CRM005', $decisions[1]->selected_value);

        $this->get(route('try.show'))
            ->assertOk()
            ->assertDontSee('Review held records', false);

        $this->post(route('try.review.matches.store'), [
            'transaction_id' => 'D007',
            'crm_record_id' => 'CRM005',
        ])->assertRedirect(route('try.show'));

        $this->assertDatabaseCount('review_decisions', 2);
        $this->assertDatabaseCount('reconciliation_runs', 3);
    }

    public function test_rejecting_candidates_and_undoing_reopens_review_without_reusing_an_old_run(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided']);
        $original = ReconciliationRun::query()->sole();
        $this->get(route('try.show'))->assertSee('None of these');
        $this->post(route('try.review.matches.store'), [
            'run_id' => $original->id,
            'transaction_id' => 'D007',
            'crm_record_id' => '',
        ])->assertSessionHasNoErrors();
        $rejected = ReconciliationRun::query()->latest('sequence')->firstOrFail();
        $gift = $rejected->gifts()->where('transaction_id', 'D007')->sole();
        $this->assertSame(GiftReconciliation::MATCH_REJECTED, $gift->crm_match_state);
        $this->assertSame(GiftReconciliation::OUTCOME_HELD, $gift->outcome);
        $this->assertContains('CRM_CANDIDATES_REJECTED', $gift->issue_codes);
        $this->assertNull($gift->matched_crm_record_id);
        $this->get(route('try.show'))->assertSee('All candidates rejected; held for review')->assertSee('Undo match decision');

        $this->post(route('try.review.matches.store'), [
            'run_id' => $rejected->id, 'transaction_id' => 'D007', 'action' => 'undo',
        ])->assertSessionHasNoErrors();
        $undone = ReconciliationRun::query()->latest('sequence')->firstOrFail();
        $this->assertSame(3, $undone->sequence);
        $this->assertNotSame($original->id, $undone->id);
        $this->assertSame(GiftReconciliation::MATCH_AMBIGUOUS, $undone->gifts()->where('transaction_id', 'D007')->sole()->crm_match_state);
        $this->get(route('try.show'))->assertSee('None of these')->assertDontSee('All candidates rejected; held for review');
        $this->get(route('try.history.runs.show', $undone))->assertSee('reject')->assertSee('undo');
        $this->assertDatabaseCount('review_decisions', 2);

        $this->post(route('try.review.matches.store'), [
            'run_id' => $rejected->id, 'transaction_id' => 'D007', 'crm_record_id' => 'CRM005',
        ])->assertSessionHasErrors('run_id');
    }

    public function test_undoing_a_confirmed_match_invalidates_approvals_and_preserves_the_original_decision(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided']);
        $this->post(route('try.review.matches.store'), ['transaction_id' => 'D007', 'crm_record_id' => 'CRM005']);
        $confirmed = ReconciliationRun::query()->latest('sequence')->firstOrFail();
        $this->post(route('try.approvals.store'), ['run_id' => $confirmed->id]);
        $this->post(route('try.review.matches.store'), [
            'run_id' => $confirmed->id, 'transaction_id' => 'D007', 'action' => 'undo',
        ])->assertSessionHasNoErrors();
        $latest = ReconciliationRun::query()->latest('sequence')->firstOrFail();
        $this->assertSame(0, $latest->approvals()->count());
        $this->assertSame(GiftReconciliation::MATCH_AMBIGUOUS, $latest->gifts()->where('transaction_id', 'D007')->sole()->crm_match_state);
        $this->assertSame(GiftReconciliation::MATCH_MANUAL, $confirmed->gifts()->where('transaction_id', 'D007')->sole()->crm_match_state);
        $this->assertDatabaseCount('review_decisions', 2);
    }

    public function test_a_candidate_outside_the_current_evidence_is_rejected(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided']);

        $this->from(route('try.show'))
            ->post(route('try.review.matches.store'), [
                'transaction_id' => 'D007',
                'crm_record_id' => 'CRM001',
            ])
            ->assertRedirect(route('try.show'))
            ->assertSessionHasErrors('crm_record_id');

        $this->assertDatabaseCount('review_decisions', 0);
        $this->assertDatabaseCount('reconciliation_runs', 1);
    }

    public function test_a_campaign_outside_the_current_review_queue_is_rejected(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided']);

        $this->from(route('try.show'))
            ->post(route('try.review.campaigns.store'), [
                'campaign_code' => 'GENERAL',
                'campaign_id' => 'CAMPAIGN-OTHER',
            ])
            ->assertRedirect(route('try.show'))
            ->assertSessionHasErrors('campaign_code');

        $this->assertDatabaseCount('review_decisions', 0);
        $this->assertDatabaseCount('reconciliation_runs', 1);
    }

    public function test_a_duplicate_target_is_not_accepted_as_a_manual_candidate_review(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided']);

        $this->from(route('try.show'))
            ->post(route('try.review.matches.store'), [
                'transaction_id' => 'D005',
                'crm_record_id' => 'CRM003',
            ])
            ->assertRedirect(route('try.show'))
            ->assertSessionHasErrors('transaction_id');

        $this->assertDatabaseCount('review_decisions', 0);
        $this->assertDatabaseCount('reconciliation_runs', 1);
    }
}
