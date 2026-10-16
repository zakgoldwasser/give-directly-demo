<?php

namespace App\Http\Middleware;

use App\Services\WorkspaceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestWorkspace
{
    public function __construct(
        private readonly WorkspaceService $workspaces,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $this->workspaces->resolve();

        if ($workspace === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'WORKSPACE_REQUIRED',
                        'message' => 'Start a temporary workspace from the landing page.',
                    ],
                ], 403);
            }

            return redirect()->route('landing');
        }

        if ($workspace->isExpired()) {
            if ($workspace->state !== \App\Models\GuestWorkspace::STATE_EXPIRED) {
                $workspace->forceFill([
                    'state' => \App\Models\GuestWorkspace::STATE_EXPIRED,
                ])->save();
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'WORKSPACE_EXPIRED',
                        'message' => 'This temporary workspace has expired.',
                    ],
                ], 410);
            }

            return response()
                ->view('public.expired', [
                    'workspace' => $workspace,
                ], 410);
        }

        $workspace->forceFill([
            'last_activity_at' => now(),
        ])->save();

        $request->attributes->set('guest_workspace', $workspace);

        return $next($request);
    }
}
