# Inventario: organización y uso

## Qué se administra en cada lugar

- **Menú:** productos que vendes, sus precios y categorías de venta.
- **Insumos:** materiales o artículos que cuentas: harina, aceite, refrescos y preparados. Aquí defines unidad base y presentaciones de compra.
- **Categorías:** agrupaciones de insumos para encontrarlos y contarlos; son independientes de las categorías del Menú.
- **Recetas:** ingredientes necesarios por unidad vendida. Las subrecetas agrupan componentes; las recetas de producción describen un preparado que se almacena.
- **Productos del menú:** lista los productos existentes y permite asignarles receta, insumo directo o ningún consumo. No crea otro catálogo de productos.
- **Existencias:** saldo calculado por sucursal. Por ahora, mínimo e ideal se configuran en el insumo para todo el negocio; la configuración independiente por sucursal está pendiente.
- **Movimientos:** historial de entradas, salidas, mermas, producción, ventas y conteos. Recibir mercancía se registra como entrada.
- **Ajuste:** captura física de lo que realmente hay; el servidor registra la diferencia contra el saldo actual. Se usa para conciliar un conteo, no como sustituto de registrar cada compra.
- **Producción:** registra lotes preparados, descuenta ingredientes y agrega el preparado. Marcar un insumo como producido no lo fabrica automáticamente.
- **Excel:** carga y exporta el catálogo de insumos y presentaciones; no representa un conteo físico ni importa recetas.

## Preparar el negocio
1. Registrar los insumos y sus unidades/presentaciones.
2. Capturar el inventario inicial de cada sucursal y registrar las entradas posteriores.
3. Crear recetas para los productos preparados.
4. Crear los productos de venta en Menú.
5. En Productos del menú, configurar el consumo de cada producto.
6. Registrar producción cuando se preparan existencias por lotes. Al vender, se descuenta el preparado sin volver a descontar sus ingredientes.
7. Revisar existencias y hacer conteos físicos periódicos en Ajuste.

## Ejemplos de vinculación
- Refresco individual → insumo Refresco → 1 pz por venta.
- Hamburguesa → receta Hamburguesa → 1 receta por venta.
- Pizza que utiliza masa preparada → receta Pizza con masa como insumo. La producción de masa consume harina al preparar el lote.
- Producto sin seguimiento → sin control de inventario. No descuenta existencias.

Las asignaciones son del negocio y se aplican a ventas futuras. El stock se descuenta en la sucursal de la orden. Los precios continúan administrándose en Menú; asignar una receta no recalcula precios ni cambia ventas históricas.

## Flujo canónico: Pizza pepperoni

No se empieza por el producto del Menú. El producto es lo que se cobra; antes debe estar definida la forma en que consume inventario. El orden recomendado es el siguiente.

1. **Elegir la sucursal que operará.** En este ejemplo, Querétaro. El catálogo será compartido por todo el negocio, pero cada entrada, merma, venta y conteo afectará únicamente a Querétaro.
2. **Crear los insumos del catálogo.** Harina para pizza (g), queso mozzarella (g), salsa de tomate (g), pepperoni (g) y, si se quiere controlar, caja de pizza (pz). La unidad base debe ser la unidad en la que se cuenta y se descuenta: gramos para ingredientes, piezas para cajas.
3. **Dar de alta el inventario inicial de Querétaro.** Por ejemplo: 4,000 g de harina, 2,500 g de queso, 1,500 g de salsa y 1,200 g de pepperoni. Después del inventario inicial, cada compra se registra como **Entrada**; no se vuelve a usar inventario inicial para ese insumo en esa sucursal.
4. **Crear la receta de venta `Pizza pepperoni`.** Su rendimiento es siempre 1 pz y sus componentes son: 250 g de harina o masa, 150 g de queso, 80 g de salsa y 60 g de pepperoni. Si la masa se prepara por lote, primero se crea una receta de producción que genere el insumo `Masa`; la receta de pizza consume esa masa, no vuelve a consumir harina.
5. **Crear el producto `Pizza pepperoni` en Menú.** Ahí se define categoría, precio y disponibilidad para venta.
6. **Vincular el producto a la receta.** En Inventario → Productos del menú se elige `Receta`, se selecciona `Pizza pepperoni` y se guarda `1 receta por unidad vendida`.
7. **Cobrar.** Al cobrar 2 pizzas en Querétaro, Kova conserva una copia de la receta usada por cada pizza y descuenta 500 g de harina, 300 g de queso, 160 g de salsa y 120 g de pepperoni de Querétaro. No descuenta nada de Toluca.
8. **Contar.** En Ajuste se escribe la cantidad física encontrada, no la diferencia. Si el sistema marca 3,500 g de harina y físicamente hay 3,490 g, se captura `3490`; Kova crea un movimiento de conteo por `-10 g` y deja el saldo en `3490 g`. Si una venta ocurrió mientras se contaba, el servidor rechaza el registro para no sobrescribir actividad nueva.

