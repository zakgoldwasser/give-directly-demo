<?php

namespace Tests\Unit;

use App\Domain\Reconciliation\Services\ConfirmationComparator;
use App\Models\ConfirmationResult;
use App\Models\ReconciliationApproval;
use Tests\TestCase;

class ConfirmationComparatorTest extends TestCase
{
    public function test_it_reports_matching_and_field_mismatch_outcomes(): void
    {
        $approval = $this->approval();
        $record = $this->record();
        $comparator = app(ConfirmationComparator::class);

        $matching = $comparator->compare($approval, [$record], true);

        $this->assertSame(ConfirmationResult::OUTCOME_MATCHES, $matching['outcome']);
        $this->assertSame(['CRM-1'], $matching['matched_crm_record_ids']);
        $this->assertSame([], $matching['differences']);
        $this->assertArrayNotHasKey('donor_email', $matching['observed_values']);

        $record['campaign_id'] = 'CAMPAIGN-OTHER';
        $mismatching = $comparator->compare($approval, [$record], true);

        $this->assertSame(ConfirmationResult::OUTCOME_MISMATCHES, $mismatching['outcome']);
        $this->assertSame([
            'expected' => 'CAMPAIGN-1',
            'observed' => 'CAMPAIGN-OTHER',
        ], $mismatching['differences']['campaign_id']);
    }

    public function test_it_reports_duplicate_missing_and_unverifiable_records(): void
    {
        $approval = $this->approval();
        $record = $this->record();
        $duplicate = $record;
        $duplicate['crm_record_id'] = 'CRM-2';
        $comparator = app(ConfirmationComparator::class);

        $duplicateResult = $comparator->compare($approval, [$record, $duplicate], true);
        $missingResult = $comparator->compare($approval, [], true);
        $unverifiableResult = $comparator->compare($approval, [], false);

        $this->assertSame(ConfirmationResult::OUTCOME_DUPLICATE, $duplicateResult['outcome']);
        $this->assertSame(['CRM-1', 'CRM-2'], $duplicateResult['matched_crm_record_ids']);
        $this->assertSame(ConfirmationResult::OUTCOME_MISMATCHES, $missingResult['outcome']);
        $this->assertSame('missing', $missingResult['differences']['record']['observed']);
        $this->assertSame(ConfirmationResult::OUTCOME_CANNOT_VERIFY, $unverifiableResult['outcome']);
        $this->assertSame([], $unverifiableResult['differences']);
    }

    public function test_it_falls_back_to_the_provider_transaction_id_when_the_external_key_is_absent(): void
    {
        $approval = $this->approval();
        $values = $approval->after_values;
        $values['external_gift_key'] = null;
        $approval->after_values = $values;
        $record = $this->record();
        $record['external_gift_key'] = null;

        $result = app(ConfirmationComparator::class)->compare($approval, [$record], true);

        $this->assertSame(ConfirmationResult::OUTCOME_MATCHES, $result['outcome']);
    }

    private function approval(): ReconciliationApproval
    {
        return new ReconciliationApproval([
            'transaction_id' => 'D100',
            'action' => 'update',
            'after_values' => [
                'external_gift_key' => 'xgk_100',
                'provider_transaction_id' => 'D100',
                'gross_amount_minor' => 10000,
                'refunded_amount_minor' => 0,
                'currency' => 'GBP',
                'gifted_at' => '2026-09-01T10:00:00+01:00',
                'gift_status' => 'paid',
                'campaign_id' => 'CAMPAIGN-1',
                'donor_email' => 'excluded@example.test',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return [
            'crm_record_id' => 'CRM-1',
            'external_gift_key' => 'xgk_100',
            'provider_transaction_id' => 'D100',
            'gross_amount_minor' => 10000,
            'refunded_amount_minor' => 0,
            'currency' => 'GBP',
            'gifted_at' => '2026-09-01T10:00:00+01:00',
            'gift_status' => 'paid',
            'campaign_id' => 'CAMPAIGN-1',
            'donor_email' => 'excluded@example.test',
        ];
    }
}
