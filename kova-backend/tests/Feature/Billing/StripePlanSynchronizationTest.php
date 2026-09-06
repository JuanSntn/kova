<?php

namespace Tests\Feature\Billing;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Listeners\SyncStripePlanSubscription;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookHandled;
use Tests\TestCase;

class StripePlanSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_webhook_result_is_idempotently_reflected_in_plan_entitlements(): void
    {
        config([
            'billing.plans.business.price' => 'price_business_test',
            'billing.plans.restaurant.price' => 'price_restaurant_test',
        ]);
        $this->seed([PlanSeeder::class, RoleSeeder::class]);

        $owner = User::factory()->create();
        $free = Plan::query()->where('code', PlanCode::Free->value)->sole();
        $tenant = app(CreateTenant::class)->handle(
            $owner,
            $free,
            'Webhook Tenant',
            'webhook-tenant',
        );
        $tenant->stripe_id = 'cus_kova_test';
        $tenant->save();

        $payload = [
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'id' => 'sub_kova_test',
                    'customer' => 'cus_kova_test',
                    'status' => 'active',
                    'start_date' => now()->timestamp,
                    'current_period_start' => now()->timestamp,
                    'current_period_end' => now()->addMonth()->timestamp,
                    'items' => [
                        'data' => [[
                            'price' => [
                                'id' => 'price_business_test',
                                'product' => 'prod_business_test',
                            ],
                        ]],
                    ],
                ],
            ],
        ];

        $listener = app(SyncStripePlanSubscription::class);
        $listener->handle(new WebhookHandled($payload));
        $listener->handle(new WebhookHandled($payload));

        app(CurrentTenant::class)->run($tenant, function (): void {
            $paid = PlanSubscription::query()
                ->where('stripe_subscription_id', 'sub_kova_test')
                ->sole();

            $this->assertSame(PlanCode::Business, $paid->plan->code);
            $this->assertSame(SubscriptionStatus::Active, $paid->status);
            $this->assertSame('stripe', $paid->provider);
            $this->assertSame(1, PlanSubscription::query()
                ->where('stripe_subscription_id', 'sub_kova_test')
                ->count());
            $this->assertSame(1, AuditEvent::query()
                ->where('action', 'billing.subscription_synced')
                ->count());
        });

        $this->assertFalse(app(CurrentTenant::class)->isResolved());
    }

    public function test_cancelled_subscription_restores_free_plan_without_duplicates(): void
    {
        config(['billing.plans.business.price' => 'price_business_test']);
        $this->seed([PlanSeeder::class, RoleSeeder::class]);

        $owner = User::factory()->create();
        $free = Plan::query()->where('code', PlanCode::Free->value)->sole();
        $tenant = app(CreateTenant::class)->handle($owner, $free, 'Cancel', 'cancel');
        $tenant->stripe_id = 'cus_cancel_test';
        $tenant->save();

        $payload = [
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_cancel_test',
                    'customer' => 'cus_cancel_test',
                    'status' => 'canceled',
                    'items' => ['data' => []],
                ],
            ],
        ];

        $listener = app(SyncStripePlanSubscription::class);
        $listener->handle(new WebhookHandled($payload));
        $listener->handle(new WebhookHandled($payload));

        app(CurrentTenant::class)->run($tenant, function () use ($free): void {
            $this->assertSame(1, PlanSubscription::query()
                ->where('plan_id', $free->getKey())
                ->where('status', SubscriptionStatus::Active->value)
                ->count());
        });
    }
}
