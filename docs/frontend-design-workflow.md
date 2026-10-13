# Flujo de diseño frontend de Kova

Este flujo aplica a pantallas nuevas y rediseños. `PRODUCT.md` define el producto y `DESIGN.md` es el contrato visual obligatorio.

1. **design-md** mantiene `DESIGN.md` a partir de los patrones reales del repositorio. Cualquier regla nueva debe ser compatible con los componentes existentes de Kova.
2. **frontend-design** propone la composición y la jerarquía de la pantalla según la tarea, el usuario y el contrato visual.
3. **ui-design** implementa y revisa React y Tailwind reutilizando primero `src/components/ui`.
4. **web-design-guidelines** audita accesibilidad, teclado, foco, formularios, responsive e interacción.
5. **Impeccable** se usa al final para crítica y pulido. No define por sí sola la identidad visual del producto.

## Criterios de salida

- Una sola navegación lateral de aplicación.
- Sin iconos decorativos, tarjetas anidadas ni patrones visuales ajenos a `DESIGN.md`.
- Estados de carga, vacío, error, éxito y deshabilitado resueltos.
- Navegación por teclado, labels y foco visible verificados.
- Comportamiento revisado en móvil, tablet y escritorio.
- Build de TypeScript/Vite exitoso.
- Las decisiones de permisos, negocio y seguridad permanecen en backend.
