<?php

namespace Tests\Unit;

use App\Domain\Reconciliation\Services\PayoutReconciler;
use App\Models\CanonicalRecord;
use App\Models\CanonicalRecordVersion;
use App\Models\PayoutReconciliation;
use OverflowException;
use PHPUnit\Framework\TestCase;

class PayoutReconcilerTest extends TestCase
{
    public function test_incomplete_groups_and_unexplained_adjustments_are_blocking_and_flow_to_linked_gifts(): void
    {
        $result = (new PayoutReconciler)->reconcile(collect([
            $this->component('C100', [
                'payout_id' => 'P100',
                'component_type' => 'gift_credit',
                'amount_signed_minor' => 1000,
                'currency' => 'GBP',
                'transaction_id' => 'D100',
                'reported_net_amount_minor' => 1000,
                'expected_item_count' => 2,
                'payout_status' => 'sent',
            ]),
            $this->component('C200', [
                'payout_id' => 'P200',
                'component_type' => 'gift_credit',
                'amount_signed_minor' => 1000,
                'currency' => 'GBP',
                'transaction_id' => 'D200',
                'reported_net_amount_minor' => 1500,
                'expected_item_count' => 2,
                'payout_status' => 'sent',
            ]),
            $this->component('C201', [
                'payout_id' => 'P200',
                'component_type' => 'adjustment_credit',
                'amount_signed_minor' => 500,
                'currency' => 'GBP',
                'transaction_id' => null,
                'reported_net_amount_minor' => 1500,
                'expected_item_count' => 2,
                'payout_status' => 'sent',
            ]),
        ]));

        $payouts = collect($result['payouts'])->keyBy('payout_id');

        $this->assertSame(PayoutReconciliation::STATE_INCOMPLETE, $payouts['P100']['state']);
        $this->assertContains('INCOMPLETE_PAYOUT', $result['gift_evidence']['D100']['issue_codes']);
        $this->assertSame(PayoutReconciliation::STATE_CONFLICTING, $payouts['P200']['state']);
        $this->assertContains('UNEXPLAINED_ADJUSTMENT', $payouts['P200']['issue_codes']);
        $this->assertContains('UNEXPLAINED_ADJUSTMENT', $result['gift_evidence']['D200']['issue_codes']);
    }

    public function test_signed_minor_unit_arithmetic_rejects_integer_overflow(): void
    {
        $this->expectException(OverflowException::class);

        (new PayoutReconciler)->reconcile(collect([
            $this->component('C300', [
                'payout_id' => 'P300',
                'component_type' => 'gift_credit',
                'amount_signed_minor' => PHP_INT_MAX,
                'currency' => 'GBP',
                'transaction_id' => 'D300',
                'reported_net_amount_minor' => 0,
                'expected_item_count' => 2,
                'payout_status' => 'sent',
            ]),
            $this->component('C301', [
                'payout_id' => 'P300',
                'component_type' => 'gift_credit',
                'amount_signed_minor' => 1,
                'currency' => 'GBP',
                'transaction_id' => 'D301',
                'reported_net_amount_minor' => 0,
                'expected_item_count' => 2,
                'payout_status' => 'sent',
            ]),
        ]));
    }

    /** @param array<string, mixed> $data */
    private function component(string $id, array $data): CanonicalRecord
    {
        $record = new CanonicalRecord;
        $record->forceFill(['id' => $id]);
        $version = new CanonicalRecordVersion;
        $version->forceFill(['normalized_data' => $data]);
        $record->setRelation('activeVersion', $version);

        return $record;
    }
}
