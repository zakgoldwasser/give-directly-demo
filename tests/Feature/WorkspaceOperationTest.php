<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureGuestWorkspace;
use App\Models\GuestWorkspace;
use App\Models\WorkspaceOperation;
use App\Services\Operations\OperationRejectedException;
use App\Services\Operations\WorkspaceOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_completed_request_is_replayed_and_changed_input_is_rejected(): void
    {
        $executions = 0;
        Route::middleware([EnsureGuestWorkspace::class, 'bounded.operation:test'])
            ->post('/_test/bounded-operation', function (Request $request) use (&$executions) {
                $executions++;

                return response()->json([
                    'execution' => $executions,
                    'value' => $request->string('value')->toString(),
                ]);
            });
        $this->post(route('try.start'), ['intent' => 'scenarios']);
        $key = 'request-key-0001';

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/_test/bounded-operation', ['value' => 'same']);
        $traceId = $first->headers->get('X-Trace-Id');

        $first->assertOk()->assertJson(['execution' => 1, 'value' => 'same']);
        $this->assertNotNull($traceId);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/_test/bounded-operation', ['value' => 'same'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertHeader('X-Trace-Id', $traceId)
            ->assertJson(['execution' => 1, 'value' => 'same']);

        $this->assertSame(1, $executions);
        $this->assertDatabaseHas('workspace_operations', [
            'idempotency_key' => $key,
            'status' => WorkspaceOperation::STATUS_COMPLETED,
        ]);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/_test/bounded-operation', ['value' => 'changed'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED')
            ->assertJsonPath('error.trace_id', $traceId);

        $this->assertSame(1, $executions);
    }

    public function test_an_active_lease_blocks_duplicate_work_and_an_expired_lease_can_be_reclaimed(): void
    {
        $workspace = GuestWorkspace::factory()->create();
        $operations = $this->app->make(WorkspaceOperationService::class);
        $claim = $operations->claim($workspace, 'export', 'request-key-0002', hash('sha256', 'input'));
        $firstTraceId = $claim->operation->trace_id;

        try {
            $operations->claim($workspace, 'export', 'request-key-0002', hash('sha256', 'input'));
            $this->fail('The active operation should have rejected duplicate work.');
        } catch (OperationRejectedException $exception) {
            $this->assertSame('OPERATION_IN_PROGRESS', $exception->errorCode);
        }

        $claim->operation->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        $retried = $operations->claim($workspace, 'export', 'request-key-0002', hash('sha256', 'input'));

        $this->assertFalse($retried->replayed);
        $this->assertNotSame($firstTraceId, $retried->operation->trace_id);
        $this->assertSame(WorkspaceOperation::STATUS_RUNNING, $retried->operation->status);
        $this->assertTrue($retried->operation->lease_expires_at->isFuture());

        $pending = WorkspaceOperation::query()->create([
            'guest_workspace_id' => $workspace->id,
            'operation_type' => 'import',
            'idempotency_key' => 'request-key-0005',
            'input_fingerprint' => hash('sha256', 'pending'),
            'status' => WorkspaceOperation::STATUS_PENDING,
            'trace_id' => fake()->uuid(),
        ]);
        $started = $operations->claim($workspace, 'import', 'request-key-0005', hash('sha256', 'pending'));

        $this->assertSame($pending->trace_id, $started->operation->trace_id);
        $this->assertSame(WorkspaceOperation::STATUS_RUNNING, $started->operation->status);
    }

    public function test_the_deadline_returns_a_traceable_error_and_status_reads_expire_crashed_leases(): void
    {
        config(['reconciliation.max_processing_seconds' => 0]);
        Route::middleware([EnsureGuestWorkspace::class, 'bounded.operation:test'])
            ->post('/_test/deadline', fn () => response()->json(['completed' => true]));
        $this->post(route('try.start'), ['intent' => 'scenarios']);

        $response = $this->withHeader('Idempotency-Key', 'request-key-0003')
            ->postJson('/_test/deadline');
        $traceId = $response->headers->get('X-Trace-Id');

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'PROCESSING_DEADLINE_EXCEEDED')
            ->assertJsonPath('error.trace_id', $traceId);
        $this->assertDatabaseHas('workspace_operations', [
            'trace_id' => $traceId,
            'status' => WorkspaceOperation::STATUS_FAILED,
            'error_code' => 'DEADLINE_EXCEEDED',
        ]);

        config(['reconciliation.max_processing_seconds' => 20]);
        $workspace = GuestWorkspace::query()->firstOrFail();
        WorkspaceOperation::query()->delete();
        $crashed = WorkspaceOperation::query()->create([
            'guest_workspace_id' => $workspace->id,
            'operation_type' => 'reconciliation',
            'idempotency_key' => 'request-key-0004',
            'input_fingerprint' => hash('sha256', 'crashed'),
            'status' => WorkspaceOperation::STATUS_RUNNING,
            'trace_id' => fake()->uuid(),
            'lease_expires_at' => now()->subSecond(),
            'started_at' => now()->subMinute(),
        ]);

        $this->get(route('try.show'))
            ->assertOk()
            ->assertSee('operation did not finish', false)
            ->assertSee($crashed->trace_id, false);

        $this->assertDatabaseHas('workspace_operations', [
            'id' => $crashed->id,
            'status' => WorkspaceOperation::STATUS_FAILED,
            'error_code' => 'LEASE_EXPIRED',
        ]);
    }
}
