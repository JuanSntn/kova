# Base de datos — Fase 0

## Tablas centrales

### `plans`

Contiene precio, límites y capacidades. Un límite `null` significa ilimitado.
Las capacidades no se codifican mediante condicionales por nombre del plan.

### `tenants`

Representa un restaurante o negocio. `public_id` es el identificador expuesto
por API; `id` se reserva para relaciones internas.

### `subscriptions`

Pertenece a un tenant y un plan. Conserva:

- `monthly_price_snapshot`
- `entitlements_snapshot`
- Inicio y fin del periodo

Cambiar un plan no modifica retroactivamente las condiciones del periodo
contratado.

### `branches`

Es tenant-owned y está protegida por el scope global. `code` es único dentro
del tenant, no globalmente.

### `memberships`

Relaciona identidades globales con tenants. Los roles no se guardan aquí:
Spatie los asigna al usuario usando `tenant_id` como equipo.

## Reglas para migraciones posteriores

Todas las tablas operativas deben:

- Tener `tenant_id` indexado.
- Incluir `tenant_id` en restricciones únicas de negocio.
- Usar índices compuestos comenzando con `tenant_id` para consultas habituales.
- Usar strings respaldados por enums de PHP para estados cambiantes.
- Evitar enums nativos de MySQL.
- Usar UUID únicamente para identificadores públicos o tokens.
- Usar decimales exactos para importes y cantidades.

## Decisiones ya tomadas para ventas y facturación

Las relaciones futuras serán:

```text
orders 1 ─── N payments
orders 1 ─── 0..1 tickets
tickets 1 ─── 0..1 invoices
invoices 1 ─── N invoice_attempts
```

- `payments.order_id` será obligatorio.
- `payments` no tendrá `ticket_id`.
- `tickets.order_id` será único.
- El ticket se crea al completar el pago, en la misma transacción.
- `invoices.ticket_id` será único.
- Los reintentos actualizarán la misma factura lógica y cada comunicación con
  el PAC se conservará en `invoice_attempts`.

Las órdenes separarán tres dimensiones:

- Ciclo de vida.
- Estado de pago.
- Estado de preparación/entrega.

## Menú y sucursales

`categories` y `products` tendrán `tenant_id`, pero no `branch_id`. La
configuración por sucursal se agregará en `branch_products`, con una restricción
única sobre `(branch_id, product_id)`.
