<?php

namespace Tests\Feature;

use App\Domain\Reconciliation\Services\MappingValidationService;
use App\Models\CanonicalRecordVersion;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MappingProfile;
use App\Models\SourceOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MappingValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        $this->post(route('try.start'), ['intent' => 'scenarios']);
    }

    public function test_exact_canonical_headers_create_a_versioned_profile_and_normalize_money_without_floats(): void
    {
        $this->upload('donations', implode("\n", [
            'transaction_id,gifted_at,gross_amount,currency,status,refunded_amount,campaign_code,donor_ref,donor_email,source_updated_at',
            '00042,2026-09-02T10:15:00+01:00,100.05,GBP,paid,0.00,GENERAL,,,2026-09-02T10:16:00+01:00',
            '',
        ]));

        $batch = ImportBatch::query()->firstOrFail();
        $row = ImportRow::query()->firstOrFail();
        $profile = MappingProfile::query()->firstOrFail();

        $this->assertSame(ImportBatch::STATUS_VALIDATED, $batch->status);
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(0, $batch->rejected_rows);
        $this->assertSame(1, $profile->version);
        $this->assertSame('00042', $row->normalized_data['transaction_id']);
        $this->assertSame(10005, $row->normalized_data['gross_amount_minor']);
        $this->assertSame(0, $row->normalized_data['refunded_amount_minor']);
        $this->assertSame($row->raw_data['gross_amount'], '100.05');
    }

    public function test_missing_required_columns_leave_the_import_waiting_for_mapping(): void
    {
        $this->upload('donations', "transaction_id,gross_amount\nD001,100.00\n");

        $batch = ImportBatch::query()->firstOrFail();

        $this->assertSame(ImportBatch::STATUS_MAPPING_REQUIRED, $batch->status);
        $this->assertNull($batch->mapping_profile_id);
        $this->assertContains('MISSING_REQUIRED_MAPPING', array_column($batch->schema_issues, 'code'));
        $this->assertSame(ImportRow::STATUS_STAGED, ImportRow::query()->firstOrFail()->status);

        $this->get(route('try.imports.show', $batch))
            ->assertOk()
            ->assertSee('Column mapping', false)
            ->assertSee('Choose source columns for every required canonical field', false);
    }

    public function test_invalid_values_produce_actionable_row_findings_and_cannot_validate(): void
    {
        $this->upload('donations', implode("\n", [
            'transaction_id,gifted_at,gross_amount,currency,status,refunded_amount,campaign_code',
            'D001,09/02/2026,100,USD,settled,120.00,GENERAL',
            '',
        ]));

        $batch = ImportBatch::query()->firstOrFail();
        $row = ImportRow::query()->firstOrFail();
        $codes = array_column($row->validation_issues, 'code');

        $this->assertSame(ImportBatch::STATUS_VALIDATION_FAILED, $batch->status);
        $this->assertSame(0, $batch->valid_rows);
        $this->assertSame(1, $batch->rejected_rows);
        $this->assertContains('AMBIGUOUS_DATETIME', $codes);
        $this->assertContains('INVALID_MONEY', $codes);
        $this->assertContains('UNSUPPORTED_CURRENCY', $codes);
        $this->assertContains('UNKNOWN_STATUS', $codes);
        $this->assertDatabaseCount('canonical_records', 0);
    }

    public function test_extra_columns_are_retained_as_unmapped_evidence_without_blocking_valid_rows(): void
    {
        $this->upload('crm', implode("\n", [
            'crm_record_id,external_gift_key,provider_transaction_id,gross_amount,refunded_amount,currency,gifted_at,gift_status,campaign_id,donor_ref,donor_email,legacy_note',
            'CRM001,key,D001,10.00,0.00,GBP,2026-09-02T10:15:00+01:00,paid,CAMPAIGN-GENERAL,,,keep me',
            '',
        ]));

        $batch = ImportBatch::query()->firstOrFail();

        $this->assertSame(ImportBatch::STATUS_VALIDATED, $batch->status);
        $this->assertSame(['legacy_note'], $batch->unmapped_headers);
        $this->assertContains('UNMAPPED_COLUMN', array_column($batch->schema_issues, 'code'));
        $this->assertSame('keep me', ImportRow::query()->firstOrFail()->raw_data['legacy_note']);
    }

    public function test_custom_mapping_creates_new_versions_and_revalidates_without_changing_raw_evidence(): void
    {
        $contents = implode("\n", [
            'Gift ID,When,Gross,Curr,State,Returned,Campaign',
            'D900,2026-09-02T10:15:00+01:00,25.00,GBP,paid,0.00,GENERAL',
            '',
        ]);
        $this->upload('donations', $contents);
        $batch = ImportBatch::query()->firstOrFail();
        $originalRaw = ImportRow::query()->firstOrFail()->raw_data;
        $mapping = [
            'transaction_id' => 'Gift ID',
            'gifted_at' => 'When',
            'gross_amount' => 'Gross',
            'currency' => 'Curr',
            'status' => 'State',
            'refunded_amount' => 'Returned',
            'campaign_code' => 'Campaign',
        ];

        $this->post(route('try.imports.mapping.store', $batch), ['mapping' => $mapping])
            ->assertRedirect(route('try.imports.show', $batch));

        $batch->refresh();
        $this->assertSame(ImportBatch::STATUS_VALIDATED, $batch->status);
        $this->assertSame(1, $batch->mappingProfile->version);
        $this->assertSame(2500, ImportRow::query()->firstOrFail()->normalized_data['gross_amount_minor']);

        $this->post(route('try.imports.mapping.store', $batch), ['mapping' => $mapping])
            ->assertRedirect(route('try.imports.show', $batch));

        $batch->refresh();
        $this->assertSame(2, $batch->mappingProfile->version);
        $this->assertDatabaseCount('mapping_profiles', 2);
        $this->assertSame($originalRaw, ImportRow::query()->firstOrFail()->raw_data);
        $this->assertDatabaseCount('canonical_records', 1);
        $this->assertDatabaseCount('canonical_record_versions', 1);
        $this->assertDatabaseCount('source_occurrences', 2);
        $this->assertCount(2, SourceOccurrence::query()->distinct()->pluck('mapping_profile_id'));

        $this->upload('donations', str_replace('D900', 'D901', $contents));
        $reusedBatch = ImportBatch::query()->whereKeyNot($batch->id)->firstOrFail();

        $this->assertSame(ImportBatch::STATUS_VALIDATED, $reusedBatch->status);
        $this->assertSame($batch->mapping_profile_id, $reusedBatch->mapping_profile_id);
        $this->assertDatabaseCount('mapping_profiles', 2);
    }

    public function test_invalid_mapping_is_rejected_without_creating_a_profile(): void
    {
        $this->upload('donations', "Gift ID,Gross\nD001,10.00\n");
        $batch = ImportBatch::query()->firstOrFail();

        $response = $this->postJson(route('try.imports.mapping.store', $batch), [
            'mapping' => [
                'transaction_id' => 'Missing Header',
                'gross_amount' => 'Gross',
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 'INVALID_MAPPING');
        $this->assertDatabaseCount('mapping_profiles', 0);
        $this->assertSame(ImportBatch::STATUS_MAPPING_REQUIRED, $batch->refresh()->status);
    }

    public function test_schema_fingerprint_uses_header_names_instead_of_column_positions(): void
    {
        $service = app(MappingValidationService::class);

        $this->assertSame(
            $service->fingerprint(['transaction_id', 'gross_amount', 'currency']),
            $service->fingerprint(['currency', 'transaction_id', 'gross_amount']),
        );
        $this->assertNotSame(
            $service->fingerprint(['transaction_id', 'gross_amount']),
            $service->fingerprint(['transaction_id', 'net_amount']),
        );
    }

    public function test_invalid_calendar_dates_are_not_silently_normalized(): void
    {
        $this->upload('donations', implode("\n", [
            'transaction_id,gifted_at,gross_amount,currency,status,refunded_amount,campaign_code',
            'D001,2026-02-30T10:15:00+00:00,10.00,GBP,paid,0.00,GENERAL',
            '',
        ]));

        $batch = ImportBatch::query()->firstOrFail();
        $row = ImportRow::query()->firstOrFail();

        $this->assertSame(ImportBatch::STATUS_VALIDATION_FAILED, $batch->status);
        $this->assertContains('INVALID_DATETIME', array_column($row->validation_issues, 'code'));
    }

    public function test_amounts_outside_the_configured_range_are_rejected(): void
    {
        config(['reconciliation.max_absolute_money_minor_units' => 10_000]);

        $this->upload('donations', implode("\n", [
            'transaction_id,gifted_at,gross_amount,currency,status,refunded_amount,campaign_code',
            'D001,2026-09-02T10:15:00+00:00,100.01,GBP,paid,0.00,GENERAL',
            '',
        ]));

        $batch = ImportBatch::query()->firstOrFail();
        $row = ImportRow::query()->firstOrFail();

        $this->assertSame(ImportBatch::STATUS_VALIDATION_FAILED, $batch->status);
        $this->assertContains('MONEY_OUT_OF_RANGE', array_column($row->validation_issues, 'code'));
    }

    public function test_mapping_cannot_be_changed_from_another_workspace_session(): void
    {
        $this->upload('donations', "Gift ID,Gross\nD001,10.00\n");
        $batch = ImportBatch::query()->firstOrFail();

        $this->flushSession();
        $this->post(route('try.start'), ['intent' => 'scenarios']);

        $this->post(route('try.imports.mapping.store', $batch), [
            'mapping' => ['transaction_id' => 'Gift ID'],
        ])->assertNotFound();

        $this->assertDatabaseCount('mapping_profiles', 0);
    }

    public function test_modal_returns_separate_mapping_and_preview_panels_with_escaped_source_values(): void
    {
        $this->upload('donations', "Gift ID,Gross\n<script>alert(1)</script>,10.00\n");
        $batch = ImportBatch::query()->firstOrFail();
        $response = $this->getJson(route('try.imports.show', $batch))->assertOk()
            ->assertJsonPath('data.status', ImportBatch::STATUS_MAPPING_REQUIRED);
        $html = $response->json('data.html');
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('data-import-panel="mapping"', $html);
        $this->assertStringContainsString('data-import-panel="preview" tabindex="0" hidden', $html);
        $this->assertStringContainsString('data-mapping-form', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<!DOCTYPE', $html);
        $this->assertStringContainsString('Review mapping', $response->json('data.card_html'));
        $this->get(route('try.imports.show', $batch))->assertOk()->assertSee('Back to report set');
        $this->flushSession();
        $this->post(route('try.start'), ['intent' => 'scenarios']);
        $this->getJson(route('try.imports.show', $batch))->assertNotFound();
    }

    public function test_modal_can_reload_updated_validation_and_report_card_after_saving(): void
    {
        $this->upload('donations', "Gift ID,gifted_at,gross_amount,currency,status,refunded_amount,campaign_code\nCUSTOM01,2026-09-02T10:15:00+00:00,25.00,GBP,paid,0.00,GENERAL\n");
        $batch = ImportBatch::query()->firstOrFail();
        $mapping = array_combine(
            ['transaction_id', 'gifted_at', 'gross_amount', 'currency', 'status', 'refunded_amount', 'campaign_code'],
            ['Gift ID', 'gifted_at', 'gross_amount', 'currency', 'status', 'refunded_amount', 'campaign_code'],
        );
        $this->postJson(route('try.imports.mapping.store', $batch), ['mapping' => $mapping])
            ->assertOk()->assertJsonPath('data.status', ImportBatch::STATUS_VALIDATED);
        $response = $this->getJson(route('try.imports.show', $batch))->assertOk();
        $this->assertStringContainsString('Validation passed for all 1 rows', $response->json('data.html'));
        $this->assertStringContainsString('data-report-status="validated"', $response->json('data.card_html'));
        $this->assertStringContainsString('1 valid', $response->json('data.card_html'));
    }

    #[DataProvider('optionalRefundCases')]
    public function test_refund_defaults_reach_canonical_records_without_hiding_invalid_values(string $reportType, ?string $refund, ?int $expectedMinor, ?string $error): void
    {
        $raw = $reportType === 'donations'
            ? ['transaction_id' => 'OPTIONAL01', 'gifted_at' => '2026-09-02T10:15:00+00:00', 'gross_amount' => '25.00', 'currency' => 'GBP', 'status' => 'paid', 'campaign_code' => 'GENERAL']
            : ['crm_record_id' => 'CRM-OPTIONAL01', 'gifted_at' => '2026-09-02T10:15:00+00:00', 'gross_amount' => '25.00', 'currency' => 'GBP', 'gift_status' => 'paid', 'campaign_id' => 'CAMPAIGN-GENERAL'];
        if ($refund !== null) {
            $raw['refunded_amount'] = $refund;
        }
        $this->upload($reportType, implode(',', array_keys($raw))."\n".implode(',', $raw)."\n");
        $batch = ImportBatch::query()->firstOrFail();
        $row = ImportRow::query()->firstOrFail();
        $storedRaw = $row->raw_data;
        ksort($raw);
        ksort($storedRaw);
        $this->assertSame($raw, $storedRaw);
        $this->assertSame($expectedMinor, $row->normalized_data['refunded_amount_minor']);
        if ($error === null) {
            $this->assertSame(ImportBatch::STATUS_VALIDATED, $batch->status);
            $this->assertSame($expectedMinor, CanonicalRecordVersion::query()->firstOrFail()->normalized_data['refunded_amount_minor']);
        } else {
            $this->assertSame(ImportBatch::STATUS_VALIDATION_FAILED, $batch->status);
            $this->assertContains($error, array_column($row->validation_issues, 'code'));
            $this->assertDatabaseCount('canonical_record_versions', 0);
        }
    }

    public static function optionalRefundCases(): iterable
    {
        foreach (['donations', 'crm'] as $type) {
            foreach ([
                'missing column' => [null, 0, null],
                'empty cell' => ['', 0, null],
                'whitespace cell' => ['   ', 0, null],
                'explicit zero' => ['0.00', 0, null],
                'nonzero refund' => ['5.25', 525, null],
                'invalid text' => ['unknown', null, 'INVALID_MONEY'],
                'negative refund' => ['-1.00', -100, 'AMOUNT_NEGATIVE'],
                'excessive refund' => ['30.00', 3000, 'REFUND_EXCEEDS_GROSS'],
            ] as $case => $arguments) {
                yield $type.' '.$case => [$type, ...$arguments];
            }
        }
    }

    private function upload(string $reportType, string $contents): void
    {
        $this->post(route('try.imports.store'), [
            'report_type' => $reportType,
            'file' => UploadedFile::fake()->createWithContent($reportType.'.csv', $contents),
        ])->assertRedirect();
    }
}
