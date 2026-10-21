<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\GiftReconciliation;
use App\Models\GuestWorkspace;
use App\Models\ReconciliationApproval;
use App\Models\ReconciliationRun;
use App\Models\ReviewDecision;
use App\Services\Operations\ProcessingDeadline;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    public function __construct(
        private readonly ProcessingDeadline $deadline,
    ) {}

    /**
     * @return array{eligible: list<array<string, mixed>>, eligible_count: int, approved_count: int, excluded_count: int, open_count: int, locked: bool, complete: bool}
     */
    public function preview(GuestWorkspace $workspace, ?ReconciliationRun $run): array
    {
        if ($run === null) {
            return ['eligible' => [], 'eligible_count' => 0, 'approved_count' => 0, 'complete' => false, 'excluded_count' => 0, 'open_count' => 0, 'locked' => false];
        }

        $exclusions = $this->exclusions($run);
        $approvedIds = $run->approvals()->pluck('gift_reconciliation_id')->all();
        $eligible = $this->eligibleResults($run)
            ->map(fn (GiftReconciliation $result): array => [
                'id' => $result->id,
                'transaction_id' => $result->transaction_id,
                'action' => $result->outcome,
                'before_values' => $this->destinationValues($result->destination_values),
                'after_values' => $result->expected_values,
                'differences' => $result->differences,
                'approved' => ! isset($exclusions[$result->transaction_id]) && in_array($result->id, $approvedIds, true),
                'excluded' => isset($exclusions[$result->transaction_id]),
                'reason' => $exclusions[$result->transaction_id] ?? null,
            ])
            ->values()
            ->all();
        $approvedCount = collect($eligible)->where('approved', true)->count();

        $excludedCount = collect($eligible)->where('excluded', true)->count();

        return [
            'eligible' => $eligible,
            'eligible_count' => count($eligible),
            'approved_count' => $approvedCount,
            'excluded_count' => $excludedCount,
            'open_count' => count($eligible) - $approvedCount - $excludedCount,
            'locked' => $run->exportBatches()->exists(),
            'complete' => $eligible !== [] && $approvedCount + $excludedCount === count($eligible),
        ];
    }

    public function approveEligible(GuestWorkspace $workspace, string $runId, ?string $transactionId = null): int
    {
        $this->deadline->checkpoint();

        return DB::transaction(function () use ($workspace, $runId, $transactionId): int {
            GuestWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $run = $this->currentRun($workspace, $runId);
            $this->assertEditable($run);
            $exclusions = $this->exclusions($run);
            $eligible = $this->eligibleResults($run)
                ->filter(fn (GiftReconciliation $result): bool => ! isset($exclusions[$result->transaction_id])
                    && ($transactionId === null || $result->transaction_id === $transactionId));

            if ($eligible->isEmpty()) {
                throw ValidationException::withMessages([
                    'approval' => 'The current run has no eligible creates or updates.',
                ]);
            }

            $now = now();

            foreach ($eligible as $result) {
                $this->deadline->checkpoint();
                $version = $result->gift?->activeVersion;

                ReconciliationApproval::query()->firstOrCreate(
                    [
                        'reconciliation_run_id' => $run->id,
                        'transaction_id' => $result->transaction_id,
                    ],
                    [
                        'guest_workspace_id' => $workspace->id,
                        'gift_reconciliation_id' => $result->id,
                        'action' => $result->outcome,
                        'revision' => 1,
                        'before_values' => $this->destinationValues($result->destination_values),
                        'after_values' => $result->expected_values,
                        'evidence' => [
                            'run_sequence' => $run->sequence,
                            'run_fingerprint' => $run->input_fingerprint,
                            'canonical_gift_id' => $result->canonical_gift_id,
                            'canonical_gift_version_id' => $version?->id,
                            'source_import_batch_id' => $version?->source_import_batch_id,
                            'source_import_row_id' => $version?->source_import_row_id,
                        ],
                        'actor_type' => 'guest_reviewer',
                        'approved_at' => $now,
                    ],
                );
            }

            $this->deadline->checkpoint();

            return $eligible->count();
        });
    }

    /** @return array<string, string> */
    public function exclusions(ReconciliationRun $run): array
    {
        return ReviewDecision::query()->where('guest_workspace_id', $run->guest_workspace_id)
            ->where('decision_type', ReviewDecision::TYPE_EXPORT_EXCLUSION)
            ->where('evidence->based_on_reconciliation_run_id', $run->id)
            ->orderBy('sequence')->get()->keyBy('subject_key')
            ->filter(fn (ReviewDecision $decision): bool => $decision->selected_value === 'exclude')
            ->map(fn (ReviewDecision $decision): string => $decision->evidence['reason'])
            ->all();
    }

    public function exclude(GuestWorkspace $workspace, string $runId, string $transactionId, string $reason, bool $restore = false): void
    {
        DB::transaction(function () use ($workspace, $runId, $transactionId, $reason, $restore): void {
            GuestWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $run = $this->currentRun($workspace, $runId);
            $this->assertEditable($run);
            if (! $this->eligibleResults($run)->contains('transaction_id', $transactionId)) {
                throw ValidationException::withMessages(['transaction_id' => 'Choose an eligible change from the current run.']);
            }
            $workspace->reviewDecisions()->create([
                'sequence' => ((int) $workspace->reviewDecisions()->max('sequence')) + 1,
                'decision_type' => ReviewDecision::TYPE_EXPORT_EXCLUSION,
                'subject_key' => $transactionId,
                'selected_value' => $restore ? 'restore' : 'exclude',
                'evidence' => ['based_on_reconciliation_run_id' => $run->id, 'reason' => $reason],
                'actor_type' => 'guest_reviewer',
            ]);
        });
    }

    private function assertEditable(ReconciliationRun $run): void
    {
        if ($run->exportBatches()->exists()) {
            throw ValidationException::withMessages(['approval' => 'This run already has an export package. Its review decisions are locked.']);
        }
    }

    private function currentRun(GuestWorkspace $workspace, string $runId): ReconciliationRun
    {
        $run = $workspace->reconciliationRuns()
            ->where('status', ReconciliationRun::STATUS_COMPLETED)
            ->orderByDesc('sequence')
            ->first();

        if ($run === null || $run->id !== $runId) {
            throw ValidationException::withMessages([
                'run_id' => 'The reconciliation changed. Review and approve the current run.',
            ]);
        }

        return $run;
    }

    /** @return Collection<int, GiftReconciliation> */
    private function eligibleResults(ReconciliationRun $run): Collection
    {
        return $run->gifts()
            ->whereIn('outcome', [GiftReconciliation::OUTCOME_CREATE, GiftReconciliation::OUTCOME_UPDATE])
            ->with('gift.activeVersion')
            ->orderBy('transaction_id')
            ->get()
            ->filter(fn (GiftReconciliation $result): bool => $result->issue_codes === [])
            ->values();
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function destinationValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return array_intersect_key($values, array_flip([
            'crm_record_id',
            'external_gift_key',
            'provider_transaction_id',
            'gifted_at',
            'currency',
            'gross_amount_minor',
            'refunded_amount_minor',
            'gift_status',
            'campaign_id',
        ]));
    }
}
