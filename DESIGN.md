# Kova Design System

## Overview

Kova usa una interfaz de producto sobria y cálida para trabajo operativo. La densidad es moderada y la jerarquía se construye con tipografía, espacio y bordes suaves, no con decoración.

## Theme

- Tema principal claro para restaurantes y comercios con iluminación ambiental variable.
- Neutros ligeramente cálidos y naranja Kova como acento funcional.
- Las superficies principales usan el fondo y tokens existentes de `src/index.css`.

## Typography

- Familia: Geist Variable.
- Títulos de página: 20 px (`text-xl`), peso 600, tracking ajustado.
- Títulos de sección: 16 px, peso 600.
- Texto de interfaz: 14 px.
- Ayuda y metadata: 12 a 13 px con interlineado cómodo.

## Color

- Primary: token `primary`, naranja Kova.
- Background: token `background`.
- Surface: token `card`.
- Secondary surface: token `muted` con opacidad moderada.
- Success, warning y destructive se usan sólo para estados reales.

## Components

- Usar primero los componentes de `src/components/ui`.
- Inputs y selects de formularios principales usan 40 px de altura.
- Los títulos de sección son texto simple. No llevan iconos ni mosaicos decorativos.
- Una pantalla de configuración puede tener una sola superficie dividida en secciones. Evitar tarjetas anidadas o una tarjeta idéntica por cada grupo.
- Acciones primarias al final del grupo que modifican.
- Errores debajo del campo y asociados mediante `aria-invalid`.
- Listados cortos de configuración usan filas con separadores, no grids de tarjetas.
- Los iconos se reservan para alertas, estado semántico y controles estándar que los necesitan.
- El naranja se reserva para acciones primarias, foco y selección. No se usa como decoración.

## Layout

- Kova tiene un solo sidebar: la navegación principal de la aplicación.
- No usar una segunda navegación vertical dentro del contenido ni duplicar la estructura del sidebar.
- Las pantallas de Settings siguen un flujo vertical continuo con títulos simples, divisores y acciones cercanas al contenido que modifican.
- Contenido principal fluido, con un ancho máximo consistente (`max-w-6xl`) y texto explicativo limitado a 70 caracteres por línea.
- Los formularios usan dos columnas en escritorio y una columna en móvil.
- Espaciado de 20 a 24 px dentro de secciones y 32 px entre grupos conceptuales.

## Motion

- Transiciones de estado entre 150 y 200 ms.
- Sin animaciones decorativas ni secuencias de entrada.
