> Antecedente documental. La [especificación vigente v1](inventario-especificacion-v1.md) prevalece ante contradicciones. Ya quedan resueltos: unidad base por insumo, múltiples presentaciones, datos del proveedor por presentación, tres tipos de receta separados, subrecetas sin stock propio, consumo de producidos sin duplicar ingredientes y consumo único al completar la venta. Las preguntas antiguas sobre estos puntos no siguen pendientes.

Quiero que documentemos todas estas funciones y las dejemos definidas desde ahora, aunque algunas se desarrollen más adelante. La intención es tener clara la estructura completa del módulo de inventarios antes de comenzar a implementar cada una de sus partes.

## Módulo de Inventarios

La idea es separar toda la lógica relacionada con inventarios en un módulo independiente y definir claramente las secciones que lo van a componer.

Para poder manejar correctamente un inventario, primero necesitamos trabajar con **insumos**. Después, estos insumos podrán utilizarse para crear recetas, productos preparados, subrecetas y posteriormente realizar movimientos y cálculos de inventario.

### 1. Catálogo de insumos

Debe existir un apartado donde el usuario pueda registrar todos los insumos que utiliza en su negocio.

Los insumos podrán cargarse de dos maneras:

- Carga manual.
- Carga masiva mediante archivo Excel.

Cada insumo deberá contener la siguiente información.

### Información general

- Nombre del insumo.
- Categoría.

Ejemplos:

- Harina.
- Aceite.
- Queso.
- Refresco.
- Salsa de tomate.

### Información de presentación

Aquí se deberá indicar cómo se compra o recibe normalmente el insumo.

Campos:

- Nombre de la presentación.
- Cantidad de la presentación.
- Unidad de medida de la presentación.
- Cantidad de presentaciones por caja, cuando corresponda.

Las unidades de medida pueden ser, por ejemplo:

- kg
- g
- L
- ml
- pz

Ejemplo:

Un proveedor vende una caja con 24 latas.

La configuración podría ser:

- Presentación: Lata.
- Cantidad: 355.
- Unidad de medida: ml.
- Presentaciones por caja: 24.

El campo de presentaciones por caja únicamente deberá llenarse cuando el producto se compre de esa manera.

### Información para recetas

También debemos definir en qué unidad se utilizará ese insumo dentro de las recetas.

Este dato puede ser diferente de la presentación en la que se compra.

Por ejemplo:

Un producto puede comprarse por lata, pero dentro de una receta utilizarse en:

- gramos,
- kilogramos,
- mililitros,
- litros,
- piezas.

Por lo tanto, debe existir el campo:

**Unidad de medida para receta.**

La descripción para el usuario podría ser:

“Selecciona la unidad en la que normalmente utilizas este insumo dentro de tus recetas.”

Esto permitirá realizar posteriormente las conversiones entre la presentación de compra y el consumo real de las recetas.

### Información del proveedor

Cada insumo podrá tener información relacionada con su proveedor.

Campos:

- Proveedor.
- Código de producto del proveedor.
- Precio sin impuesto.
- Impuesto aplicable.

Más adelante esta estructura podría permitir tener más de un proveedor para un mismo insumo.

### Información de inventario

Cada insumo también deberá tener parámetros para controlar sus existencias.

Campos:

- Nivel mínimo.
- Nivel ideal.
- Insumo producido.

Ejemplo:

- Nivel mínimo: 12.
- Nivel ideal: 34.

El campo **Insumo producido** será de tipo Sí/No.

Se utilizará de la siguiente manera:

**No:** cuando se trata de un insumo que compramos directamente a un proveedor.

Ejemplos:

- Harina.
- Aceite.
- Queso.
- Refresco.

**Sí:** cuando se trata de un insumo que nosotros mismos producimos dentro del negocio.

Ejemplos:

- Masa.
- Salsa.
- Aderezo.
- Mezcla preparada.

Esto permitirá diferenciar los productos comprados de los productos elaborados internamente.

---

# 2. Recetas

Una vez configurados los insumos, podremos comenzar con el módulo de recetas.

Las recetas deberán permitir transformar insumos en productos preparados y también utilizar otras recetas previamente creadas.

Existirán principalmente dos tipos:

- Receta de producción.
- Receta o subreceta asociada a un producto.

---

## 3. Receta de producción

La opción **Receta de producción** se utilizará cuando queramos crear un producto o insumo final a partir de otros insumos o materias primas.

Ejemplos:

- Masa.
- Salsa.
- Aderezo.
- Mezcla.
- Preparados internos.

La descripción para el usuario podría ser:

“Selecciona Receta de producción si deseas crear un producto final a partir de insumos o materias primas, como salsas, masas u otros preparados que posteriormente formarán parte de tu inventario.”

### Información de la receta

La receta deberá tener:

- Nombre de la receta.
- Insumo producido.
- Cantidad producida.
- Unidad de medida del resultado.

Ejemplo:

**Nombre:** Masa para pizza.

**Insumo producido:** Masa.

**Cantidad producida:** 5.

**Unidad:** kg.

Esto significa que al ejecutar esa receta se producirán 5 kg de masa.

---

## 4. Ingredientes de una receta

