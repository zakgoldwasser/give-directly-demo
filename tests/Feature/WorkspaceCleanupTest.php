<?php

namespace Tests\Feature;

use App\Models\ExportBatch;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Models\ReconciliationRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class WorkspaceCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        Storage::fake('exports');
    }

    public function test_expired_workspaces_and_private_files_are_purged_without_touching_active_workspaces(): void
    {
        $expired = GuestWorkspace::factory()->create([
            'expires_at' => now()->subMinute(),
            'state' => GuestWorkspace::STATE_ACTIVE,
        ]);
        $active = GuestWorkspace::factory()->create();

        $expiredImport = $this->importFor($expired);
        $activeImport = $this->importFor($active);
        $expiredExport = $this->exportFor($expired);
        $activeExport = $this->exportFor($active);

        Storage::disk('imports')->put($expiredImport->private_file_path, 'expired import');
        Storage::disk('imports')->put($activeImport->private_file_path, 'active import');
        Storage::disk('exports')->put($expiredExport->private_file_path, 'expired export');
        Storage::disk('exports')->put($activeExport->private_file_path, 'active export');

        $this->artisan('workspaces:purge-expired')
            ->expectsOutputToContain('1 purged, 0 failed')
            ->assertSuccessful();

        $this->assertDatabaseMissing('guest_workspaces', ['id' => $expired->id]);
        $this->assertDatabaseMissing('import_batches', ['id' => $expiredImport->id]);
        $this->assertDatabaseMissing('export_batches', ['id' => $expiredExport->id]);
        $this->assertDatabaseHas('guest_workspaces', ['id' => $active->id]);
        $this->assertDatabaseHas('import_batches', ['id' => $activeImport->id]);
        $this->assertDatabaseHas('export_batches', ['id' => $activeExport->id]);

        Storage::disk('imports')->assertMissing($expiredImport->private_file_path);
        Storage::disk('exports')->assertMissing($expiredExport->private_file_path);
        Storage::disk('imports')->assertExists($activeImport->private_file_path);
        Storage::disk('exports')->assertExists($activeExport->private_file_path);

        $this->artisan('workspaces:purge-expired')
            ->expectsOutputToContain('0 purged, 0 failed')
            ->assertSuccessful();
    }

    public function test_cleanup_is_scheduled_every_fifteen_minutes(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'workspaces:purge-expired'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_a_storage_failure_retains_the_workspace_for_a_later_retry(): void
    {
        $workspace = GuestWorkspace::factory()->create([
            'expires_at' => now()->subMinute(),
            'state' => GuestWorkspace::STATE_EXPIRED,
        ]);
        $imports = Mockery::mock(Filesystem::class);
        $imports->shouldReceive('deleteDirectory')
            ->once()
            ->with($workspace->id)
            ->andReturnFalse();
        Storage::shouldReceive('disk')
            ->once()
            ->with('imports')
            ->andReturn($imports);

        $this->artisan('workspaces:purge-expired')
            ->expectsOutputToContain('0 purged, 1 failed')
            ->assertFailed();

        $this->assertDatabaseHas('guest_workspaces', ['id' => $workspace->id]);
    }

    private function importFor(GuestWorkspace $workspace): ImportBatch
    {
        return ImportBatch::factory()->for($workspace, 'workspace')->create([
            'private_file_path' => "{$workspace->id}/source.csv",
            'expires_at' => $workspace->expires_at,
        ]);
    }

    private function exportFor(GuestWorkspace $workspace): ExportBatch
    {
        $run = ReconciliationRun::query()->create([
            'guest_workspace_id' => $workspace->id,
            'sequence' => 1,
            'input_fingerprint' => hash('sha256', $workspace->id),
            'status' => ReconciliationRun::STATUS_COMPLETED,
            'rule_version' => 'test_v1',
            'input_snapshot' => [],
            'decisions' => [],
            'outcome_counts' => [],
            'payout_counts' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        return ExportBatch::query()->create([
            'guest_workspace_id' => $workspace->id,
            'reconciliation_run_id' => $run->id,
            'fingerprint' => hash('sha256', 'export-'.$workspace->id),
            'status' => ExportBatch::STATUS_PREPARED,
            'profile_version' => 'test_v1',
            'counts' => [],
            'file_checksums' => [],
            'manifest' => [],
            'private_file_path' => "{$workspace->id}/export.zip",
            'byte_size' => 14,
            'generated_at' => now(),
        ]);
    }
}
