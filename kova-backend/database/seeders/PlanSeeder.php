<?php

namespace Database\Seeders;

use App\Enums\PlanCode;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'free',
                'name' => 'Free',
                'monthly_price' => 0,
                'max_users' => 1,
                'max_products' => 20,
                'max_categories' => 10,
                'monthly_sales_limit' => 25000,
                'included_branches' => 1,
                'included_stamps' => 0,
                'additional_stamp_price' => null,
                'additional_branch_price' => null,
                'allows_counter_orders' => true,
                'allows_table_orders' => false,
                'allows_cash_shifts' => false,
                'allows_inventory' => false,
                'allows_invoicing' => false,
                'is_active' => true,
            ],
            [
                'code' => 'business',
                'name' => 'Negocio',
                'monthly_price' => 100,
                'max_users' => 5,
                'max_products' => 150,
                'max_categories' => 150,
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
            ],
            [
                'code' => 'restaurant',
                'name' => 'Restaurante',
                'monthly_price' => 600,
                'max_users' => null,
                'max_products' => null,
                'max_categories' => 250,
                'monthly_sales_limit' => null,
                'included_branches' => 1,
                'included_stamps' => 150,
                'additional_stamp_price' => 1.30,
                'additional_branch_price' => 350,
                'allows_counter_orders' => true,
                'allows_table_orders' => true,
                'allows_cash_shifts' => true,
                'allows_inventory' => true,
                'allows_invoicing' => true,
                'is_active' => true,
            ],
        ];

        $catalog = app(\App\Services\Subscriptions\CatalogService::class);
        foreach ($plans[0] as $code=>$value) {
            if (!str_starts_with($code,'allows_') && !in_array($code,['max_users','max_products','max_categories','monthly_sales_limit','included_branches','included_stamps'])) continue;
            \App\Models\Feature::firstOrCreate(['code'=>$code],['name'=>$code,'type'=>is_bool($value)?'boolean':'integer','unit'=>$code === 'monthly_sales_limit' ? 'mxn_minor' : 'count','reset_policy'=>in_array($code,['monthly_sales_limit','included_stamps'])?'month':'none','additive'=>!is_bool($value)]);
        }
        foreach (['extra_branch'=>['Sucursal adicional','recurring',35000,['included_branches'=>1]],'extra_stamp'=>['Timbre excedente','metered',130,[]]] as $code=>[$name,$mode,$amount,$entitlements]) {
            $addon=\App\Models\Addon::firstOrCreate(['code'=>$code],['name'=>$name]);
            if (!$addon->versions()->exists()) { $av=$catalog->createVersion($addon,['mode'=>$mode,'entitlements'=>$entitlements,'prices'=>['month'=>$amount]]); $catalog->publish($av); }
        }
        foreach ($plans as $plan) {
            $identity = Plan::query()->firstOrCreate(
                ['code' => $plan['code']],
                $plan,
            );
            if ($identity->versions()->exists()) continue;
            $benefits=array_intersect_key($plan,array_flip(\App\Models\Feature::pluck('code')->all()));
            if ($benefits['monthly_sales_limit'] !== null) $benefits['monthly_sales_limit'] *= 100;
            $addons=[];
            foreach (\App\Models\AddonVersion::with('addon')->get() as $av) {
                if (($av->addon->code === 'extra_stamp' && $plan['allows_invoicing']) || ($av->addon->code === 'extra_branch' && $plan['code'] === 'restaurant')) $addons[]=['version'=>$av->public_id,'automatic'=>$av->mode === 'metered'];
            }
            $prices=['month'=>$plan['monthly_price']*100];
            if ($plan['monthly_price'] > 0) $prices['year']=$plan['monthly_price']*1000;
            $version=$catalog->createVersion($identity,['entitlements'=>$benefits,'prices'=>$prices,'addons'=>$addons]);
            $catalog->publish($version);
        }
    }
}
