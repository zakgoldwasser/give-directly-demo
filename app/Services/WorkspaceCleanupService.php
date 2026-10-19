<?php

namespace App\Services;

use App\Models\GuestWorkspace;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class WorkspaceCleanupService
{
    /**
     * @return array{purged: int, failed: int}
     */
    public function purgeExpired(): array
    {
        $result = [
            'purged' => 0,
            'failed' => 0,
        ];

        GuestWorkspace::query()
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($workspaces) use (&$result): void {
                foreach ($workspaces as $workspace) {
                    try {
                        $this->purge($workspace);
                        $result['purged']++;
                    } catch (Throwable $exception) {
                        $result['failed']++;

                        Log::error('Expired workspace cleanup failed.', [
                            'workspace_id' => $workspace->id,
                            'exception' => $exception,
                        ]);
                    }
                }
            });

        return $result;
    }

    private function purge(GuestWorkspace $workspace): void
    {
        foreach (['imports', 'exports'] as $disk) {
            if (! Storage::disk($disk)->deleteDirectory($workspace->id)) {
                throw new \RuntimeException("The {$disk} directory could not be deleted.");
            }
        }

        $workspace->deleteOrFail();
    }
}
