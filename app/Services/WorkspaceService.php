<?php

namespace App\Services;

use App\Models\GuestWorkspace;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

class WorkspaceService
{
    public const SESSION_SECRET_KEY = 'guest_workspace_secret';

    public const SESSION_INTENT_KEY = 'guest_workspace_intent';

    public function __construct(
        private readonly Session $session,
    ) {}

    /**
     * Create a guest workspace bound to the current session.
     *
     * The raw binding secret is stored only in the session. The database
     * stores a SHA-256 hash and never accepts a workspace id from the client.
     */
    public function start(?string $intent = null): GuestWorkspace
    {
        $secret = Str::random(64);
        $lifetime = (int) config('reconciliation.workspace_lifetime_minutes', 1_440);
        $now = now();

        $workspace = GuestWorkspace::query()->create([
            'session_binding_hash' => $this->hashSecret($secret),
            'expires_at' => $now->copy()->addMinutes($lifetime),
            'last_activity_at' => $now,
            'state' => GuestWorkspace::STATE_ACTIVE,
        ]);

        $this->session->put(self::SESSION_SECRET_KEY, $secret);

        if ($intent !== null) {
            $this->session->put(self::SESSION_INTENT_KEY, $intent);
        }

        return $workspace;
    }

    /**
     * Resolve the workspace for the current session, if any.
     *
     * Request body, query, and URL values are ignored.
     */
    public function resolve(): ?GuestWorkspace
    {
        $secret = $this->session->get(self::SESSION_SECRET_KEY);

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        return GuestWorkspace::query()
            ->where('session_binding_hash', $this->hashSecret($secret))
            ->first();
    }

    public function resolveActive(): ?GuestWorkspace
    {
        $workspace = $this->resolve();

        if ($workspace === null) {
            return null;
        }

        if ($workspace->isExpired()) {
            if ($workspace->state !== GuestWorkspace::STATE_EXPIRED) {
                $workspace->forceFill([
                    'state' => GuestWorkspace::STATE_EXPIRED,
                ])->save();
            }

            return null;
        }

        $workspace->forceFill([
            'last_activity_at' => now(),
        ])->save();

        return $workspace;
    }

    public function currentIsExpired(): bool
    {
        $workspace = $this->resolve();

        return $workspace !== null && $workspace->isExpired();
    }

    public function intent(): ?string
    {
        $intent = $this->session->get(self::SESSION_INTENT_KEY);

        return is_string($intent) ? $intent : null;
    }

    /**
     * Mark the current workspace expired and create a replacement.
     */
    public function reset(): GuestWorkspace
    {
        $current = $this->resolve();
        $intent = $this->intent();

        if ($current !== null && ! $current->isExpired()) {
            $current->forceFill([
                'state' => GuestWorkspace::STATE_EXPIRED,
                'expires_at' => now(),
            ])->save();
        }

        $this->session->forget(self::SESSION_SECRET_KEY);

        return $this->start($intent);
    }

    public function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
