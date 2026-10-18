<?php

namespace App\Services\ImportExceptions;

use Exception;

class ImportLimitException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
