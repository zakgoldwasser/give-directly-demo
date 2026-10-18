<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanonicalRecord extends Model
{
    use HasUuids;

    public const TYPE_GIFT = 'gift';

    public const TYPE_PAYOUT_COMPONENT = 'payout_component';

    public const TYPE_CRM_RECORD = 'crm_record';

    public const RESOLUTION_ACTIVE = 'active';

    public const RESOLUTION_CONFLICTING = 'conflicting';

    public const RESOLUTION_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'guest_workspace_id',
        'record_type',
        'logical_key_hash',
        'identity',
        'resolution_status',
        'active_version_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['identity' => 'array'];
    }

    /**
     * @return BelongsTo<GuestWorkspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(GuestWorkspace::class, 'guest_workspace_id');
    }

    /**
     * @return HasMany<CanonicalRecordVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CanonicalRecordVersion::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(CanonicalRecordVersion::class, 'active_version_id');
    }
}
