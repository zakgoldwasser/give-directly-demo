<?php

namespace App\Domain\Reconciliation\Exceptions;

use RuntimeException;

class InvalidMappingException extends RuntimeException
{
    /**
     * @param  list<array{code: string, column: string|null, message: string, severity: string}>  $issues
     */
    public function __construct(
        public readonly array $issues,
        string $message = 'The column mapping is incomplete or invalid.',
    ) {
        parent::__construct($message);
    }
}
