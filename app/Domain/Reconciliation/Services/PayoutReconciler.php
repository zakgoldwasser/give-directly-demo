<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\CanonicalRecord;
use App\Models\PayoutReconciliation;
use Illuminate\Support\Collection;
use OverflowException;

class PayoutReconciler
{
    /**
     * @param  Collection<int, CanonicalRecord>  $componentRecords
     * @return array{payouts: list<array<string, mixed>>, gift_evidence: array<string, array<string, mixed>>}
     */
    public function reconcile(Collection $componentRecords): array
    {
        $groups = [];

        foreach ($componentRecords as $record) {
            if ($record->activeVersion === null) {
                continue;
            }

            $data = $record->activeVersion->normalized_data;
            $groups[(string) $data['payout_id']][] = [
                'record_id' => $record->id,
                'data' => $data,
            ];
        }

        ksort($groups, SORT_STRING);
        $payouts = [];
        $giftEvidence = [];

        foreach ($groups as $payoutId => $components) {
            $currencies = $this->uniqueValues($components, 'currency');
            $statuses = $this->uniqueValues($components, 'payout_status');
            $expectedCounts = $this->uniqueValues($components, 'expected_item_count');
            $reportedTotals = $this->uniqueValues($components, 'reported_net_amount_minor');
            $issues = [];

            if (count($currencies) !== 1 || count($statuses) !== 1 || count($expectedCounts) !== 1 || count($reportedTotals) !== 1) {
                $issues[] = 'INCONSISTENT_PAYOUT_METADATA';
            }

            $currency = count($currencies) === 1 ? (string) $currencies[0] : null;
            $status = count($statuses) === 1 ? (string) $statuses[0] : null;
            $expectedCount = count($expectedCounts) === 1 ? (int) $expectedCounts[0] : null;
            $reportedTotal = count($reportedTotals) === 1 ? (int) $reportedTotals[0] : null;
            $componentTotal = 0;

            foreach ($components as $component) {
                $componentTotal = $this->safeAdd($componentTotal, (int) $component['data']['amount_signed_minor']);

                if (in_array($component['data']['component_type'], ['adjustment_credit', 'adjustment_debit'], true)) {
                    $issues[] = 'UNEXPLAINED_ADJUSTMENT';
                }
            }

            if ($expectedCount === null || $expectedCount !== count($components)) {
                $issues[] = 'INCOMPLETE_PAYOUT';
            }

            $difference = $reportedTotal === null ? null : $componentTotal - $reportedTotal;

            if ($difference !== null && $difference !== 0) {
                $issues[] = 'PAYOUT_DIFFERENCE';
            }

            if ($status === 'pending') {
                $issues[] = 'PENDING_PAYOUT';
            } elseif ($status === 'cancelled') {
                $issues[] = 'CANCELLED_PAYOUT';
            }

            $state = match (true) {
                in_array('INCONSISTENT_PAYOUT_METADATA', $issues, true),
                in_array('UNEXPLAINED_ADJUSTMENT', $issues, true) => PayoutReconciliation::STATE_CONFLICTING,
                in_array('INCOMPLETE_PAYOUT', $issues, true) => PayoutReconciliation::STATE_INCOMPLETE,
                in_array('PAYOUT_DIFFERENCE', $issues, true) => PayoutReconciliation::STATE_UNBALANCED,
                $status !== 'sent' => PayoutReconciliation::STATE_PENDING,
                default => PayoutReconciliation::STATE_BALANCED_SENT,
            };

            $payouts[] = [
                'payout_id' => $payoutId,
                'currency' => $currency,
                'payout_status' => $status,
                'component_count' => count($components),
                'expected_item_count' => $expectedCount,
                'component_total_minor' => $componentTotal,
                'reported_net_amount_minor' => $reportedTotal,
                'difference_minor' => $difference,
                'state' => $state,
                'issue_codes' => array_values(array_unique($issues)),
                'component_record_ids' => array_column($components, 'record_id'),
            ];

            foreach ($components as $component) {
                $data = $component['data'];
                $transactionId = $data['transaction_id'] ?? null;

                if (! is_string($transactionId) || $transactionId === '') {
                    continue;
                }

                $giftEvidence[$transactionId] ??= [
                    'payout_ids' => [],
                    'payout_states' => [],
                    'issue_codes' => [],
                    'gift_credit_minor' => 0,
                    'returned_minor' => 0,
                ];
                $giftEvidence[$transactionId]['payout_ids'][$payoutId] = true;
                $giftEvidence[$transactionId]['payout_states'][$state] = true;

                foreach ($issues as $issue) {
                    $giftEvidence[$transactionId]['issue_codes'][$issue] = true;
                }

                if ($data['component_type'] === 'gift_credit') {
                    $giftEvidence[$transactionId]['gift_credit_minor'] = $this->safeAdd(
                        $giftEvidence[$transactionId]['gift_credit_minor'],
                        (int) $data['amount_signed_minor'],
                    );
                }

                if (in_array($data['component_type'], ['refund_debit', 'reversal_debit'], true)) {
                    $giftEvidence[$transactionId]['returned_minor'] = $this->safeAdd(
                        $giftEvidence[$transactionId]['returned_minor'],
                        abs((int) $data['amount_signed_minor']),
                    );
                }
            }
        }

        foreach ($giftEvidence as &$evidence) {
            $evidence['payout_ids'] = array_keys($evidence['payout_ids']);
            sort($evidence['payout_ids'], SORT_STRING);
            $evidence['payout_states'] = array_keys($evidence['payout_states']);
            sort($evidence['payout_states'], SORT_STRING);
            $evidence['issue_codes'] = array_keys($evidence['issue_codes']);
            sort($evidence['issue_codes'], SORT_STRING);
        }
        unset($evidence);

        return ['payouts' => $payouts, 'gift_evidence' => $giftEvidence];
    }

    /**
     * @param  list<array{record_id: string, data: array<string, mixed>}>  $components
     * @return list<mixed>
     */
    private function uniqueValues(array $components, string $field): array
    {
        $values = [];

        foreach ($components as $component) {
            $value = $component['data'][$field] ?? null;
            $values[json_encode($value, JSON_THROW_ON_ERROR)] = $value;
        }

        return array_values($values);
    }

    private function safeAdd(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new OverflowException('Payout arithmetic exceeded the signed 64-bit integer range.');
        }

        return $left + $right;
    }
}
