<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\CsvParserService;
use App\Domain\Reconciliation\Services\ReferenceFixtureCatalog;
use App\Http\Controllers\Controller;
use App\Services\WorkspaceService;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function __invoke(ReferenceFixtureCatalog $fixtures, CsvParserService $parser, WorkspaceService $workspaces): View
    {
        return view('landing.index', [
            'sampleScenarios' => $fixtures->choices(),
            'samplePreviews' => $fixtures->previews($parser),
            'imports' => $workspaces->intent() === 'uploads'
                ? ($workspaces->resolveActive()?->importBatches()->with('mappingProfile')->orderBy('created_at')->orderBy('id')->get()->keyBy('report_type') ?? collect())
                : collect(),
        ]);
    }
}
