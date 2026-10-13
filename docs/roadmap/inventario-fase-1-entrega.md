# Inventario — Fase 1

Implementación: ruta /$tenantSlug/inventory, listado paginado, búsqueda, alta y edición manual.

## Datos y alcance

- Catálogo del Tenant, independiente de productos y categorías de venta.
- Nombre, categoría de inventario (texto), unidad base, mínimo/ideal opcionales e indicador de producción.
- Mínimo e ideal se guardan como parámetros del catálogo en unidad base; no representan existencias. La fase 2 deberá establecer configuración por sucursal.
- Varias presentaciones, cantidad, unidad compatible y conversión autoritativa decimal.
- Proveedor y código como referencia textual por presentación: no se crea un módulo de proveedores.
- Precio de compra sin impuesto y referencia opcional a un Tax activo del Tenant. No se calcula valuación, IVA fronterizo ni contabilidad de compras.
- Hasta seis decimales en cantidades. Piezas admite cantidades decimales en esta fase; cualquier restricción adicional requiere decisión de producto.
- No se registran movimientos ni ventas, y no se implementa Excel en esta fase.

## Autoridad y acceso

Backend usa allows_inventory existente y contrato writable. Permisos inventory.view / inventory.manage; Owner recibe ambos. No modifica versiones ni precios.

GET /api/inventory/supplies (search y page)
POST /api/inventory/supplies
PUT /api/inventory/supplies/{uuid}

Edición con versión optimista: si otro operador guardó antes, responde 409. UUID de alta estable durante reintentos; un alta ya registrada no crea un duplicado con el mismo UUID.

## Despliegue

No se ejecutaron migraciones ni seeds contra la base de desarrollo durante esta implementación.

Desde kova-backend:

    php artisan migrate --path=database/migrations/2026_09_28_140000_create_inventory_supplies.php
    php artisan db:seed --class=InventoryPermissionsSeeder

El seeder añade únicamente los permisos de inventario a Owner y conserva otros permisos.
Publicar backend y frontend coordinadamente. Si hay cachés de configuración/rutas de despliegue, regenerarlas con el proceso habitual.

## Validación

Pruebas API con SQLite :memory: y pruebas de navegador con endpoints simulados. Ninguna modifica datos comerciales reales.
