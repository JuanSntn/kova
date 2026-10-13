<?php

namespace Database\Factories;

use App\Models\Feature;
use App\Models\Plan;
use App\Services\Subscriptions\CatalogService;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => 'test_'.fake()->unique()->lexify('????????'), 'name' => 'Test plan', 'is_active' => true];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Plan $plan) {
            $values = ['max_users' => 5, 'max_products' => 150, 'max_categories' => 150, 'monthly_sales_limit' => 15000000, 'included_branches' => 10, 'included_stamps' => 50, 'allows_counter_orders' => true, 'allows_table_orders' => false, 'allows_cash_shifts' => true, 'allows_preparation' => true, 'max_preparation_stations' => 1, 'allows_inventory' => true, 'allows_invoicing' => true];
            foreach ($values as $code => $value) {
                Feature::firstOrCreate(['code' => $code], ['name' => $code, 'type' => is_bool($value) ? 'boolean' : 'integer', 'additive' => ! is_bool($value)]);
            }
            $catalog = app(CatalogService::class);
            $version = $catalog->createVersion($plan, ['entitlements' => $values, 'prices' => ['month' => 0]]);
            $catalog->publish($version);
        });
    }
}
