<?php

namespace App\Console\Commands;

use App\Models\BillingOutbox;
use App\Models\BillingWebhookEvent;
use App\Models\Tenant;
use App\Services\Subscriptions\BillingAccessPolicy;
use App\Services\Subscriptions\BillingProcessor;
use App\Services\Subscriptions\BillingReconciler;
use App\Services\Subscriptions\StripeWebhookProcessor;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

final class ProcessBilling extends Command
{
    protected $signature = 'billing:process {--reconcile : Consultar el estado remoto y recuperar webhooks fallidos} {--tenant= : UUID publico del tenant a procesar}';

    protected $description = 'Procesa cobros pendientes y reconcilia contratos sin duplicar operaciones';

    public function handle(
        CurrentTenant $context,
        BillingProcessor $processor,
        BillingReconciler $reconciler,
        StripeWebhookProcessor $webhooks,
        BillingAccessPolicy $billingAccess,
    ): int {
        $failed = false;

        if ($this->option('reconcile')) {
            foreach (
                BillingWebhookEvent::query()
                    ->where('status', 'failed')
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', 'processing')
                            ->where(function ($lease): void {
                                $lease
                                    ->whereNull('locked_at')
                                    ->orWhere(
                                        'locked_at',
                                        '<',
                                        now()->subMinutes(10),
                                    );
                            });
                    })
                    ->cursor() as $event
            ) {
                try {
                    $webhooks->process($event->payload);
                } catch (\Throwable $e) {
                    $failed = true;
                    report($e);
                }
            }
        }

        $tenants = Tenant::query();

        if ($this->option('tenant')) {
            $tenants->where('public_id', $this->option('tenant'));
        }

        foreach ($tenants->cursor() as $tenant) {
            $context->run($tenant, function () use ($tenant, $processor, $reconciler, $billingAccess, &$failed) {
                if ($this->option('reconcile') && ! $reconciler->run($tenant)) {
                    $failed = true;

                    return;
                }

                foreach (BillingOutbox::where(fn ($q) => $q->where(fn ($p) => $p->where('status', 'pending')->where('available_at', '<=', now()))->orWhere(fn ($p) => $p->where('status', 'processing')->where('locked_at', '<', now()->subMinutes(10))))->orderBy('id')->limit(100)->get() as $op) {
                    try {
                        $processor->run($op);
                    } catch (\Throwable $e) {
                        $failed = true;
                        $this->error($op->id.': '.$e->getMessage());
                    }
                }
                $billingAccess->applyExpiredGrace($tenant);
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
