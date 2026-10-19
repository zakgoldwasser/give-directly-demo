<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConfirmationBatch extends Model
{
    use HasUuids;

    public const STATUS_COMPLETED = 'completed';

    public const COVERAGE_COMPLETE = 'complete';

    /** @var list<string> */
    protected $fillable = [
        'guest_workspace_id',
        'export_batch_id',
        'snapshot_filename',
        'snapshot_checksum',
        'snapshot_generated_at',
        'coverage_status',
        'status',
        'counts',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot_generated_at' => 'immutable_datetime',
            'counts' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(GuestWorkspace::class, 'guest_workspace_id');
    }

    public function exportBatch(): BelongsTo
    {
        return $this->belongsTo(ExportBatch::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ConfirmationResult::class);
    }
}
