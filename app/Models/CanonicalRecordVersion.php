<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanonicalRecordVersion extends Model
{
    use HasUuids;

    public const STATE_ACTIVE = 'active';

    public const STATE_SUPERSEDED = 'superseded';

    public const STATE_CONFLICTING = 'conflicting';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'canonical_record_id',
        'source_import_batch_id',
        'source_import_row_id',
        'revision_at',
        'revision_source',
        'content_fingerprint',
        'normalized_data',
        'resolution_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision_at' => 'immutable_datetime',
            'normalized_data' => 'array',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CanonicalRecord::class, 'canonical_record_id');
    }

    public function sourceImportBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'source_import_batch_id');
    }

    public function sourceImportRow(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class, 'source_import_row_id');
    }

    /**
     * @return HasMany<SourceOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(SourceOccurrence::class);
    }
}
