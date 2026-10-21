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
    }

    public function test_upload_stages_typed_report_rows_synchronously(): void
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

    public function test_homepage_upload_creates_a_workspace_and_accepts_custom_csv(): void
    {
        $response = $this->postJson(route('try.upload'), [
            'report_type' => 'donations',
            'file' => $this->csvUpload("transaction_id,gross_amount\nD001,100.00\n"),
        ]);

        $batch = ImportBatch::query()->firstOrFail();
        $response->assertCreated()->assertJsonPath('data.redirect', route('try.imports.show', $batch));
        $this->assertDatabaseCount('guest_workspaces', 1);
        $this->assertSame(ImportBatch::STATUS_MAPPING_REQUIRED, $batch->status);
        $this->assertNull($batch->context);
        $this->get(route('try.imports.show', $batch))->assertOk();
        $this->get(route('try.show'))->assertOk()->assertDontSee('Public demo uploads must match');
    }

    public function test_homepage_uploads_share_a_workspace_and_retries_are_idempotent(): void
    {
        $data = ['report_type' => 'donations', 'idempotency_key' => 'homepage-upload-1'];
        $csv = "transaction_id,gross_amount\nCUSTOM001,25.00\n";
        $first = $this->postJson(route('try.upload'), [...$data, 'file' => $this->csvUpload($csv)]);
        $first->assertCreated();
        $this->postJson(route('try.upload'), [...$data, 'file' => $this->csvUpload($csv)])
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->post(route('try.upload'), [
            'report_type' => 'crm',
            'file' => $this->csvUpload("crm_record_id,gross_amount\nCUSTOMCRM001,25.00\n", 'crm.csv'),
        ])->assertRedirect();
        $this->assertDatabaseCount('guest_workspaces', 1);
        $this->assertDatabaseCount('import_batches', 2);
    }

    public function test_homepage_upload_does_not_mix_custom_files_with_a_sample_workspace(): void
    {
        $this->post(route('try.start'), ['intent' => 'guided'])->assertRedirect();
        $sample = GuestWorkspace::query()->firstOrFail();
        $response = $this->postJson(route('try.upload'), [
            'report_type' => 'donations',
            'file' => $this->csvUpload("transaction_id,gross_amount\nCUSTOM001,25.00\n"),
        ])->assertCreated();
        $batch = ImportBatch::findOrFail($response->json('data.id'));
        $this->assertNotSame($sample->id, $batch->guest_workspace_id);
        $this->assertSame(3, $sample->importBatches()->count());
        $this->assertDatabaseCount('guest_workspaces', 2);
    }

    public function test_invalid_homepage_upload_does_not_create_a_workspace(): void
    {
        $this->postJson(route('try.upload'), ['report_type' => 'donations'])->assertUnprocessable();
        $this->postJson(route('try.upload'), [
            'report_type' => 'unknown', 'file' => $this->csvUpload("id\n001\n"),
        ])->assertUnprocessable();
        config(['reconciliation.max_csv_size_bytes' => 1024]);
        $this->postJson(route('try.upload'), [
            'report_type' => 'donations', 'file' => UploadedFile::fake()->create('large.csv', 2),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('guest_workspaces', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_homepage_upload_respects_workspace_availability(): void
    {
        config(['reconciliation.new_workspaces_enabled' => false]);
        $this->postJson(route('try.upload'), [
            'report_type' => 'donations', 'file' => $this->csvUpload("id\n001\n"),
        ])->assertStatus(503)->assertJsonPath('error.code', 'NEW_WORKSPACES_DISABLED');
        $this->assertDatabaseCount('guest_workspaces', 0);
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
        $this->post(route('try.start'), ['intent' => 'scenarios'])->assertRedirect(route('try.show'));
        $this->assertNotEmpty(session(WorkspaceService::SESSION_SECRET_KEY));
    }

    private function csvUpload(string $contents, string $name = 'report.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
