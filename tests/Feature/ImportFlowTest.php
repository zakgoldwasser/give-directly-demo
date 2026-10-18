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

    public function test_sample_import_stages_rows_with_mapping_config(): void
    {
        $this->startWorkspace('sample');

        $response = $this->post(route('try.sample'));
        $batch = ImportBatch::query()->firstOrFail();

        $response->assertRedirect(route('try.imports.show', $batch));

        $batch->refresh();

        $this->assertSame(ImportBatch::STATUS_MAPPING_REQUIRED, $batch->status);
        $this->assertGreaterThan(100, $batch->total_rows);
        $this->assertSame($batch->total_rows, ImportRow::query()->where('import_batch_id', $batch->id)->count());
        $this->assertIsArray($batch->mapping_config);
        $this->assertArrayHasKey('fields', $batch->mapping_config);
        $this->assertNotNull($batch->parsing_config);
        $this->assertSame('Gift Date', $batch->parsing_config['headers'][0] ?? null);

        $show = $this->get(route('try.imports.show', $batch));
        $show->assertOk();
        $show->assertSee('Parse complete', false);
        $show->assertSee('Raw preview', false);
    }

    public function test_upload_creates_staged_rows_without_mapping_config(): void
    {
        $this->startWorkspace('upload');

        $file = $this->csvUpload("Gift Date,Gift Value\n2025-01-01,25.00\n2025-01-02,40.00\n");

        $response = $this->post(route('try.imports.store'), [
            'file' => $file,
        ]);

        $batch = ImportBatch::query()->firstOrFail();

        $response->assertRedirect(route('try.imports.show', $batch));
        $this->assertSame(ImportBatch::STATUS_MAPPING_REQUIRED, $batch->fresh()->status);
        $this->assertNull($batch->fresh()->mapping_config);
        $this->assertSame(2, $batch->fresh()->total_rows);
        $this->assertSame(2, ImportRow::query()->count());
    }

    public function test_json_upload_returns_redirect_payload(): void
    {
        $this->startWorkspace('upload');

        $file = $this->csvUpload("Gift Date,Gift Value\n2025-01-01,25.00\n");

        $response = $this->postJson(route('try.imports.store'), [
            'file' => $file,
        ]);

        $batch = ImportBatch::query()->firstOrFail();

        $response->assertCreated();
        $response->assertJsonPath('data.id', $batch->id);
        $response->assertJsonPath('data.redirect', route('try.imports.show', $batch));
    }

    public function test_duplicate_checksum_reuses_existing_import(): void
    {
        $this->startWorkspace('upload');

        $contents = "Gift Date,Gift Value\n2025-01-01,25.00\n";

        $this->post(route('try.imports.store'), [
            'file' => $this->csvUpload($contents),
        ])->assertRedirect();

        $this->assertSame(1, ImportBatch::query()->count());
        $first = ImportBatch::query()->firstOrFail();
        $rowCount = ImportRow::query()->count();

        $response = $this->post(route('try.imports.store'), [
            'file' => $this->csvUpload($contents, 'again.csv'),
        ]);

        $response->assertRedirect(route('try.imports.show', $first));
        $response->assertSessionHas('import_duplicate_warning');
        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame($rowCount, ImportRow::query()->count());
    }

    public function test_active_import_cap_is_enforced(): void
    {
        config(['givingledger.max_active_imports_per_session' => 3]);

        $this->startWorkspace('upload');
        $workspace = GuestWorkspace::query()->firstOrFail();

        ImportBatch::factory()->count(3)->create([
            'guest_workspace_id' => $workspace->id,
            'status' => ImportBatch::STATUS_MAPPING_REQUIRED,
            'expires_at' => $workspace->expires_at,
        ]);

        $response = $this->postJson(route('try.imports.store'), [
            'file' => $this->csvUpload("Gift Date,Gift Value\n2025-01-01,1\n"),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'IMPORT_LIMIT_REACHED');
        $this->assertSame(3, ImportBatch::query()->count());
    }

    public function test_oversized_upload_is_rejected(): void
    {
        config(['givingledger.max_csv_size_bytes' => 64]);

        $this->startWorkspace('upload');

        $response = $this->postJson(route('try.imports.store'), [
            'file' => $this->csvUpload(str_repeat('a', 200)),
        ]);

        $response->assertStatus(413);
        $this->assertSame(0, ImportBatch::query()->where('status', ImportBatch::STATUS_MAPPING_REQUIRED)->count());
    }

    public function test_binary_upload_fails_without_completed_batch(): void
    {
        $this->startWorkspace('upload');

        $response = $this->post(route('try.imports.store'), [
            'file' => $this->csvUpload("Gift Date,Gift Value\n2025-01-01,10\0.00\n"),
        ]);

        $batch = ImportBatch::query()->firstOrFail();
        $response->assertRedirect(route('try.imports.show', $batch));

        $batch->refresh();
        $this->assertSame(ImportBatch::STATUS_FAILED, $batch->status);
        $this->assertSame('BINARY_FILE', $batch->processing_error_code);
        $this->assertSame(0, ImportRow::query()->count());
    }

    public function test_guest_cannot_access_another_workspace_import(): void
    {
        $this->startWorkspace('upload');

        $this->post(route('try.imports.store'), [
            'file' => $this->csvUpload("Gift Date,Gift Value\n2025-01-01,25.00\n"),
        ]);

        $batch = ImportBatch::query()->firstOrFail();

        $this->flushSession();
        $this->startWorkspace('upload');

        $this->get(route('try.imports.show', $batch))->assertNotFound();
        $this->getJson(route('try.imports.status', $batch))->assertNotFound();
    }

    public function test_expired_workspace_cannot_open_import(): void
    {
        $this->startWorkspace('upload');

        $this->post(route('try.imports.store'), [
            'file' => $this->csvUpload("Gift Date,Gift Value\n2025-01-01,25.00\n"),
        ]);

        $batch = ImportBatch::query()->firstOrFail();
        $workspace = GuestWorkspace::query()->firstOrFail();
        $workspace->forceFill([
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->get(route('try.imports.show', $batch))->assertStatus(410);
        $this->getJson(route('try.imports.status', $batch))->assertStatus(410);
    }

    public function test_stored_import_file_is_not_under_public_disk(): void
    {
        $this->startWorkspace('upload');

        $this->post(route('try.imports.store'), [
            'file' => $this->csvUpload("Gift Date,Gift Value\n2025-01-01,25.00\n"),
        ]);

        $batch = ImportBatch::query()->firstOrFail();

        Storage::disk('imports')->assertExists($batch->private_file_path);
        $this->assertStringNotContainsString('/public/', $batch->private_file_path);
        $this->assertFalse(is_file(public_path($batch->private_file_path)));
    }

    public function test_status_endpoint_returns_progress_payload(): void
    {
        $this->startWorkspace('sample');
        $this->post(route('try.sample'));
        $batch = ImportBatch::query()->firstOrFail();

        $response = $this->getJson(route('try.imports.status', $batch));

        $response->assertOk();
        $response->assertJsonPath('data.status', ImportBatch::STATUS_MAPPING_REQUIRED);
        $response->assertJsonStructure([
            'data' => [
                'status',
                'processed_rows',
                'total_rows',
                'processing_error_code',
            ],
        ]);
    }

    private function startWorkspace(string $intent): void
    {
        $this->post(route('try.start'), ['intent' => $intent])->assertRedirect(route('try.show'));
        $this->assertNotEmpty(session(WorkspaceService::SESSION_SECRET_KEY));
    }

    private function csvUpload(string $contents, string $name = 'donations.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
