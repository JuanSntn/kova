<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Exceptions\MissingTenantContext;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_owned_models_are_scoped_and_fail_closed_without_context(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $plan = Plan::factory()->create();
        $createTenant = app(CreateTenant::class);

        $firstTenant = $createTenant->handle($owner, $plan, 'Primero', 'primero');
        $secondTenant = $createTenant->handle($owner, $plan, 'Segundo', 'segundo');

        app(CurrentTenant::class)->run($firstTenant, function (): void {
            Branch::query()->create([
                'code' => 'NORTE',
                'name' => 'Sucursal Norte',
            ]);

            $this->assertSame(2, Branch::query()->count());
        });

        app(CurrentTenant::class)->run($secondTenant, function (): void {
            $this->assertSame(1, Branch::query()->count());
            $this->assertSame('PRIN', Branch::query()->sole()->code);
        });

        $this->assertSame(0, Branch::query()->count());
    }

    public function test_writes_are_rejected_without_or_outside_the_current_tenant(): void
    {
        $this->expectException(MissingTenantContext::class);

        Branch::query()->create([
            'code' => 'INVALID',
            'name' => 'Sin tenant',
        ]);
    }

    public function test_an_explicit_mismatched_tenant_id_is_rejected(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create();
        $plan = Plan::factory()->create();
        $createTenant = app(CreateTenant::class);

        $firstTenant = $createTenant->handle($owner, $plan, 'Primero', 'primero');
        $secondTenant = $createTenant->handle($owner, $plan, 'Segundo', 'segundo');

        $this->expectException(LogicException::class);

        app(CurrentTenant::class)->run($firstTenant, function () use ($secondTenant): void {
            $branch = new Branch([
                'code' => 'INVALID',
                'name' => 'Tenant incorrecto',
            ]);
            $branch->tenant_id = $secondTenant->id;
            $branch->save();
        });
    }
}