Un refresco en botella sigue un camino más corto: se crea como producto de Menú y se vincula directamente al insumo `Refresco`, con `1 pz por venta`. No necesita receta.

Las unidades de pieza sólo admiten números enteros. Por ejemplo, una caja puede registrarse como `1` o `24 pz`; leche, salsa y otros insumos de volumen o peso pueden usar decimales, como `1.5 L`.

## Qué se comprobó con la simulación

La prueba automatizada crea los cuatro ingredientes, la receta y el producto Pizza pepperoni; registra inventario inicial, cobra dos pizzas, abre una segunda sucursal y realiza un conteo físico. El resultado esperado se cumple:

- Querétaro queda con 3,500 g de harina, 2,200 g de queso, 1,340 g de salsa y 1,080 g de pepperoni.
- La segunda sucursal inicia con 0 de cada insumo; no recibe el descuento de Querétaro.
- Un conteo de 3,490 g contra 3,500 g registrados genera el movimiento de corrección de `-10 g`.
- La venta conserva el consumo que tenía la receta al cobrar, por lo que una edición posterior de la receta no cambia la historia.

## Hallazgos que deben resolverse

1. **No hay transferencia entre sucursales.** Hoy no existe una operación que retire stock de Querétaro y lo reciba en Toluca como un único movimiento trazable. No se debe usar una salida y una entrada manual como proceso oficial: pierde el vínculo entre origen, destino y responsable. Antes de operar inventario compartido entre sucursales hace falta crear `Transferencias`.
2. **Mínimo e ideal siguen siendo globales al negocio.** Las existencias son por sucursal, pero el mínimo e ideal de un insumo se guardan una sola vez en el catálogo. Una pizzería no puede pedir 20 kg de harina para Querétaro y 8 kg para Toluca. La siguiente corrección del modelo debe mover esos niveles a la configuración de cada sucursal.
3. **Crear un producto no conduce a configurar su consumo.** La lógica funciona, pero quien crea `Pizza pepperoni` en Menú debe descubrir después Inventario → Recetas y Productos del menú. Hace falta una acción visible de “Configurar inventario” al guardar o editar el producto, con enlace directo a la receta o a su vinculación.
4. **El conteo es por renglón, no una sesión completa.** Cada confirmación guarda de inmediato una corrección independiente. Sirve para conteos puntuales, pero un inventario físico grande necesita una sesión con fecha, responsable, avance, revisión y cierre para saber qué quedó pendiente.

La existencia negativa se permite deliberadamente hoy para no impedir una venta. Kova la advierte en existencias y en el reporte. Antes de activar inventario en operación real hay que decidir si se conserva esa política o si se bloquea la venta cuando no hay stock suficiente.

## Validación técnica

La simulación vive en `InventoryWorkflowTest::test_pizza_pepperoni_flow_consumes_only_the_selling_branch_and_reconciles_a_count`. Se ejecuta junto con las protecciones de inventario y modificadores mediante:

```bash
php artisan test tests/Feature/Inventory/InventoryWorkflowTest.php tests/Feature/Inventory/InventorySecurityTest.php tests/Feature/Pos/ModifiersTest.php
```

## Acceso

Se conservan la capacidad `allows_inventory`, los permisos `inventory.view/manage` y las comprobaciones del contrato en backend. Ninguna regla depende del nombre del plan. El listado no otorga acceso adicional a modificar productos o precios.

## Referencias consultadas

- Restaurant365, [Classic Menu Item Record](https://docs.restaurant365.com/docs/classic-menu-item-record): vinculación entre menú y recetas para consumo teórico.
- Restaurant365, [Recipes](https://docs.restaurant365.com/docs/recipe-items-page): composición de recetas con ingredientes y subrecetas.
- Loyverse, [How to Create a Composite Item](https://help.loyverse.com/help/how-create-composite-item): configurar componentes antes de asociarlos al artículo vendido.

Estas referencias orientan la organización. La implementación respeta el modelo de Kova y no incorpora los módulos de costos o contabilidad de esos proveedores.
