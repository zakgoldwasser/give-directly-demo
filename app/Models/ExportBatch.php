<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportBatch extends Model
{
    use HasUuids;

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_DOWNLOADED = 'downloaded';

    /** @var list<string> */
    protected $fillable = [
        'guest_workspace_id',
        'reconciliation_run_id',
        'fingerprint',
        'status',
        'profile_version',
        'counts',
        'file_checksums',
        'manifest',
        'private_file_path',
        'byte_size',
        'generated_at',
        'downloaded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'file_checksums' => 'array',
            'manifest' => 'array',
            'byte_size' => 'integer',
            'generated_at' => 'immutable_datetime',
            'downloaded_at' => 'immutable_datetime',
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
}
