<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\GiftReconciliation;
use App\Models\GuestWorkspace;
use App\Models\ReconciliationApproval;
use App\Models\ReconciliationRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    /**
     * @return array{eligible: list<array<string, mixed>>, eligible_count: int, approved_count: int, complete: bool}
     */
    public function preview(GuestWorkspace $workspace, ?ReconciliationRun $run): array
    {
        if ($run === null) {
            return ['eligible' => [], 'eligible_count' => 0, 'approved_count' => 0, 'complete' => false];
        }

        $approvedIds = $run->approvals()->pluck('gift_reconciliation_id')->all();
        $eligible = $this->eligibleResults($run)
            ->map(fn (GiftReconciliation $result): array => [
                'id' => $result->id,
                'transaction_id' => $result->transaction_id,
                'action' => $result->outcome,
                'before_values' => $this->destinationValues($result->destination_values),
                'after_values' => $result->expected_values,
                'differences' => $result->differences,
                'approved' => in_array($result->id, $approvedIds, true),
            ])
            ->values()
            ->all();
        $approvedCount = collect($eligible)->where('approved', true)->count();

        return [
            'eligible' => $eligible,
            'eligible_count' => count($eligible),
            'approved_count' => $approvedCount,
            'complete' => $eligible !== [] && $approvedCount === count($eligible),
        ];
    }

    public function approveEligible(GuestWorkspace $workspace, string $runId): int
    {
        return DB::transaction(function () use ($workspace, $runId): int {
            GuestWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $run = $this->currentRun($workspace, $runId);
            $eligible = $this->eligibleResults($run);

            if ($eligible->isEmpty()) {
                throw ValidationException::withMessages([
                    'approval' => 'The current run has no eligible creates or updates.',
                ]);
            }

            $now = now();

            foreach ($eligible as $result) {
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

            return $eligible->count();
        });
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
