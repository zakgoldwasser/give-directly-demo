<?php

namespace Tests\Feature;

use App\Domain\Reconciliation\Services\CsvParserService;
use App\Domain\Reconciliation\Services\ReferenceFixtureCatalog;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReferenceScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        config(['reconciliation.allow_arbitrary_uploads' => false]);
    }

    public function test_manifest_checksums_and_declared_row_counts_match_the_versioned_fixtures(): void
    {
        $catalog = app(ReferenceFixtureCatalog::class);
        $parser = app(CsvParserService::class);

        foreach (['donations', 'payouts', 'crm'] as $reportType) {
            $report = $catalog->report($reportType);
            $path = $catalog->path($reportType);

            $this->assertSame($report['sha256'], hash_file('sha256', $path));
            $this->assertSame($report['expected_rows'], $parser->parse($path)['total_rows']);
        }

        $this->assertSame([
            'donation_rows' => 13,
            'logical_gifts' => 12,
            'payout_components' => 16,
            'crm_records' => 8,
            'gross_amount' => '1130.00',
            'returned_amount' => '130.00',
            'outcomes' => [
                'unchanged' => 2,
                'create' => 2,
                'update' => 4,
                'held' => 4,
            ],
        ], $catalog->scenario()['expected']);
    }

    public function test_guided_demo_loads_the_complete_report_set_with_context_and_immutable_rows(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided'])
            ->assertRedirect(route('try.show'));

        $workspace = GuestWorkspace::query()->firstOrFail();
        $batches = $workspace->importBatches()->get()->keyBy('report_type');

        $this->assertCount(3, $batches);
        $this->assertSame(13, $batches['donations']->total_rows);
        $this->assertSame(16, $batches['payouts']->total_rows);
        $this->assertSame(8, $batches['crm']->total_rows);
        $this->assertDatabaseCount('import_rows', 37);

        foreach ($batches as $type => $batch) {
            $this->assertSame(ImportBatch::STATUS_MAPPING_REQUIRED, $batch->status);
            $this->assertSame($type, $batch->context['report_type']);
            $this->assertSame('reference_v1', $batch->context['scenario_id']);
            $this->assertSame('demo_caf', $batch->context['provider_id']);
            $this->assertSame('GBP', $batch->context['currency']);
            Storage::disk('imports')->assertExists($batch->private_file_path);
        }

        $donationRows = ImportRow::query()
            ->where('import_batch_id', $batches['donations']->id)
            ->orderBy('row_number')
            ->limit(2)
            ->get();

        $this->assertSame($donationRows[0]->raw_data, $donationRows[1]->raw_data);
        $this->assertSame('D001', $donationRows[0]->raw_data['transaction_id']);

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('Loaded 13 rows', false)
            ->assertSee('Loaded 16 rows', false)
            ->assertSee('Loaded 8 rows', false);
    }

    public function test_fixture_download_returns_the_catalogued_file(): void
    {
        $catalog = app(ReferenceFixtureCatalog::class);

        $response = $this->get(route('reference-fixtures.download', 'donations'));

        $response->assertOk();
        $response->assertDownload('donations.csv');
        $this->assertSame(
            $catalog->report('donations')['sha256'],
            hash('sha256', $response->streamedContent()),
        );
    }

    public function test_public_mode_accepts_the_supplied_fixture_for_its_declared_report_type(): void
    {
        $catalog = app(ReferenceFixtureCatalog::class);
        $this->post(route('try.start'), ['intent' => 'scenarios']);

        $response = $this->post(route('try.imports.store'), [
            'report_type' => 'payouts',
            'file' => UploadedFile::fake()->createWithContent(
                'payouts.csv',
                file_get_contents($catalog->path('payouts')),
            ),
        ]);

        $batch = ImportBatch::query()->firstOrFail();
        $response->assertRedirect(route('try.imports.show', $batch));
        $this->assertSame(16, $batch->total_rows);
        $this->assertSame('payouts', $batch->context['report_type']);
    }

    public function test_public_mode_rejects_a_valid_fixture_uploaded_as_the_wrong_report_type(): void
    {
        $catalog = app(ReferenceFixtureCatalog::class);
        $this->post(route('try.start'), ['intent' => 'scenarios']);

        $response = $this->postJson(route('try.imports.store'), [
            'report_type' => 'crm',
            'file' => UploadedFile::fake()->createWithContent(
                'donations.csv',
                file_get_contents($catalog->path('donations')),
            ),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 'PUBLIC_FIXTURE_REQUIRED');
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_resetting_a_guided_demo_loads_a_fresh_reference_set(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided']);
        $previousWorkspace = GuestWorkspace::query()->firstOrFail();

        $this->post(route('try.reset'))->assertRedirect(route('try.show'));

        $previousWorkspace->refresh();
        $replacement = GuestWorkspace::query()->whereKeyNot($previousWorkspace->id)->firstOrFail();

        $this->assertTrue($previousWorkspace->isExpired());
        $this->assertCount(3, $replacement->importBatches);
        $this->assertDatabaseCount('import_batches', 6);
    }
}
