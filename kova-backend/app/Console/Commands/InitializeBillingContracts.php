<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ContractService;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class InitializeBillingContracts extends Command
{
    protected $signature = 'billing:initialize-local-contracts';

    protected $description = 'Convierte contratos locales de desarrollo a versiones; rechaza contratos de cobro existentes';

    public function handle(CurrentTenant $context, CatalogService $catalog, ContractService $contracts): int
    {
        if (app()->isProduction()) {
            $this->error('Solo desarrollo.');

            return self::FAILURE;
        }
        foreach (Tenant::cursor() as $tenant) {
            $context->run($tenant, function () use ($tenant, $catalog, $contracts) {
                DB::transaction(function () use ($tenant, $catalog, $contracts) {
                    Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                    if ($tenant->planSubscriptions()->whereNotNull('current_tenant_id')->exists()) {
                        return;
                    }
                    if ($tenant->subscriptions()->exists() || $tenant->planSubscriptions()->where('provider', 'stripe')->exists()) {
                        throw new \DomainException('Hay contratos externos: no se reinician automáticamente.');
                    }
                    $tenant->planSubscriptions()->whereNull('ended_at')->update([
                        'pending_tenant_id' => null,
                        'status' => 'cancelled',
                        'ended_at' => now(),
                    ]);
                    $contracts->create($tenant, $catalog->price('free'), true);
                });
            });
        }
        $this->info('Contratos locales inicializados.');

        return self::SUCCESS;
    }
}
