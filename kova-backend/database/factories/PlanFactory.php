<?php

namespace Database\Factories;

use App\Enums\PlanCode;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => PlanCode::Business,
            'name' => 'Negocio',
            'monthly_price' => fake()->randomFloat(2, 0, 1000),
            'max_users' => 5,
            'max_products' => 150,
            'monthly_sales_limit' => 150000,
            'included_branches' => 1,
            'included_stamps' => 50,
            'additional_stamp_price' => 1.30,
            'additional_branch_price' => null,
            'allows_counter_orders' => true,
            'allows_table_orders' => false,
            'allows_cash_shifts' => true,
            'allows_inventory' => true,
            'allows_invoicing' => true,
            'is_active' => true,
        ];
    }
}
