<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\CanonicalRecord;
use App\Models\GiftReconciliation;
use App\Models\PayoutReconciliation;
use Illuminate\Support\Collection;

class MatchService
{
    /**
     * @param  Collection<int, CanonicalRecord>  $gifts
     * @param  Collection<int, CanonicalRecord>  $crmRecords
     * @param  array<string, array<string, mixed>>  $payoutEvidence
     * @param  array<string, string>  $campaignMappings
     * @param  array<string, string>  $manualMatches
     * @param  array<string, string>  $rejectedMatches
     * @return list<array<string, mixed>>
     */
    public function reconcile(
        Collection $gifts,
        Collection $crmRecords,
        array $payoutEvidence,
        array $campaignMappings,
        array $manualMatches = [],
        ?string $crmCoverageIssue = null,
        array $rejectedMatches = [],
    ): array {
        $crm = $this->crmIndexes($crmRecords);
        $results = [];

        foreach ($gifts->sortBy(fn (CanonicalRecord $gift): string => (string) $gift->identity['transaction_id']) as $gift) {
            if ($gift->activeVersion === null) {
                $results[] = [
                    'canonical_gift_id' => $gift->id,
                    'matched_crm_record_id' => null,
                    'transaction_id' => (string) $gift->identity['transaction_id'],
                    'outcome' => GiftReconciliation::OUTCOME_HELD,
                    'crm_match_state' => GiftReconciliation::MATCH_UNMATCHED,
                    'payout_state' => 'cannot_evaluate',
                    'payout_ids' => [],
                    'issue_codes' => ['CONFLICTING_GIFT_VERSION'],
                    'differences' => [],
                    'expected_values' => [],
                    'destination_values' => null,
                    'candidate_crm_record_ids' => [],
                ];

                continue;
            }

            $data = $gift->activeVersion->normalized_data;
            $transactionId = (string) $data['transaction_id'];
            $externalKey = (string) $data['external_gift_key'];
            $candidateIds = [];
            $matched = null;
            $matchState = GiftReconciliation::MATCH_UNMATCHED;
            $issues = [];
            $externalMatches = $crm['by_external_key'][$externalKey] ?? [];

            if (count($externalMatches) > 1) {
                $matchState = GiftReconciliation::MATCH_DUPLICATE_TARGET;
                $candidateIds = array_column($externalMatches, 'record_id');
                $issues[] = 'DUPLICATE_CRM_TARGET';
            } elseif (count($externalMatches) === 1) {
                $matched = $externalMatches[0];
                $matchState = GiftReconciliation::MATCH_EXACT;
            } else {
                $transactionMatches = $crm['by_transaction_id'][$transactionId] ?? [];

                if (count($transactionMatches) > 1) {
                    $matchState = GiftReconciliation::MATCH_DUPLICATE_TARGET;
                    $candidateIds = array_column($transactionMatches, 'record_id');
                    $issues[] = 'DUPLICATE_CRM_TARGET';
                } elseif (count($transactionMatches) === 1) {
                    $matched = $transactionMatches[0];
                    $matchState = GiftReconciliation::MATCH_EXACT;
                } else {
                    $candidates = $this->supportingCandidates($data, $crm['all']);
                    $candidateIds = array_column($candidates, 'record_id');
                    $manualCrmId = $manualMatches[$transactionId] ?? null;

                    if (isset($rejectedMatches[$transactionId])) {
                        $matchState = GiftReconciliation::MATCH_REJECTED;
                        $issues[] = 'CRM_CANDIDATES_REJECTED';
                    } elseif (is_string($manualCrmId) && in_array($manualCrmId, array_column($candidates, 'crm_record_id'), true)) {
                        $matched = collect($candidates)->firstWhere('crm_record_id', $manualCrmId);
                        $matchState = GiftReconciliation::MATCH_MANUAL;
                    } elseif ($candidates !== []) {
                        $matchState = GiftReconciliation::MATCH_AMBIGUOUS;
                        $issues[] = 'AMBIGUOUS_CRM_CANDIDATE';
                    } elseif (is_string($manualCrmId)) {
                        $issues[] = 'INVALID_MANUAL_MATCH';
                    }
                }
            }

            if ($matched !== null) {
                if (($matched['data']['currency'] ?? null) !== $data['currency']) {
                    $issues[] = 'INCOMPATIBLE_CURRENCY';
                }

                $destinationTransactionId = $matched['data']['provider_transaction_id'] ?? null;

                if (is_string($destinationTransactionId) && $destinationTransactionId !== '' && $destinationTransactionId !== $transactionId) {
                    $issues[] = 'CONFLICTING_IDENTIFIERS';
                }
            }

            $campaignId = $campaignMappings[(string) $data['campaign_code']] ?? null;

            if ($campaignId === null) {
                $issues[] = 'UNMAPPED_CAMPAIGN';
            }

            [$payoutState, $payoutIds, $payoutIssues] = $this->giftPayoutState($data, $payoutEvidence[$transactionId] ?? null);
            $issues = array_merge($issues, $payoutIssues);
            $expected = $this->expectedValues($data, $campaignId);
            $destination = $matched['data'] ?? null;
            $differences = $destination === null ? [] : $this->differences($expected, $destination);

            if ($matched === null && $matchState === GiftReconciliation::MATCH_UNMATCHED) {
                if ($crmCoverageIssue !== null) {
                    $issues[] = $crmCoverageIssue;
                }

                if ($data['status'] !== 'paid') {
                    $issues[] = 'STATUS_NOT_CREATE_ELIGIBLE';
                }
            }

            $issues = array_values(array_unique($issues));
            $outcome = match (true) {
                $issues !== [] => GiftReconciliation::OUTCOME_HELD,
                $matched === null => GiftReconciliation::OUTCOME_CREATE,
                $differences !== [] => GiftReconciliation::OUTCOME_UPDATE,
                default => GiftReconciliation::OUTCOME_UNCHANGED,
            };

            $results[] = [
                'canonical_gift_id' => $gift->id,
                'matched_crm_record_id' => $matched['record_id'] ?? null,
                'transaction_id' => $transactionId,
                'outcome' => $outcome,
                'crm_match_state' => $matchState,
                'payout_state' => $payoutState,
                'payout_ids' => $payoutIds,
                'issue_codes' => $issues,
                'differences' => $differences,
                'expected_values' => $expected,
                'destination_values' => $destination,
                'candidate_crm_record_ids' => $candidateIds,
            ];
        }

        $this->holdContendedTargets($results);

        return $results;
    }

