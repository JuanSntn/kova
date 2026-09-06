<?php

namespace App\Console\Commands;

use App\Models\Tenant;
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
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if ($dryRun) {
            $this->warn('Modo --dry-run: no se ejecutarán cambios reales.');
        }

        $processed = 0;
        $released = 0;
        $skipped = 0;
        $parameters = [
            'limit' => min($limit, 100),
        ];

        $tenantStripeIds = Tenant::query()
            ->whereNotNull('stripe_id')
            ->pluck('stripe_id')
            ->flip()
            ->all();

        do {
            $page = $stripe->subscriptionSchedules->all($parameters);

            foreach ($page->data as $schedule) {
                $processed++;

                // Filtrar localmente: solo schedules activos
                if ($schedule->status !== 'active') {
                    $this->line("  [SKIP] Schedule {$schedule->id} — status: {$schedule->status}");
                    $skipped++;

                    continue;
                }

                // Verificar que el schedule pertenezca a un tenant de nuestro SaaS
                if (! $this->belongsToOurSaas($schedule, $tenantStripeIds)) {
                    $this->line("  [SKIP] Schedule {$schedule->id} — no pertenece a nuestro SaaS");
                    $skipped++;

                    continue;
                }

                $this->info("  [RELEASE] Schedule {$schedule->id} → subscription {$schedule->subscription}");

                if (! $dryRun) {
                    try {
                        $stripe->subscriptionSchedules->release($schedule->id);
                        $released++;
                    } catch (\Exception $e) {
                        $this->error("  [ERROR] No se pudo liberar {$schedule->id}: {$e->getMessage()}");
                    }
                } else {
                    $released++;
                }
            }

            $last = end($page->data);
            $parameters['starting_after'] = $last ? $last->id : null;
        } while ($page->has_more);

        $this->newLine();
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Schedules procesados', $processed],
                ['Schedules liberados', $released],
                ['Schedules omitidos (no SaaS)', $skipped],
                ['Modo', $dryRun ? '--dry-run (simulación)' : 'REAL'],
            ],
        );

        return Command::SUCCESS;
    }

    /**
     * Verifica si un schedule pertenece a nuestro SaaS.
     */
    private function belongsToOurSaas(\Stripe\SubscriptionSchedule $schedule, array $tenantStripeIds): bool
    {
        // Método 1: verificar por customer ID en nuestra BD
        if (isset($schedule->customer) && isset($tenantStripeIds[$schedule->customer])) {
            return true;
        }

        // Método 2: verificar por metadata
        $metadata = $schedule->metadata?->toArray() ?? [];

        if (isset($metadata['tenant_public_id'])) {
            return Tenant::query()
                ->where('public_id', $metadata['tenant_public_id'])
                ->exists();
        }

        // Método 3: verificar por subscription ID en nuestra BD
        if (isset($schedule->subscription)) {
            return \App\Models\PlanSubscription::query()
                ->where('stripe_subscription_id', $schedule->subscription)
                ->exists();
        }

        return false;
    }
}
