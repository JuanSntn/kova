> Antecedente documental. La [especificación vigente v1](inventario-especificacion-v1.md) prevalece ante contradicciones. Ya quedan resueltos: unidad base por insumo, múltiples presentaciones, datos del proveedor por presentación, tres tipos de receta separados, subrecetas sin stock propio, consumo de producidos sin duplicar ingredientes y consumo único al completar la venta. Las preguntas antiguas sobre estos puntos no siguen pendientes.

# Inventario: integración y etapas futuras

Fecha: 28 de septiembre de 2026.
Estado: complemento técnico propuesto; sin implementación.

El alcance funcional del usuario se conserva en [inventario-requisitos.md](inventario-requisitos.md). Este documento aclara dependencias y preguntas pendientes, sin reemplazarlo.

## Estructura funcional solicitada

1. **Insumos:** nombre, categoría, presentación, cantidad y unidad de presentación, presentaciones por caja opcionales, unidad de receta, proveedor, código del proveedor, precio sin impuesto, impuesto, mínimo, ideal e indicador de producción interna.
2. **Importación Excel:** alternativa a la captura manual.
3. **Recetas de producción:** nombre, insumo producido, cantidad producida y unidad de resultado.
4. **Ingredientes:** buscador de insumos; recuperar la unidad configurada automáticamente y capturar cantidad.
5. **Subrecetas:** composiciones reutilizables con insumos y otras recetas.
6. **Asociar recetas:** acción dentro del mismo formulario, sin navegar fuera ni perder el borrador.
7. **Recetas de productos:** acción “Crear receta” junto al platillo para configurar consumo.
8. **Agregar receta:** elegir entre receta de producción y receta/subreceta.
9. **Consultas futuras:** existencias, consumo, costo de recetas y platillos, mínimos, compras necesarias, producción posible, proveedores y dependencias.

## Unidades y presentaciones

Separar presentación comercial de cantidad física. Ejemplo solicitado: caja de 24 latas, cada una de 355 ml. Una caja representa 8,520 ml; una lata, 355 ml.

- kg ↔ g y L ↔ ml admiten conversiones de la misma dimensión.
- No convertir automáticamente masa a volumen: requiere una equivalencia explícita del insumo.
- “Pieza” debe tener significado concreto. Una lata puede equivaler a 355 ml, pero una pieza de otro insumo no.
- Antes de implementar, definir unidad base de existencias y unidad en la que se expresan mínimo e ideal. Deben aparecer visibles en el formulario.
- Las conversiones usadas en operaciones históricas deben conservarse aunque cambie la presentación.
- La unidad de receta se toma del insumo; el usuario no necesita escribirla repetidamente.

Pendientes: precisión y redondeo, rendimiento aprovechable, equivalencias por presentación y modificación de unidades con historial.

## Producción y subrecetas: evitar consumo duplicado

Hay dos comportamientos que deben distinguirse explícitamente antes de programar:

**Preparado producido y almacenado:** ejecutar producción consume materias primas y genera existencias del preparado. Vender una pizza consume la masa almacenada; no vuelve a descontar la harina que ya se consumió.

**Composición sin producción almacenada:** una subreceta describe cantidades reutilizables; su consumo se resuelve en sus componentes al ejecutar la operación correspondiente.

La interfaz deberá identificar cuál aplica. No asumir que toda subreceta genera stock ni que todas se expanden hasta materias primas.

Impedir referencias circulares entre recetas. Conservar la versión/composición y rendimiento utilizados en cada consumo o producción.

## Movimientos y existencias

Propuesta de etapas para llegar a los objetivos del usuario:

- Existencias iniciales.
- Recepciones/entradas.
- Consumo por producción y entrada del resultado.
- Consumo por venta o preparación.
- Mermas y ajustes documentados.
- Conteos físicos y diferencias.

Todo movimiento requiere Tenant, Branch, autor, fecha, cantidad, unidad y origen. Correcciones mediante movimientos trazables; no reescribir el historial.

La implementación deberá garantizar atomicidad e idempotencia para que un reintento no duplique consumo o producción.

