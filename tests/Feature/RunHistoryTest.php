<?php

namespace Tests\Feature;

use App\Models\ConfirmationBatch;
use App\Models\ExportBatch;
use App\Models\ImportBatch;
use App\Models\ReconciliationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RunHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        Storage::fake('exports');
        config(['reconciliation.allow_arbitrary_uploads' => false]);
    }

    public function test_workspace_history_links_the_full_reconciliation_audit_chain(): void
    {
        $verificationRun = $this->completeVerifiedFlow();
        $sourceRun = ReconciliationRun::query()->where('sequence', 3)->firstOrFail();
        $export = ExportBatch::query()->sole();
        $supersededCrm = ImportBatch::query()
            ->where('report_type', 'crm')
            ->where('status', ImportBatch::STATUS_SUPERSEDED)
            ->sole();

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('View run history', false);

        $this->get(route('try.history.index'))
            ->assertOk()
            ->assertSee('Run history', false)
            ->assertSee('4 immutable runs', false)
            ->assertSee('Run 4 · Post-import verification', false)
            ->assertSee('Run 3 · Reviewed export source', false)
            ->assertSee('Run 1 · Automated baseline', false)
            ->assertSee('crm-post-import.csv', false);

        $this->get(route('try.history.runs.show', $sourceRun))
            ->assertOk()
            ->assertSee('Reconciliation run 3', false)
            ->assertSee('Frozen inputs', false)
            ->assertSee('Review decisions in this run', false)
            ->assertSee('NEW-PROGRAM', false)
            ->assertSee('CRM005', false)
            ->assertSee('Gift outcomes', false)
            ->assertSee('D002', false)
            ->assertSee('Approvals and downstream artifacts', false)
            ->assertSee($export->id, false)
            ->assertSee('Confirmation · crm-post-import.csv · verified', false)
            ->assertSee('Expected action', false)
            ->assertSee('matches snapshot', false)
            ->assertSee('Open verification run 4', false)
            ->assertDontSee('@example.test', false);

        $this->get(route('try.imports.show', $supersededCrm))
            ->assertOk()
            ->assertSee('read-only history', false)
            ->assertSee('Source preview', false)
            ->assertDontSee('Save mapping and validate', false);

        $this->post(route('try.imports.mapping.store', $supersededCrm), [
            'mapping' => $supersededCrm->mappingProfile->mapping,
        ])->assertConflict();

        $this->get(route('try.history.runs.show', $verificationRun))
            ->assertOk()
            ->assertSee('Later CRM snapshot rerun', false)
            ->assertSee('Open source run 3', false)
            ->assertSee('crm-post-import.csv', false)
            ->assertSee('8', false)
            ->assertDontSee('@example.test', false);

        $this->post(route('try.start'), ['intent' => 'scenarios']);

        $this->get(route('try.history.runs.show', $sourceRun))->assertNotFound();
    }

    private function completeVerifiedFlow(): ReconciliationRun
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
        $sourceRun = ReconciliationRun::query()->where('sequence', 3)->firstOrFail();
        $this->post(route('try.approvals.store'), ['run_id' => $sourceRun->id]);
        $this->post(route('try.exports.store'), ['run_id' => $sourceRun->id]);
        $export = ExportBatch::query()->sole();
        $this->post(route('try.exports.confirm', $export));
        $confirmation = ConfirmationBatch::query()->sole();
        $this->post(route('try.confirmations.verify', $confirmation));

        return ReconciliationRun::query()->where('sequence', 4)->firstOrFail();
    }
}
