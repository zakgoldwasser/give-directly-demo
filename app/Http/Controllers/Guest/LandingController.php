<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('landing.index', [
            'githubUrl' => config('givingledger.github_url'),
            'workspaceLifetimeMinutes' => (int) config('givingledger.workspace_lifetime_minutes'),
            'maxCsvSizeMb' => round(((int) config('givingledger.max_csv_size_bytes')) / 1024 / 1024, 1),
            'maxDataRows' => (int) config('givingledger.max_data_rows'),
        ]);
    }
}
