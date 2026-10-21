<?php

use App\Http\Controllers\Guest\ApprovalController;
use App\Http\Controllers\Guest\CampaignReviewController;
use App\Http\Controllers\Guest\ConfirmationController;
use App\Http\Controllers\Guest\ExportController;
use App\Http\Controllers\Guest\ExportDownloadController;
use App\Http\Controllers\Guest\FixtureController;
use App\Http\Controllers\Guest\ImportController;
use App\Http\Controllers\Guest\LandingController;
use App\Http\Controllers\Guest\MappingController;
use App\Http\Controllers\Guest\MatchReviewController;
use App\Http\Controllers\Guest\PageController;
use App\Http\Controllers\Guest\PostImportVerificationController;
use App\Http\Controllers\Guest\RunHistoryController;
use App\Http\Controllers\Guest\WorkspaceController;
use App\Http\Middleware\EnsureGuestWorkspace;
use App\Http\Middleware\EnsureUploadWorkspace;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('landing');

Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');
Route::get('/about', [PageController::class, 'about'])->name('about');

Route::post('/try/start', [WorkspaceController::class, 'start'])
    ->middleware(['new.workspaces', 'throttle:workspace-starts'])
    ->name('try.start');
Route::get('/fixtures/reference/{reportType}', FixtureController::class)->name('reference-fixtures.download');

Route::post('/try/upload', [ImportController::class, 'store'])
    ->middleware(['new.workspaces', 'throttle:workspace-starts', EnsureUploadWorkspace::class, 'throttle:workspace-work', 'bounded.operation:import'])
    ->block()
    ->name('try.upload');

Route::middleware(EnsureGuestWorkspace::class)->group(function () {
    Route::get('/try', [WorkspaceController::class, 'show'])->name('try.show');
    Route::get('/try/history', [RunHistoryController::class, 'index'])->name('try.history.index');
    Route::get('/try/history/runs/{run}', [RunHistoryController::class, 'show'])->name('try.history.runs.show');
    Route::post('/try/reset', [WorkspaceController::class, 'reset'])
        ->middleware(['new.workspaces', 'throttle:workspace-starts'])
        ->name('try.reset');

    Route::post('/try/imports', [ImportController::class, 'store'])
        ->middleware(['throttle:workspace-work', 'bounded.operation:import'])
        ->name('try.imports.store');
    Route::get('/try/imports/{import}', [ImportController::class, 'show'])->name('try.imports.show');
    Route::post('/try/imports/{import}/mapping', MappingController::class)
        ->middleware(['throttle:workspace-work', 'bounded.operation:reconciliation'])
        ->name('try.imports.mapping.store');
    Route::post('/try/review/campaigns', CampaignReviewController::class)
        ->middleware(['throttle:workspace-work', 'bounded.operation:reconciliation'])
        ->name('try.review.campaigns.store');
    Route::post('/try/review/matches', MatchReviewController::class)
        ->middleware(['throttle:workspace-work', 'bounded.operation:reconciliation'])
        ->name('try.review.matches.store');
    Route::post('/try/approvals', ApprovalController::class)
        ->middleware(['throttle:workspace-work', 'bounded.operation:approval'])
        ->name('try.approvals.store');
    Route::post('/try/exports', ExportController::class)
        ->middleware(['throttle:workspace-work', 'bounded.operation:export'])
        ->name('try.exports.store');
    Route::get('/try/exports/{export}/download', ExportDownloadController::class)->name('try.exports.download');
    Route::post('/try/exports/{export}/confirm', ConfirmationController::class)
        ->middleware(['throttle:workspace-work', 'bounded.operation:confirmation'])
        ->name('try.exports.confirm');
    Route::post('/try/confirmations/{confirmation}/verify', PostImportVerificationController::class)
        ->middleware(['throttle:workspace-work', 'bounded.operation:verification'])
        ->name('try.confirmations.verify');
});
