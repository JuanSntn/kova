# Suscripciones versionadas de Kova

## Contrato y catálogo

`plans` contiene únicamente identidad comercial. `plan_versions` fija beneficios mediante `plan_entitlements` y `features`. Cada `plan_price` identifica un importe entero en centavos, moneda y periodicidad. Una versión publicada no admite cambios en beneficios, precios ni addons disponibles; se publica otra versión. Retirar versiones impide nuevas contrataciones, sin afectar contratos existentes. Las definiciones de features ya utilizadas tampoco se reinterpretan.

`addons`, `addon_versions`, `addon_entitlements` y `addon_prices` siguen la misma regla. `plan_version_addons` fija la compatibilidad. El precio del timbre excedente no añade timbres gratuitos.

`plan_subscriptions` referencia la versión y precio contratados. La clave única nullable `current_tenant_id` permite un solo contrato vigente y conserva el historial. `subscription_addons` conserva las versiones y cantidades contratadas. `subscription_changes` fija la oferta elegida y fecha efectiva. Los antiguos campos de snapshot se conservan como evidencia, pero los permisos se calculan desde la versión inmutable y sus addons vigentes; `monthly_price_snapshot` es legado y no debe usarse para interpretar una periodicidad anual.

`TenantPlanService` produce una proyección compatible con el POS. No se debe guardar esa proyección en `plans`. Para reglas nuevas, usar `EntitlementService` / `CapacityService`, nunca leer límites del catálogo actual ni comparar códigos de planes. Los códigos de planes son datos; incorporar una feature nueva exige implementar su comportamiento, además de definirla en el catálogo.

## Cobro y períodos

Cada contrato pagado tiene un componente base mensual o anual y otro mensual de extras. `billing_subscription_links` los vincula con `subscriptions` / `subscription_items` de Cashier. Stripe confirma pagos y períodos; no decide qué beneficios concede una versión.

- MXN, cantidades en centavos, impuestos configurados mediante `STRIPE_TAX_RATE_MX_IVA`.
- Negocio: 10000 mensual / 100000 anual; Restaurante: 60000 / 600000.
- Sucursal extra: 35000 mensual, Restaurante; timbre excedente: 130 por unidad.
- El cupo mensual se calcula desde el aniversario original; enero 31 → febrero 28/29 → marzo 31. Free usa mes calendario en la zona del negocio. No hay acumulación de cupos.
- Los cambios de plan y periodicidad ocurren al renovar. Cambiar solo la periodicidad dentro de la misma versión conserva addons; cambiar de versión toma los addons automáticos de la oferta de destino y retira los recurrentes anteriores, solicitando las sucursales que se conservarán cuando corresponda.
- Un aumento de sucursales usa `pending_if_incomplete`, factura proporcional y mantiene el ancla mensual. La cantidad enviada es el **total deseado**, no un incremento. La reducción se programa al final del mes de extras.
- El primer impago de cualquier componente inicia siete días de gracia. Se consultan facturas abiertas para que pagar una factura no oculte otra deuda. Al vencer se bloquean escrituras operativas, conservando consulta y facturación.
- Cancelar preserva acceso hasta el fin contratado, coordina el cierre de extras y devuelve a Free; no elimina productos, usuarios, sucursales ni historial.

El portal de Stripe debe permitir pagos, datos del cliente e historial, con cambios y cancelaciones de suscripciones deshabilitados. La aplicación coordina ambos componentes; el gateway comprueba esa configuración antes de abrir el portal.

## API compatible

Se conservan `/api/billing`, `/checkout`, `/portal`, `/cancel`, `/reactivate`, `/invoices` y los campos existentes del resumen. Todos requieren autenticación y `X-Tenant-ID`; gestionar cambios requiere `billing.manage`.

