# Seguridad y multi-tenancy

## Contrato de una ruta operativa

Toda ruta que lea o modifique datos de negocio debe aplicar, en este orden:

1. `auth:sanctum`
2. `verified`
3. `tenant`
4. route model binding por `public_id`
5. Policy o permiso

`ResolveTenant` tiene prioridad sobre `SubstituteBindings`. Por eso el scope de
tenant está activo antes de resolver un modelo y un UUID ajeno responde igual que
uno inexistente: `404`.

## Reglas de implementación

- Los IDs enteros son internos. Las URLs, headers y respuestas usan UUID públicos.
- Ningún controlador operativo puede usar `withoutGlobalScope()` ni
  `withoutGlobalScopes()`.
- Los `FormRequest` no aceptan `tenant_id`, actores, permisos, estados protegidos
  ni totales calculados.
- Los recursos hijos se cargan desde el agregado padre ya autorizado.
- Las mutaciones críticas viven en Actions transaccionales y repiten invariantes
  de tenant.
- Jobs, listeners y comandos reciben el tenant explícitamente y ejecutan dentro
  de `CurrentTenant::run()`.
- Cada endpoint nuevo requiere una prueba negativa para otro tenant y otra para
  falta de permiso.
- Las acciones sensibles registran un `AuditEvent`; el registro es append-only y
  no admite contraseñas, PIN, cookies, tokens, certificados ni llaves.

## Cliente web

La SPA usa Sanctum mediante cookies `HttpOnly` y CSRF. No se emiten API tokens y
el modelo `User` no incluye `HasApiTokens`. El tenant activo existe solo en
memoria y Axios envía su UUID público en `X-Tenant-ID` únicamente a endpoints
operativos.

No se guardan sesión, usuario, tenant, permisos ni credenciales en
`localStorage`, `sessionStorage` o IndexedDB. Toda información que el backend
envía al cliente se considera visible para el usuario autenticado.

## Producción

El arranque falla si no están activas estas opciones:

- `APP_DEBUG=false`
- `SESSION_DRIVER=database`
- `SESSION_ENCRYPT=true`
- `SESSION_SECURE_COOKIE=true`
- `SESSION_HTTP_ONLY=true`
- `SESSION_SAME_SITE=lax`

Los orígenes de CORS y los dominios stateful se configuran mediante entorno. No
se permite `*` junto con credenciales.
