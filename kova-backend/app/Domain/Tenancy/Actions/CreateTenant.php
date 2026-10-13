<?php

namespace App\Domain\Tenancy\Actions;

use App\Enums\RoleName;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Branches\BranchService;
use App\Services\Memberships\MembershipService;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ContractService;
use App\Services\Taxes\TenantTaxProvisioner;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateTenant
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantTaxProvisioner $taxes,
        private readonly BranchService $branches,
        private readonly MembershipService $memberships,
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
                    $price = app(CatalogService::class)->price((string) $plan->code);
                    app(ContractService::class)->create($tenant, $price, true);

                    $this->branches->createInitialBranch($tenant, $branchName, $branchCode);

                    $this->taxes->provisionDefaults($tenant);

                    $this->memberships->createActive($tenant, $owner);

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
