<?php

namespace App\Http\Controllers\Guest;

use App\Domain\Reconciliation\Services\ApprovalService;
use App\Domain\Reconciliation\Services\CsvParserService;
use App\Domain\Reconciliation\Services\ExportService;
use App\Domain\Reconciliation\Services\ReferenceFixtureCatalog;
use App\Domain\Reconciliation\Services\ReviewDecisionService;
use App\Http\Controllers\Controller;
use App\Models\CanonicalRecord;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Models\WorkspaceOperation;
use App\Services\Operations\WorkspaceOperationService;
use App\Services\ReferenceScenarioService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceService $workspaces,
        private readonly ReferenceScenarioService $referenceScenario,
        private readonly ReferenceFixtureCatalog $fixtures,
        private readonly ReviewDecisionService $reviews,
        private readonly ApprovalService $approvals,
        private readonly WorkspaceOperationService $operations,
    ) {}

    public function start(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'intent' => ['required', 'in:guided,scenarios'],
            'scenario_id' => ['sometimes', 'required', 'string', Rule::in(array_keys($this->fixtures->choices()))],
        ]);

        $workspace = $this->workspaces->start($validated['intent']);

        if ($validated['intent'] === 'guided' || isset($validated['scenario_id'])) {
            $this->referenceScenario->load($workspace, $validated['scenario_id'] ?? 'reference_v1');
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => ['redirect' => route('try.show')]]);
        }

        return redirect()->route('try.show');
    }

    public function show(Request $request, CsvParserService $parser): View
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');
        $this->operations->expireStale($workspace);
        $latestOperation = $workspace->operations()->orderByDesc('started_at')->first();
        $recoverableOperation = $latestOperation?->status === WorkspaceOperation::STATUS_FAILED
            && in_array($latestOperation->error_code, ['LEASE_EXPIRED', 'DEADLINE_EXCEEDED', 'PROCESSING_FAILED'], true)
                ? $latestOperation
                : null;
        $reconciliationRun = $workspace->reconciliationRuns()
            ->with(['payouts' => fn ($query) => $query->orderBy('payout_id')])
            ->orderByDesc('sequence')
            ->first();

        $reviewOptions = $this->reviews->options($workspace, $reconciliationRun);
        $imports = $workspace->importBatches()
            ->with('mappingProfile')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy('report_type');
        $exportBatch = $workspace->exportBatches()
            ->where(fn ($query) => $query->where('reconciliation_run_id', $reconciliationRun?->id)
                ->orWhereHas('confirmations', fn ($query) => $query->where('verification_reconciliation_run_id', $reconciliationRun?->id)))
            ->orderByDesc('generated_at')->first();
        $confirmationBatch = $workspace->confirmationBatches()->where('export_batch_id', $exportBatch?->id)
            ->with(['results' => fn ($query) => $query->orderBy('transaction_id')])
            ->orderByDesc('completed_at')
            ->first();
        $fixtures = $this->fixtures->forWorkspace($workspace);
        $scenarioId = $imports->get('donations')?->context['scenario_id'] ?? null;
        $sampleLoaded = $imports->count() === 3 && $imports->every(
            fn (ImportBatch $batch): bool => $batch->status === ImportBatch::STATUS_VALIDATED
                && ($batch->context['scenario_id'] ?? null) === $scenarioId,
        );
        $expectedConfirmationCounts = $fixtures->confirmationSnapshot()['expected'];
        $verificationEligible = $confirmationBatch !== null
            && collect($expectedConfirmationCounts)->every(
                fn (int $count, string $outcome): bool => ($confirmationBatch->counts[$outcome] ?? null) === $count,
            );

        return view('public.try', [
            'workspace' => $workspace,
            'recoverableOperation' => $recoverableOperation,
            'intent' => $this->workspaces->intent(),
            'imports' => $imports,
            'canonicalCounts' => $workspace->canonicalRecords()
                ->whereIn('resolution_status', [
                    CanonicalRecord::RESOLUTION_ACTIVE,
                    CanonicalRecord::RESOLUTION_CONFLICTING,
                ])
                ->selectRaw('record_type, count(*) as aggregate')
                ->groupBy('record_type')
                ->pluck('aggregate', 'record_type'),
            'reconciliationRun' => $reconciliationRun,
            'reviewOptions' => $reviewOptions,
            'approvalPreview' => $this->approvals->preview($workspace, $reconciliationRun),
            'exportBatch' => $exportBatch,
            'confirmationBatch' => $confirmationBatch,
            'verificationEligible' => $verificationEligible,
            'exportProfileVersion' => ExportService::PROFILE_VERSION,
            'crmSnapshotGeneratedAt' => $imports->get('crm')?->context['generated_at'] ?? null,
            'fixtureReports' => $fixtures->reports(),
            'sampleScenarios' => $this->fixtures->choices(),
            'samplePreviews' => $this->fixtures->previews($parser),
            'selectedScenarioId' => $scenarioId,
            'selectedScenario' => $sampleLoaded ? ($this->fixtures->choices()[$scenarioId] ?? null) : null,
            'maxCsvSizeMb' => round(((int) config('reconciliation.max_csv_size_bytes')) / 1024 / 1024, 1),
            'maxDataRows' => (int) config('reconciliation.max_data_rows'),
            'maxColumns' => (int) config('reconciliation.max_columns'),
            'maxFieldLength' => (int) config('reconciliation.max_field_length'),
            'maxActiveImports' => (int) config('reconciliation.max_active_imports_per_session'),
            'workspaceLifetimeMinutes' => (int) config('reconciliation.workspace_lifetime_minutes'),
            'minutesRemaining' => $workspace->minutesRemaining(),
        ]);
    }

    public function reset(): RedirectResponse
    {
        $current = $this->workspaces->resolve();
        $scenarioId = $current?->importBatches()->where('report_type', 'donations')->oldest()->first()?->context['scenario_id'] ?? null;
        $scenarioId = isset($this->fixtures->choices()[$scenarioId]) ? $scenarioId : null;
        $workspace = $this->workspaces->reset();

        if ($this->workspaces->intent() === 'guided' || $scenarioId !== null) {
            $this->referenceScenario->load($workspace, $scenarioId ?? 'reference_v1');
        }

        return redirect()->route('try.show');
    }
}
