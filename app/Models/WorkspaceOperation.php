<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceOperation extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'guest_workspace_id',
        'operation_type',
        'idempotency_key',
        'input_fingerprint',
        'status',
        'trace_id',
        'lease_expires_at',
        'started_at',
        'completed_at',
        'response_status',
        'response_location',
        'response_payload',
        'error_code',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'response_status' => 'integer',
            'response_payload' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(GuestWorkspace::class, 'guest_workspace_id');
    }
}
