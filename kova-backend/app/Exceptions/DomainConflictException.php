<?php

namespace App\Exceptions;

use RuntimeException;

class DomainConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $domainCode,
    ) {
        parent::__construct($message);
    }
}