- `POST /api/billing/checkout`: `plan`, `interval` (`month` por defecto, o `year`), `offer_id` opcional y `retained_branch_ids` si hace falta reducir sucursales. El servidor resuelve importes y objetos de Stripe.
- `GET /api/billing/offers`: catálogo y ofertas públicas; `available=false` si faltan vínculos de cobro.
- `GET /api/billing/changes`; `DELETE /api/billing/changes/{uuid}`: consultar/cancelar cambios pendientes.
- `POST /api/billing/addons`: `offer_id`, `quantity` total; devuelve estado e información de pago pendiente.
- `PATCH /api/billing/addons/{uuid}`: menor `quantity`, incluyendo cero, y `retained_branch_ids` si corresponde.
- `POST /api/billing/cancel`: admite `retained_branch_ids` para seleccionar sucursales al volver a Free.
- `POST /api/pos/orders`: admite `branch_id` público opcional. Con varias sucursales operativas debe seleccionarse una. Una sucursal deshabilitada comercialmente no puede emitir ventas.

El resumen añade `entitlements` (valores monetarios en centavos), `usage_period`, `usage`, `addons`, `billing_components`, `pending_changes`. Los campos monetarios legados dentro de `plans` siguen expresados en pesos para el frontend existente. Nunca se aceptan precios ni tenant internos enviados por el cliente.

## Consumo fiscal y trabajos

No existe un proveedor de emisión fiscal en este repositorio. Se entrega el punto de integración confiable:

```php
app(UsageService::class)->recordIssuedStamp($tenant, $uuidFiscalConfirmado, $fechaEmision);
```

Invocarlo solo después de una emisión fiscal exitosa, dentro del contexto del tenant. No exponerlo directamente al navegador ni invocarlo por emitir tickets del POS. El UUID fiscal identifica un solo consumo, incluso con reintentos. El saldo se serializa bajo bloqueo del tenant; únicamente el excedente crea un reporte en `billing_outbox`. Registrar el consumo inmediatamente evita reportes fuera de la ventana admitida por Stripe.

`billing_webhook_events` conserva resultados y errores. Los webhooks vuelven a consultar el estado actual de Stripe; ante fallos de red quedan pendientes de reintento en lugar de aplicar un evento antiguo. `billing_outbox` registra operaciones, resultados, claves de idempotencia y reintentos. Sus claves remotas incluyen el UUID del tenant para evitar colisiones al reconstruir bases de prueba.

`composer dev` inicia también el scheduler. Si ejecutas los procesos por separado, mantener el scheduler de Laravel activo:

```sh
php artisan schedule:work
```

Procesa pendientes cada minuto y reconcilia cada cinco minutos. La reconciliación recupera webhooks fallidos, checkouts terminados/expirados, períodos y acceso. También se puede ejecutar:

```sh
php artisan billing:process
php artisan billing:process --reconcile
```

Supervisar la salida/exit code, `billing_webhook_events.status=failed`, outbox pendientes con `last_error` y `reconciliation_required`. Un resultado remoto incierto de más de 23 horas se detiene para revisión: no se vuelve a enviar a ciegas después de caducar la protección de idempotencia de Stripe. Corroborar en Stripe los objetos y el resultado de la operación antes de cualquier intervención manual.

## Catálogo y despliegue

```sh
php artisan migrate --force
php artisan billing:catalog seed
php artisan billing:sync-prices --dry-run
php artisan billing:sync-prices --test
php artisan billing:configure-test-portal
```

El último comando devuelve `STRIPE_PORTAL_CONFIGURATION`. `BILLING_LIVEMODE=false` es el valor predeterminado. Los comandos de sincronización crean recursos nuevos y conservan los identificadores anteriores. En producción, configurar credenciales y modo reales, un portal restringido y ejecutar explícitamente `billing:sync-prices --live`; las pruebas realizadas aquí usan exclusivamente Stripe de prueba.

Para añadir un plan o addon:

```sh
php artisan billing:catalog draft plan negocio-plus --file=/ruta/oferta.json
php artisan billing:catalog validate plan UUID_VERSION
php artisan billing:catalog publish plan UUID_VERSION
php artisan billing:sync-prices --test
php artisan billing:catalog retire plan UUID_VERSION_ANTERIOR
```

