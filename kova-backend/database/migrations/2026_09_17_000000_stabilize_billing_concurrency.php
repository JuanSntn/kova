<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('plan_subscriptions')
            ->select('tenant_id')
            ->where('provider', 'stripe')
            ->whereNull('current_tenant_id')
            ->whereNull('ended_at')
            ->groupBy('tenant_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Hay más de una contratación Stripe pendiente para un tenant. Reconcílialas antes de continuar.'
            );
        }

        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            $table->foreignId('pending_tenant_id')
                ->nullable()
                ->unique()
                ->after('current_tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $table->timestamp('last_billing_verified_at')->nullable();
            $table->timestamp('billing_verification_failed_since')->nullable();
        });

        Schema::table('billing_outbox', function (Blueprint $table): void {
            $table->uuid('lock_token')->nullable()->after('locked_at');
        });

        DB::table('plan_subscriptions')
            ->where('provider', 'stripe')
            ->whereNull('current_tenant_id')
            ->whereNull('ended_at')
            ->update([
                'pending_tenant_id' => DB::raw('tenant_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('billing_outbox', function (Blueprint $table): void {
            $table->dropColumn('lock_token');
        });

        Schema::table('plan_subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pending_tenant_id');
            $table->dropColumn([
                'last_billing_verified_at',
                'billing_verification_failed_since',
            ]);
        });
    }
};
