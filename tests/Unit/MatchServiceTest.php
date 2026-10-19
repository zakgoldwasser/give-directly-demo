<?php

namespace Tests\Unit;

use App\Domain\Reconciliation\Services\MatchService;
use App\Models\CanonicalRecord;
use App\Models\CanonicalRecordVersion;
use App\Models\GiftReconciliation;
use App\Models\PayoutReconciliation;
use PHPUnit\Framework\TestCase;

class MatchServiceTest extends TestCase
{
    public function test_a_conflicting_gift_version_is_retained_as_a_held_result(): void
    {
        $gift = new CanonicalRecord;
        $gift->forceFill([
            'id' => 'gift-conflict',
            'identity' => ['transaction_id' => 'D-CONFLICT'],
            'resolution_status' => CanonicalRecord::RESOLUTION_CONFLICTING,
        ]);
        $gift->setRelation('activeVersion', null);

        $results = (new MatchService)->reconcile(collect([$gift]), collect(), [], []);

        $this->assertCount(1, $results);
        $this->assertSame(GiftReconciliation::OUTCOME_HELD, $results[0]['outcome']);
        $this->assertSame(['CONFLICTING_GIFT_VERSION'], $results[0]['issue_codes']);
    }

    public function test_blank_optional_donor_fields_do_not_create_a_candidate_match(): void
    {
        $gift = $this->record('gift-1', ['transaction_id' => 'D001'], [
            'transaction_id' => 'D001',
            'external_gift_key' => 'gift-key',
            'gross_amount_minor' => 1000,
            'refunded_amount_minor' => 0,
            'currency' => 'GBP',
            'gifted_at' => '2026-09-01T10:00:00+00:00',
            'status' => 'paid',
            'campaign_code' => 'GENERAL',
            'donor_ref' => null,
            'donor_email' => null,
        ]);
        $crm = $this->record('crm-1', ['crm_record_id' => 'CRM001'], [
            'crm_record_id' => 'CRM001',
            'external_gift_key' => null,
            'provider_transaction_id' => null,
            'gross_amount_minor' => 1000,
            'refunded_amount_minor' => 0,
            'currency' => 'GBP',
            'gifted_at' => '2026-09-01T10:00:00+00:00',
            'gift_status' => 'paid',
            'campaign_id' => 'CAMPAIGN-GENERAL',
            'donor_ref' => null,
            'donor_email' => null,
        ]);
        $payoutEvidence = [
            'D001' => [
                'payout_ids' => ['P001'],
                'payout_states' => [PayoutReconciliation::STATE_BALANCED_SENT],
                'issue_codes' => [],
                'gift_credit_minor' => 1000,
                'returned_minor' => 0,
            ],
        ];

        $results = (new MatchService)->reconcile(
            collect([$gift]),
            collect([$crm]),
            $payoutEvidence,
            ['GENERAL' => 'CAMPAIGN-GENERAL'],
        );

        $this->assertSame(GiftReconciliation::MATCH_UNMATCHED, $results[0]['crm_match_state']);
        $this->assertSame(GiftReconciliation::OUTCOME_CREATE, $results[0]['outcome']);
        $this->assertSame([], $results[0]['candidate_crm_record_ids']);
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $normalized
     */
    private function record(string $id, array $identity, array $normalized): CanonicalRecord
    {
        $record = new CanonicalRecord;
        $record->forceFill([
            'id' => $id,
            'identity' => $identity,
            'resolution_status' => CanonicalRecord::RESOLUTION_ACTIVE,
        ]);
        $version = new CanonicalRecordVersion;
        $version->forceFill(['normalized_data' => $normalized]);
        $record->setRelation('activeVersion', $version);

        return $record;
    }
}
