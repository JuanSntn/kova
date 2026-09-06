<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $t) {
            $t->id(); $t->string('code')->unique(); $t->string('name');
            $t->string('type'); $t->string('unit')->nullable();
            $t->string('reset_policy')->default('none'); $t->boolean('additive')->default(false); $t->timestamps();
        });
        Schema::create('addons', function (Blueprint $t) {
            $t->id(); $t->string('code')->unique(); $t->string('name'); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        foreach (['plan', 'addon'] as $kind) {
            Schema::create($kind.'_versions', function (Blueprint $t) use ($kind) {
                $t->id(); $t->uuid('public_id')->unique(); $t->foreignId($kind.'_id')->constrained($kind.'s')->restrictOnDelete();
                $t->unsignedInteger('version'); $t->string('name'); $t->string('status')->default('draft');
                $t->string('mode')->default('recurring'); $t->timestamp('published_at')->nullable(); $t->timestamp('retired_at')->nullable();
                $t->timestamps(); $t->unique([$kind.'_id', 'version']);
            });
            Schema::create($kind.'_entitlements', function (Blueprint $t) use ($kind) {
                $t->id(); $t->foreignId($kind.'_version_id')->constrained()->restrictOnDelete();
                $t->foreignId('feature_id')->constrained()->restrictOnDelete();
                $t->bigInteger('value')->nullable(); $t->boolean('is_unlimited')->default(false); $t->timestamps();
                $t->unique([$kind.'_version_id', 'feature_id']);
            });
            Schema::create($kind.'_prices', function (Blueprint $t) use ($kind) {
                $t->id(); $t->uuid('public_id')->unique(); $t->foreignId($kind.'_version_id')->constrained()->restrictOnDelete();
                $t->string('currency', 3)->default('mxn'); $t->string('interval')->default('month');
                $t->unsignedBigInteger('amount'); $t->timestamps();
                $t->unique([$kind.'_version_id', 'currency', 'interval']);
            });
        }
        Schema::create('plan_version_addons', function (Blueprint $t) {
            $t->id(); $t->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('addon_version_id')->constrained()->restrictOnDelete();
            $t->boolean('automatic')->default(false); $t->timestamps(); $t->unique(['plan_version_id', 'addon_version_id']);
        });
        Schema::table('plan_subscriptions', function (Blueprint $t) {
            $t->uuid('public_id')->nullable()->unique();
            $t->foreignId('plan_version_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('plan_price_id')->nullable()->constrained()->restrictOnDelete();
            // Nullable unique key: only the current contract owns its tenant's slot.
            $t->foreignId('current_tenant_id')->nullable()->unique()->constrained('tenants')->restrictOnDelete();
            $t->timestamp('usage_anchor_at')->nullable(); $t->timestamp('grace_ends_at')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
        });
        Schema::create('subscription_addons', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_subscription_id')->constrained()->restrictOnDelete();
            $t->foreignId('addon_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('addon_price_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity')->default(1); $t->string('status')->default('pending');
            $t->string('stripe_item_id')->nullable(); $t->timestamp('starts_at')->nullable(); $t->timestamp('ends_at')->nullable(); $t->timestamps();
        });
        Schema::create('subscription_changes', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_subscription_id')->constrained()->restrictOnDelete();
            $t->string('kind'); $t->string('status')->default('pending');
            $t->foreignId('target_plan_price_id')->nullable()->constrained('plan_prices')->restrictOnDelete();
            $t->foreignId('subscription_addon_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity')->nullable(); $t->json('retained_branch_ids')->nullable();
            $t->timestamp('effective_at'); $t->timestamp('completed_at')->nullable(); $t->timestamps();
        });
        Schema::create('usage_periods', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_subscription_id')->constrained()->restrictOnDelete(); $t->foreignId('feature_id')->constrained()->restrictOnDelete();
            $t->timestamp('starts_at'); $t->timestamp('ends_at'); $t->unsignedBigInteger('consumed')->default(0);
            $t->unsignedBigInteger('included')->nullable(); $t->timestamps(); $t->unique(['plan_subscription_id','feature_id','starts_at'], 'usage_period_unique');
        });
        Schema::create('usage_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignId('usage_period_id')->constrained()->restrictOnDelete();
            $t->string('source_id'); $t->unsignedBigInteger('quantity'); $t->unsignedBigInteger('billable_quantity');
            $t->timestamp('occurred_at'); $t->timestamps(); $t->unique(['tenant_id','source_id']);
        });
        Schema::create('billing_subscription_links', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $t->foreignId('plan_subscription_id')->constrained()->restrictOnDelete();
            $t->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete(); $t->string('role');
            $t->string('stripe_subscription_id')->unique(); $t->string('status');
            $t->timestamp('period_starts_at')->nullable(); $t->timestamp('period_ends_at')->nullable();
            $t->timestamp('past_due_since')->nullable(); $t->timestamp('last_event_at')->nullable(); $t->timestamps();
            $t->unique(['plan_subscription_id','role']);
        });
        Schema::create('billing_price_mappings', function (Blueprint $t) {
            $t->id(); $t->foreignId('plan_price_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('addon_price_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('livemode')->default(false); $t->string('stripe_price_id')->unique();
            $t->string('stripe_meter_id')->nullable(); $t->string('meter_event_name')->nullable(); $t->timestamps();
            $t->unique(['plan_price_id','livemode']); $t->unique(['addon_price_id','livemode']);
        });
        Schema::create('billing_webhook_events', function (Blueprint $t) {
            $t->id(); $t->string('stripe_event_id')->unique(); $t->string('type'); $t->json('payload');
            $t->string('status')->default('pending'); $t->text('last_error')->nullable(); $t->timestamp('processed_at')->nullable(); $t->timestamps();
        });
        Schema::create('billing_outbox', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('operation_key')->unique(); $t->string('kind'); $t->json('payload'); $t->json('result')->nullable();
            $t->string('status')->default('pending'); $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable(); $t->timestamp('locked_at')->nullable();
            $t->text('last_error')->nullable(); $t->timestamps();
        });
        Schema::table('branches', fn (Blueprint $t) => $t->boolean('billing_enabled')->default(true));
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn('billing_enabled'));
        foreach (['billing_outbox','billing_webhook_events','billing_price_mappings','billing_subscription_links','usage_events','usage_periods','subscription_changes','subscription_addons'] as $table) Schema::dropIfExists($table);
        Schema::table('plan_subscriptions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('current_tenant_id'); $t->dropConstrainedForeignId('plan_price_id'); $t->dropConstrainedForeignId('plan_version_id');
            $t->dropColumn(['public_id','usage_anchor_at','grace_ends_at','cancel_at_period_end']);
        });
        Schema::dropIfExists('plan_version_addons');
        foreach (['addon','plan'] as $kind) foreach (['prices','entitlements','versions'] as $suffix) Schema::dropIfExists($kind.'_'.$suffix);
        Schema::dropIfExists('addons'); Schema::dropIfExists('features');
    }
};
