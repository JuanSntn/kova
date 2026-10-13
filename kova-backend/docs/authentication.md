# Autenticación y verificación de correo

## Flujo

1. El usuario registra su nombre, negocio, correo y contraseña.
2. Kova crea, en una transacción:
   - Usuario global sin verificar.
   - Tenant en el plan Free.
   - Suscripción.
   - Sucursal principal.
   - Membresía activa.
   - Rol `owner` dentro del tenant.
3. Se envía un enlace firmado con vigencia de 60 minutos.
4. El usuario abre el enlace y Laravel asigna `email_verified_at`.
5. Solo después puede iniciar sesión.

Una contraseña correcta no es suficiente cuando el correo sigue pendiente. El
endpoint de login responde `403` con:

```json
{
  "code": "email_unverified"
}
```

## Endpoints

```text
POST /api/auth/register
POST /api/auth/login
POST /api/auth/logout
GET  /api/auth/me
POST /api/auth/email/verification-notification
GET  /api/auth/email/verification-status
GET  /api/auth/email/verify/{id}/{hash}
```

Los endpoints de sesión utilizan cookies HttpOnly de Laravel Sanctum. El
frontend debe solicitar primero `/sanctum/csrf-cookie` y enviar credenciales.

## Correo local

El entorno local usa:

```dotenv
MAIL_MAILER=log
```

Por ello el mensaje y el enlace de verificación aparecen en:

```text
storage/logs/laravel.log
```

Para recibir correos reales se debe cambiar el mailer a `smtp` y configurar
`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` y
`MAIL_FROM_ADDRESS`.

Nunca se deben versionar credenciales SMTP.

## URLs locales

Backend:

```dotenv
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
```

Frontend:

```dotenv
VITE_API_URL=http://localhost:8000
```

El dominio y puerto del frontend deben estar incluidos en
`SANCTUM_STATEFUL_DOMAINS` cuando cambien.

## Controles de seguridad

- Contraseña mínima de ocho caracteres, mayúscula, minúscula y número.
- Correos normalizados a minúsculas.
- Login y registro limitados por frecuencia.
- Reenvío de verificación limitado por frecuencia y con respuesta genérica.
- Enlaces de verificación temporales y firmados.
- Regeneración del ID de sesión después del login.
- Invalidación de sesión y token CSRF al cerrar sesión.
- Rutas privadas protegidas con `auth:sanctum` y `verified`.

## Seguimiento y reenvío

El registro y el login con contraseña correcta de una cuenta pendiente guardan
un contexto de verificación en la sesión del servidor durante una hora y regeneran
su identificador. Este contexto no autentica al usuario ni concede acceso al negocio.
El cliente debe enviar Origin/Referer de un dominio stateful y las cookies de Sanctum.

`GET /api/auth/email/verification-status` devuelve `pending`, `verified` o
`unavailable`. Solo consulta el usuario asociado a ese contexto; ignora correos,
identificadores y estados enviados por el cliente. No devuelve enlaces firmados.
La respuesta lleva `Cache-Control: no-store, private` y puede incluir `email`,
`retry_after` y el mensaje del último intento. Al expirar, se recupera el seguimiento
iniciando sesión con la contraseña, sin iniciar una sesión autenticada hasta verificar.

La pantalla consulta cada cinco segundos mientras está visible y al recuperar el
foco. Al detectar `verified`, muestra la confirmación y permite iniciar sesión.
Abrir directamente `/email-verified` no confirma ninguna cuenta. En otro navegador,
sin contexto previo, esa pantalla invita a iniciar sesión para comprobar el acceso.

El reenvío conserva su entrada `email` y la respuesta genérica para visitantes sin
prueba de identidad. Para la cuenta del contexto devuelve una confirmación precisa:
`local` cuando el mailer es log/array, `submitted` cuando se completa la llamada al
transporte o `failed` con HTTP 503 si falla. Un envío aceptado por el transporte no
prueba entrega en la bandeja; rebotes y spam requieren comprobar el proveedor.
No configurar transportes de respaldo a log/array para correo real.

Hay una espera de 60 segundos por cuenta, compartida entre IPs/sesiones, protegida
por un bloqueo de caché. En despliegues con varias instancias, usar caché compartida
(database o Redis). El endpoint también limita tres solicitudes por minuto e IP.
La consulta tiene su propio límite de 30 por minuto, separado del reenvío y login.
El registro conserva la cuenta si falla el envío, informa del fallo y permite reintentar.

Las pruebas usan base en memoria y notificaciones simuladas; no demuestran entrega
SMTP real. Alterar `verified`, `email_verified_at`, una respuesta HTTP o una ruta de
React no modifica el usuario en la base ni omite `auth:sanctum`/`verified`. La
verificación solo ocurre con hash correcto y firma temporal válida del servidor.

## Recuperación de contraseña

`POST /api/auth/forgot-password` recibe `email`, normaliza espacios y mayúsculas,
y responde siempre con el mismo mensaje para correo existente, inexistente,
limitado o con fallo de transporte. Incluye `retry_after: 60` y `local_delivery`
según el transporte configurado. Limita tres solicitudes por minuto e IP.

`POST /api/auth/reset-password` recibe `email`, `token`, `password` y
`password_confirmation`. Limita cinco intentos por minuto e IP, separados de
la solicitud y del login. El broker de Laravel guarda solo un hash del token,
con caducidad de 60 minutos y uso único. Las operaciones de solicitud y consumo
bloquean la fila del usuario en una transacción. No cambian email_verified_at.

El correo en español abre `/reset-password#token=...&email=...` en FRONTEND_URL.
El fragmento no se transmite en la petición HTTP al servidor del frontend; la
pantalla lo envía únicamente al backend al guardar la contraseña. Tras el éxito
se elimina de la barra de direcciones y se ofrece volver al login sin autenticar.
Los enlaces inválidos, usados y vencidos muestran el mismo error y permiten
solicitar uno nuevo. No añadir analítica ni grabación de sesión a estas pantallas.

Al restablecer se rotan remember_token, se eliminan sesiones de la base de datos
y se invalida la sesión solicitante. Sanctum comprueba además la contraseña de
las sesiones autenticadas. Los contextos pendientes de verificación incluyen
una huella HMAC de la contraseña almacenada con APP_KEY; una huella ausente o
antigua invalida el contexto sin autenticar a nadie.

### Activar correo real posteriormente

Mantener MAIL_MAILER=log durante el desarrollo. Ese transporte escribe el correo
completo (incluido su enlace sensible) en storage/logs/laravel.log, únicamente
para pruebas locales. No exponer, compartir ni conservar esos logs en producción.
El código de recuperación solo registra el tipo de fallo de transporte, nunca
su mensaje o traza, que podrían incluir secretos.

Cuando haya proveedor: autorizar el remitente, configurar MAIL_* según .env.example,
completar los registros DNS exigidos por el proveedor si se usa dominio propio,
y ejecutar `php artisan config:clear` para aplicar cambios. Reiniciar procesos
persistentes tras modificar configuración. Usar TLS con validación de certificados
y evitar fallback a log/array en producción.

Verificar registro, reenvío y recuperación con una dirección de prueba autorizada,
comprobar entrega en el buzón y revisar eventos de rechazo del proveedor. La
respuesta del transporte no garantiza entrega. Esta integración no activa ni
contrata un proveedor; el envío real queda pendiente de esa comprobación.
