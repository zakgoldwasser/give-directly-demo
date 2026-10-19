<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\CanonicalRecord;
use App\Models\GiftReconciliation;
use App\Models\GuestWorkspace;
use App\Models\ReconciliationRun;
use App\Models\ReviewDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewDecisionService
{
    public function __construct(
        private readonly ReconciliationService $reconciliation,
    ) {}

    public function saveCampaignMapping(GuestWorkspace $workspace, string $campaignCode, string $campaignId): ReconciliationRun
    {
        return DB::transaction(function () use ($workspace, $campaignCode, $campaignId): ReconciliationRun {
            GuestWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $current = $workspace->reviewDecisions()
                ->where('decision_type', ReviewDecision::TYPE_CAMPAIGN_MAPPING)
                ->where('subject_key', $campaignCode)
                ->orderByDesc('sequence')
                ->first();
            $result = $this->latestRun($workspace)->gifts()
                ->with('gift.activeVersion')
                ->get()
                ->first(function (GiftReconciliation $result) use ($campaignCode): bool {
                    $data = $result->gift?->activeVersion?->normalized_data;

                    return is_array($data)
                        && ($data['campaign_code'] ?? null) === $campaignCode
                        && in_array('UNMAPPED_CAMPAIGN', $result->issue_codes, true);
                });
            $gift = $result?->gift;

            if ($gift === null && $current === null) {
                throw ValidationException::withMessages([
                    'campaign_code' => 'Choose an unmapped campaign from the current review queue.',
                ]);
            }

            $gift ??= CanonicalRecord::query()
                ->where('guest_workspace_id', $workspace->id)
                ->where('record_type', CanonicalRecord::TYPE_GIFT)
                ->where('resolution_status', CanonicalRecord::RESOLUTION_ACTIVE)
                ->with('activeVersion')
                ->get()
                ->first(fn (CanonicalRecord $record): bool => ($record->activeVersion?->normalized_data['campaign_code'] ?? null) === $campaignCode);

            if ($gift === null) {
                throw ValidationException::withMessages([
                    'campaign_code' => 'The source campaign for this earlier decision is no longer available.',
                ]);
            }

            $this->appendLocked(
                $workspace,
                ReviewDecision::TYPE_CAMPAIGN_MAPPING,
                $campaignCode,
                $campaignId,
                [
                    'canonical_gift_id' => $gift->id,
                    'active_version_id' => $gift->active_version_id,
                ],
            );

            return $this->reconciliation->run($workspace, $this->currentDecisions($workspace))
                ?? throw ValidationException::withMessages(['review' => 'All three validated reports are required.']);
        });
    }

    public function confirmCrmMatch(GuestWorkspace $workspace, string $transactionId, string $crmRecordId): ReconciliationRun
    {
        return DB::transaction(function () use ($workspace, $transactionId, $crmRecordId): ReconciliationRun {
            GuestWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $run = $this->latestRun($workspace);
            $result = $run->gifts()
                ->where('transaction_id', $transactionId)
                ->first();
            $current = $workspace->reviewDecisions()
                ->where('decision_type', ReviewDecision::TYPE_CRM_MATCH)
                ->where('subject_key', $transactionId)
                ->orderByDesc('sequence')
                ->first();

            if (
                $result === null
                || $result->candidate_crm_record_ids === []
                || ($result->crm_match_state !== GiftReconciliation::MATCH_AMBIGUOUS && $current === null)
            ) {
                throw ValidationException::withMessages([
                    'transaction_id' => 'Choose an ambiguous gift from the current review queue.',
                ]);
            }

            $candidate = CanonicalRecord::query()
                ->where('guest_workspace_id', $workspace->id)
                ->where('record_type', CanonicalRecord::TYPE_CRM_RECORD)
                ->whereIn('id', $result->candidate_crm_record_ids)
                ->with('activeVersion')
                ->get()
                ->first(fn (CanonicalRecord $record): bool => ($record->activeVersion?->normalized_data['crm_record_id'] ?? null) === $crmRecordId);

            if ($candidate === null) {
                throw ValidationException::withMessages([
                    'crm_record_id' => 'Choose one of the CRM candidates supported by the current evidence.',
                ]);
            }

            $this->appendLocked(
                $workspace,
                ReviewDecision::TYPE_CRM_MATCH,
                $transactionId,
                $crmRecordId,
                [
                    'gift_reconciliation_id' => $result->id,
                    'candidate_canonical_record_id' => $candidate->id,
                    'candidate_active_version_id' => $candidate->active_version_id,
                ],
            );

            return $this->reconciliation->run($workspace, $this->currentDecisions($workspace))
                ?? throw ValidationException::withMessages(['review' => 'All three validated reports are required.']);
        });
    }

    /**
     * @return array{campaigns: list<array<string, mixed>>, matches: list<array<string, mixed>>}
     */
    public function options(GuestWorkspace $workspace, ?ReconciliationRun $run): array
    {
        if ($run === null) {
            return ['campaigns' => [], 'matches' => []];
        }

        $results = $run->gifts()
            ->with('gift.activeVersion')
            ->orderBy('transaction_id')
            ->get();
        $campaigns = [];
        $campaignSuggestions = (array) config('reconciliation.campaign_suggestions', []);
        $candidateIds = $results->flatMap(fn (GiftReconciliation $result): array => $result->candidate_crm_record_ids)->unique()->values();
        $candidates = CanonicalRecord::query()
            ->where('guest_workspace_id', $workspace->id)
            ->whereIn('id', $candidateIds)
            ->with('activeVersion')
            ->get()
            ->keyBy('id');
        $matches = [];

        foreach ($results as $result) {
            $giftData = $result->gift?->activeVersion?->normalized_data;

            if (! is_array($giftData)) {
                continue;
            }

            if (in_array('UNMAPPED_CAMPAIGN', $result->issue_codes, true)) {
                $code = (string) $giftData['campaign_code'];
                $campaigns[$code]['campaign_code'] = $code;
                $campaigns[$code]['suggested_campaign_id'] = $campaignSuggestions[$code] ?? null;
                $campaigns[$code]['transaction_ids'][] = $result->transaction_id;
            }

            if ($result->crm_match_state !== GiftReconciliation::MATCH_AMBIGUOUS) {
                continue;
            }

            $candidateOptions = [];

            foreach ($result->candidate_crm_record_ids as $candidateId) {
                $candidate = $candidates->get($candidateId);
                $data = $candidate?->activeVersion?->normalized_data;

                if (! is_array($data)) {
                    continue;
                }

                $candidateOptions[] = [
                    'crm_record_id' => $data['crm_record_id'],
                    'gross_amount_minor' => $data['gross_amount_minor'],
                    'currency' => $data['currency'],
                    'gifted_at' => $data['gifted_at'],
                    'donor_ref' => $data['donor_ref'] ?? null,
                    'donor_email' => $data['donor_email'] ?? null,
                ];
            }

            if ($candidateOptions !== []) {
                $matches[] = [
                    'transaction_id' => $result->transaction_id,
                    'gross_amount_minor' => $giftData['gross_amount_minor'],
                    'currency' => $giftData['currency'],
                    'gifted_at' => $giftData['gifted_at'],
                    'donor_ref' => $giftData['donor_ref'] ?? null,
                    'donor_email' => $giftData['donor_email'] ?? null,
                    'candidates' => $candidateOptions,
                ];
            }
        }

        return [
            'campaigns' => array_values($campaigns),
            'matches' => $matches,
        ];
    }

    /**
     * @return array{campaign_mappings: array<string, string>, manual_matches: array<string, string>}
     */
    public function currentDecisions(GuestWorkspace $workspace): array
    {
        $decisions = ['campaign_mappings' => [], 'manual_matches' => []];

        foreach ($workspace->reviewDecisions()->orderBy('sequence')->get() as $decision) {
            $key = $decision->decision_type === ReviewDecision::TYPE_CAMPAIGN_MAPPING
                ? 'campaign_mappings'
                : 'manual_matches';
            $decisions[$key][$decision->subject_key] = $decision->selected_value;
        }

        ksort($decisions['campaign_mappings'], SORT_STRING);
        ksort($decisions['manual_matches'], SORT_STRING);

        return $decisions;
    }

    private function latestRun(GuestWorkspace $workspace): ReconciliationRun
    {
        return $workspace->reconciliationRuns()
            ->orderByDesc('sequence')
            ->first()
            ?? throw ValidationException::withMessages(['review' => 'Run reconciliation before reviewing exceptions.']);
    }

    /** @param array<string, mixed> $evidence */
    private function appendLocked(
        GuestWorkspace $workspace,
        string $type,
        string $subject,
        string $selectedValue,
        array $evidence,
    ): ReviewDecision {
        $current = $workspace->reviewDecisions()
            ->where('decision_type', $type)
            ->where('subject_key', $subject)
            ->orderByDesc('sequence')
            ->first();

        if ($current?->selected_value === $selectedValue) {
            return $current;
        }

        return $workspace->reviewDecisions()->create([
            'sequence' => ((int) $workspace->reviewDecisions()->max('sequence')) + 1,
            'decision_type' => $type,
            'subject_key' => $subject,
            'selected_value' => $selectedValue,
            'evidence' => $evidence,
            'actor_type' => 'guest_reviewer',
        ]);
    }
}
