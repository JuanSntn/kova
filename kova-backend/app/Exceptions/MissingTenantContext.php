<?php

namespace App\Exceptions;

use LogicException;

class MissingTenantContext extends LogicException
{
    public static function make(): self
    {
        return new self('A tenant context is required for this operation.');
    }
}
