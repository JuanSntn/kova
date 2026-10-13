<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $features = [
            'allows_preparation' => [
                'name' => 'allows_preparation',
                'type' => 'boolean',
                'unit' => 'count',
            ],
            'max_preparation_stations' => [
                'name' => 'max_preparation_stations',
                'type' => 'integer',
                'unit' => 'count',
            ],
        ];

        foreach ($features as $code => $definition) {
            DB::table('features')->updateOrInsert(
                ['code' => $code],
                [...$definition, 'reset_policy' => 'none', 'additive' => false, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $featureIds = DB::table('features')->whereIn('code', array_keys($features))->pluck('id', 'code');
        $versions = DB::table('plan_versions')
            ->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
            ->select('plan_versions.id', 'plans.code')
            ->get();

        foreach ($versions as $version) {
            $enabled = in_array($version->code, ['business', 'restaurant'], true);
            $stations = match ($version->code) {
                'business' => 1,
                'restaurant' => null,
                default => 0,
            };

            DB::table('plan_entitlements')->updateOrInsert(
                ['plan_version_id' => $version->id, 'feature_id' => $featureIds['allows_preparation']],
                ['value' => $enabled ? 1 : 0, 'is_unlimited' => false, 'created_at' => $now, 'updated_at' => $now],
            );
            DB::table('plan_entitlements')->updateOrInsert(
                ['plan_version_id' => $version->id, 'feature_id' => $featureIds['max_preparation_stations']],
                ['value' => $stations, 'is_unlimited' => $version->code === 'restaurant', 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $ids = DB::table('features')->whereIn('code', ['allows_preparation', 'max_preparation_stations'])->pluck('id');
        DB::table('plan_entitlements')->whereIn('feature_id', $ids)->delete();
        DB::table('features')->whereIn('id', $ids)->delete();
    }
};
