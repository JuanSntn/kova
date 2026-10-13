# Migraciones de contratos y cobro

Este documento reúne las migraciones de las tablas que sostienen los contratos
comerciales de Kova y la infraestructura de Laravel Cashier/Stripe.

> **Importante:** las definiciones consolidadas de este documento sirven como
> referencia del esquema vigente. No deben copiarse como una migración nueva
> sobre una base que ya ejecutó las migraciones originales, porque las tablas y
> restricciones ya existen.

## Separación de responsabilidades

- `plan_subscriptions`, `subscription_addons` y `subscription_changes` forman
  el contrato comercial de Kova.
- `subscriptions` y `subscription_items` son el espejo técnico que mantiene
  Laravel Cashier de los objetos de Stripe.
- `billing_subscription_links` vincula un contrato de Kova con sus componentes
  Stripe `base` y `extras`.
- `billing_webhook_events` y `billing_outbox` aportan idempotencia, reintentos y
  reconciliación.

## Migraciones originales

El esquema actual se construyó mediante esta secuencia:

1. [`2026_08_04_000200_create_subscriptions_table.php`](../database/migrations/2026_08_04_000200_create_subscriptions_table.php)
   creó el contrato comercial bajo el nombre inicial `subscriptions`.
2. [`2026_08_05_000000_prepare_tenant_billing_for_cashier.php`](../database/migrations/2026_08_05_000000_prepare_tenant_billing_for_cashier.php)
   renombró esa tabla a `plan_subscriptions` y creó las nuevas tablas
   `subscriptions` y `subscription_items` de Cashier.
3. [`2026_08_06_020000_add_stripe_event_tracking_to_plan_subscriptions.php`](../database/migrations/2026_08_06_020000_add_stripe_event_tracking_to_plan_subscriptions.php)
   agregó `last_stripe_event_created_at`.
4. [`2026_08_06_030000_add_ended_at_to_plan_subscriptions.php`](../database/migrations/2026_08_06_030000_add_ended_at_to_plan_subscriptions.php)
   agregó `ended_at`.
5. [`2026_09_06_000000_create_versioned_billing_catalog.php`](../database/migrations/2026_09_06_000000_create_versioned_billing_catalog.php)
   agregó el contrato versionado y creó addons, cambios, vínculos, webhooks y
   outbox.
6. [`2026_09_06_000200_add_cancellation_branch_selection.php`](../database/migrations/2026_09_06_000200_add_cancellation_branch_selection.php)
   agregó la selección de sucursales conservadas al cancelar.
7. [`2026_09_07_220000_harden_billing_plan_changes.php`](../database/migrations/2026_09_07_220000_harden_billing_plan_changes.php)
   agregó el modo de cambio, contrato destino y fecha de solicitud de
   cancelación.

## `subscriptions` y `subscription_items`

Estas son las tablas técnicas de Laravel Cashier. No conceden beneficios del
plan por sí mismas.

```php
Schema::create('subscriptions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')
        ->constrained()
        ->cascadeOnDelete();
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
    $table->foreignId('subscription_id')
        ->constrained()
        ->cascadeOnDelete();
    $table->string('stripe_id')->unique();
    $table->string('stripe_product');
    $table->string('stripe_price');
    $table->string('meter_id')->nullable();
    $table->integer('quantity')->nullable();
    $table->string('meter_event_name')->nullable();
    $table->timestamps();

    $table->index(['subscription_id', 'stripe_price']);
});
```

Rollback correspondiente:

```php
Schema::dropIfExists('subscription_items');
Schema::dropIfExists('subscriptions');
```

## `plan_subscriptions`

Esta es la definición consolidada del contrato comercial vigente. La tabla se
creó y amplió en varias migraciones históricas.

```php
Schema::create('plan_subscriptions', function (Blueprint $table) {
    $table->id();

    $table->foreignId('tenant_id')
        ->constrained()
        ->cascadeOnDelete();
    $table->foreignId('plan_id')
        ->constrained()
        ->restrictOnDelete();

    $table->string('status', 24)->default('active');
    $table->decimal('monthly_price_snapshot', 12, 2);
    $table->json('entitlements_snapshot');

    $table->timestamp('starts_at');
    $table->timestamp('current_period_starts_at');
    $table->timestamp('current_period_ends_at');
    $table->timestamp('cancelled_at')->nullable();
    $table->timestamp('ended_at')->nullable();

    $table->string('provider', 24)->default('local');
    $table->string('stripe_subscription_id')->nullable()->unique();
    $table->string('stripe_price_id')->nullable();
    $table->timestamp('last_stripe_event_created_at')->nullable();

    $table->uuid('public_id')->nullable()->unique();
    $table->foreignId('plan_version_id')
        ->nullable()
        ->constrained()
        ->restrictOnDelete();
    $table->foreignId('plan_price_id')
        ->nullable()
        ->constrained()
        ->restrictOnDelete();

    // Sólo el contrato vigente ocupa esta ranura única del tenant.
    $table->foreignId('current_tenant_id')
        ->nullable()
        ->unique()
        ->constrained('tenants')
        ->restrictOnDelete();

    $table->timestamp('usage_anchor_at')->nullable();
    $table->timestamp('grace_ends_at')->nullable();
    $table->boolean('cancel_at_period_end')->default(false);
    $table->json('cancellation_branch_ids')->nullable();
    $table->timestamp('cancellation_requested_at')->nullable();

    $table->timestamps();

    $table->index(['tenant_id', 'status']);
    $table->index(['status', 'current_period_ends_at']);
});
```

