# Inventario v1 — entrega

## Alcance implementado

- Catálogo de insumos y categorías propias del Tenant, unidades y presentaciones convertibles.
- Excel XLSX: plantilla, exportación, previsualización e importación transaccional. Una fila por presentación. Importación de altas; no sobrescribe insumos existentes. Hasta 2,000 filas y 5 MB por archivo.
- Existencias por sucursal; inventario inicial, entradas, ajustes positivos/negativos y mermas con motivo.
- Recetas por unidad vendida, subrecetas reutilizables y recetas de producción con resultado inventariable.
- Producción descuenta componentes y aumenta el preparado; vender ese preparado no vuelve a consumir ingredientes.
- Vinculación desde la edición de producto: insumo directo o receta. Guardado independiente mediante «Guardar consumo».
- Venta confirmada consume cantidades físicas en su propia transacción; un reintento no vuelve a consumir.
- Operaciones y saldos decimales, historial con snapshots e idempotencia; recuperación del intento en la pestaña.
- Se permiten existencias negativas y se advierten en inventario y comprobante de venta.

La autorización reutiliza `allows_inventory`, `inventory.view/manage`, CurrentTenant y BranchAccessService. No cambia planes ni Stripe.
Mínimo e ideal siguen siendo parámetros del insumo comunes a sus sucursales. No se incluye valoración contable, lotes, caducidades, transferencias ni motor de promociones.

## Activación

No se aplicaron migraciones ni seeds a desarrollo. Desde `kova-backend`, con respaldo y publicación coordinada del frontend:

```sh
composer install
php artisan migrate --path=database/migrations/2026_09_28_140000_create_inventory_supplies.php
php artisan migrate --path=database/migrations/2026_09_28_150000_complete_inventory.php
php artisan db:seed --class=InventoryPermissionsSeeder
```

Laravel omitirá la primera migración si ya fue aplicada. El seeder añade permisos a Owner sin resembrar planes, precios ni SAT. Publicar el build de frontend con `npm run build` desde `kova-frontend`.

## Uso

1. Crear categorías e insumos manualmente o mediante plantilla Excel.
2. Registrar inventario inicial/entradas en cada sucursal.
3. Crear subrecetas, recetas o producción según el proceso.
4. Editar productos y guardar su consumo de inventario.
5. Registrar producción cuando aplique; vender desde Caja.
6. Revisar existencias, advertencias y movimientos.

## Validación

Pruebas de API y concurrencia con SQLite aislado, procesos separados y servicios reales. Pruebas de navegador con API simulada, móvil/escritorio y formularios de recetas. Build TypeScript/Vite y lint del módulo. No se hicieron pagos reales ni se modificaron ventas históricas.
