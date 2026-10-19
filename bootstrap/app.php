<?php

use App\Http\Middleware\BoundedWorkspaceOperation;
use App\Http\Middleware\EnsureNewWorkspacesEnabled;
use App\Http\Middleware\RequireConfiguredBearerToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/operations.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'bearer.secret' => RequireConfiguredBearerToken::class,
            'bounded.operation' => BoundedWorkspaceOperation::class,
            'new.workspaces' => EnsureNewWorkspacesEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
