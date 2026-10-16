<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PageController extends Controller
{
    public function privacy(): View
    {
        return view('pages.privacy', [
            'workspaceLifetimeMinutes' => (int) config('givingledger.workspace_lifetime_minutes'),
            'maxCsvSizeMb' => round(((int) config('givingledger.max_csv_size_bytes')) / 1024 / 1024, 1),
            'maxDataRows' => (int) config('givingledger.max_data_rows'),
        ]);
    }

    public function about(): View
    {
        return view('pages.about', [
            'githubUrl' => config('givingledger.github_url'),
        ]);
    }
}
