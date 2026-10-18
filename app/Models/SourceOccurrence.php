<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceOccurrence extends Model
{
    use HasUuids;

    public const KIND_PRIMARY = 'primary';

    public const KIND_IDENTICAL_DUPLICATE = 'identical_duplicate';

    public const KIND_STALE_REVISION = 'stale_revision';

    public const KIND_CONFLICT = 'conflict';

    public const KIND_CONFLICT_DUPLICATE = 'conflict_duplicate';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'guest_workspace_id',
        'import_batch_id',
        'import_row_id',
        'mapping_profile_id',
        'canonical_record_id',
        'canonical_record_version_id',
        'occurrence_kind',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(GuestWorkspace::class, 'guest_workspace_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function importRow(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class);
    }

    public function mappingProfile(): BelongsTo
    {
        return $this->belongsTo(MappingProfile::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CanonicalRecord::class, 'canonical_record_id');
    }

    public function recordVersion(): BelongsTo
    {
        return $this->belongsTo(CanonicalRecordVersion::class, 'canonical_record_version_id');
    }
}
