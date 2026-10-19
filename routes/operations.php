<?php

use App\Http\Controllers\Operations\ReadinessController;
use App\Http\Controllers\Operations\WorkspaceCleanupController;
use Illuminate\Support\Facades\Route;

Route::get('/ready', ReadinessController::class)
    ->middleware('bearer.secret:reconciliation.readiness_secret')
    ->name('operations.ready');

Route::get('/internal/cron/workspaces/purge', WorkspaceCleanupController::class)
    ->middleware('bearer.secret:reconciliation.cron_secret')
    ->name('operations.workspaces.purge');