    /**
     * @param  Collection<int, CanonicalRecord>  $records
     * @return array{all: list<array<string, mixed>>, by_external_key: array<string, list<array<string, mixed>>>, by_transaction_id: array<string, list<array<string, mixed>>>}
     */
    private function crmIndexes(Collection $records): array
    {
        $indexes = ['all' => [], 'by_external_key' => [], 'by_transaction_id' => []];

        foreach ($records as $record) {
            if ($record->activeVersion === null) {
                continue;
            }

            $data = $record->activeVersion->normalized_data;
            $item = [
                'record_id' => $record->id,
                'crm_record_id' => (string) $data['crm_record_id'],
                'data' => $data,
            ];
            $indexes['all'][] = $item;

            foreach (['external_gift_key' => 'by_external_key', 'provider_transaction_id' => 'by_transaction_id'] as $field => $index) {
                $value = $data[$field] ?? null;

                if (is_string($value) && $value !== '') {
                    $indexes[$index][$value][] = $item;
                }
            }
        }

        return $indexes;
    }

    /**
     * @param  array<string, mixed>  $gift
     * @param  list<array<string, mixed>>  $crmRecords
     * @return list<array<string, mixed>>
     */
    private function supportingCandidates(array $gift, array $crmRecords): array
    {
        return array_values(array_filter($crmRecords, function (array $crm) use ($gift): bool {
            $destination = $crm['data'];
            $giftDonorRef = $gift['donor_ref'] ?? null;
            $giftEmail = $gift['donor_email'] ?? null;
            $donorMatches = is_string($giftDonorRef) && $giftDonorRef !== ''
                && $giftDonorRef === ($destination['donor_ref'] ?? null);
            $emailMatches = is_string($giftEmail) && $giftEmail !== ''
                && mb_strtolower($giftEmail) === mb_strtolower((string) ($destination['donor_email'] ?? ''));

            return ($donorMatches || $emailMatches)
                && $gift['gross_amount_minor'] === $destination['gross_amount_minor']
                && $gift['currency'] === $destination['currency']
                && $gift['gifted_at'] === $destination['gifted_at'];
        }));
    }

