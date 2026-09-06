<?php

namespace App\Domain\Tenancy\Actions;

use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Taxes\TenantTaxProvisioner;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateTenant
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantTaxProvisioner $taxes,
    ) {}

    public function handle(
        User $owner,
        Plan $plan,
        string $name,
        string $slug,
        string $branchName = 'Principal',
        string $branchCode = 'PRIN',
    ): Tenant {
        $previousPermissionsTenantId = getPermissionsTeamId();

        try {
            return DB::transaction(function () use (
                $owner,
                $plan,
                $name,
                $slug,
                $branchName,
                $branchCode,
            ): Tenant {
                $tenant = Tenant::query()->create([
                    'name' => $name,
                    'slug' => Str::slug($slug),
                    'status' => TenantStatus::Active,
                ]);

                return $this->currentTenant->run($tenant, function () use (
                    $tenant,
                    $owner,
                    $plan,
                    $branchName,
                    $branchCode,
                ): Tenant {
                    $periodStartsAt = now();

                    PlanSubscription::query()->create([
                        'plan_id' => $plan->getKey(),
                        'status' => SubscriptionStatus::Active,
                        'monthly_price_snapshot' => $plan->monthly_price,
                        'entitlements_snapshot' => $plan->entitlements(),
                        'starts_at' => $periodStartsAt,
                        'current_period_starts_at' => $periodStartsAt,
                        'current_period_ends_at' => $periodStartsAt->copy()->addMonthNoOverflow(),
                    ]);

                    Branch::query()->create([
                        'code' => Str::upper($branchCode),
                        'name' => $branchName,
                        'timezone' => $tenant->timezone,
                    ]);

                    $this->taxes->provisionDefaults($tenant);

                    Membership::query()->create([
                        'tenant_id' => $tenant->getKey(),
                        'user_id' => $owner->getKey(),
                        'status' => MembershipStatus::Active,
                        'joined_at' => now(),
                    ]);

                    setPermissionsTeamId($tenant->getKey());
                    $owner->unsetRelation('roles');
                    $owner->assignRole(RoleName::Owner);

                    return $tenant;
                });
            });
        } finally {
            $owner->unsetRelation('roles');
            setPermissionsTeamId($previousPermissionsTenantId);
        }
    }
}
