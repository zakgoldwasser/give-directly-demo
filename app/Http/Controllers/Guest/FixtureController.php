<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\ReferenceFixtureCatalog;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FixtureController extends Controller
{
    public function __construct(
        private readonly ReferenceFixtureCatalog $fixtures,
    ) {}

    public function __invoke(string $reportType): BinaryFileResponse
    {
        abort_unless(in_array($reportType, ['donations', 'payouts', 'crm'], true), 404);

        $report = $this->fixtures->report($reportType);

        return response()->download(
            $this->fixtures->path($reportType),
            $report['filename'],
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
