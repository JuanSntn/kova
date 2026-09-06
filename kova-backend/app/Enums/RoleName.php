<?php

namespace App\Enums;

enum RoleName: string
{
    case Owner = 'owner';
    case Cashier = 'cashier';
    case Waiter = 'waiter';
    case Kitchen = 'kitchen';
    case Bar = 'bar';
}
