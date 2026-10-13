<?php

namespace App\Models\Concerns;

use App\Exceptions\MissingTenantContext;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::saving(function (Model $model): void {
            $context = app(CurrentTenant::class);
            if (! $context->isResolved()) {
                throw MissingTenantContext::make();
            }
            $model->tenant_id ??= $context->id();
            if ((int) $model->tenant_id !== $context->id() || ($model->exists && (int) $model->getRawOriginal('tenant_id') !== $context->id())) {
                throw new LogicException('Cannot change or cross a model tenant.');
            }
            foreach ([
                'plan_subscription_id' => 'plan_subscriptions',
                'usage_period_id' => 'usage_periods',
                'subscription_addon_id' => 'subscription_addons',
                'subscription_overage_policy_id' => 'subscription_overage_policies',
                'tenant_fiscal_profile_id' => 'tenant_fiscal_profiles',
                'order_id' => 'orders',
                'ticket_id' => 'tickets',
                'invoice_id' => 'invoices',
            ] as $key => $table) {
                if ($model->getAttribute($key) !== null && ! DB::table($table)->where('id', $model->getAttribute($key))->where('tenant_id', $context->id())->exists()) {
                    throw new LogicException('The related billing record belongs to another tenant.');
                }
            }
        });

        static::creating(function (Model $model): void {
            $currentTenant = app(CurrentTenant::class);

            if (! $currentTenant->isResolved()) {
                throw MissingTenantContext::make();
            }

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $currentTenant->id());

                return;
            }

            if ((int) $model->getAttribute('tenant_id') !== $currentTenant->id()) {
                throw new LogicException('The model tenant does not match the current tenant context.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
