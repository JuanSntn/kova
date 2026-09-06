<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('subscriptions', 'plan_subscriptions');

        Schema::table('plan_subscriptions', function (Blueprint $table) {
            $table->string('provider', 24)->default('local')->after('tenant_id');
            $table->string('stripe_subscription_id')->nullable()->unique()->after('provider');
            $table->string('stripe_price_id')->nullable()->after('stripe_subscription_id');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn([
                'stripe_id',
                'pm_type',
                'pm_last_four',
                'trial_ends_at',
            ]);
        });

        Schema::table('plan_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['stripe_subscription_id']);
            $table->dropColumn([
                'provider',
                'stripe_subscription_id',
                'stripe_price_id',
            ]);
        });

        Schema::rename('plan_subscriptions', 'subscriptions');
    }
};
