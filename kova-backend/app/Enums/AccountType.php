<?php

namespace App\Enums;

enum AccountType: string
{
    case Email = 'email';
    case TenantLocal = 'tenant_local';
}
