<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class GuestWorkspace extends Model
{
    use HasUuids;

    public const STATE_ACTIVE = 'active';

    public const STATE_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'session_binding_hash',
        'expires_at',
        'last_activity_at',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        if ($this->state === self::STATE_EXPIRED) {
            return true;
        }

        return $this->expires_at instanceof Carbon
            && $this->expires_at->isPast();
    }

    public function minutesRemaining(): int
    {
        if ($this->isExpired()) {
            return 0;
        }

        $seconds = $this->expires_at->getTimestamp() - now()->getTimestamp();

        return max(0, (int) ceil($seconds / 60));
    }
}