    /**
     * @param  array<string, mixed>  $gift
     * @param  array<string, mixed>|null  $evidence
     * @return array{0: string, 1: list<string>, 2: list<string>}
     */
    private function giftPayoutState(array $gift, ?array $evidence): array
    {
        if ($evidence === null) {
            return [
                'missing',
                [],
                [$gift['status'] === 'pending' ? 'PENDING_GIFT' : 'INSUFFICIENT_PAYOUT_COVERAGE'],
            ];
        }

        $issues = $evidence['issue_codes'];
        $states = $evidence['payout_states'];

        if (in_array(PayoutReconciliation::STATE_CONFLICTING, $states, true)) {
            $issues[] = 'CONFLICTING_PAYOUT_METADATA';
        }

        if (in_array(PayoutReconciliation::STATE_INCOMPLETE, $states, true)) {
            $issues[] = 'INCOMPLETE_PAYOUT';
        }

        if (in_array(PayoutReconciliation::STATE_UNBALANCED, $states, true)) {
            $issues[] = 'PAYOUT_DIFFERENCE';
        }

        if (in_array(PayoutReconciliation::STATE_PENDING, $states, true)) {
            $issues[] = 'PENDING_PAYOUT';
        }

        if ($evidence['gift_credit_minor'] !== $gift['gross_amount_minor']) {
            $issues[] = 'GIFT_CREDIT_MISMATCH';
        }

        if ($evidence['returned_minor'] !== $gift['refunded_amount_minor']) {
            $issues[] = 'RETURNED_AMOUNT_MISMATCH';
        }

        $state = PayoutReconciliation::STATE_BALANCED_SENT;

        foreach ([
            PayoutReconciliation::STATE_CONFLICTING,
            PayoutReconciliation::STATE_INCOMPLETE,
            PayoutReconciliation::STATE_UNBALANCED,
            PayoutReconciliation::STATE_PENDING,
        ] as $blockingState) {
            if (in_array($blockingState, $states, true)) {
                $state = $blockingState;
                break;
            }
        }

        return [$state, $evidence['payout_ids'], array_values(array_unique($issues))];
    }

    /** @param array<string, mixed> $gift */
    private function expectedValues(array $gift, ?string $campaignId): array
    {
        return [
            'external_gift_key' => $gift['external_gift_key'],
            'provider_transaction_id' => $gift['transaction_id'],
            'gross_amount_minor' => $gift['gross_amount_minor'],
            'refunded_amount_minor' => $gift['refunded_amount_minor'],
            'currency' => $gift['currency'],
            'gifted_at' => $gift['gifted_at'],
            'gift_status' => $gift['status'],
            'campaign_id' => $campaignId,
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $destination
     * @return list<array{field: string, source: mixed, destination: mixed}>
     */
    private function differences(array $expected, array $destination): array
    {
        $differences = [];

        foreach ($expected as $field => $sourceValue) {
            if ($sourceValue === null || $sourceValue === ($destination[$field] ?? null)) {
                continue;
            }

            $differences[] = [
                'field' => $field,
                'source' => $sourceValue,
                'destination' => $destination[$field] ?? null,
            ];
        }

        return $differences;
    }

    /** @param list<array<string, mixed>> $results */
    private function holdContendedTargets(array &$results): void
    {
        $matched = [];

        foreach ($results as $index => $result) {
            if ($result['matched_crm_record_id'] !== null) {
                $matched[$result['matched_crm_record_id']][] = $index;
            }
        }

        foreach ($matched as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            foreach ($indexes as $index) {
                $results[$index]['outcome'] = GiftReconciliation::OUTCOME_HELD;
                $results[$index]['crm_match_state'] = GiftReconciliation::MATCH_AMBIGUOUS;
                $results[$index]['issue_codes'][] = 'CRM_RECORD_CONTENDED';
                $results[$index]['issue_codes'] = array_values(array_unique($results[$index]['issue_codes']));
            }
        }
    }
}
