<?php

namespace Tests\Feature;

use App\Domain\Reconciliation\Services\CsvParserService;
use App\Domain\Reconciliation\Services\ReferenceFixtureCatalog;
use App\Models\ConfirmationBatch;
use App\Models\ExportBatch;
use App\Models\ImportBatch;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SampleScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        Storage::fake('exports');
        config(['reconciliation.allow_arbitrary_uploads' => false]);
    }

    public function test_landing_and_empty_workspace_offer_described_one_click_samples(): void
    {
        $this->get(route('landing'))->assertOk()
            ->assertSee('What the CSVs contain')
            ->assertSee('What it demonstrates')
            ->assertSee('Load sample: Update this month’s donations')
            ->assertSee('Load sample: Resolve records that need review')
            ->assertSee('Load sample: Explain a payout difference')
            ->assertSee('Load sample: Full reconciliation example');

        $this->post(route('try.start'), ['intent' => 'scenarios'])->assertRedirect(route('try.show'));
        $this->get(route('try.show'))->assertOk()->assertSee('Choose a sample dataset');
        $this->assertDatabaseCount('import_batches', 0);
    }

    #[TestWith(['monthly_v1', [6, 7, 4], ['create' => 2, 'update' => 1, 'unchanged' => 3, 'held' => 0]])]
    #[TestWith(['review_v1', [7, 7, 5], ['create' => 1, 'update' => 0, 'unchanged' => 2, 'held' => 3]])]
    #[TestWith(['payouts_v1', [6, 10, 6], ['create' => 0, 'update' => 2, 'unchanged' => 3, 'held' => 1]])]
    #[TestWith(['reference_v1', [13, 16, 8], ['create' => 1, 'update' => 3, 'unchanged' => 2, 'held' => 6]])]
    public function test_selecting_a_sample_loads_three_validated_reports_and_real_results(string $scenarioId, array $rows, array $outcomes): void
    {
        $this->post(route('try.start'), ['intent' => 'guided', 'scenario_id' => $scenarioId])
            ->assertRedirect(route('try.show'));

        $workspace = app(WorkspaceService::class)->resolveActive();
        $batches = $workspace->importBatches()->get()->keyBy('report_type');
        $this->assertCount(3, $batches);

        foreach (['donations', 'payouts', 'crm'] as $index => $type) {
            $this->assertSame($rows[$index], $batches[$type]->total_rows);
            $this->assertSame(ImportBatch::STATUS_VALIDATED, $batches[$type]->status);
            $this->assertSame($scenarioId, $batches[$type]->context['scenario_id']);
            Storage::disk('imports')->assertExists($batches[$type]->private_file_path);
        }

        $this->assertEquals($outcomes, $workspace->reconciliationRuns()->sole()->outcome_counts);
        $choice = app(ReferenceFixtureCatalog::class)->choices()[$scenarioId];
        $this->get(route('try.show'))->assertOk()
            ->assertSee($choice['title'])
            ->assertSee($choice['contains'])
            ->assertSee($choice['demonstrates'])
            ->assertSee('Currently loaded')
            ->assertDontSee('@if')
            ->assertDontSee('Choose CSV');
    }

    public function test_payout_sample_isolates_expected_deductions_from_an_unexplained_difference(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided', 'scenario_id' => 'payouts_v1']);
        $run = app(WorkspaceService::class)->resolveActive()->reconciliationRuns()->sole();
        $payouts = $run->payouts->keyBy('payout_id');

        $this->assertSame('balanced_sent', $payouts['P-BALANCED']->state);
        $this->assertSame(51500, $payouts['P-BALANCED']->component_total_minor);
        $this->assertSame(0, $payouts['P-BALANCED']->difference_minor);
        $this->assertSame('unbalanced', $payouts['P-DIFFERENCE']->state);
        $this->assertSame(300, abs($payouts['P-DIFFERENCE']->difference_minor));
    }

    #[TestWith(['monthly_v1', 3, 6, 0, []])]
    #[TestWith(['review_v1', 3, 5, 1, [
        ['try.review.campaigns.store', ['campaign_code' => 'NEW-PROGRAM', 'campaign_id' => 'CAMPAIGN-NEW-PROGRAM']],
        ['try.review.matches.store', ['transaction_id' => 'D007', 'crm_record_id' => 'CRM005']],
    ]])]
    #[TestWith(['payouts_v1', 2, 5, 1, []])]
    public function test_each_new_sample_exports_and_verifies_against_its_own_snapshot(string $scenarioId, int $matches, int $unchanged, int $held, array $decisions): void
    {
        $this->post(route('try.start'), ['intent' => 'guided', 'scenario_id' => $scenarioId]);
        foreach ($decisions as [$route, $payload]) {
            $this->post(route($route), $payload)->assertRedirect(route('try.show'))->assertSessionHasNoErrors();
        }

        $workspace = app(WorkspaceService::class)->resolveActive();
        $run = $workspace->reconciliationRuns()->orderByDesc('sequence')->firstOrFail();
        $this->post(route('try.approvals.store'), ['run_id' => $run->id])->assertRedirect(route('try.show'))->assertSessionHasNoErrors();
        $this->post(route('try.exports.store'), ['run_id' => $run->id])->assertRedirect(route('try.show'))->assertSessionHasNoErrors();
        $export = ExportBatch::query()->sole();
        $this->get(route('try.exports.download', $export))->assertOk();
        $this->post(route('try.exports.confirm', $export))->assertRedirect(route('try.show'))->assertSessionHasNoErrors();

        $confirmation = ConfirmationBatch::query()->sole();
        $this->assertEquals([
            'matches_snapshot' => $matches,
            'mismatches_snapshot' => 0,
            'duplicate_key' => 0,
            'cannot_verify_coverage' => 0,
        ], $confirmation->counts);
        $this->assertSame(
            app(ReferenceFixtureCatalog::class)->forScenario($scenarioId)->confirmationSnapshot()['sha256'],
            $confirmation->snapshot_checksum,
        );

        $this->get(route('try.show'))->assertOk()->assertSee('Run post-import verification');
        $this->post(route('try.confirmations.verify', $confirmation))->assertRedirect(route('try.show'))->assertSessionHasNoErrors();
        $this->assertEquals(['create' => 0, 'update' => 0, 'unchanged' => $unchanged, 'held' => $held], $confirmation->refresh()->verification_counts);
        $this->get(route('try.show'))->assertOk()->assertSee('The confirmed snapshot is reconciled');
    }

    public function test_reset_reloads_the_selected_sample_and_switching_keeps_workspaces_separate(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided', 'scenario_id' => 'review_v1']);
        $this->post(route('try.review.campaigns.store'), ['campaign_code' => 'NEW-PROGRAM', 'campaign_id' => 'CAMPAIGN-NEW-PROGRAM']);
        $original = app(WorkspaceService::class)->resolveActive();

        $this->post(route('try.reset'))->assertRedirect(route('try.show'));
        $reset = app(WorkspaceService::class)->resolveActive();
        $this->assertTrue($original->refresh()->isExpired());
        $this->assertSame(['review_v1'], $reset->importBatches->pluck('context.scenario_id')->unique()->values()->all());
        $this->assertSame(0, $reset->reviewDecisions()->count());

        $this->post(route('try.start'), ['intent' => 'guided', 'scenario_id' => 'monthly_v1'])->assertRedirect(route('try.show'));
        $switched = app(WorkspaceService::class)->resolveActive();
        $this->assertNotSame($reset->id, $switched->id);
        $this->assertSame(['monthly_v1'], $switched->importBatches->pluck('context.scenario_id')->unique()->values()->all());
        $this->assertSame(0, $switched->reviewDecisions()->count());
        $this->get(route('try.imports.show', $reset->importBatches->first()))->assertNotFound();
    }

    #[TestWith(['../../.env'])]
    #[TestWith(['unknown'])]
    #[TestWith([['monthly_v1']])]
    public function test_invalid_sample_selection_does_not_create_or_replace_a_workspace(mixed $scenarioId): void
    {
        $this->postJson(route('try.start'), ['intent' => 'guided', 'scenario_id' => $scenarioId])
            ->assertUnprocessable()->assertJsonValidationErrors('scenario_id');
        $this->assertDatabaseCount('guest_workspaces', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    #[TestWith(['monthly_v1'])]
    #[TestWith(['review_v1'])]
    #[TestWith(['payouts_v1'])]
    public function test_optional_downloads_and_uploads_use_the_selected_catalogue(string $scenarioId): void
    {
        $catalog = app(ReferenceFixtureCatalog::class)->forScenario($scenarioId);
        $response = $this->get(route('reference-fixtures.download', ['reportType' => 'donations', 'scenario_id' => $scenarioId]));
        $response->assertOk()->assertDownload('donations.csv');
        $contents = $response->streamedContent();
        $this->assertSame($catalog->report('donations')['sha256'], hash('sha256', $contents));

        $this->post(route('try.start'), ['intent' => 'scenarios']);
        $this->post(route('try.imports.store'), [
            'report_type' => 'donations',
            'file' => UploadedFile::fake()->createWithContent('donations.csv', $contents),
        ])->assertRedirect();
        $this->assertSame($scenarioId, ImportBatch::query()->sole()->context['scenario_id']);
        $this->get(route('try.show'))->assertOk()
            ->assertSee('Load sample: '.$catalog->choices()[$scenarioId]['title'])
            ->assertDontSee('Currently loaded')
            ->assertDontSee('Your sample donation, payout, and CRM reports are loaded.');
    }

    public function test_unknown_sample_download_is_not_found(): void
    {
        $this->get(route('reference-fixtures.download', ['reportType' => 'donations', 'scenario_id' => '../reference-v1']))->assertNotFound();
    }

    public function test_background_loading_returns_a_workspace_link_after_reconciliation_is_complete(): void
    {
        $this->postJson(route('try.start'), ['intent' => 'guided', 'scenario_id' => 'monthly_v1'])
            ->assertOk()->assertJsonPath('data.redirect', route('try.show'));

        $workspace = app(WorkspaceService::class)->resolveActive();
        $this->assertSame(3, $workspace->importBatches()->where('status', ImportBatch::STATUS_VALIDATED)->count());
        $this->assertEquals(['create' => 2, 'update' => 1, 'unchanged' => 3, 'held' => 0], $workspace->reconciliationRuns()->sole()->outcome_counts);
        $this->get(route('try.show'))->assertOk()->assertSee('Update this month’s donations');
    }

    public function test_unavailable_background_loading_returns_json_without_creating_a_workspace(): void
    {
        config(['reconciliation.new_workspaces_enabled' => false]);

        $this->postJson(route('try.start'), ['intent' => 'guided', 'scenario_id' => 'monthly_v1'])
            ->assertStatus(503)->assertJsonPath('error.code', 'NEW_WORKSPACES_DISABLED');
        $this->assertDatabaseCount('guest_workspaces', 0);
        $this->get(route('landing'))->assertOk()->assertSee('View sample CSVs');
    }

    public function test_all_source_previews_are_embedded_without_creating_a_workspace_and_preserve_csv_values(): void
    {
        $response = $this->get(route('landing'))->assertOk()
            ->assertSee('Only show highlighted rows')
            ->assertSee('Why it matters');
        $previews = $response->viewData('samplePreviews');
        $catalog = app(ReferenceFixtureCatalog::class);
        $parser = app(CsvParserService::class);

        $this->assertCount(4, $previews);
        foreach ($previews as $scenarioId => $reports) {
            $this->assertSame(['donations', 'payouts', 'crm'], array_keys($reports));
            $scenario = $catalog->forScenario($scenarioId);
            foreach ($reports as $type => $report) {
                $parsed = $parser->parse($scenario->path($type));
                $this->assertSame($parsed['headers'], $report['headers']);
                $this->assertSame($parsed['rows'], array_column($report['rows'], 'values'));
                $this->assertSame(range(1, count($parsed['rows'])), array_column($report['rows'], 'number'));
                $this->assertNotEmpty(array_filter(array_column($report['rows'], 'note')));
                $identifier = ['donations' => 'transaction_id', 'payouts' => 'component_id', 'crm' => 'crm_record_id'][$type];
                $this->assertSame([], array_diff(
                    array_keys($scenario->scenario()['preview_notes'][$type]),
                    array_column($parsed['rows'], $identifier),
                ));
            }
        }
        $monthlyGifts = collect($previews['monthly_v1']['donations']['rows'])->keyBy('values.transaction_id');
        $this->assertNull($monthlyGifts['D001']['note']);
        $this->assertStringContainsString('GBP 75.00', $monthlyGifts['D003']['note']);
        $this->assertStringContainsString('GBP 70.00', $monthlyGifts['D003']['note']);
        $repeatedGift = collect($previews['review_v1']['donations']['rows'])->filter(fn (array $row): bool => $row['values']['transaction_id'] === 'D001');
        $this->assertCount(2, $repeatedGift);
        $this->assertCount(2, $repeatedGift->filter(fn (array $row): bool => $row['note'] !== null));
        $this->assertDatabaseCount('guest_workspaces', 0);
    }
}
