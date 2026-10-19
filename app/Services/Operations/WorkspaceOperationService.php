<?php

namespace App\Services\Operations;

use App\Models\GuestWorkspace;
use App\Models\WorkspaceOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceOperationService
{
    public function claim(
        GuestWorkspace $workspace,
        string $operationType,
        string $idempotencyKey,
        string $inputFingerprint,
    ): OperationClaim {
        $now = now();
        WorkspaceOperation::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'guest_workspace_id' => $workspace->id,
            'operation_type' => $operationType,
            'idempotency_key' => $idempotencyKey,
            'input_fingerprint' => $inputFingerprint,
            'status' => WorkspaceOperation::STATUS_PENDING,
            'trace_id' => (string) Str::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::transaction(fn (): OperationClaim => $this->claimLocked(
            $workspace,
            $operationType,
            $idempotencyKey,
            $inputFingerprint,
        ));
    }

    /** @param array<string, mixed>|null $responsePayload */
    public function complete(
        WorkspaceOperation $operation,
        int $responseStatus,
        ?string $responseLocation,
        ?array $responsePayload,
    ): void {
        DB::transaction(function () use ($operation, $responseStatus, $responseLocation, $responsePayload): void {
            $locked = WorkspaceOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkspaceOperation::STATUS_RUNNING
                || ! hash_equals($locked->trace_id, $operation->trace_id)
                || $locked->lease_expires_at?->isPast()) {
                throw new ProcessingDeadlineExceeded($operation->trace_id);
            }

            $locked->forceFill([
                'status' => WorkspaceOperation::STATUS_COMPLETED,
                'lease_expires_at' => null,
                'completed_at' => now(),
                'response_status' => $responseStatus,
                'response_location' => $responseLocation,
                'response_payload' => $responsePayload,
                'error_code' => null,
            ])->save();
        });
    }

    public function fail(WorkspaceOperation $operation, string $errorCode): void
    {
        WorkspaceOperation::query()
            ->whereKey($operation->id)
            ->where('trace_id', $operation->trace_id)
            ->where('status', WorkspaceOperation::STATUS_RUNNING)
            ->update([
                'status' => WorkspaceOperation::STATUS_FAILED,
                'lease_expires_at' => null,
                'completed_at' => now(),
                'error_code' => $errorCode,
                'updated_at' => now(),
            ]);
    }

    public function expireStale(GuestWorkspace $workspace): int
    {
        return WorkspaceOperation::query()
            ->where('guest_workspace_id', $workspace->id)
            ->where('status', WorkspaceOperation::STATUS_RUNNING)
            ->where('lease_expires_at', '<=', now())
            ->update([
                'status' => WorkspaceOperation::STATUS_FAILED,
                'lease_expires_at' => null,
                'completed_at' => now(),
                'error_code' => 'LEASE_EXPIRED',
                'updated_at' => now(),
            ]);
    }

    private function claimLocked(
        GuestWorkspace $workspace,
        string $operationType,
        string $idempotencyKey,
        string $inputFingerprint,
    ): OperationClaim {
        $operation = WorkspaceOperation::query()
            ->where('guest_workspace_id', $workspace->id)
            ->where('operation_type', $operationType)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($operation === null) {
            throw new \RuntimeException('The concurrent operation could not be resolved.');
        }

        if (! hash_equals($operation->input_fingerprint, $inputFingerprint)) {
            throw new OperationRejectedException(
                'IDEMPOTENCY_KEY_REUSED',
                'This idempotency key was already used with different input.',
                $operation,
            );
        }

        if ($operation->status === WorkspaceOperation::STATUS_COMPLETED) {
            return new OperationClaim($operation, true);
        }

        if ($operation->status === WorkspaceOperation::STATUS_RUNNING
            && $operation->lease_expires_at?->isFuture()) {
            throw new OperationRejectedException(
                'OPERATION_IN_PROGRESS',
                'An identical operation is already running. Retry after its lease expires.',
                $operation,
            );
        }

        $operation->forceFill([
            'status' => WorkspaceOperation::STATUS_RUNNING,
            'trace_id' => $operation->status === WorkspaceOperation::STATUS_PENDING
                ? $operation->trace_id
                : (string) Str::uuid(),
            'lease_expires_at' => now()->addSeconds((int) config('reconciliation.operation_lease_seconds', 60)),
            'started_at' => now(),
            'completed_at' => null,
            'response_status' => null,
            'response_location' => null,
            'response_payload' => null,
            'error_code' => null,
        ])->save();

        return new OperationClaim($operation->refresh(), false);
    }
}
