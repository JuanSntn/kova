<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Suspended = 'suspended';
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
}
