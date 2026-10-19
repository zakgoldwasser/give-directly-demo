<?php

namespace App\Console\Commands;

use App\Services\WorkspaceCleanupService;
use Illuminate\Console\Command;

class PurgeExpiredWorkspaces extends Command
{
    protected $signature = 'workspaces:purge-expired';

    protected $description = 'Delete expired guest workspaces and their private files';

    public function handle(WorkspaceCleanupService $cleanup): int
    {
        $result = $cleanup->purgeExpired();

        $this->components->info(
            "Expired workspace cleanup completed: {$result['purged']} purged, {$result['failed']} failed.",
        );

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
