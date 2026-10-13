# Requerimiento funcional — Inventarios y Recetas

## 1. Objetivo

Crear un módulo de inventarios separado y ordenado que permita:

- Registrar insumos.
- Registrar cómo se compran los insumos.
- Controlar existencias por sucursal.
- Crear recetas.
- Crear recetas de producción.
- Crear subrecetas.
- Vincular productos directamente con insumos o con recetas.
- Descontar inventario automáticamente al vender.

La lógica de inventario deberá mantenerse separada de Productos, Caja, Mesas y Cocina, aunque posteriormente se conecte con estos módulos.

---

# 2. Unidades

El sistema deberá manejar unidades de medida como:

- Pieza (`pz`)
- Gramo (`g`)
- Kilogramo (`kg`)
- Mililitro (`ml`)
- Litro (`L`)

Cada insumo deberá tener una **unidad base de inventario**.

Esta unidad será utilizada para conocer su existencia real.

Ejemplos:

### Coca-Cola 355 ml

Unidad base:

`pz`

### Aceite

Unidad base:

`ml`

### Harina

Unidad base:

`g` o `kg`

Las presentaciones de compra deberán convertirse automáticamente a la unidad base del insumo.

---

# 3. Insumos

Debe existir una pantalla independiente:

**Inventario → Insumos**

Los insumos podrán registrarse:

- Manualmente.
- Mediante carga masiva por Excel.

Cada insumo deberá contener la siguiente información.

## Información general

- Nombre.
- Categoría.

## Información de inventario

- Unidad base.
- Nivel mínimo.
- Nivel ideal.
- Insumo producido: Sí / No.

**Insumo producido = No**

Cuando se compra a un proveedor.

Ejemplos:

- Harina.
- Aceite.
- Coca-Cola.
- Queso.

**Insumo producido = Sí**

Cuando se produce dentro del establecimiento.

Ejemplos:

- Masa.
- Salsa.
- Aderezo.

---

# 4. Presentaciones de compra

Un insumo podrá tener una o varias presentaciones.

La presentación indica **cómo se compra el insumo**, pero no cambia su unidad base de inventario.

Ejemplo:

### Insumo

Coca-Cola 355 ml

### Unidad base

`pz`

### Presentación

Caja

### Contenido

`24 pz`

Al registrar una entrada de:

`1 caja`

el sistema deberá convertirla automáticamente en:

`+24 pz`

Existencia:

`24 pz`

También podría existir otra presentación:

`Paquete de 6 = 6 pz`

Todas las presentaciones alimentan el mismo inventario del insumo.

---

# 5. Ejemplo de insumos que se consumen por cantidad

Para productos como aceite:

### Insumo

Aceite vegetal

### Unidad base

`ml`

### Presentación de compra

Garrafa de 5 L

El sistema convierte:

`5 L = 5,000 ml`

Si una receta utiliza:

`150 ml`

el inventario cambia de:

`5,000 ml`

a:

`4,850 ml`

Por lo tanto, el sistema deberá soportar tanto:

**Caja → piezas**

como:

**Garrafa → litros/ml**

y otros casos equivalentes.

---

# 6. Información del proveedor

Las presentaciones podrán contener:

- Proveedor.
- Código de producto del proveedor.
- Precio sin impuesto.
- Impuesto.

Esto permite que la información comercial esté relacionada con la forma específica en la que se compra el insumo.

---

# 7. Existencias y movimientos

Las existencias deberán controlarse por sucursal.

No se deberá modificar directamente el número de existencia.

Todo cambio de inventario deberá generar un movimiento.

Ejemplos:

- Inventario inicial.
- Entrada.
- Ajuste positivo.
- Ajuste negativo.
- Merma.
- Consumo por venta.
- Consumo por producción.
- Entrada por producción.

Ejemplo:

Entrada:

`+24 pz Coca-Cola`

Venta:

`-1 pz Coca-Cola`

Existencia:

`23 pz`

Los movimientos serán la trazabilidad del inventario.

