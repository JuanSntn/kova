<?php

namespace App\Models\Scopes;

use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $currentTenant = app(CurrentTenant::class);

        if (! $currentTenant->isResolved()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $currentTenant->id(),
        );
    }
}
