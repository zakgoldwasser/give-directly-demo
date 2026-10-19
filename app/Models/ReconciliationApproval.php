<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationApproval extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'guest_workspace_id',
        'reconciliation_run_id',
        'gift_reconciliation_id',
        'transaction_id',
        'action',
        'revision',
        'before_values',
        'after_values',
        'evidence',
        'actor_type',
        'approved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'before_values' => 'array',
            'after_values' => 'array',
            'evidence' => 'array',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(GuestWorkspace::class, 'guest_workspace_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(GiftReconciliation::class, 'gift_reconciliation_id');
    }
}
