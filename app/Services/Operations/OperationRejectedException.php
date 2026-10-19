<?php

namespace App\Services\Operations;

use App\Models\WorkspaceOperation;
use RuntimeException;

class OperationRejectedException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly WorkspaceOperation $operation,
    ) {
        parent::__construct($message);
    }
}
