<?php

namespace App\Domain\Reconciliation\Services;

class ScopedRecordIdentity
{
    /**
     * @param  list<string>  $parts
     */
    public function hash(array $parts): string
    {
        return hash('sha256', $this->encode($parts));
    }

    public function externalGiftKey(
        string $organizationId,
        string $providerId,
        string $accountId,
        string $transactionId,
    ): string {
        return 'xgk_'.$this->hash([
            $organizationId,
            $providerId,
            $accountId,
            $transactionId,
        ]);
    }

    /**
     * JSON array encoding preserves boundaries between identity parts and is
     * portable across workspaces and future adapter implementations.
     *
     * @param  list<string>  $parts
     */
    private function encode(array $parts): string
    {
        return json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
