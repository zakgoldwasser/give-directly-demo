<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureNewWorkspacesEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('reconciliation.new_workspaces_enabled', true)) {
            return $next($request);
        }

        $traceId = (string) Str::uuid();
        $error = [
            'code' => 'NEW_WORKSPACES_DISABLED',
            'message' => 'New temporary workspaces are currently unavailable. Please try again later.',
            'trace_id' => $traceId,
        ];
        $response = $request->expectsJson()
            ? response()->json(['error' => $error], 503)
            : response()->view('public.operation-error', ['error' => $error], 503);
        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}