---

# 8. Módulo de recetas

Debe existir una pantalla independiente:

**Inventario → Recetas**

Esta pantalla permitirá consultar las recetas existentes.

En la parte superior deberá existir:

**Agregar receta**

Al hacer clic deberá mostrar exactamente tres opciones:

1. Receta.
2. Receta de producción.
3. Subreceta.

Cada opción tendrá su propia pantalla y su propia lógica.

No se deberán combinar los tres tipos dentro de un mismo formulario.

---

# 9. Receta

Una **Receta** define qué consume un producto vendido.

Ejemplo:

### Producto

Pizza Pepperoni

### Receta

- Masa: 250 g.
- Salsa: 100 g.
- Queso: 150 g.
- Pepperoni: 50 g.

Una receta podrá utilizar:

- Insumos.
- Subrecetas.
- Insumos producidos.

Cuando se venda el producto, se descontarán las cantidades configuradas.

---

# 10. Receta de producción

Una **Receta de producción** se utilizará para crear un insumo dentro del establecimiento.

Ejemplo:

### Receta de producción

Masa para pizza

### Produce

`5 kg de masa`

### Consume

- Harina: 3 kg.
- Agua: 1.5 L.
- Aceite: 200 ml.
- Levadura: 30 g.
- Sal: 40 g.

Al registrar la producción:

1. Se descuentan los ingredientes utilizados.
2. Se agrega al inventario el insumo producido.

Ejemplo:

`Harina -3 kg`

`Aceite -200 ml`

`Masa +5 kg`

La masa se convierte entonces en una existencia normal del inventario.

---

# 11. Regla de producción

Un insumo producido **no deberá volver a descontar sus ingredientes cuando posteriormente sea utilizado**.

Ejemplo:

Primero producimos masa.

En ese momento se descuenta:

- Harina.
- Agua.
- Aceite.
- Levadura.
- Sal.

Posteriormente una pizza utiliza:

`250 g de masa`

Al vender la pizza únicamente se descuentan:

`250 g de masa`

No se vuelve a descontar harina, aceite, agua, etc.

Esto evita duplicar consumos.

---

# 12. Subreceta

Una **Subreceta** será una preparación reutilizable dentro de otras recetas.

Ejemplo:

### Subreceta

Mezcla de toppings

Componentes:

- Queso: 150 g.
- Tomate: 80 g.
- Albahaca: 5 g.

Una subreceta:

- Puede utilizarse dentro de una receta.
- Puede reutilizarse en varios productos.
- No genera existencia propia.

La subreceta funciona como agrupación de componentes.

---

# 13. Diferencia definitiva entre los tres tipos

## Receta

Define qué consume un producto que se vende.

## Receta de producción

Consume insumos y genera un nuevo insumo dentro del inventario.

## Subreceta

Agrupa componentes reutilizables, pero no genera existencia propia.

---

# 14. Buscador de componentes

Dentro de los formularios de recetas deberá existir un buscador reutilizable.

Ejemplo:

`[ Tipo ▼ ] [ Buscar... ]`

Tipos disponibles según corresponda:

- Insumo.
- Subreceta.

Cuando se seleccione un insumo, el sistema deberá mostrar automáticamente su unidad.

Ejemplo:

Aceite:

`Cantidad: 200`

`Unidad: ml`

El usuario captura la cantidad; la unidad proviene de la configuración del insumo.

---

# 15. Productos vinculados directamente a insumos

No todos los productos necesitan una receta.

Los productos terminados que simplemente se compran y revenden podrán vincularse directamente con un insumo.

Ejemplo:

### Producto

Coca-Cola

### Control de inventario

Sí

### Tipo de consumo

Insumo directo

### Insumo

Coca-Cola 355 ml

### Cantidad por venta

`1 pz`

Si se recibió una caja de 24:

`Inventario = 24 pz`

Al vender una Coca-Cola:

`24 - 1 = 23 pz`

Al vender otra:

