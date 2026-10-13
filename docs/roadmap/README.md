# Funciones futuras de Kova

Fecha: 28 de septiembre de 2026.
Estado: documentación para desarrollo futuro; no implica funcionalidad implementada.

## Propósito

Conservar el alcance solicitado para promociones, descuentos, mesas, cocina e inventario. Antes de implementar cada fase se revisará el repositorio vigente para extender sus servicios, permisos y componentes, sin reconstruir módulos existentes.

La pantalla de punto de venta se llama **Caja**; su ruta actual permanece en /pos. **Caja y turnos** controla apertura, movimientos, conteo y cierre.

## Documentos

- **[Inventarios y Recetas: especificación vigente v1](inventario-especificacion-v1.md).** Requerimiento actualizado del usuario. Prevalece sobre las propuestas anteriores cuando exista contradicción. Define cinco fases y tres formularios separados: Receta, Receta de producción y Subreceta.

- [Inventario: requisitos completos del usuario](inventario-requisitos.md). Conserva íntegramente el documento recibido, incluidos campos, ejemplos y comportamiento de formularios.
- [Inventario: integración y decisiones pendientes](inventario-integracion.md).
- Esta hoja de ruta reúne las funciones conversadas y sus dependencias.

## Invariantes de integración

- Tenant representa el negocio; Branch representa la sucursal operativa.
- Mantener los catálogos del negocio y resolver existencias y operaciones por sucursal. La distribución concreta de tablas se definirá tras revisar el código.
- Reutilizar CurrentTenant, CurrentBranch, acceso a sucursales, permisos y membresías.
- Las capacidades comerciales se resolverán mediante PlanSubscription, PlanVersion, addons, EntitlementService y CapacityService. No introducir condiciones por nombre del plan.
- No se ha decidido qué versiones de planes incluirán cada función nueva.
- Backend valida disponibilidad, reglas, precios, impuestos y operaciones. Frontend sólo representa resultados y acciones autorizadas.
- Extender el checkout existente: cotización, reconfirmación, idempotencia, snapshots y recuperación.
- Una respuesta perdida nunca debe duplicar venta, descuento, producción o consumo.
- Conservar operaciones históricas. Editar una regla o receta no modifica ventas o movimientos anteriores.
- Usar aritmética decimal para cantidades e importes, con redondeos explícitos.
- Las pruebas serán aisladas; no usar la base de desarrollo ni recursos reales de Stripe.

## 1. Promociones

### Función solicitada

Un apartado para configurar promociones y seleccionar los productos participantes. Caja mostrará las promociones vigentes y aplicará el beneficio automáticamente cuando la compra cumpla las condiciones.

Primer caso solicitado: **martes 2×1 en cerveza**.

### Alcance inicial propuesto

- Nombre de promoción.
- Tipo 2×1.
- Productos participantes.
- Sucursales participantes.
- Días de la semana.
- Horario completo o intervalo.
- Vigencia inicial y fin opcional.
- Activación y desactivación.

Backend evalúa día y horario con la zona horaria de la sucursal. La interfaz muestra una etiqueta discreta, por ejemplo “2×1 hoy”, y una línea de descuento en el resumen.

Ejemplo con cerveza de $50:

- Una unidad: $50, sin bonificación.
- Dos unidades: importe original $100, descuento $50, total $50.
- Tres unidades: un par bonificado y una unidad a precio normal; total $100.

La venta guarda regla aplicada, unidades, importe original, descuento e importe final. Modificar o desactivar la promoción no altera esos snapshots.

La cotización debe incluir las condiciones comerciales aplicadas. Si cambian antes de confirmar, el backend exige reconfirmación según el mecanismo existente.

### Decisiones por cerrar antes de implementar

- Sólo pares del mismo producto o combinaciones entre productos participantes.
- Para combinaciones: propuesta de bonificar la unidad de menor precio.
- Acumulación con otras promociones y descuentos manuales. Propuesta inicial: no acumular sobre las mismas unidades.
- Tratamiento de cantidades fraccionarias, topes por compra y promociones que cruzan medianoche.
- Permisos para administrar y omitir promociones.
- Distribución del descuento por concepto y tratamiento fiscal, incluyendo impuestos diferentes. Debe definirse antes de emitir tickets/CFDI con promociones.
- Reglas aplicables a cuentas de mesa abiertas cuando comienza o termina una promoción.

### Integración con caja e inventario

El turno recibe sólo el importe efectivamente cobrado en efectivo. Tarjeta y transferencia mantienen su clasificación actual.

En un 2×1 se entregan y consumen dos unidades, aunque sólo se cobre una. El descuento comercial nunca reduce las cantidades físicas consumidas.

