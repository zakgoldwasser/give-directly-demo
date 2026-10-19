<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\ConfirmationResult;
use App\Models\ReconciliationApproval;

class ConfirmationComparator
{
    /** @var list<string> */
    private const COMPARABLE_FIELDS = [
        'external_gift_key',
        'provider_transaction_id',
        'gross_amount_minor',
        'refunded_amount_minor',
        'currency',
        'gifted_at',
        'gift_status',
        'campaign_id',
    ];

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array{outcome: string, matched_crm_record_ids: list<string>, differences: array<string, array{expected: mixed, observed: mixed}>, expected_values: array<string, mixed>, observed_values: array<string, mixed>|null}
     */
    public function compare(ReconciliationApproval $approval, array $records, bool $completeCoverage): array
    {
        $expected = $this->comparableValues($approval->after_values ?? []);
        $externalKey = $expected['external_gift_key'] ?? null;
        $transactionId = $expected['provider_transaction_id'] ?? $approval->transaction_id;

        $matches = array_values(array_filter(
            $records,
            fn (array $record): bool => is_string($externalKey)
                && $externalKey !== ''
                && ($record['external_gift_key'] ?? null) === $externalKey,
        ));

        if ($matches === []) {
            $matches = array_values(array_filter(
                $records,
                fn (array $record): bool => is_string($transactionId)
                    && $transactionId !== ''
                    && ($record['provider_transaction_id'] ?? null) === $transactionId,
            ));
        }

        $recordIds = array_values(array_map(
            fn (array $record): string => (string) $record['crm_record_id'],
            $matches,
        ));

        if (count($matches) > 1) {
            return $this->result(
                ConfirmationResult::OUTCOME_DUPLICATE,
                $recordIds,
                [],
                $expected,
                null,
            );
        }

        if ($matches === []) {
            return $this->result(
                $completeCoverage ? ConfirmationResult::OUTCOME_MISMATCHES : ConfirmationResult::OUTCOME_CANNOT_VERIFY,
                [],
                $completeCoverage ? ['record' => ['expected' => 'present', 'observed' => 'missing']] : [],
                $expected,
                null,
            );
        }

        $observed = [
            'crm_record_id' => $matches[0]['crm_record_id'],
            ...$this->comparableValues($matches[0]),
        ];
        $differences = [];

        foreach (self::COMPARABLE_FIELDS as $field) {
            if (($expected[$field] ?? null) !== ($observed[$field] ?? null)) {
                $differences[$field] = [
                    'expected' => $expected[$field] ?? null,
                    'observed' => $observed[$field] ?? null,
                ];
            }
        }

        return $this->result(
            $differences === [] ? ConfirmationResult::OUTCOME_MATCHES : ConfirmationResult::OUTCOME_MISMATCHES,
            $recordIds,
            $differences,
            $expected,
            $observed,
        );
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function comparableValues(array $values): array
    {
        return array_intersect_key($values, array_flip(self::COMPARABLE_FIELDS));
    }

    /**
     * @param  list<string>  $recordIds
     * @param  array<string, array{expected: mixed, observed: mixed}>  $differences
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>|null  $observed
     * @return array{outcome: string, matched_crm_record_ids: list<string>, differences: array<string, array{expected: mixed, observed: mixed}>, expected_values: array<string, mixed>, observed_values: array<string, mixed>|null}
     */
    private function result(string $outcome, array $recordIds, array $differences, array $expected, ?array $observed): array
    {
        return [
            'outcome' => $outcome,
            'matched_crm_record_ids' => $recordIds,
            'differences' => $differences,
            'expected_values' => $expected,
            'observed_values' => $observed,
        ];
    }
}