`23 - 1 = 22 pz`

No será necesario crear una receta únicamente para descontar una Coca-Cola.

---

# 16. Configuración de inventario del producto

Un producto podrá tener inicialmente dos formas de consumo:

### Insumo directo

Para productos como:

- Coca-Cola.
- Agua.
- Cerveza.
- Productos empacados.

Ejemplo:

`Coca-Cola → 1 pz Coca-Cola 355 ml`

### Receta

Para productos preparados.

Ejemplo:

`Latte → Receta Latte`

`Pizza → Receta Pizza`

Esta configuración deberá realizarse desde el producto.

---

# 17. Consumo por venta

Inicialmente el inventario se descontará cuando la venta quede completada.

Cada venta deberá generar el consumo de inventario una sola vez.

Ejemplo:

Venta:

`2 Coca-Cola`

Configuración:

`1 Coca-Cola = 1 pz`

Movimiento:

`-2 pz Coca-Cola`

La operación deberá protegerse para evitar que la misma venta descuente inventario dos veces.

Mesas, Cocina y Caja podrán conectarse posteriormente con esta misma lógica, pero nunca deberán generar un doble consumo.

---

# 18. Promociones

Las promociones deberán afectar el precio, no la cantidad física consumida.

Ejemplo:

### Promoción

2×1 Coca-Cola

El cliente recibe:

`2 Coca-Cola`

Se cobra:

`1 Coca-Cola`

Inventario:

`-2 pz`

El inventario siempre deberá representar lo que físicamente salió del establecimiento.

---

# 19. Pantallas

La estructura inicial será:

## Insumos

`Inventario → Insumos`

- Listado.
- Crear.
- Editar.
- Carga Excel.

## Recetas

`Inventario → Recetas`

Listado general de:

- Recetas.
- Recetas de producción.
- Subrecetas.

Botón:

**Agregar receta**

Opciones:

- Receta.
- Receta de producción.
- Subreceta.

Cada tipo abrirá su propio formulario.

## Existencias

`Inventario → Existencias`

Existencias por:

- Sucursal.
- Insumo.

## Movimientos

`Inventario → Movimientos`

Historial de entradas y salidas de inventario.

---

# 20. Orden de implementación

La implementación deberá realizarse en este orden:

### Fase 1

- Unidades.
- Insumos.
- Presentaciones.
- Captura manual.

### Fase 2

- Existencias por sucursal.
- Movimientos.
- Inventario inicial.
- Entradas.
- Ajustes.
- Mermas.

### Fase 3

- Recetas.
- Recetas de producción.
- Subrecetas.

### Fase 4

- Vinculación Producto → Insumo.
- Vinculación Producto → Receta.

### Fase 5

- Descuento automático al completar ventas.
- Protección contra doble consumo.
- Promociones respetando cantidades físicas.

---

# 21. Reglas finales

Estas reglas quedan definidas para el desarrollo:

1. Un insumo tiene una unidad base de inventario.
2. Una presentación indica cómo compramos el insumo.
3. Comprar una caja puede convertirse automáticamente a piezas.
4. Un producto puede venderse por pieza aunque el insumo se compre por caja.
5. Las existencias se modifican mediante movimientos.
6. Las existencias se controlan por sucursal.
7. Un producto simple puede vincularse directamente con un insumo.
8. Un producto preparado puede vincularse con una receta.
9. Receta, Receta de producción y Subreceta son procesos separados.
10. Una Receta de producción genera inventario.
11. Una Subreceta no genera inventario.
12. Los ingredientes de un insumo producido se descuentan durante la producción, no nuevamente durante la venta.
13. Cada venta consume inventario una sola vez.
14. Las promociones modifican el precio, pero no la cantidad física consumida.
15. Las pantallas y responsabilidades deberán mantenerse separadas.

Con estas reglas queda definida la base funcional del módulo de Inventarios y Recetas. El desarrollo deberá seguir este alcance sin agregar nuevos procesos hasta completar y validar estas etapas.