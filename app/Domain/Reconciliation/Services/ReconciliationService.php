<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\CanonicalRecord;
use App\Models\GiftReconciliation;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Models\PayoutReconciliation;
use App\Models\ReconciliationRun;
use App\Services\Operations\ProcessingDeadline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconciliationService
{
    public function __construct(
        private readonly PayoutReconciler $payouts,
        private readonly MatchService $matches,
        private readonly ProcessingDeadline $deadline,
    ) {}

    /**
     * @param  array{campaign_mappings?: array<string, string>, manual_matches?: array<string, string>, rejected_matches?: array<string, string>, review_revision?: string}  $decisions
     */
    public function run(GuestWorkspace $workspace, array $decisions = []): ?ReconciliationRun
    {
        $this->deadline->checkpoint();

        return DB::transaction(function () use ($workspace, $decisions): ?ReconciliationRun {
            GuestWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $batches = $this->latestValidatedBatches($workspace);

            if ($batches->count() !== 3) {
                return null;
            }

            $records = CanonicalRecord::query()
                ->where('guest_workspace_id', $workspace->id)
                ->whereIn('resolution_status', [
                    CanonicalRecord::RESOLUTION_ACTIVE,
                    CanonicalRecord::RESOLUTION_CONFLICTING,
                ])
                ->with('activeVersion')
                ->get()
                ->groupBy('record_type');
            $normalizedDecisions = $this->normalizeDecisions($decisions);
            $campaignMappings = array_replace(
                (array) config('reconciliation.campaign_mappings', []),
                $normalizedDecisions['campaign_mappings'],
            );
            ksort($campaignMappings, SORT_STRING);

            $inputSnapshot = $this->inputSnapshot($batches, $records, $campaignMappings, $normalizedDecisions['manual_matches']);
            $inputSnapshot['rejected_matches'] = $normalizedDecisions['rejected_matches'];
            $inputSnapshot['review_revision'] = $decisions['review_revision'] ?? null;
            $fingerprint = hash('sha256', json_encode($inputSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $existing = ReconciliationRun::query()
                ->where('guest_workspace_id', $workspace->id)
                ->where('input_fingerprint', $fingerprint)
                ->first();

            if ($existing !== null) {
                return $existing->load(['gifts', 'payouts']);
            }

            $payoutResult = $this->payouts->reconcile(
                $records->get(CanonicalRecord::TYPE_PAYOUT_COMPONENT, collect()),
            );
            $this->deadline->checkpoint();
            $coverageIssue = $this->crmCoverageIssue($batches, $records);
            $giftResults = $this->matches->reconcile(
                $records->get(CanonicalRecord::TYPE_GIFT, collect()),
                $records->get(CanonicalRecord::TYPE_CRM_RECORD, collect()),
                $payoutResult['gift_evidence'],
                $campaignMappings,
                $normalizedDecisions['manual_matches'],
                $coverageIssue,
                $normalizedDecisions['rejected_matches'],
            );
            $this->deadline->checkpoint();
            $now = now();
            $outcomeCounts = $this->counts(
                $giftResults,
                'outcome',
                [
                    GiftReconciliation::OUTCOME_CREATE,
                    GiftReconciliation::OUTCOME_UPDATE,
                    GiftReconciliation::OUTCOME_UNCHANGED,
                    GiftReconciliation::OUTCOME_HELD,
                ],
            );
            $payoutCounts = $this->counts(
                $payoutResult['payouts'],
                'state',
                [
                    PayoutReconciliation::STATE_BALANCED_SENT,
                    PayoutReconciliation::STATE_PENDING,
                    PayoutReconciliation::STATE_UNBALANCED,
                    PayoutReconciliation::STATE_INCOMPLETE,
                    PayoutReconciliation::STATE_CONFLICTING,
                ],
            );
            $run = ReconciliationRun::query()->create([
                'guest_workspace_id' => $workspace->id,
                'sequence' => ((int) ReconciliationRun::query()
                    ->where('guest_workspace_id', $workspace->id)
                    ->max('sequence')) + 1,
                'input_fingerprint' => $fingerprint,
                'status' => ReconciliationRun::STATUS_COMPLETED,
                'rule_version' => (string) config('reconciliation.rule_version', 'demo_v1'),
                'input_snapshot' => $inputSnapshot,
                'decisions' => $normalizedDecisions,
                'outcome_counts' => $outcomeCounts,
                'payout_counts' => $payoutCounts,
                'started_at' => $now,
                'completed_at' => $now,
            ]);

            $giftRows = array_map(fn (array $result): array => [
                'id' => (string) Str::uuid(),
                'reconciliation_run_id' => $run->id,
                'canonical_gift_id' => $result['canonical_gift_id'],
                'matched_crm_record_id' => $result['matched_crm_record_id'],
                'transaction_id' => $result['transaction_id'],
                'outcome' => $result['outcome'],
                'crm_match_state' => $result['crm_match_state'],
                'payout_state' => $result['payout_state'],
                'payout_ids' => json_encode($result['payout_ids'], JSON_THROW_ON_ERROR),
                'issue_codes' => json_encode($result['issue_codes'], JSON_THROW_ON_ERROR),
                'differences' => json_encode($result['differences'], JSON_THROW_ON_ERROR),
                'expected_values' => json_encode($result['expected_values'], JSON_THROW_ON_ERROR),
                'destination_values' => $result['destination_values'] === null
                    ? null
                    : json_encode($result['destination_values'], JSON_THROW_ON_ERROR),
                'candidate_crm_record_ids' => json_encode($result['candidate_crm_record_ids'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ], $giftResults);

            foreach (array_chunk($giftRows, 200) as $chunk) {
                $this->deadline->checkpoint();
                DB::table('gift_reconciliations')->insert($chunk);
            }

            $payoutRows = array_map(fn (array $result): array => [
                'id' => (string) Str::uuid(),
                'reconciliation_run_id' => $run->id,
                ...$result,
                'issue_codes' => json_encode($result['issue_codes'], JSON_THROW_ON_ERROR),
                'component_record_ids' => json_encode($result['component_record_ids'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ], $payoutResult['payouts']);

            foreach (array_chunk($payoutRows, 200) as $chunk) {
                $this->deadline->checkpoint();
                DB::table('payout_reconciliations')->insert($chunk);
            }

            $this->deadline->checkpoint();

            return $run->load(['gifts', 'payouts']);
        });
    }

    public function runCurrentIfReady(GuestWorkspace $workspace): ?ReconciliationRun
    {
        return $this->run($workspace);
    }

    /** @return Collection<string, ImportBatch> */
    private function latestValidatedBatches(GuestWorkspace $workspace): Collection
    {
        return $workspace->importBatches()
            ->where('status', ImportBatch::STATUS_VALIDATED)
            ->whereIn('report_type', ['donations', 'payouts', 'crm'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('report_type')
            ->keyBy('report_type');
    }

    /**
     * @param  array{campaign_mappings?: array<string, string>, manual_matches?: array<string, string>, rejected_matches?: array<string, string>, review_revision?: string}  $decisions
     * @return array{campaign_mappings: array<string, string>, manual_matches: array<string, string>, rejected_matches: array<string, string>}
     */
    private function normalizeDecisions(array $decisions): array
    {
        $normalized = ['campaign_mappings' => [], 'manual_matches' => [], 'rejected_matches' => []];

        foreach (array_keys($normalized) as $key) {
            foreach (($decisions[$key] ?? []) as $source => $destination) {
                if (is_string($source) && $source !== '' && is_string($destination) && $destination !== '') {
                    $normalized[$key][$source] = $destination;
                }
            }

            ksort($normalized[$key], SORT_STRING);
        }

        return $normalized;
    }

    /**
     * @param  Collection<string, ImportBatch>  $batches
     * @param  Collection<string, Collection<int, CanonicalRecord>>  $records
     * @param  array<string, string>  $campaignMappings
     * @param  array<string, string>  $manualMatches
     * @return array<string, mixed>
     */
    private function inputSnapshot(Collection $batches, Collection $records, array $campaignMappings, array $manualMatches): array
    {
        $imports = $batches->map(fn (ImportBatch $batch): array => [
            'id' => $batch->id,
            'report_type' => $batch->report_type,
            'checksum' => $batch->sha256_checksum,
            'mapping_profile_id' => $batch->mapping_profile_id,
        ])->sortBy('report_type')->values()->all();
        $versions = [];

        foreach ($records as $recordGroup) {
            foreach ($recordGroup as $record) {
                $versions[] = [
                    'record_id' => $record->id,
                    'record_type' => $record->record_type,
                    'active_version_id' => $record->active_version_id,
                ];
            }
        }

        usort($versions, fn (array $left, array $right): int => $left['record_id'] <=> $right['record_id']);

        return [
            'rule_version' => (string) config('reconciliation.rule_version', 'demo_v1'),
            'imports' => $imports,
            'record_versions' => $versions,
            'campaign_mappings' => $campaignMappings,
            'manual_matches' => $manualMatches,
        ];
    }

    /**
     * @param  Collection<string, ImportBatch>  $batches
     * @param  Collection<string, Collection<int, CanonicalRecord>>  $records
     */
    private function crmCoverageIssue(Collection $batches, Collection $records): ?string
    {
        $context = $batches->get('crm')?->context ?? [];

        if (($context['complete_snapshot'] ?? false) !== true) {
            return 'INCOMPLETE_CRM_SNAPSHOT';
        }

        if ($records->get(CanonicalRecord::TYPE_CRM_RECORD, collect())
            ->contains(fn (CanonicalRecord $record): bool => $record->resolution_status === CanonicalRecord::RESOLUTION_CONFLICTING)) {
            return 'CONFLICTING_CRM_SNAPSHOT';
        }

        $generatedAt = $context['generated_at'] ?? null;

        if (! is_string($generatedAt) || $generatedAt === '') {
            return 'UNKNOWN_CRM_SNAPSHOT_TIME';
        }

        $latestSourceRevision = null;

        foreach ([CanonicalRecord::TYPE_GIFT, CanonicalRecord::TYPE_PAYOUT_COMPONENT] as $recordType) {
            foreach ($records->get($recordType, collect()) as $record) {
                $revision = $record->activeVersion?->revision_at;

                if ($revision !== null && ($latestSourceRevision === null || $revision->greaterThan($latestSourceRevision))) {
                    $latestSourceRevision = $revision;
                }
            }
        }

        if ($latestSourceRevision !== null && CarbonImmutable::parse($generatedAt)->lt($latestSourceRevision)) {
            return 'STALE_CRM_SNAPSHOT';
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function counts(array $rows, string $field, array $keys): array
    {
        $counts = array_fill_keys($keys, 0);

        foreach ($rows as $row) {
            $counts[$row[$field]] = ($counts[$row[$field]] ?? 0) + 1;
        }

        return $counts;
    }
}