`monthly_price_snapshot` y `entitlements_snapshot` son campos históricos de
compatibilidad. La autoridad comercial es `plan_version_id`, `plan_price_id` y
los addons contratados. `current_tenant_id` garantiza una sola suscripción
comercial vigente por tenant.

## `subscription_addons`

```php
Schema::create('subscription_addons', function (Blueprint $table) {
    $table->id();
    $table->uuid('public_id')->unique();
    $table->foreignId('tenant_id')
        ->constrained()
        ->cascadeOnDelete();
    $table->foreignId('plan_subscription_id')
        ->constrained()
        ->restrictOnDelete();
    $table->foreignId('addon_version_id')
        ->constrained()
        ->restrictOnDelete();
    $table->foreignId('addon_price_id')
        ->constrained()
        ->restrictOnDelete();
    $table->unsignedInteger('quantity')->default(1);
    $table->string('status')->default('pending');
    $table->string('stripe_item_id')->nullable();
    $table->timestamp('starts_at')->nullable();
    $table->timestamp('ends_at')->nullable();
    $table->timestamps();
});
```

Rollback correspondiente:

```php
Schema::dropIfExists('subscription_addons');
```

## `subscription_changes`

La definición consolidada incluye los campos agregados posteriormente para
cambios inmediatos o al final del periodo.

```php
Schema::create('subscription_changes', function (Blueprint $table) {
    $table->id();
    $table->uuid('public_id')->unique();
    $table->foreignId('tenant_id')
        ->constrained()
        ->cascadeOnDelete();
    $table->foreignId('plan_subscription_id')
        ->constrained()
        ->restrictOnDelete();

    $table->string('kind');
    $table->string('status')->default('pending');
    $table->string('change_mode')->default('period_end');

    $table->foreignId('target_plan_price_id')
        ->nullable()
        ->constrained('plan_prices')
        ->restrictOnDelete();
    $table->foreignId('target_plan_subscription_id')
        ->nullable()
        ->constrained('plan_subscriptions')
        ->restrictOnDelete();

    $table->foreignId('subscription_addon_id')
        ->nullable()
        ->constrained()
        ->restrictOnDelete();
    $table->unsignedInteger('quantity')->nullable();
    $table->json('retained_branch_ids')->nullable();

    $table->timestamp('effective_at');
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
});
```

Rollback correspondiente:

```php
Schema::dropIfExists('subscription_changes');
```

## `billing_subscription_links`

Cada contrato pagado puede tener un componente `base` y uno `extras`. La
restricción compuesta impide duplicar el mismo rol para un contrato.

```php
Schema::create('billing_subscription_links', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')
        ->constrained()
        ->cascadeOnDelete();
    $table->foreignId('plan_subscription_id')
        ->constrained()
        ->restrictOnDelete();
    $table->foreignId('subscription_id')
        ->nullable()
        ->constrained()
        ->nullOnDelete();
    $table->string('role');
    $table->string('stripe_subscription_id')->unique();
    $table->string('status');
    $table->timestamp('period_starts_at')->nullable();
    $table->timestamp('period_ends_at')->nullable();
    $table->timestamp('past_due_since')->nullable();
    $table->timestamp('last_event_at')->nullable();
    $table->timestamps();

    $table->unique(['plan_subscription_id', 'role']);
});
```

Rollback correspondiente:

```php
Schema::dropIfExists('billing_subscription_links');
```

## `billing_webhook_events`

La unicidad de `stripe_event_id` hace idempotente la recepción de webhooks.

```php
Schema::create('billing_webhook_events', function (Blueprint $table) {
    $table->id();
    $table->string('stripe_event_id')->unique();
    $table->string('type');
    $table->json('payload');
    $table->string('status')->default('pending');
    $table->text('last_error')->nullable();
    $table->timestamp('processed_at')->nullable();
    $table->timestamps();
});
```

Rollback correspondiente:

```php
Schema::dropIfExists('billing_webhook_events');
```

## `billing_outbox`

La outbox conserva las operaciones que Kova debe ejecutar o reconciliar con
Stripe. `operation_key` proporciona idempotencia local.

```php
Schema::create('billing_outbox', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')
        ->constrained()
        ->cascadeOnDelete();
    $table->string('operation_key')->unique();
    $table->string('kind');
    $table->json('payload');
    $table->json('result')->nullable();
    $table->string('status')->default('pending');
    $table->unsignedInteger('attempts')->default(0);
    $table->timestamp('available_at')->nullable();
    $table->timestamp('locked_at')->nullable();
    $table->text('last_error')->nullable();
    $table->timestamps();
});
```

Rollback correspondiente:

```php
Schema::dropIfExists('billing_outbox');
```

## Orden de eliminación por dependencias

En una base reconstruida, estas tablas deben eliminarse respetando sus claves
foráneas:

```php
Schema::dropIfExists('billing_subscription_links');
Schema::dropIfExists('billing_webhook_events');
Schema::dropIfExists('billing_outbox');
Schema::dropIfExists('subscription_changes');
Schema::dropIfExists('subscription_addons');
Schema::dropIfExists('subscription_items');
Schema::dropIfExists('subscriptions');
Schema::dropIfExists('plan_subscriptions');
```

`plan_subscriptions` sólo puede eliminarse después de cualquier otra tabla del
sistema que la referencie, como periodos de consumo, reservas o políticas de
excedentes.
