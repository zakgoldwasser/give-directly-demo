<?php

namespace App\Services\Operations;

use App\Models\WorkspaceOperation;

readonly class OperationClaim
{
    public function __construct(
        public WorkspaceOperation $operation,
        public bool $replayed,
    ) {}
}
