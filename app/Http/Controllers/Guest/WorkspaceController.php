<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\ReferenceFixtureCatalog;
use App\Domain\Reconciliation\Services\ReviewDecisionService;
use App\Http\Controllers\Controller;
use App\Models\CanonicalRecord;
use App\Models\GuestWorkspace;
use App\Services\ReferenceScenarioService;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceService $workspaces,
        private readonly ReferenceScenarioService $referenceScenario,
        private readonly ReferenceFixtureCatalog $fixtures,
        private readonly ReviewDecisionService $reviews,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'intent' => ['required', 'in:guided,scenarios'],
        ]);

        $workspace = $this->workspaces->start($validated['intent']);

        if ($validated['intent'] === 'guided') {
            $this->referenceScenario->load($workspace);
        }

        return redirect()->route('try.show');
    }

    public function show(Request $request): View
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');
        $reconciliationRun = $workspace->reconciliationRuns()
            ->with(['payouts' => fn ($query) => $query->orderBy('payout_id')])
            ->orderByDesc('sequence')
            ->first();

        return view('public.try', [
            'workspace' => $workspace,
            'intent' => $this->workspaces->intent(),
            'imports' => $workspace->importBatches()->with('mappingProfile')->get()->keyBy('report_type'),
            'canonicalCounts' => $workspace->canonicalRecords()
                ->whereIn('resolution_status', [
                    CanonicalRecord::RESOLUTION_ACTIVE,
                    CanonicalRecord::RESOLUTION_CONFLICTING,
                ])
                ->selectRaw('record_type, count(*) as aggregate')
                ->groupBy('record_type')
                ->pluck('aggregate', 'record_type'),
            'reconciliationRun' => $reconciliationRun,
            'reviewOptions' => $this->reviews->options($workspace, $reconciliationRun),
            'fixtureReports' => $this->fixtures->reports(),
            'maxCsvSizeMb' => round(((int) config('reconciliation.max_csv_size_bytes')) / 1024 / 1024, 1),
            'maxDataRows' => (int) config('reconciliation.max_data_rows'),
            'maxColumns' => (int) config('reconciliation.max_columns'),
            'maxFieldLength' => (int) config('reconciliation.max_field_length'),
            'maxActiveImports' => (int) config('reconciliation.max_active_imports_per_session'),
            'workspaceLifetimeMinutes' => (int) config('reconciliation.workspace_lifetime_minutes'),
            'arbitraryUploadsAllowed' => (bool) config('reconciliation.allow_arbitrary_uploads'),
            'minutesRemaining' => $workspace->minutesRemaining(),
        ]);
    }

    public function reset(): RedirectResponse
    {
        $workspace = $this->workspaces->reset();

        if ($this->workspaces->intent() === 'guided') {
            $this->referenceScenario->load($workspace);
        }

        return redirect()->route('try.show');
    }
}
