<?php

namespace App\Http\Middleware;

use App\Services\WorkspaceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUploadWorkspace
{
    public function __construct(private readonly WorkspaceService $workspaces) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Validate before creating a workspace or replacing a sample session.
        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) ceil(config('reconciliation.max_csv_size_bytes') / 1024)],
            'report_type' => ['required', 'in:donations,payouts,crm'],
        ]);

        $workspace = $this->workspaces->resolveActive();

        // Keep subsequent uploads and retries together, separate from sample data.
        if ($workspace === null || $this->workspaces->intent() !== 'uploads') {
            $workspace = $this->workspaces->start('uploads');
        }

        $request->attributes->set('guest_workspace', $workspace);

        return $next($request);
    }
}