El JSON incluye `name`, `entitlements` por código (booleanos, enteros, `null` para ilimitado), `prices` con claves `month`/`year` e importes en centavos, y `addons` con `version` UUID y `automatic`. Para addons: `mode=recurring|metered` y precio mensual. Definir todas las features al publicar planes. En esta implementación cada plan pagado debe definir su addon mensual medido automático para provisionar el componente de extras.

`billing:initialize-local-contracts` solo sirve para desarrollo local sin contratos Stripe; rechaza producción y contratos externos. No usar `migrate:fresh` sobre datos que se quieran conservar. El antiguo comando que liberaba schedules en masa está deshabilitado.

## Verificación y estado del entorno

```sh
php artisan test --compact
php tests/Support/stripe-smoke.php --test
```

La suite fuerza SQLite `:memory:` e ignora cachés de configuración/rutas. El smoke test usa una copia temporal de SQLite, crea clientes/suscripciones solo en Stripe de prueba y cancela sus suscripciones al finalizar. Los clientes de prueba pueden permanecer identificados mediante `kova_smoke_test`.

Durante la primera ejecución previa a esa protección, la configuración cacheada dirigió las pruebas a la SQLite local y esta quedó vacía. La copia disponible también estaba vacía. Se reconstruyó el catálogo de prueba, pero no usuarios ni datos operativos anteriores. El punto Git `417477d` permite volver al código, no recuperar una base SQLite ignorada por Git.

Verificación completada: 68 pruebas automatizadas (249 aserciones) y recorrido real de Stripe de prueba con plan anual, extras mensuales, prorrateo, reporte medido idempotente, rechazo/recuperación de pago, cambios programados, cancelación, reactivación y portal restringido.


## Pantalla de facturación

El cliente consulta ofertas públicas y envía el `offer_id` seleccionado con su
periodicidad. Compara la oferta contratada por identificador, no por nombre ni
por una jerarquía fija de planes. Las condiciones del contrato se muestran por
separado de las nuevas ofertas. Los importes nuevos de la API son centavos;
los campos compatibles `plan_price` y `monthly_price` siguen siendo pesos.

El resumen incluye `contract` también para Free, `capacity_usage`, ventas del
período, `branches` operativas y `available_addons` de la versión contratada,
aunque esté retirada comercialmente. Los cambios incluyen destino, fecha y
`confirmed`, que exige una operación de cobro completada. Las altas de addons
pendientes se exponen como `pending_addon_operations`, sin identificadores
internos ni mensajes de error del proveedor. Una redirección de Checkout no
se interpreta como pago confirmado.

La página permite seleccionar mensual/anual, contratar o reducir complementos,
cancelar cambios pendientes, cancelar al cierre y revertir esa cancelación.
Antes de reducir sucursales muestra cuáles conservar. El backend sigue validando
la selección, permisos, oferta y cantidades. Las altas envían la cantidad total
y una clave de idempotencia conservada al reintentar desde la confirmación.

El resumen y las facturas usan cachés separadas por tenant. Los estados en
proceso se consultan cada cinco segundos durante un máximo de dos minutos,
solo mientras la página está visible. Tres errores consecutivos detienen el
sondeo; se conservan los datos anteriores y «Actualizar estado» inicia otra
ventana. El foco y la reconexión no reinician esta ventana. El historial
conserva su paginación y se actualiza tras las operaciones. Solo billing.manage
habilita mutaciones; billing.view permite consultar.


Menú y Facturación comparten `SectionNav` y `SectionNavButton`, con navegación
subrayada y foco de teclado. Los errores de continuar la contratación permanecen
junto al botón. Los errores HTTP incluyen estado y referencia `X-Request-ID`
cuando está disponible; los fallos 5xx no muestran detalles internos.

CORS permite explícitamente `Idempotency-Key`, además de los encabezados de
sesión y tenant, únicamente desde el origen configurado en `FRONTEND_URL`.
Una sesión Checkout creada no implica pago ni activación. El webhook y el
procesador programado deben confirmar el pago y preparar extras antes de dar
acceso al contrato. Si el navegador bloquea el preflight, comprobar CORS antes
de atribuir el error a Stripe.
