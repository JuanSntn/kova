<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Keep existing Business contracts on their agreed base price and
     * entitlements while making the newly introduced optional branch add-on
     * available to them. The add-on is never automatic and still requires a
     * server-side Stripe purchase.
     */
    public function up(): void
    {
        $addonVersionId = DB::table('addon_versions')
            ->join('addons', 'addons.id', '=', 'addon_versions.addon_id')
            ->where('addons.code', 'extra_branch')
            ->where('addon_versions.status', 'published')
            ->orderByDesc('addon_versions.version')
            ->value('addon_versions.id');

        if (! $addonVersionId) {
            return;
        }

        $businessVersions = DB::table('plan_versions')
            ->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
            ->where('plans.code', 'business')
            ->where('plan_versions.status', 'published')
            ->pluck('plan_versions.id');

        foreach ($businessVersions as $planVersionId) {
            DB::table('plan_version_addons')->updateOrInsert(
                [
                    'plan_version_id' => $planVersionId,
                    'addon_version_id' => $addonVersionId,
                ],
                [
                    'automatic' => false,
                ],
            );
        }
    }

    public function down(): void
    {
        // This availability is part of the published commercial migration.
        // Removing it could strand an active paid add-on during a rollback.
    }
};
