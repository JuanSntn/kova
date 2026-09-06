# Seguridad del cliente web

- Kova es una SPA de Vite; no usa Inertia, SSR ni React Server Components.
- Sanctum autentica mediante cookie de sesión `HttpOnly` y protección CSRF.
- El estado de sesión y el tenant activo viven solo en memoria.
- Ningún secreto puede declararse con prefijo `VITE_`; esas variables se incluyen
  públicamente en el bundle.
- Axios adjunta `X-Tenant-ID` solo a rutas operativas `/api/*`, nunca a rutas de
  autenticación.
- Las respuestas `401` y `419` limpian inmediatamente el contexto en memoria.
- React no debe usar `dangerouslySetInnerHTML`.

El cliente utiliza únicamente `@tanstack/react-router` en modo SPA con rutas
declaradas en código. La versión está fijada exactamente; no se instalan Router
Plugin, Devtools, TanStack Start, SSR, RSC ni generadores.

El lockfile debe permanecer libre del indicador `@tanstack/setup` y de
`router_init.js`, asociado a las versiones comprometidas publicadas en mayo de
2026. Cualquier actualización de TanStack requiere revisar el manifiesto,
integridad publicada y `npm audit` antes de modificar la versión fijada.
