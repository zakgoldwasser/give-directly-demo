<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\ReferenceFixtureCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FixtureController extends Controller
{
    public function __construct(
        private readonly ReferenceFixtureCatalog $fixtures,
    ) {}

    public function __invoke(Request $request, string $reportType): BinaryFileResponse
    {
        abort_unless(in_array($reportType, ['donations', 'payouts', 'crm'], true), 404);

        $scenarioId = $request->query('scenario_id', 'reference_v1');
        abort_unless(is_string($scenarioId) && array_key_exists($scenarioId, $this->fixtures->choices()), 404);
        $fixtures = $this->fixtures->forScenario($scenarioId);
        $report = $fixtures->report($reportType);

        return response()->download(
            $fixtures->path($reportType),
            $report['filename'],
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
