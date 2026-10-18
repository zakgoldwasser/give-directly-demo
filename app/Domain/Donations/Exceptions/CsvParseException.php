<?php

namespace App\Domain\Donations\Exceptions;

use Exception;

class CsvParseException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
