# Arquitectura multi-tenant

## Decisión

Kova utiliza una sola base de datos con aislamiento por fila. Los usuarios son
identidades globales y su acceso a cada negocio se representa mediante
`memberships`.

Esta decisión permite que una persona acceda a más de un tenant con el mismo
correo, pero conserve roles independientes en cada uno.

## Identificación del tenant

Las rutas privadas que acceden a datos de negocio deben usar, en este orden:

1. `auth:sanctum`
2. `tenant`

El cliente envía el UUID público del negocio:

```http
X-Tenant-ID: 00000000-0000-0000-0000-000000000000
```

`ResolveTenant` valida que:

- El usuario esté autenticado.
- El tenant exista y esté activo.
- Exista una membresía activa para ese usuario y tenant.

Después configura `CurrentTenant` y el identificador de equipo de
`spatie/laravel-permission`. Ambos contextos se limpian en un bloque `finally`
para evitar fugas entre solicitudes en procesos de larga duración.

## Aislamiento de modelos

Todo modelo operativo con `tenant_id` debe usar `BelongsToTenant`.

El trait:

- Agrega automáticamente el filtro por `tenant_id`.
- Devuelve cero filas si no existe un contexto activo.
- Asigna `tenant_id` automáticamente al crear.
- Rechaza una escritura si el `tenant_id` explícito no coincide con el contexto.

Los modelos centrales `Plan`, `Tenant`, `User` y `Membership` no utilizan el
scope. Solo la infraestructura de autenticación y aprovisionamiento debe
consultarlos directamente.

Para tareas administrativas o procesos programados, cada operación debe
ejecutarse explícitamente con:

```php
app(CurrentTenant::class)->run($tenant, function () {
    // Consultas y escrituras del tenant.
});
```

Nunca se debe usar `withoutGlobalScopes()` dentro de controladores de negocio.

## Roles

Spatie Permission funciona con `teams=true` y `tenant_id` como clave de equipo.
Los nombres de rol son globales y fijos:

- `owner`
- `cashier`
- `waiter`
- `kitchen`
- `bar`

La asignación se almacena con el `tenant_id` activo. Antes de consultar o
asignar roles debe estar configurado el contexto del tenant.

## Aprovisionamiento

`CreateTenant` crea en una sola transacción:

1. Tenant.
2. Suscripción con fotografía del precio y beneficios.
3. Sucursal principal.
4. Membresía activa del propietario.
5. Rol `owner` dentro del tenant.

Si cualquier paso falla, no queda un negocio parcialmente creado.

## Procesos asíncronos

Los jobs, listeners y comandos futuros que accedan a datos operativos deben
transportar el `tenant_id`, resolver el tenant y ejecutar su trabajo dentro de
`CurrentTenant::run`. Nunca deben depender del tenant de una solicitud anterior.

## Identificadores

- Las relaciones internas usan enteros autoincrementales.
- Los tenants y sucursales exponen `public_id` UUID.
- Los clientes nunca deben seleccionar un tenant mediante su ID interno.
