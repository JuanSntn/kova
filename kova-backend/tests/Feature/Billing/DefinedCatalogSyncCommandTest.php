<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Services\Subscriptions\CatalogService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefinedCatalogSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_the_changed_plan_definition_once_and_preserves_its_addons(): void
    {
        $defined = require config_path('billing_catalog.php');
        $legacy = $defined;
        $legacy['plans']['business']['prices'] = ['month' => 10_000, 'year' => 100_000];
        $legacy['plans']['business']['addons'] = [
            'extra_stamp' => ['automatic' => true],
        ];
        $legacy['plans']['restaurant']['prices'] = ['month' => 60_000, 'year' => 600_000];

        config()->set('billing_catalog', $legacy);
        $this->seed(PlanSeeder::class);
        config()->set('billing_catalog', $defined);

        $this->artisan('billing:sync-defined-catalog')
            ->expectsOutputToContain('Publicada versión nueva de plan business')
            ->expectsOutputToContain('Publicada versión nueva de plan restaurant')
            ->assertSuccessful();

        $catalog = app(CatalogService::class);
        $business = $catalog->price('business');
        $restaurant = $catalog->price('restaurant');

        $this->assertSame(12_900, $business->amount);
        $this->assertSame(59_900, $restaurant->amount);
        $this->assertSame(2, $business->version->version);
        $this->assertTrue(
            $business->version->availableAddons()
                ->whereHas('addon', fn ($query) => $query->where('code', 'extra_branch'))
                ->exists(),
        );

        $this->artisan('billing:sync-defined-catalog')
            ->expectsOutput('El catálogo definido ya está publicado.')
            ->assertSuccessful();

        $this->assertSame(2, Plan::query()->where('code', 'business')->firstOrFail()->versions()->count());
    }
}
