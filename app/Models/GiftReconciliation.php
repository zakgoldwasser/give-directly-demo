<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftReconciliation extends Model
{
    use HasUuids;

    public const OUTCOME_CREATE = 'create';

    public const OUTCOME_UPDATE = 'update';

    public const OUTCOME_UNCHANGED = 'unchanged';

    public const OUTCOME_HELD = 'held';

    public const MATCH_UNMATCHED = 'unmatched';

    public const MATCH_EXACT = 'exact';

    public const MATCH_MANUAL = 'manually_confirmed';

    public const MATCH_AMBIGUOUS = 'ambiguous';

    public const MATCH_REJECTED = 'candidates_rejected';

    public const MATCH_DUPLICATE_TARGET = 'duplicate_target';

    /** @var list<string> */
    protected $fillable = [
        'reconciliation_run_id',
        'canonical_gift_id',
        'matched_crm_record_id',
        'transaction_id',
        'outcome',
        'crm_match_state',
        'payout_state',
        'payout_ids',
        'issue_codes',
        'differences',
        'expected_values',
        'destination_values',
        'candidate_crm_record_ids',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payout_ids' => 'array',
            'issue_codes' => 'array',
            'differences' => 'array',
            'expected_values' => 'array',
            'destination_values' => 'array',
            'candidate_crm_record_ids' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(CanonicalRecord::class, 'canonical_gift_id');
    }

    public function matchedCrmRecord(): BelongsTo
    {
        return $this->belongsTo(CanonicalRecord::class, 'matched_crm_record_id');
    }
}