Dentro de la misma receta deberá existir un apartado para agregar los ingredientes necesarios.

Debe existir un buscador que permita buscar y agregar insumos.

Ejemplo:

Buscamos:

**Aceite**

El sistema deberá recuperar automáticamente la unidad de medida para receta que configuramos anteriormente.

Si el aceite se configuró para utilizarse en **ml**, al agregarlo a la receta deberá mostrarse:

- Insumo: Aceite.
- Cantidad requerida: 200.
- Unidad: ml.

La unidad no deberá escribirse manualmente cada vez, sino obtenerse directamente de la configuración del insumo.

Otro ejemplo:

- Harina: 2 kg.
- Aceite: 200 ml.
- Sal: 30 g.
- Levadura: 20 g.

De esta manera podremos calcular exactamente cuánto inventario consume cada producción.

---

# 5. Subrecetas

Las recetas también deberán poder utilizar otras recetas previamente creadas.

Por ejemplo:

Una pizza podría utilizar:

- Masa.
- Salsa.
- Queso.
- Pepperoni.

Pero la **masa** podría ser una receta de producción y la **salsa** podría ser otra receta de producción.

Por lo tanto, cuando agreguemos componentes a una receta debemos poder buscar tanto:

- Insumos.
- Subrecetas.

El buscador deberá incorporar un selector para definir qué queremos buscar.

Por ejemplo:

**Tipo:**
- Insumo.
- Subreceta.

**Buscar:**
[ Campo de búsqueda ]

La interfaz podría funcionar conceptualmente como:

`[ Tipo ▼ ] [ Buscar insumo o subreceta... ]`

Esto permitirá mantener un solo flujo dentro del formulario de receta.

---

# 6. Asociar recetas

Dentro del formulario deberá existir un botón llamado:

**Asociar recetas**

Este botón permitirá relacionar recetas previamente creadas con la receta actual.

Importante:

Al hacer clic en **Asociar recetas**, no se deberá abandonar el formulario actual ni enviar al usuario a otra pantalla.

La asociación debe realizarse dentro del mismo formulario.

La intención es que el usuario pueda construir completamente una receta desde una sola pantalla.

Por ejemplo:

**Pizza Pepperoni**

Ingredientes:

- 250 g de masa.
- 100 g de salsa.
- 150 g de queso.
- 50 g de pepperoni.

En este caso:

- Masa puede ser una subreceta.
- Salsa puede ser otra subreceta.
- Queso puede ser un insumo.
- Pepperoni puede ser un insumo.

Todo debe convivir dentro de la misma receta.

---

# 7. Recetas asociadas a productos

Posteriormente tendremos productos o platillos que necesiten una receta.

Por ejemplo:

- Pizza Pepperoni.
- Hamburguesa.
- Latte.
- Capuchino.
- Ensalada.

Junto al producto podrá aparecer una acción como:

**Crear receta**

La descripción para el usuario podría ser:

“Da clic en Crear receta junto al platillo correspondiente y define paso a paso los ingredientes, subrecetas y cantidades necesarias para prepararlo.”

De esta forma podremos calcular automáticamente cuánto inventario debe descontarse cada vez que se venda el producto.

---

# 8. Botón “Agregar receta”

Cuando el usuario haga clic en **Agregar receta**, el sistema deberá mostrar qué tipo de receta desea crear.

Opciones iniciales:

### Receta de producción

Para crear un insumo o preparado que nosotros mismos producimos.

Ejemplos:

- Masa.
- Salsa.
- Aderezo.

### Receta / Subreceta

Para crear una composición que podrá utilizarse dentro de otros productos o recetas.

Esto permitirá construir recetas en diferentes niveles.

Por ejemplo:

**Pizza Pepperoni**

→ utiliza Masa.

→ la Masa utiliza Harina + Agua + Aceite + Levadura.

→ utiliza Salsa.

→ la Salsa utiliza Tomate + Sal + Especias.

Así podemos construir una estructura completa de producción e inventarios.

---

# 9. Objetivo final del sistema

La estructura del inventario debe permitir que en el futuro podamos saber automáticamente:

- Cuánto inventario tenemos disponible.
- Cuánto consume cada receta.
- Cuánto consume cada venta.
- Cuánto cuesta producir cada producto.
- Cuánto cuesta cada receta.
- Qué insumos están por debajo del nivel mínimo.
- Qué insumos debemos comprar.
- Qué insumos producimos internamente.
- Cuántas unidades podemos producir con el inventario disponible.
- Qué proveedor vende cada insumo.
- Cuál es el costo real de cada platillo.
- Qué productos utilizan determinado insumo.
- Qué subrecetas forman parte de otras recetas.

La estructura debe diseñarse desde el principio pensando en estas funciones, aunque algunas se implementen en etapas posteriores.

El flujo conceptual sería:

**Proveedores → Insumos → Presentaciones → Inventario → Recetas de producción → Subrecetas → Productos → Ventas → Descuento automático de inventario.**

La intención es mantener separada toda la lógica de inventarios de otros módulos del sistema, pero permitir que posteriormente se conecte con compras, proveedores, productos, punto de venta, costos y ventas.