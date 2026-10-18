<?php

namespace App\Domain\Reconciliation\Services;

use InvalidArgumentException;

class CanonicalReportSchema
{
    /**
     * @return array<string, array{label: string, type: string, required: bool, normalized?: string, values?: list<string>}>
     */
    public function fields(string $reportType): array
    {
        return match ($reportType) {
            'donations' => $this->donationFields(),
            'payouts' => $this->payoutFields(),
            'crm' => $this->crmFields(),
            default => throw new InvalidArgumentException("Unknown report type [{$reportType}]."),
        };
    }

    /**
     * @return list<string>
     */
    public function requiredFields(string $reportType): array
    {
        return array_keys(array_filter(
            $this->fields($reportType),
            fn (array $field): bool => $field['required'],
        ));
    }

    /**
     * @return array<string, array{label: string, type: string, required: bool, normalized?: string, values?: list<string>}>
     */
    private function donationFields(): array
    {
        return [
            'transaction_id' => ['label' => 'Transaction ID', 'type' => 'text', 'required' => true],
            'gifted_at' => ['label' => 'Gift date', 'type' => 'datetime', 'required' => true],
            'gross_amount' => ['label' => 'Gross amount', 'type' => 'positive_money', 'required' => true, 'normalized' => 'gross_amount_minor'],
            'currency' => ['label' => 'Currency', 'type' => 'currency', 'required' => true],
            'status' => ['label' => 'Gift status', 'type' => 'enum', 'required' => true, 'values' => ['pending', 'paid', 'partially_refunded', 'refunded', 'reversed']],
            'refunded_amount' => ['label' => 'Refunded amount', 'type' => 'non_negative_money', 'required' => true, 'normalized' => 'refunded_amount_minor'],
            'campaign_code' => ['label' => 'Campaign code', 'type' => 'text', 'required' => true],
            'donor_ref' => ['label' => 'Donor reference', 'type' => 'text', 'required' => false],
            'donor_email' => ['label' => 'Donor email', 'type' => 'email', 'required' => false],
            'anonymous' => ['label' => 'Anonymous', 'type' => 'boolean', 'required' => true],
            'source_updated_at' => ['label' => 'Source updated at', 'type' => 'datetime', 'required' => false],
        ];
    }

    /**
     * @return array<string, array{label: string, type: string, required: bool, normalized?: string, values?: list<string>}>
     */
    private function payoutFields(): array
    {
        return [
            'component_id' => ['label' => 'Component ID', 'type' => 'text', 'required' => true],
            'payout_id' => ['label' => 'Payout ID', 'type' => 'text', 'required' => true],
            'component_type' => ['label' => 'Component type', 'type' => 'enum', 'required' => true, 'values' => ['gift_credit', 'fee', 'refund_debit', 'reversal_debit', 'adjustment_credit', 'adjustment_debit']],
            'amount_signed' => ['label' => 'Signed amount', 'type' => 'money', 'required' => true, 'normalized' => 'amount_signed_minor'],
            'currency' => ['label' => 'Currency', 'type' => 'currency', 'required' => true],
            'transaction_id' => ['label' => 'Transaction ID', 'type' => 'text', 'required' => false],
            'reported_net_amount' => ['label' => 'Reported payout total', 'type' => 'money', 'required' => true, 'normalized' => 'reported_net_amount_minor'],
            'expected_item_count' => ['label' => 'Expected item count', 'type' => 'positive_integer', 'required' => true],
            'payout_status' => ['label' => 'Payout status', 'type' => 'enum', 'required' => true, 'values' => ['pending', 'sent', 'cancelled']],
            'payout_date' => ['label' => 'Payout date', 'type' => 'date', 'required' => false],
            'source_updated_at' => ['label' => 'Source updated at', 'type' => 'datetime', 'required' => false],
        ];
    }

    /**
     * @return array<string, array{label: string, type: string, required: bool, normalized?: string, values?: list<string>}>
     */
    private function crmFields(): array
    {
        return [
            'crm_record_id' => ['label' => 'CRM record ID', 'type' => 'text', 'required' => true],
            'external_gift_key' => ['label' => 'External gift key', 'type' => 'text', 'required' => false],
            'provider_transaction_id' => ['label' => 'Provider transaction ID', 'type' => 'text', 'required' => false],
            'gross_amount' => ['label' => 'Gross amount', 'type' => 'positive_money', 'required' => true, 'normalized' => 'gross_amount_minor'],
            'refunded_amount' => ['label' => 'Refunded amount', 'type' => 'non_negative_money', 'required' => true, 'normalized' => 'refunded_amount_minor'],
            'currency' => ['label' => 'Currency', 'type' => 'currency', 'required' => true],
            'gifted_at' => ['label' => 'Gift date', 'type' => 'datetime', 'required' => true],
            'gift_status' => ['label' => 'Gift status', 'type' => 'enum', 'required' => true, 'values' => ['pending', 'paid', 'partially_refunded', 'refunded', 'reversed']],
            'campaign_id' => ['label' => 'Campaign ID', 'type' => 'text', 'required' => true],
            'donor_ref' => ['label' => 'Donor reference', 'type' => 'text', 'required' => false],
            'donor_email' => ['label' => 'Donor email', 'type' => 'email', 'required' => false],
        ];
    }
}
