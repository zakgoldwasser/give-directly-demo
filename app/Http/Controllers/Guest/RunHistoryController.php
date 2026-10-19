<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Models\ReconciliationRun;
use App\Models\ReviewDecision;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class RunHistoryController extends Controller
{
    public function index(Request $request): View
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');
        $runs = $workspace->reconciliationRuns()
            ->withCount(['gifts', 'payouts', 'approvals'])
            ->with([
                'exportBatches' => fn ($query) => $query->orderByDesc('generated_at'),
                'exportBatches.confirmations',
                'verificationConfirmation.exportBatch',
            ])
            ->orderByDesc('sequence')
            ->get();

        return view('public.history.index', [
            'runs' => $runs,
            'imports' => $this->importsForRuns($workspace, $runs),
            'minutesRemaining' => $workspace->minutesRemaining(),
        ]);
    }

    public function show(Request $request, ReconciliationRun $run): View
    {
        /** @var GuestWorkspace $workspace */
        $workspace = $request->attributes->get('guest_workspace');

        abort_unless($run->guest_workspace_id === $workspace->id, 404);

        $run->load([
            'gifts' => fn ($query) => $query->orderBy('transaction_id'),
            'payouts' => fn ($query) => $query->orderBy('payout_id'),
            'approvals' => fn ($query) => $query->orderBy('transaction_id'),
            'exportBatches' => fn ($query) => $query->orderByDesc('generated_at'),
            'exportBatches.confirmations.results' => fn ($query) => $query->orderBy('transaction_id'),
            'exportBatches.confirmations.verificationRun',
            'verificationConfirmation.exportBatch.run',
            'verificationConfirmation.verificationImport',
        ]);

        return view('public.history.show', [
            'run' => $run,
            'imports' => $this->importsForRuns($workspace, collect([$run])),
            'reviewDecisions' => $this->reviewDecisionsForRun($workspace, $run),
            'minutesRemaining' => $workspace->minutesRemaining(),
        ]);
    }

    /**
     * @param  Collection<int, ReconciliationRun>  $runs
     * @return EloquentCollection<string, ImportBatch>
     */
    private function importsForRuns(GuestWorkspace $workspace, Collection $runs): EloquentCollection
    {
        $ids = $runs
            ->flatMap(fn (ReconciliationRun $run): array => collect($run->input_snapshot['imports'] ?? [])->pluck('id')->all())
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        return ImportBatch::query()
            ->where('guest_workspace_id', $workspace->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /** @return EloquentCollection<int, ReviewDecision> */
    private function reviewDecisionsForRun(GuestWorkspace $workspace, ReconciliationRun $run): EloquentCollection
    {
        $snapshot = $run->decisions;

        return $workspace->reviewDecisions()
            ->orderBy('sequence')
            ->get()
            ->filter(function (ReviewDecision $decision) use ($snapshot): bool {
                $group = $decision->decision_type === ReviewDecision::TYPE_CAMPAIGN_MAPPING
                    ? 'campaign_mappings'
                    : 'manual_matches';

                return ($snapshot[$group][$decision->subject_key] ?? null) === $decision->selected_value;
            })
            ->values();
    }
}
