<?php

namespace App\Services\Operations;

use RuntimeException;

class ProcessingDeadlineExceeded extends RuntimeException
{
    public function __construct(public readonly string $traceId)
    {
        parent::__construct('The operation exceeded the application processing deadline.');
    }
}
