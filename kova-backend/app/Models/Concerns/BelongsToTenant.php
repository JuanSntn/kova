<?php

namespace App\Models\Concerns;

use App\Exceptions\MissingTenantContext;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

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
