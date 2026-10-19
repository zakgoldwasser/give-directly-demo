<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfirmationResult extends Model
{
    use HasUuids;

    public const OUTCOME_MATCHES = 'matches_snapshot';

    public const OUTCOME_MISMATCHES = 'mismatches_snapshot';

    public const OUTCOME_DUPLICATE = 'duplicate_key';

    public const OUTCOME_CANNOT_VERIFY = 'cannot_verify_coverage';

    /** @var list<string> */
    protected $fillable = [
        'confirmation_batch_id',
        'reconciliation_approval_id',
        'transaction_id',
        'expected_action',
        'outcome',
        'matched_crm_record_ids',
        'differences',
        'expected_values',
        'observed_values',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'matched_crm_record_ids' => 'array',
            'differences' => 'array',
            'expected_values' => 'array',
            'observed_values' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ConfirmationBatch::class, 'confirmation_batch_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ReconciliationApproval::class, 'reconciliation_approval_id');
    }
}
