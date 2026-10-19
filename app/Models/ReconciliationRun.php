<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReconciliationRun extends Model
{
    use HasUuids;

    public const STATUS_COMPLETED = 'completed';

    /** @var list<string> */
    protected $fillable = [
        'guest_workspace_id',
        'sequence',
        'input_fingerprint',
        'status',
        'rule_version',
        'input_snapshot',
        'decisions',
        'outcome_counts',
        'payout_counts',
        'started_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'input_snapshot' => 'array',
            'sequence' => 'integer',
            'decisions' => 'array',
            'outcome_counts' => 'array',
            'payout_counts' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(GuestWorkspace::class, 'guest_workspace_id');
    }

    public function gifts(): HasMany
    {
        return $this->hasMany(GiftReconciliation::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(PayoutReconciliation::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ReconciliationApproval::class);
    }

    public function exportBatches(): HasMany
    {
        return $this->hasMany(ExportBatch::class);
    }

    public function verificationConfirmation(): HasOne
    {
        return $this->hasOne(ConfirmationBatch::class, 'verification_reconciliation_run_id');
    }
}
