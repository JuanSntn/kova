# Caja y turnos v1

Implementado el 26 de septiembre de 2026. La aplicación conserva la autorización Tenant–Branch, las capacidades versionadas y el checkout existente.

## Activación local

Desde `kova-backend`, aplicar únicamente la migración nueva y el seeder aditivo de permisos:

```sh
php artisan migrate --path=database/migrations/2026_09_26_120000_create_cash_management_tables.php
php artisan db:seed --class=CashPermissionSeeder
```

Este seeder agrega `cash.view`, `cash.operate` y `cash.manage` a Owner; Cashier recibe los dos primeros. No revoca permisos ni crea/modifica planes, precios, catálogos SAT o recursos de Stripe. El RoleSeeder habitual también incluye los permisos para instalaciones nuevas, pero no hace falta ejecutarlo para este despliegue.

Backend y frontend deben publicarse juntos. Si se usa Vite en desarrollo, recargar el navegador; para producción ejecutar `npm run build` desde `kova-frontend` y publicar los artefactos mediante el procedimiento habitual. Renovar caches de rutas/configuración del despliegue si se utilizan.

Las migraciones y el seeder de este documento NO fueron ejecutados sobre la base de desarrollo durante la implementación.

## Primer uso

1. Owner entra a **Caja y turnos** desde el sidebar y crea una caja en la sucursal seleccionada.
2. El responsable abre su propio turno con el fondo inicial, que puede ser cero.
3. Cada venta nueva se vincula al turno propio. Efectivo recibido menos cambio tiene impacto neto igual al total de la venta; tarjeta y transferencia no aumentan efectivo.
4. Las entradas/salidas exigen importe positivo y motivo. No pueden editarse ni eliminarse.
5. Para cerrar, enviar primero el conteo físico por total o denominaciones.
6. Revisar diferencia y explicar faltantes/sobrantes. Confirmar el cierre definitivo.
7. El historial permanece en consulta. Si hubo actividad tras el conteo, se exige uno nuevo.

La capacidad de caja se deriva de `max_users > 1` o ilimitado, incluyendo addons vigentes, mediante `EntitlementService::allowsCash()`. No depende del nombre del plan ni de los empleados creados. Al estar habilitada, el turno es obligatorio para cobrar; Owner también necesita turno propio. Los contratos sin esa capacidad continúan usando POS sin caja, una vez cerrados los turnos pendientes.

## Implementación

- Migración: `kova-backend/database/migrations/2026_09_26_120000_create_cash_management_tables.php`.
- Dominio: `kova-backend/app/Services/Cash/CashService.php`.
- Serialización compartida con checkout: `kova-backend/app/Services/Pos/TenantWriteTransaction.php`.
- API: `kova-backend/app/Http/Controllers/Cash/CashController.php` y `kova-backend/routes/cash.php`.
- Permisos: `kova-backend/database/seeders/CashPermissionSeeder.php`.
- Pantalla, API y recuperación: `kova-frontend/src/features/cash/`.
- Ruta: `/$tenantSlug/cash`.

Las cuatro tablas nuevas son `cash_registers`, `cash_shifts`, `cash_movements` y `cash_operations`. Las órdenes históricas mantienen `cash_shift_id = null`.

Los conteos se conservan como operaciones inmutables `COUNT_SHIFT`, con UUID público y `activity_version`. Los slots únicos impiden un segundo turno abierto para la caja o para el usuario dentro del Tenant. SQLite añade triggers de integridad e inmutabilidad. Si cambia el motor SQL, validar sus garantías y las pruebas concurrentes antes de migrar.

## API

- `GET/POST /api/cash/registers`
- `PATCH /api/cash/registers/{register}`
- `GET /api/cash/context`
- `GET/POST /api/cash/shifts`
- `GET /api/cash/shifts/{shift}`
- `POST /api/cash/shifts/{shift}/movements`
- `POST /api/cash/shifts/{shift}/counts`
- `POST /api/cash/shifts/{shift}/close`

Apertura, movimiento, conteo y cierre requieren `Idempotency-Key` UUID. Las referencias de API son UUID públicos. El backend resuelve Tenant, pertenencia, permisos y Branch real; en recursos históricos no utiliza la selección actual como autoridad.

Los listados de turnos aceptan `register`, `responsible` (UUID de Membership), `status`, `business_date` y `page`. Un `X-Branch-ID` limita el listado a esa sucursal autorizada; sin él se consideran las sucursales históricas autorizadas. El frontend consulta el historial completo autorizado para que las sucursales bloqueadas sigan accesibles.

El cierre excepcional tras suspensión, downgrade o bloqueo de sucursal permite contar y cerrar, no operar nuevas ventas/movimientos. Un responsable suspendido no puede acceder; un Owner activo puede intervenir con motivo registrado.

## Recuperación

Se conserva el intento exacto en `sessionStorage` antes de enviar. Ante respuesta incierta, **Verificar operación** reenvía el mismo contenido y clave, recuperando la respuesta persistida. No debe repetirse con una clave nueva ni borrarse el almacenamiento mientras el resultado sea incierto.

El cierre desde la misma pestaña está bloqueado si queda una venta pendiente de verificar. Entre pestañas, la transacción y la versión de actividad protegen el resultado. Los reintentos de ventas confirmadas se resuelven antes de comprobar si el turno sigue abierto.

## Validación realizada

- Suite backend aislada: 218 pruebas, 1177 aserciones.
- Pruebas concurrentes en SQLite temporal y procesos independientes: apertura, movimiento, cierre, venta vs cierre, movimiento vs cierre y contención recuperable.
- Navegador con API simulada: caja y checkout, recarga, pérdida de respuesta, almacenamiento no disponible, conteo ciego, teclado y anchos 390/768/1440.
- Build TypeScript/Vite.
- ESLint de los archivos frontend modificados.

Las pruebas no usan la base de desarrollo ni realizan pagos. La validación de navegador usa respuestas simuladas; el dominio y las rutas reales se verifican con la suite backend. No se probaron dispositivos físicos. Vite conserva el aviso existente de bundle superior a 500 kB.

## Fuera de alcance

Devoluciones, pagos mixtos, cierre diario consolidado, propinas, mesas/comandas, transferencias entre cajas, dispositivos, impresión nueva y cobros bancarios automáticos. `cash_refunds_total` permanece en `0.00`.
