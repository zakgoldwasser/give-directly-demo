<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewDecision extends Model
{
    use HasUuids;

    public const TYPE_CAMPAIGN_MAPPING = 'campaign_mapping';

    public const TYPE_CRM_MATCH = 'crm_match_confirmation';

    public const TYPE_EXPORT_EXCLUSION = 'export_exclusion';

    /** @var list<string> */
    protected $fillable = [
        'guest_workspace_id',
        'sequence',
        'decision_type',
        'subject_key',
        'selected_value',
        'evidence',
        'actor_type',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'evidence' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(GuestWorkspace::class, 'guest_workspace_id');
    }
}
