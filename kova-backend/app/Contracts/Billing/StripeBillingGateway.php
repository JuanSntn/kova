<?php

namespace App\Contracts\Billing;

use App\Data\Billing\StripeBillingSnapshot;
use App\Models\Tenant;

interface StripeBillingGateway
{
    public function getSnapshot(Tenant $tenant): StripeBillingSnapshot;
}
