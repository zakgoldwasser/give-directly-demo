<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\WorkspaceCleanupService;
use Illuminate\Cache\Lock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class WorkspaceCleanupController extends Controller
{
    public function __invoke(WorkspaceCleanupService $cleanup): JsonResponse
    {
        /** @var Lock $lock */
        $lock = Cache::lock(
            'workspaces:purge-expired',
            max(1, (int) config('reconciliation.cleanup_lock_seconds', 120)),
        );

        if (! $lock->get()) {
            return response()->json([
                'status' => 'skipped',
                'reason' => 'cleanup_already_running',
            ]);
        }

        try {
            $result = $cleanup->purgeExpired();

            return response()->json([
                'status' => $result['failed'] === 0 ? 'completed' : 'failed',
                ...$result,
            ], $result['failed'] === 0 ? 200 : 500);
        } finally {
            $lock->release();
        }
    }
}
