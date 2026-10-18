<?php

namespace App\Models;

use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_MAPPING_REQUIRED = 'mapping_required';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Statuses that count toward the per-workspace active import cap.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_UPLOADED,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_MAPPING_REQUIRED,
        self::STATUS_COMPLETED,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'guest_workspace_id',
        'original_filename',
        'private_file_path',
        'sha256_checksum',
        'source_partner',
        'mapping_config',
        'parsing_config',
        'status',
        'total_rows',
        'processed_rows',
        'valid_rows',
        'review_rows',
        'rejected_rows',
        'processing_version',
        'processing_error_code',
        'completed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mapping_config' => 'array',
            'parsing_config' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'valid_rows' => 'integer',
            'review_rows' => 'integer',
            'rejected_rows' => 'integer',
            'processing_version' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GuestWorkspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(GuestWorkspace::class, 'guest_workspace_id');
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_MAPPING_REQUIRED,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
        ], true);
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, [
            self::STATUS_UPLOADED,
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
        ], true);
    }
}
