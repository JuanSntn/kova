<?php

namespace Tests\Feature\Branches;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\MembershipStatus;
use App\Enums\RoleName;
use App\Models\Feature;
use App\Models\AddonPrice;
use App\Models\BillingPriceMapping;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Services\Subscriptions\CatalogService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_capacity_is_reported_and_a_forged_create_request_is_rejected_by_backend(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        $this->tenantRequest($owner, $tenant)
            ->getJson('/api/branches')
            ->assertOk()
            ->assertJsonPath('meta.capacity.limit', 1)
            ->assertJsonPath('meta.capacity.used', 1)
            ->assertJsonPath('meta.capacity.remaining', 0)
            ->assertJsonPath('meta.capacity.unlimited', false)
            ->assertJsonPath('meta.capacity.canCreate', false)
            ->assertJsonPath('meta.capacity.reason', 'limit_reached')
            ->assertJsonPath('meta.additionalBranch.available', false)
            ->assertJsonPath('meta.additionalBranch.can_purchase', false);

        $this->tenantRequest($owner, $tenant)
            ->postJson('/api/branches', $this->payload())
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED')
            ->assertJsonPath(
                'message',
                'Tu plan actual no permite agregar más sucursales. Sube de plan o contrata una sucursal adicional para continuar.',
            );

        $this->assertSame(1, $tenant->branches()->withoutGlobalScopes()->count());
    }

    public function test_additional_branch_checkout_accepts_no_client_selected_price_or_scope(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        $this->tenantRequest($owner, $tenant)
            ->postJson('/api/branches/extra/purchase', [
                'tenant_id' => $tenant->public_id,
                'branch_id' => $tenant->branches()->withoutGlobalScopes()->value('public_id'),
                'offer_id' => fake()->uuid(),
                'price_id' => fake()->uuid(),
                'quantity' => 99,
                'amount' => 1,
                'currency' => 'usd',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tenant_id',
                'branch_id',
                'offer_id',
                'price_id',
                'quantity',
                'amount',
                'currency',
            ]);
    }

    public function test_additional_branch_cancellation_rejects_client_selected_addon_or_quantity(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        $this->tenantRequest($owner, $tenant)
            ->postJson('/api/branches/extra/cancel', [
                'tenant_id' => $tenant->public_id,
                'addon_id' => fake()->uuid(),
                'offer_id' => fake()->uuid(),
                'price_id' => fake()->uuid(),
                'quantity' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tenant_id',
                'addon_id',
                'offer_id',
                'price_id',
                'quantity',
            ]);
    }

    public function test_business_plan_exposes_only_its_server_resolved_branch_addon(): void
    {
        [$owner, $tenant] = $this->provisionOwner();

        $business = app(CatalogService::class)->price('business');
        $addon = AddonPrice::query()
            ->where('interval', 'month')
            ->whereHas('version.addon', fn ($query) => $query->where('code', 'extra_branch'))
            ->firstOrFail();

        BillingPriceMapping::query()->create([
            'addon_price_id' => $addon->id,
            'stripe_price_id' => 'price_branch_addon_test',
            'livemode' => false,
        ]);

        DB::table('plan_subscriptions')
            ->where('current_tenant_id', $tenant->id)
            ->update([
                'plan_id' => $business->version->plan_id,
                'plan_version_id' => $business->plan_version_id,
                'plan_price_id' => $business->id,
            ]);

        $this->tenantRequest($owner, $tenant)
            ->getJson('/api/branches')
            ->assertOk()
            ->assertJsonPath('meta.capacity.canCreate', false)
            ->assertJsonPath('meta.additionalBranch.available', true)
            ->assertJsonPath('meta.additionalBranch.can_purchase', true)
            ->assertJsonPath('meta.additionalBranch.price.amount', 35000)
            ->assertJsonPath('meta.additionalBranch.price.currency', 'mxn')
            ->assertJsonMissingPath('meta.additionalBranch.price.id');
    }

    public function test_owner_can_list_create_show_and_update_a_branch_with_sat_derived_fields(): void
    {
        [$owner, $tenant] =
            $this->provisionOwner();

        $this->allowAdditionalBranches(
            $tenant,
        );

        $this->seedPostalCode();

        $this->tenantRequest(
            $owner,
            $tenant,
        )
            ->getJson('/api/branches')
            ->assertOk()
            ->assertJsonPath(
                'data.0.code',
                'PRIN',
            )
            ->assertJsonPath(
                'data.0.name',
                'Principal',
            )
            ->assertJsonPath(
                'data.0.postalCode',
                null,
            )
            ->assertJsonPath(
                'data.0.fiscalReady',
                false,
            );

        $created =
            $this->tenantRequest(
                $owner,
                $tenant,
            )
                ->postJson(
                    '/api/branches',
                    $this->payload(),
                )
                ->assertCreated()
                ->assertJsonPath(
                    'data.code',
                    'CUL01',
                )
                ->assertJsonPath(
                    'data.state',
                    'SIN',
                )
                ->assertJsonPath(
                    'data.municipality',
                    '006',
                )
                ->assertJsonPath(
                    'data.locality',
                    '02',
                )
                ->assertJsonPath(
                    'data.countryCode',
                    'MX',
                )
                ->assertJsonPath(
                    'data.timezone',
                    'America/Mazatlan',
                )
                ->assertJsonPath(
                    'data.latitude',
                    null,
                )
                ->assertJsonPath(
                    'data.longitude',
                    null,
                )
                ->assertJsonPath(
                    'data.fiscalReady',
                    true,
                );

        $publicId =
            $created->json(
                'data.id',
            );

        $branch =
            DB::table('branches')
                ->where(
                    'code',
                    'CUL01',
                )
                ->first();

        $this->assertNotNull(
            $branch,
        );

        $this->assertSame(
            $tenant->id,
            $branch->tenant_id,
        );

        $this->assertSame(
            '80290',
            $branch->postal_code,
        );

        $this->assertSame(
            'SIN',
            $branch->state,
        );

        $this->assertSame(
            '006',
            $branch->municipality,
        );

        $this->assertSame(
            '02',
            $branch->locality,
        );

        $this->assertSame(
            'MX',
            $branch->country_code,
        );

        $this->assertSame(
            'America/Mazatlan',
            $branch->timezone,
        );

        $this->assertNull(
            $branch->latitude,
        );

        $this->assertNull(
            $branch->longitude,
        );

        $this->tenantRequest(
            $owner,
            $tenant,
        )
            ->getJson(
                '/api/branches/'.$publicId,
            )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $publicId,
            )
            ->assertJsonPath(
                'data.timezone',
                'America/Mazatlan',
            )
            ->assertJsonPath(
                'data.fiscalReady',
                true,
            );

        $this->tenantRequest(
            $owner,
            $tenant,
        )
            ->getJson(
                '/api/branches/'.$branch->id,
            )
            ->assertNotFound();

        DB::table('branches')
            ->where(
                'id',
                $branch->id,
            )
            ->update([
                'latitude' => 24.8091,

                'longitude' => -107.3940,
            ]);

        $this->tenantRequest(
            $owner,
            $tenant,
        )
            ->patchJson(
                '/api/branches/'.$publicId,
                [
                    'name' => 'Sucursal Culiacán Centro',

                    'street' => 'Francisco I. Madero',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Sucursal Culiacán Centro',
            )
            ->assertJsonPath(
                'data.street',
                'Francisco I. Madero',
            )
            ->assertJsonPath(
                'data.timezone',
                'America/Mazatlan',
            )
            ->assertJsonPath(
                'data.latitude',
                null,
            )
            ->assertJsonPath(
                'data.longitude',
                null,
            )
            ->assertJsonPath(
                'data.fiscalReady',
                true,
            );

        $updated =
            DB::table('branches')
                ->where(
                    'id',
                    $branch->id,
                )
                ->first();

        $this->assertSame(
            'America/Mazatlan',
            $updated->timezone,
        );

        $this->assertNull(
            $updated->latitude,
        );

        $this->assertNull(
            $updated->longitude,
        );
    }

    public function test_branch_writes_reject_invalid_duplicate_and_server_controlled_fields(): void
    {
        [$owner, $tenant] =
            $this->provisionOwner();

        $this->allowAdditionalBranches(
            $tenant,
        );

        $this->seedPostalCode();

        $this->tenantRequest(
            $owner,
            $tenant,
        )
            ->postJson(
                '/api/branches',
                [
                    ...$this->payload(),
                    'postal_code' => '00000',
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'postal_code',
            );

        $created =
            $this->tenantRequest(
                $owner,
                $tenant,
            )
                ->postJson(
                    '/api/branches',
                    $this->payload(),
                )
                ->assertCreated();

        $this->tenantRequest(
            $owner,
            $tenant,
        )
            ->postJson(
                '/api/branches',
                $this->payload(),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'code',
            );

        $controlled = [
            'tenant_id' => $tenant->id,

            'timezone' => 'America/Mazatlan',

            'latitude' => 24.8091,

            'longitude' => -107.3940,

            'state' => 'MANIPULADO',

            'municipality' => 'MANIPULADO',

            'locality' => 'MANIPULADO',

            'country_code' => 'US',

            'billing_enabled' => true,
        ];

        $this->tenantRequest(
            $owner,
            $tenant,
        )
            ->patchJson(
                '/api/branches/'
                    .$created->json('data.id'),
                $controlled,
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                array_keys(
                    $controlled,
                ),
            );
    }

    public function test_branch_routes_require_authentication_owner_role_and_current_tenant(): void
    {
        [$owner, $tenant] =
            $this->provisionOwner();

        [, $otherTenant] =
            $this->provisionOwner(
                'other-owner@example.test',
                'Otro',
            );

        $this->allowUnlimitedFeature(
            $tenant,
            'max_users',
        );

        $member =
            $this->addMember(
                $tenant,
            );

        $branchPublicId =
            $tenant
                ->branches()
                ->withoutGlobalScopes()
                ->value(
                    'public_id',
                );

        $this
            ->withHeader(
                'X-Tenant-ID',
                $tenant->public_id,
            )
            ->postJson(
                '/api/branches',
                $this->payload(),
            )
            ->assertUnauthorized();

        $this->tenantRequest(
            $member,
            $tenant,
        )
            ->postJson(
                '/api/branches',
                $this->payload(),
            )
            ->assertForbidden();

        $this->tenantRequest(
            $member,
            $tenant,
        )
            ->postJson('/api/branches/extra/purchase')
            ->assertForbidden();

        $this->tenantRequest(
            $member,
            $tenant,
        )
            ->postJson('/api/branches/extra/cancel')
            ->assertForbidden();

        $this->tenantRequest(
            $owner,
            $otherTenant,
        )
            ->getJson(
                '/api/branches/'
                    .$branchPublicId,
            )
            ->assertNotFound();

        $this->tenantRequest(
            $owner,
            $otherTenant,
        )
            ->patchJson(
                '/api/branches/'
                    .$branchPublicId,
                [
                    'name' => 'Intrusión',
                ],
            )
            ->assertNotFound();
    }

    private function provisionOwner(
        string $email = 'owner@example.test',
        string $business = 'Kova',
    ): array {
        $this->seed([
            PlanSeeder::class,
            RoleSeeder::class,
        ]);

        $owner =
            User::factory()
                ->create([
                    'email' => $email,

                    'email_verified_at' => now(),
                ]);

        $plan =
            Plan::query()
                ->where(
                    'code',
                    'free',
                )
                ->firstOrFail();

        $tenant =
            app(CreateTenant::class)
                ->handle(
                    $owner,
                    $plan,
                    $business,
                    strtolower($business)
                        .'-'
                        .uniqid(),
                );

        return [
            $owner,
            $tenant,
        ];
    }

    private function addMember(
        Tenant $tenant,
    ): User {
        $member =
            User::factory()
                ->create([
                    'email_verified_at' => now(),
                ]);

        app(CurrentTenant::class)
            ->run(
                $tenant,
                function () use (
                    $member,
                    $tenant,
                ): void {
                    Membership::query()
                        ->create([
                            'tenant_id' => $tenant->id,

                            'user_id' => $member->id,

                            'status' => MembershipStatus::Active,

                            'joined_at' => now(),
                        ]);

                    setPermissionsTeamId(
                        $tenant->id,
                    );

                    $member->assignRole(
                        RoleName::Cashier,
                    );

                    setPermissionsTeamId(
                        null,
                    );
                },
            );

        return $member;
    }

    private function allowAdditionalBranches(
        Tenant $tenant,
    ): void {
        $this->allowUnlimitedFeature(
            $tenant,
            'included_branches',
        );
    }

    private function allowUnlimitedFeature(
        Tenant $tenant,
        string $feature,
    ): void {
        $versionId =
            DB::table(
                'plan_subscriptions',
            )
                ->where(
                    'current_tenant_id',
                    $tenant->id,
                )
                ->value(
                    'plan_version_id',
                );

        $featureId =
            Feature::query()
                ->where(
                    'code',
                    $feature,
                )
                ->value(
                    'id',
                );

        DB::table(
            'plan_entitlements',
        )
            ->where(
                'plan_version_id',
                $versionId,
            )
            ->where(
                'feature_id',
                $featureId,
            )
            ->update([
                'is_unlimited' => true,
            ]);
    }

    private function seedPostalCode(): void
    {
        $versionId =
            DB::table(
                'sat_catalog_versions',
            )
                ->where(
                    'active_slot',
                    1,
                )
                ->where(
                    'status',
                    'active',
                )
                ->value(
                    'id',
                );

        if (! $versionId) {
            $versionId =
                DB::table(
                    'sat_catalog_versions',
                )
                    ->insertGetId([
                        'version' => 'test-branches',

                        'status' => 'active',

                        'active_slot' => 1,

                        'published_at' => now(),

                        'imported_at' => now(),

                        'validated_at' => now(),

                        'activated_at' => now(),

                        'created_at' => now(),

                        'updated_at' => now(),
                    ]);
        }

        DB::table(
            'sat_postal_codes',
        )
            ->insertOrIgnore([
                'catalog_version_id' => $versionId,

                'code' => '80290',

                'description' => '80290',

                'normalized_description' => '80290',

                'valid_from' => '2019-01-07',

                'valid_until' => null,

                'state' => 'SIN',

                'municipality' => '006',

                'locality' => '02',

                'timezone_description' => 'Tiempo del Pacífico',

                'summer_start_month' => null,

                'summer_start_day' => null,

                'summer_start_hour' => null,

                'summer_difference' => '-7',

                'winter_start_month' => null,

                'winter_start_day' => null,

                'winter_start_hour' => null,

                'winter_difference' => '-7',
            ]);
    }

    private function payload(): array
    {
        return [
            'code' => 'CUL01',

            'name' => 'Sucursal Culiacán',

            'postal_code' => '80290',

            'city' => 'Culiacán',

            'neighborhood' => 'Centro',

            'street' => 'Álvaro Obregón',

            'exterior_number' => '100',

            'phone' => '6671234567',
        ];
    }

    private function tenantRequest(
        User $user,
        Tenant $tenant,
    ): static {
        return $this
            ->actingAs(
                $user,
            )
            ->withHeader(
                'X-Tenant-ID',
                $tenant->public_id,
            );
    }
}
