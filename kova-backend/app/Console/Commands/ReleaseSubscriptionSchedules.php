<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Stripe\StripeClient;

class ReleaseSubscriptionSchedules extends Command
{
    protected $signature = 'billing:release-subscription-schedules
        {--dry-run : Mostrar los schedules que se liberarían sin ejecutar la acción}
        {--limit=100 : Cantidad máxima de schedules por página}';

    protected $description = 'Libera los Subscription Schedules activos en Stripe para migrar al enfoque de cambios inmediatos';

    public function handle(StripeClient $stripe): int
    {
        $this->error('Este comando fue retirado: los schedules ahora representan cambios contratados. Usa la API para cancelar un cambio individual.');

        return self::FAILURE;
    }
}
