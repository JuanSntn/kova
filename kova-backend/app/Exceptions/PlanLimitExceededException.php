<?php

namespace App\Exceptions;

class PlanLimitExceededException extends DomainConflictException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'PLAN_LIMIT_EXCEEDED');
    }
}
