<?php

namespace Tests\Feature\Preparation;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\PlanCode;
use App\Enums\RoleName;
use App\Enums\AccountType;
use App\Enums\MembershipStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\CatalogService;
use App\Services\Subscriptions\ContractService;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreparationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_uses_one_shared_queue_for_kitchen_and_bar(): void
    {
        [$owner, $tenant, $branch] = $this->tenant(PlanCode::Business, 'negocio');
        $kitchen = $this->staff($tenant, $branch, RoleName::Kitchen, 'cocina');
        [, $barItem] = $this->orderWithStations($tenant, $branch, $owner);

        $this->actingAs($kitchen)
            ->withHeaders($this->headers($tenant, $branch))
            ->getJson('/api/preparation/orders')
            ->assertOk()
            ->assertJsonPath('meta.mode', 'shared')
            ->assertJsonPath('meta.station', null)
            ->assertJsonCount(2, 'data.0.items');

        $this->actingAs($kitchen)
            ->withHeaders($this->headers($tenant, $branch))
            ->patchJson('/api/preparation/items/'.$barItem->public_id, ['status' => 'preparing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'preparing');
    }

    public function test_restaurant_staff_is_locked_to_its_assigned_station(): void
    {
        [$owner, $tenant, $branch] = $this->tenant(PlanCode::Restaurant, 'restaurante');
        $kitchen = $this->staff($tenant, $branch, RoleName::Kitchen, 'cocina');
        [$kitchenItem, $barItem] = $this->orderWithStations($tenant, $branch, $owner);

        $response = $this->actingAs($kitchen)
            ->withHeaders($this->headers($tenant, $branch))
            ->getJson('/api/preparation/orders?station=bar')
            ->assertOk()
            ->assertJsonPath('meta.mode', 'independent')
            ->assertJsonPath('meta.station', 'kitchen')
            ->assertJsonPath('meta.station_locked', true)
            ->assertJsonCount(1, 'data.0.items');

        $this->assertSame($kitchenItem->public_id, $response->json('data.0.items.0.id'));

        $this->actingAs($kitchen)
            ->withHeaders($this->headers($tenant, $branch))
            ->patchJson('/api/preparation/items/'.$barItem->public_id, ['status' => 'ready'])
            ->assertNotFound();
    }

    public function test_item_uuid_cannot_be_used_from_another_tenant(): void
    {
        [$firstOwner, $firstTenant, $firstBranch] = $this->tenant(PlanCode::Restaurant, 'primero');
        [, $foreignItem] = $this->orderWithStations($firstTenant, $firstBranch, $firstOwner);
        [$secondOwner, $secondTenant, $secondBranch] = $this->tenant(PlanCode::Restaurant, 'segundo');

        $this->actingAs($secondOwner)
            ->withHeaders($this->headers($secondTenant, $secondBranch))
            ->patchJson('/api/preparation/items/'.$foreignItem->public_id, ['status' => 'ready'])
            ->assertNotFound();
    }

    public function test_free_plan_cannot_access_preparation(): void
    {
        [$owner, $tenant, $branch] = $this->tenant(PlanCode::Free, 'gratis');

        $this->actingAs($owner)
            ->withHeaders($this->headers($tenant, $branch))
            ->getJson('/api/preparation/orders')
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');
    }

    /** @return array{User, Tenant, Branch} */
    private function tenant(PlanCode $code, string $slug): array
    {
        $this->seed([PlanSeeder::class, RoleSeeder::class]);
        $owner = User::factory()->create(['email' => $slug.'@example.test']);
        $tenant = app(CreateTenant::class)->handle(
            $owner,
            Plan::query()->where('code', PlanCode::Free->value)->sole(),
            ucfirst($slug),
            $slug,
        );
        if ($code !== PlanCode::Free) {
            app(CurrentTenant::class)->run($tenant, function () use ($tenant, $code): void {
                $tenant->planSubscriptions()->whereNotNull('current_tenant_id')->update([
                    'current_tenant_id' => null,
                    'ended_at' => now(),
                ]);
                $contract = app(ContractService::class)->create(
                    $tenant,
                    app(CatalogService::class)->price($code->value),
                );
                $contract->update([
                    'current_tenant_id' => $tenant->id,
                    'pending_tenant_id' => null,
                    'status' => 'active',
                ]);
            });
        }
        $branch = app(CurrentTenant::class)->run($tenant, fn () => $tenant->branches()->sole());

        return [$owner, $tenant, $branch];
    }

    private function staff(Tenant $tenant, Branch $branch, RoleName $role, string $username): User
    {
        return app(CurrentTenant::class)->run($tenant, function () use ($tenant, $branch, $role, $username): User {
            $user = User::query()->create([
                'name' => ucfirst($username),
                'password' => 'KovaPass1',
                'account_type' => AccountType::TenantLocal,
            ]);
            $membership = Membership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'login_name' => $username,
                'login_name_normalized' => $username,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);
            $membership->branches()->sync([$branch->id]);
            setPermissionsTeamId($tenant->id);
            $user->assignRole($role->value);

            return $user;
        });
    }

    /** @return array{OrderItem, OrderItem} */
    private function orderWithStations(Tenant $tenant, Branch $branch, User $owner): array
    {
        return app(CurrentTenant::class)->run($tenant, function () use ($tenant, $branch, $owner): array {
            $order = $tenant->orders()->create([
                'branch_id' => $branch->id,
                'folio' => 'POS-'.str_pad((string) $tenant->id, 6, '0', STR_PAD_LEFT),
                'order_type' => 'counter',
                'status' => 'paid',
                'subtotal' => 100,
                'tax_base_total' => 86.21,
                'tax_total' => 13.79,
                'total' => 100,
                'payment_method' => 'card',
                'paid_at' => now(),
                'created_by' => $owner->id,
            ]);

            $item = fn (string $name, string $station) => $order->items()->create([
                'product_name_snapshot' => $name,
                'preparation_station_snapshot' => $station,
                'preparation_status' => 'pending',
                'unit_price_snapshot' => 50,
                'quantity' => 1,
                'tax_name_snapshot' => 'IVA 16%',
                'tax_code_snapshot' => '002',
                'tax_factor_type_snapshot' => 'rate',
                'tax_rate_snapshot' => 0.16,
                'tax_base' => 43.10,
                'tax_amount' => 6.90,
                'line_total' => 50,
            ]);

            return [$item('Hamburguesa', 'kitchen'), $item('Limonada', 'bar')];
        });
    }

    /** @return array<string, string> */
    private function headers(Tenant $tenant, Branch $branch): array
    {
        return ['X-Tenant-ID' => $tenant->public_id, 'X-Branch-ID' => $branch->public_id];
    }
}
