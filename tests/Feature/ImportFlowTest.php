<?php

namespace Tests\Feature;

use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        config(['reconciliation.allow_arbitrary_uploads' => true]);
    }

    public function test_local_upload_stages_typed_report_rows_synchronously(): void
    {
        $this->startWorkspace();

        $response = $this->post(route('try.imports.store'), [
            'report_type' => 'donations',
            'file' => $this->csvUpload("transaction_id,gross_amount\nD001,100.00\nD002,25.00\n"),
        ]);

        $batch = ImportBatch::query()->firstOrFail();

        $response->assertRedirect(route('try.imports.show', $batch));
        $this->assertSame('donations', $batch->report_type);
        $this->assertSame(ImportBatch::STATUS_MAPPING_REQUIRED, $batch->status);
        $this->assertSame(2, $batch->total_rows);
        $this->assertDatabaseCount('import_rows', 2);
        $this->assertSame('D001', ImportRow::query()->orderBy('row_number')->firstOrFail()->raw_data['transaction_id']);
    }

    public function test_public_mode_rejects_arbitrary_upload_before_persisting_it(): void
    {
        config([
            'reconciliation.allow_arbitrary_uploads' => false,
            'reconciliation.approved_fixture_checksums' => [],
        ]);
        $this->startWorkspace();

        $response = $this->postJson(route('try.imports.store'), [
            'report_type' => 'donations',
            'file' => $this->csvUpload("transaction_id,gross_amount\nD001,100.00\n"),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 'PUBLIC_FIXTURE_REQUIRED');
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_repeat_file_is_duplicate_only_within_the_same_report_type(): void
    {
        $this->startWorkspace();
        $contents = "id,amount\n001,10.00\n";

        $this->post(route('try.imports.store'), [
            'report_type' => 'donations',
            'file' => $this->csvUpload($contents),
        ])->assertRedirect();

        $this->postJson(route('try.imports.store'), [
            'report_type' => 'donations',
            'file' => $this->csvUpload($contents),
        ])->assertConflict()->assertJsonPath('error.code', 'DUPLICATE_FILE');

        $this->post(route('try.imports.store'), [
            'report_type' => 'crm',
            'file' => $this->csvUpload($contents, 'crm.csv'),
        ])->assertRedirect();

        $this->assertDatabaseCount('import_batches', 2);
    }

    public function test_invalid_csv_leaves_a_failed_import_without_staged_rows(): void
    {
        $this->startWorkspace();

        $this->post(route('try.imports.store'), [
            'report_type' => 'payouts',
            'file' => $this->csvUpload("component_id,amount_signed\nP001,10\0.00\n"),
        ])->assertRedirect();

        $batch = ImportBatch::query()->firstOrFail();

        $this->assertSame(ImportBatch::STATUS_FAILED, $batch->status);
        $this->assertSame('BINARY_FILE', $batch->processing_error_code);
        $this->assertDatabaseCount('import_rows', 0);
    }

    public function test_guest_cannot_access_another_workspace_import(): void
    {
        $this->startWorkspace();
        $this->post(route('try.imports.store'), [
            'report_type' => 'crm',
            'file' => $this->csvUpload("crm_record_id,gross_amount\nR001,25.00\n"),
        ]);
        $batch = ImportBatch::query()->firstOrFail();

        $this->flushSession();
        $this->startWorkspace();

        $this->get(route('try.imports.show', $batch))->assertNotFound();
    }

    public function test_expired_workspace_cannot_open_import(): void
    {
        $this->startWorkspace();
        $this->post(route('try.imports.store'), [
            'report_type' => 'crm',
            'file' => $this->csvUpload("crm_record_id,gross_amount\nR001,25.00\n"),
        ]);
        $batch = ImportBatch::query()->firstOrFail();
        GuestWorkspace::query()->firstOrFail()->forceFill([
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->get(route('try.imports.show', $batch))->assertStatus(410);
    }

    public function test_import_file_is_stored_outside_the_public_disk(): void
    {
        $this->startWorkspace();
        $this->post(route('try.imports.store'), [
            'report_type' => 'donations',
            'file' => $this->csvUpload("transaction_id,gross_amount\nD001,25.00\n"),
        ]);
        $batch = ImportBatch::query()->firstOrFail();

        Storage::disk('imports')->assertExists($batch->private_file_path);
        $this->assertStringNotContainsString('/public/', $batch->private_file_path);
        $this->assertFalse(is_file(public_path($batch->private_file_path)));
    }

    private function startWorkspace(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided'])->assertRedirect(route('try.show'));
        $this->assertNotEmpty(session(WorkspaceService::SESSION_SECRET_KEY));
    }

    private function csvUpload(string $contents, string $name = 'report.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
