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
