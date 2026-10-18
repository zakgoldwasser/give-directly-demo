<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MappingProfile extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'guest_workspace_id',
        'report_type',
        'adapter_profile',
        'version',
        'schema_version',
        'header_fingerprint',
        'mapping',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_version' => 'integer',
            'mapping' => 'array',
            'settings' => 'array',
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
     * @return HasMany<ImportBatch, $this>
     */
    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }
}
