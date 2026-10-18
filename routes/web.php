<?php

use App\Http\Controllers\Guest\FixtureController;
use App\Http\Controllers\Guest\ImportController;
use App\Http\Controllers\Guest\LandingController;
use App\Http\Controllers\Guest\MappingController;
use App\Http\Controllers\Guest\PageController;
use App\Http\Controllers\Guest\WorkspaceController;
use App\Http\Middleware\EnsureGuestWorkspace;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('landing');

Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');
Route::get('/about', [PageController::class, 'about'])->name('about');

Route::post('/try/start', [WorkspaceController::class, 'start'])->name('try.start');
Route::get('/fixtures/reference/{reportType}', FixtureController::class)->name('reference-fixtures.download');

Route::middleware(EnsureGuestWorkspace::class)->group(function () {
    Route::get('/try', [WorkspaceController::class, 'show'])->name('try.show');
    Route::post('/try/reset', [WorkspaceController::class, 'reset'])->name('try.reset');

    Route::post('/try/imports', [ImportController::class, 'store'])->name('try.imports.store');
    Route::get('/try/imports/{import}', [ImportController::class, 'show'])->name('try.imports.show');
    Route::post('/try/imports/{import}/mapping', MappingController::class)->name('try.imports.mapping.store');
});
