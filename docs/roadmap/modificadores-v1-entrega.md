# Modificadores de menú v1

## Uso

1. En **Menú → Modificadores**, crear un grupo. Tamaño exige una opción; extras permite varias según la configuración del producto.
2. Crear opciones, por ejemplo Mediana/Grande o Queso extra/Sin cebolla.
3. En **Asignar a productos**, buscar un producto existente. Agregar sus grupos, mínimos, máximos e importes adicionales. El precio base sigue siendo el del producto y su sucursal.
4. Si se tiene inventario y permiso de administración, configurar el consumo por opción:
   - Conservar: mantener el consumo habitual del producto.
   - Agregar: sumar un insumo en su unidad base o una receta de venta.
   - Quitar: restar ese consumo, sin permitir cantidades resultantes negativas.
   - Tamaño: usar otra receta completa; no multiplicar automáticamente todos los ingredientes.
5. En **Caja**, agregar el producto abre la selección. Dos configuraciones distintas generan líneas distintas. La cotización del backend confirma los importes.

Los grupos pertenecen al negocio y son reutilizables. Los precios y efectos de inventario se configuran por producto. Los consumos se registran en la sucursal de la venta. Los preparados siguen descontándose como insumos, sin descontar nuevamente su producción.

Cambiar grupos, precios o recetas afecta ventas futuras. Las ventas existentes conservan nombres, importes y consumo registrado. Una cotización que haya cambiado exige otra confirmación.

## Seguridad y consistencia

- Autenticación y Membership activa mediante middleware existente.
- Consulta administrativa: `products.view`. Escritura: `products.update` y contrato writable. Efectos de inventario: `inventory.manage` y `allows_inventory`.
- Sin comprobaciones por nombre de plan ni modificaciones al catálogo comercial.
- UUID en rutas y referencias públicas, siempre resuelto dentro del Tenant. Caja utiliza la autorización Branch existente.
- Opciones verificadas contra el grupo y la asignación del producto. El cliente sólo envía UUID seleccionados; precios e inventario se resuelven en servidor.
- Rechazo de duplicados, mínimos/máximos inválidos, objetivos ajenos y precios negativos.
- Un grupo de tamaño por producto; una selección obligatoria.
- Bloqueo de escritura del Tenant compartido con checkout. Snapshots dentro de la misma transacción de la venta y consumo.
- Reintentos de ventas completadas conservan folio y snapshots; no vuelven a consumir stock.
- Archivar un grupo lo retira de ventas nuevas. Para quitar opciones de un grupo asignado hay que desvincularlo primero; renombrar conserva sus UUID.

## Despliegue

Publicar backend y frontend coordinadamente. Ejecutar desde `kova-backend`:

```sh
php artisan migrate
```

Migración aditiva: `2026_09_29_230000_create_menu_modifiers.php`. Crea grupos/asignaciones y añade snapshots nullable a `order_items`. No requiere seeds ni modifica planes, precios o SAT. No se ejecutó contra la base de desarrollo durante esta entrega.

Después compilar el frontend con `npm run build`. Las ventas históricas conservan sus columnas y snapshots existentes.

## Verificación

- Suite backend aislada SQLite: permisos, Tenant/Branch, UUID inválidos, opciones no asignadas, manipulación, cálculos, reconfirmación y recuperación.
- Regresiones de inventario, fiscal, caja, billing y checkout.
- Prueba con procesos independientes y SQLite temporal: misma clave + modificadores produce una sola venta.
- Navegador con API simulada: grupo/asignación, selección obligatoria, líneas separadas, recarga, contexto de cotización, móvil 390 px y escritorio 1440 px.
- Las pruebas de navegador no reemplazan las pruebas HTTP reales de backend ni constituyen una auditoría exhaustiva de seguridad.

No incorpora promociones, descuentos, combos, SKU por tamaño, mesas ni devoluciones.
