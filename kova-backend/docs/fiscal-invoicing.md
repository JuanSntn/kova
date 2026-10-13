# Facturación fiscal de Kova

La integración fiscal se activa por ambiente. Los CSD, XML y acuses se guardan cifrados; `FISCAL_ENCRYPTION_KEY_V1` debe contener 32 bytes codificados con el prefijo `base64:` y debe conservarse en un gestor de secretos independiente de `APP_KEY`.

## Puesta en marcha en demo

1. Configura `FISCAL_ENABLED=true`, `FINKOK_ENVIRONMENT=development`, las credenciales reseller y la llave fiscal.
2. Ejecuta `php artisan migrate` y `php artisan db:seed --class=Database\\Seeders\\RoleSeeder`.
3. Importa la versión SAT aprobada con `php artisan sat:catalogs:sync --catalog-version=v10.16.20260903`.
4. Completa el perfil y carga el CSD desde **Configuración → Facturación fiscal**.
5. Usa **Validar con Finkok** para registrar o consultar al emisor de demo.
6. Mantén activos `php artisan schedule:work` y un worker de cola. `fiscal:process` se ejecuta cada minuto.

## Catálogos SAT

Kova importa la base SQLite publicada por `phpcfdi/resources-sat-catalogs`, una fuente técnica versionada derivada de los catálogos del SAT. El servidor necesita las extensiones PHP `bz2`, `pdo_sqlite` y `sqlite3` para ejecutar la importación.

`php artisan sat:catalogs:check` compara la versión activa con la última release disponible y no modifica datos. `php artisan sat:catalogs:status` muestra la versión activa, los conteos importados y la última versión detectada. La instalación siempre exige una release explícita mediante `--catalog-version`; Artisan reserva globalmente el nombre `--version`.

El importador obtiene el digest SHA-256 desde la API de releases de GitHub, comprueba la integridad de SQLite, valida esquema y conteos, e importa bajo un lock global. Una versión nueva sólo se activa después de completar esas comprobaciones. Los procesos de aplicación deben compartir el almacén de cache, preferentemente Redis, para que el lock también sea distribuido.

El scheduler ejecuta `sat:catalogs:check` diariamente. Cuando detecte una actualización, revísala y ejecuta manualmente el comando `sync` con la versión indicada. Nunca sincronices automáticamente `latest` en producción.

Para publicar el precio de excedente de $1.12 más IVA en una base que ya tiene el catálogo anterior, ejecuta `php artisan billing:publish-stamp-overage-offer`. Después sincroniza esa nueva oferta con Stripe en modo test mediante el comando existente de precios. El comando crea versiones nuevas; no modifica precios ni contratos históricos.

## Respaldo y restauración

`php artisan fiscal:backup` copia los blobs cifrados y genera un manifiesto con hashes SHA-256. Configura `FISCAL_BACKUP_DISK` en un bucket distinto con versionado y retención habilitados. Verifica una copia con `php artisan fiscal:backup --verify=backups/fiscal/<id>`.

Este respaldo cubre artefactos fiscales. La base de datos también debe usar respaldos automáticos del proveedor con restauración a un punto en el tiempo. Una restauración válida necesita la base, el backup fiscal y todas las versiones de `FISCAL_ENCRYPTION_KEY_V*` referenciadas por `fiscal_certificates`.

No elimines una llave anterior durante una rotación. Crea una versión nueva, cambia `FISCAL_ENCRYPTION_KEY_VERSION` y conserva las anteriores hasta volver a cifrar y verificar todos los documentos asociados.
