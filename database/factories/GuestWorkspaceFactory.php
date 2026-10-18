<?php

namespace Database\Factories;

use App\Models\GuestWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GuestWorkspace>
 */
class GuestWorkspaceFactory extends Factory
{
    protected $model = GuestWorkspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();

        return [
            'session_binding_hash' => hash('sha256', Str::random(64)),
            'expires_at' => $now->copy()->addMinutes(60),
            'last_activity_at' => $now,
            'state' => GuestWorkspace::STATE_ACTIVE,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subMinute(),
            'state' => GuestWorkspace::STATE_EXPIRED,
        ]);
    }
}
