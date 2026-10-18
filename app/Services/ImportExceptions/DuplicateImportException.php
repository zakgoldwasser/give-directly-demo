<?php

namespace App\Services\ImportExceptions;

use App\Models\ImportBatch;
use Exception;

class DuplicateImportException extends Exception
{
    public function __construct(
        public readonly ImportBatch $existingBatch,
        string $message = 'This file was already uploaded in the current workspace.',
    ) {
        parent::__construct($message);
    }
}
