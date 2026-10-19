<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutReconciliation extends Model
{
    use HasUuids;

    public const STATE_BALANCED_SENT = 'balanced_sent';

    public const STATE_PENDING = 'pending';

    public const STATE_UNBALANCED = 'unbalanced';

    public const STATE_INCOMPLETE = 'incomplete';

    public const STATE_CONFLICTING = 'conflicting';

    /** @var list<string> */
    protected $fillable = [
        'reconciliation_run_id',
        'payout_id',
        'currency',
        'payout_status',
        'component_count',
        'expected_item_count',
        'component_total_minor',
        'reported_net_amount_minor',
        'difference_minor',
        'state',
        'issue_codes',
        'component_record_ids',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'component_count' => 'integer',
            'expected_item_count' => 'integer',
            'component_total_minor' => 'integer',
            'reported_net_amount_minor' => 'integer',
            'difference_minor' => 'integer',
            'issue_codes' => 'array',
            'component_record_ids' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }
}
