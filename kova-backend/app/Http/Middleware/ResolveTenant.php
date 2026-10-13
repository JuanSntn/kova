<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Membership;
use App\Models\Tenant;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->currentTenant->clear();
        setPermissionsTeamId(null);

        $tenantPublicId = $request->header('X-Tenant-ID');

        abort_if(blank($tenantPublicId), 400, 'The X-Tenant-ID header is required.');
        abort_unless($request->user(), 401, 'Unauthenticated.');
        abort_unless(Str::isUuid($tenantPublicId), 404);

        $tenant = Tenant::query()
            ->where('public_id', $tenantPublicId)
            ->where('status', TenantStatus::Active->value)
            ->firstOrFail();

        $isMember = Membership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $request->user()->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->exists();

        abort_unless($isMember, 404);

        $this->currentTenant->set($tenant);
        setPermissionsTeamId($tenant->getKey());
        $request->user()
            ->unsetRelation('roles')
            ->unsetRelation('permissions');

        try {
            if (! $request->isMethodSafe()
                && ! $request->is('api/billing*')
                && ! $request->is('api/settings/users*')
                && ! $request->is('api/cash/*')
                && ! $request->is('api/inventory/*')
                && ! $request->is('api/pos/modifiers*', 'api/pos/products/*/modifiers')
                // POS confirmation owns its write transaction. A prior read
                // transaction cannot be upgraded safely under SQLite contention.
                // Completed replays must also remain readable after a downgrade.
                && ! $request->is('api/pos/orders', 'api/pos/checkout/quote')) {
                return DB::transaction(function () use ($request, $next, $tenant) {
                    Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                    app(EntitlementService::class)->assertWritable($tenant);

                    return $next($request);
                });
            }

            return $next($request);
        } finally {
            $request->user()
                ->unsetRelation('roles')
                ->unsetRelation('permissions');
            setPermissionsTeamId(null);
            $this->currentTenant->clear();
        }
    }
}