Pendientes: permitir existencias negativas o bloquear, manejo de inventario insuficiente, fechas retroactivas, lotes, caducidades y transferencias entre sucursales. No incluir estas últimas funciones sin alcance aprobado.

## Relación con Caja, mesas y promociones

- Una promoción modifica el importe comercial, no las unidades entregadas.
- Un 2×1 consume dos productos o dos porciones de su receta.
- Abrir una mesa no genera ingreso de efectivo.
- Definir si el consumo ocurre al cobrar, enviar a cocina o confirmar preparación. No consumir nuevamente al cobrar si ya se registró al preparar.
- Cancelaciones, desperdicio y devoluciones requieren reglas explícitas. No revertir automáticamente ingredientes ya utilizados.
- Los movimientos de inventario no son entradas o salidas de efectivo de caja por sí mismos.
- Las ventas históricas no deben recalcularse con una receta nueva.

## Proveedores, impuestos y costos

Conservar los campos solicitados de proveedor, código, precio sin impuesto e impuesto. Contemplar varios proveedores por insumo como extensión futura.

No confundir costo de compra con precio final de venta ni reutilizar indiscriminadamente sus tratamientos fiscales.

Pendientes antes de calcular “costo real”: método de valuación, impuestos recuperables/no recuperables, gastos adicionales, rendimientos y mermas. Hasta definirlos, no presentar una estimación de receta como costo contable definitivo.

## Importación Excel

Propuesta para implementación futura:

- Plantilla con columnas y unidades explícitas.
- Validación backend por fila.
- Vista previa de errores y registros aceptables antes de confirmar.
- Definir política de duplicados y actualizaciones.
- Definir importación atómica o parcial y comportamiento al reintentar.
- No aceptar Tenant, existencias calculadas ni permisos como autoridad desde el archivo.

No se está generando ni importando un archivo en esta fase documental.

## Etapas propuestas

1. Resolver unidades, equivalencias y alcance por sucursal.
2. Catálogo de insumos, presentaciones y referencia de proveedores.
3. Importación Excel validada.
4. Movimientos iniciales y existencias por sucursal.
5. Recetas, subrecetas, versiones y validación de ciclos.
6. Ejecución de producción y rendimiento.
7. Asociación a productos y conexión idempotente con ventas/preparación.
8. Costos y dependencias.
9. Alertas de mínimos, necesidades de compra y producción disponible.

## Validación futura

- Aislamiento entre negocios y sucursales.
- Conversiones válidas y rechazo de dimensiones incompatibles.
- Recetas sin ciclos.
- Producción sin doble consumo de ingredientes.
- Reintentos sin movimientos duplicados.
- Promociones consumen todas las unidades entregadas.
- Edición de recetas no altera historia.
- Concurrencia según la política de stock acordada.
- Importación con duplicados, errores y reintentos.
- Costos verificables según el método acordado.

## Pendientes tras recibir la especificación v1

No impiden comenzar el diseño de la fase 1, pero deben cerrarse antes de la fase afectada:

- **Existencias insuficientes (fases 2 y 5):** bloquear venta/producción o permitir stock negativo; no asumir una política.
- **Cantidades (fase 1):** precisión decimal y restricciones para piezas; equivalencias que crucen masa, volumen o piezas no son automáticas.
- **Mínimos e ideales (fases 1 y 2):** confirmar configuración por sucursal y expresarla en la unidad base.
- **Subrecetas (fase 3):** definir porción/rendimiento de referencia y multiplicador de uso; impedir ciclos.
- **Costos:** método de valuación e impuestos recuperables siguen sin definirse. No son requisito para introducir cálculos contables en las cinco fases.
- **Excel:** conservarlo en alcance; asignar etapa y política de duplicados antes de implementarlo.
- **Acceso comercial y permisos:** confirmar capacidades y autorizaciones reutilizando la arquitectura actual, sin asignarlas por nombre de plan.

No se agregan lotes, caducidades, transferencias, compras completas ni otros procesos al alcance de las cinco fases.