### Extensiones posteriores

Porcentajes, descuentos de importe fijo, combos y otras reglas. No quedan autorizados como comportamiento implícito del primer 2×1.

## 2. Descuentos manuales

Función futura propuesta: descuento autorizado por el operador, inicialmente sobre la venta completa.

- Permiso específico, motivo y límites validados por backend.
- Mostrar claramente importe original, descuento y total.
- Incluirlo en cotización y confirmación; conservar snapshots y autor.
- Caja contabiliza lo realmente cobrado.
- Definir distribución fiscal por línea antes de implementación.

Pendientes: importe fijo, porcentaje o ambos; máximo autorizado; necesidad de aprobación; relación con promociones; capacidad comercial del módulo.

Este cálculo puede compartir infraestructura con promociones, sin convertirlas en reglas editables por el cliente.

## 3. Mesas y cuentas abiertas

Función solicitada: operar pedidos por mesa.

Propuesta de flujo:

1. Abrir cuenta asociada a una mesa y sucursal.
2. Agregar o modificar productos según permisos.
3. Mantener el pedido abierto sin registrar ingresos de caja.
4. Cobrar mediante el checkout existente y el turno propio del cajero.
5. Conservar el pedido y su relación con la venta confirmada.

Separar pedido operativo de venta cobrada. El mesero no utiliza un turno ajeno para cobrar.

Pendientes: permisos, estados, edición concurrente, anulación de productos, traslado de mesa, cierre de cuentas y política de precios al cobrar. Pagos divididos o mixtos no están definidos para esta primera fase.

## 4. Comandas y cocina

Función futura propuesta sobre pedidos abiertos:

- Enviar productos a preparación.
- Registrar estados y responsables de preparación.
- Mostrar trabajo pendiente a cocina según permisos y sucursales.
- Preparación independiente de que la cuenta ya esté pagada.

Antes de implementar: definir estados, reenvíos, cambios/cancelaciones y momento de consumo de ingredientes. No asumir que cancelar una línea recupera insumos ya preparados.

## 5. Inventario

El alcance solicitado está en [Inventario: requisitos completos](inventario-requisitos.md).

Incluye:

- Insumos y categorías, carga manual y Excel.
- Presentaciones de compra y unidades de receta.
- Proveedor, código del proveedor, precio sin impuesto e impuesto.
- Niveles mínimo e ideal.
- Insumos comprados y producidos.
- Recetas de producción con rendimiento.
- Recetas/subrecetas reutilizables y asociadas a productos.
- Asociación de recetas dentro del formulario actual.
- Existencias, consumos, costos, alertas, necesidades de compra y capacidad de producción.
- Consulta de dependencias entre insumos, recetas y productos.

Su lógica será un módulo separado, integrado mediante operaciones explícitas con compras, proveedores, catálogo y ventas.

## Orden vigente para Inventario

Seguir las cinco fases de la especificación v1:

1. Unidades, insumos, presentaciones y captura manual.
2. Existencias por sucursal y movimientos: inicial, entradas, ajustes y mermas.
3. Recetas, recetas de producción y subrecetas, con formularios separados.
4. Vinculación de productos con insumo directo o receta.
5. Consumo único al completar ventas; promociones respetan las cantidades físicas.

El consumo al completar la venta queda definido para esta fase. Mesas y cocina no generan otro consumo. La carga Excel permanece en el alcance, pero su ubicación en estas cinco fases no está asignada explícitamente.

## Orden de desarrollo propuesto anteriormente

La siguiente secuencia se conserva como antecedente. No sustituye el orden vigente de Inventario:

Este orden es una recomendación, no una implementación comprometida:

1. Definir cálculo de descuentos y primera promoción 2×1, incluyendo impuestos y cotización.
2. Implementar administración de promociones y aplicación en Caja.
3. Incorporar descuentos manuales si se confirma su política de autorización.
4. Implementar mesas y cuentas abiertas.
5. Incorporar comandas y cocina.
6. Desarrollar inventario por las etapas de su documento de integración.
7. Ampliar promociones con reglas adicionales.

Inventario puede adelantarse o trabajarse como módulo independiente. No es requisito para aplicar promociones o abrir mesas. Antes de conectarlo a ventas/preparación debe fijarse un único momento de consumo para cada flujo.

## Criterios comunes de entrega

Cada fase deberá incluir reglas de dominio, permisos, errores comprensibles, interfaz consistente con DESIGN.md y pruebas de aislamiento, reintentos y concurrencia donde corresponda.

La planificación detallada de cada fase debe distinguir requisitos confirmados, propuestas y decisiones pendientes. Este documento no autoriza migraciones, cambios de planes o nuevas políticas comerciales por sí solo.
