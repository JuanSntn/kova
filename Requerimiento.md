# Documento de Requerimientos — Sistema POS Multi-tenant (Módulo Restaurantes)

**Versión:** 6.0  
**Fecha:** 03 de agosto de 2026  
**Autor / Product Owner:** Juan Carlos Santín  
**Estado:** Borrador — lógica de negocio, arquitectura fiscal de timbrado y pantallas en definición  
**Stack propuesto:** Laravel + MySQL + Reverb (realtime) + React/TypeScript + Tailwind + shadcn/ui  

---

### Changelog v5.0 → v6.0
- **Lógica fiscal y modelo de negocio PAC:** Se define formalmente que la plataforma POS compra las bolsas masivas de timbres al PAC y las distribuye a los tenants (restaurantes/comercios).
- **Consumo de Autofacturación e Inclusión por Plan:** Se estipula la cuota de timbres incluidos por plan ($100 = 50 timbres/mes; $600 = 150 timbres/mes).
- **Mecanismo de Excedentes de Timbrado:** Se define el modelo híbrido *Pay-As-You-Go* ($1.30 MXN por timbre adicional) cobrado a mes vencido en la mensualidad del cliente o mediante Monedero Prepago ($50 MXN mínimo) para evitar bloquear la operación en caja.
- **Configuración Fiscal del Tenant (§17.2):** Se detallan los campos y credenciales obligatorios que debe dar de alta el negocio (CSD `.cer`/`.key`, RFC, Régimen, CP) para poder emitir facturas en autoservicio.
- **Proveedores PAC Evaluados (§17.6 y §22):** Se integran opciones de compra masiva de timbres (Facturapi, SW SmarterWeb, Finkok).

---

## 1. Introducción y Resumen Ejecutivo

Este documento define los requerimientos funcionales y de negocio para la primera fase (MVP) de un sistema POS multi-tenant. El producto está diseñado desde su arquitectura para soportar múltiples giros de negocio, pero la Fase 1 se enfoca exclusivamente en el nicho de **restaurantes**.

### 1.1 Objetivo del producto
Proveer un sistema POS propio, sin dependencia de plataformas de terceros, que cubra el ciclo completo de operación de un restaurante: toma de comanda, cocina, cobro, inventario, promociones/descuentos, cargos por servicio, autofacturación fiscal 4.0 con administración de timbres y cierre de caja, con soporte multi-sucursal.

### 1.2 Alcance de esta versión (MVP)
Fase 1 cubre exclusivamente el giro de restaurante, e incluye autofacturación CFDI 4.0 vía QR basada en la cuota e infraestructura de timbres administrada por la plataforma. Quedan fuera del alcance (ver sección 20): integraciones de delivery por app, retail genérico, permisos granulares por acción.

---

## 2. Stack Tecnológico

| Capa | Tecnología | Justificación |
|---|---|---|
| Backend / lógica de negocio | Laravel + MySQL | Stack de dominio del equipo; patrones probados (Services, Events, Observers, Queues) |
| Tiempo real | Laravel Reverb | WebSockets self-hosted, sin dependencia de servicio externo |
| Frontend | React + TypeScript + Tailwind + shadcn/ui | Consistente con proyectos previos del equipo |
| Infraestructura | VPS (DigitalOcean) + nginx + PHP-FPM | Experiencia previa del equipo en despliegue y hardening |
| Impresión | ESC/POS (térmica) + generación de PDF (fallback) | Cubre impresora física y respaldo digital del ticket |
| Facturación (PAC) | SW SmarterWeb / Facturapi / Finkok (API REST) | Compra mayorista de timbres administrada en backend por la plataforma |

---

## 3. Análisis de Consistencia — Hallazgos y Decisiones

| # | Hallazgo | Decisión adoptada |
|---|---|---|
| 1 | Los tipos de orden no estaban ligados a los planes de suscripción. | Free y Negocio: solo Mostrador. Restaurante: Mesa + Mostrador. |
| 2 | La regla de turno de caja no cubría al dueño operando solo. | Free no usa turno de caja formal; Negocio y Restaurante sí, por cada usuario que cobra. |
| 3 | El código de autorización de descuentos no aplicaba cuando solo existía 1 usuario. | Se activa desde el plan Negocio en adelante. En Free no aplica. |
| 4 | No existía una fórmula explícita del total del ticket. | `total = subtotal_items − descuento_orden + cargos_servicio + propina`. Suma de pagos debe igualar el total. |
| 5 | El snapshot histórico no cubría el nombre de las opciones de modificador. | Se agrega `opcion_nombre_snapshot` en `orden_item_opciones`. |
| 6 | No existía un folio único legible para tickets. | Folio = `{código_sucursal}-{consecutivo}`, consecutivo continuo por sucursal. |
| 7 | El inventario automático no contempla consumo distinto por opción de modificador. | Fuera de alcance del MVP — inventario vive a nivel producto y modificador mediante recetas específicas. |
| 8 | Los roles Mesero/Cocina/Bar no tenían aclarado en qué planes existen. | Solo existen en el plan Restaurante. |
| 9 | Al simplificar Free, sobraba la complejidad de movimientos de caja y Corte Z. | Free usa solo un **historial de ventas simple**. |
| 10 | Negocio se quedaba en 1 solo usuario. | Negocio permite hasta 5 usuarios con rol Cajero. |
| 11 | Ambivalencia en descuentos a nivel ítem vs. nivel cuenta. | Se separan en `descuentos_articulo` y `descuentos_orden`. |
| 12 | Las promociones automáticas se confundían con el descuento manual del cajero. | Se crea catálogo `promociones` con `origen = automatico`. |
| 13 | No existía forma de cobrar cargos adicionales estructurados. | Se crea catálogo `catalogo_cargos_servicio` + `cargos_orden`. |
| 14 | El item "gratis" de una promoción NxM no tenía regla de descuento de stock. | Descuenta inventario igual que cualquier venta. |
| 15 | Diferenciación fiscal entre cargo por servicio y propina. | Cargo por servicio grava IVA y se factura; la propina voluntaria no se factura como concepto gravable. |
| 16 | No existía forma de agrupar items antes de enviarlos a cocina. | Se crea `separadores_orden`. |
| 17 | La autofacturación requiere ligar un ticket a un CFDI de forma única. | Se usa `token_publico` (UUID no secuencial) por ticket. |
| 18 | Riesgo de consumo ilimitado de timbres por un negocio pequeño. | Cada plan incluye una bolsa de timbres mensual; el excedente se cobra a $1.30 MXN/timbre a mes vencido o vía Monedero Prepago sin detener la caja. |

---

## 4. Planes de Suscripción

### 4.1 Tabla comparativa

| Variable | **Free** | **Negocio** | **Restaurante** |
|---|---|---|---|
| Precio | $0/mes | $100 MXN/mes | $600 MXN/mes |
| Para quién | Vendedor individual | Negocio de comida / Mostrador | Restaurante con equipo completo |
| Usuarios | 1 (dueño) | Hasta 5 (Owner + Cajero) | Ilimitados (roles completos) |
| Tipos de orden | Mostrador | Mostrador | Mesa + Mostrador |
| Turno de caja | Historial simple | ✓, con movimientos y Corte Z | ✓, con movimientos y Corte Z |
| Autofacturación CFDI 4.0 | ✗ | ✓ (**50 timbres incluidos/mes**) | ✓ (**150 timbres incluidos/mes**) |
| Costo por timbre excedente | N/A | **$1.30 MXN / timbre** | **$1.30 MXN / timbre** |
| Inventario y recetas | ✗ | ✓ | ✓ |
| Límite de productos | 20 | 150 | Ilimitado |
| Límite de venta mensual | $25,000 MXN | $150,000 MXN | Ilimitado |
| Sucursales incluidas | 1 | 1 | **1 incluida** (+$350 MXN/mes por sucursal extra) |

---

## 5. Arquitectura de Autofacturación y Consumo de PAC

### 5.1 Modelo de Operación del PAC
1. **Compra Centralizada:** La empresa propietaria del POS adquiere paquetes mayoristas de timbres directamente con el PAC (ej. SW SmarterWeb, Facturapi, Finkok) a un costo de $0.20 - $0.50 MXN por timbre.
2. **Aislamiento Multi-tenant:** El cliente (restaurante) no contrata al PAC. Registra sus archivos CSD (Certificado de Sello Digital) en la plataforma. La plataforma firma las facturas usando la API del PAC en nombre del restaurante.
3. **Cómputo de consumo:** Imprimir tickets de venta genera $0 costo de timbrado. Únicamente se consume 1 timbre cuando el comensal completa la autofacturación en la web mediante el QR del ticket, o cuando el restaurante emite la Factura Global del periodo.

### 5.2 Administración de Excedentes (Políticas de Cobro)
- **Límite Suave a Mes Vencido (Recomendado):** Si el negocio consume más de sus timbres incluidos en el mes (ej. emite 70 facturas en el Plan Negocio), el sistema NO detiene el timbrado. Al cortar el periodo de suscripción mensual, el sistema suma los excedentes acumulados a su factura mensual:
  $$\text{Cobro Mensual} = \text{Suscripción (\$100)} + (\text{Timbres Excedentes} \times \$1.30)$$
- **Monedero Prepago (Para pago por transferencia/efectivo):** En clientes sin tarjeta de crédito/débito en cobro recurrente automático, al agotarse la cuota mensual se activa la recarga de saldo mínimo ($50 MXN), descontando $1.30 MXN de su saldo por cada factura adicional emitida.

---

## 6. Módulo de Menú y Productos

### 6.1 Estructura jerárquica
Categoría → Producto → Variantes (opcional) → Grupos de Modificadores.

### 6.2 Modificadores
- **Grupos de modificadores:** ej. "Término de la carne", "Salsas", "Extras".
- **Límites:** `min_seleccion`, `max_seleccion`, `es_obligatorio`.
- **Opciones:** `nombre`, `precio_adicional`, `afecta_inventario`.

---

## 7. Módulo de Mesas y Áreas (Solo Plan Restaurante)

- **Áreas:** Zonas del establecimiento (ej. Terraza, Salón Principal, Barra).
- **Mesas:** ID, número/nombre, capacidad, coordenadas ($X, Y$), estado (`libre`, `ocupada`, `por_cobrar`, `reservada`).
- **Estados de mesa:** Se actualizan en tiempo real vía WebSockets (Laravel Reverb) hacia todos los dispositivos de la sucursal.

---

## 8. Módulo de Órdenes / Comandas

### 8.1 Tipos de orden
- **Mostrador (Takeout / Fast Food):** Cobro inmediato o posterior. Disponible en todos los planes.
- **Mesa (Dine-in):** Asignada a una mesa específica. Exclusivo del Plan Restaurante.

### 8.2 Separadores de Comanda (Entradas, Fuertes, Postres)
- Agrupadores lógicos de ítems dentro de una misma comanda.
- Permiten enviar a cocina por tiempos (ej. "Enviar Entradas primero").

### 8.3 Flujo de Estados de Orden
abierta -> enviada_cocina -> en_preparacion -> lista -> entregada -> pagada -> cerrada


---

## 9. Módulo de Cocina y Bar (KDS — Kitchen Display System)

- **Pantallas separadas:** Cocina Caliente, Barra / Bebidas, Postres.
- **Filtrado por categoría de producto:** Configurable por pantalla KDS.
- **Acciones:** "Empezar a preparar", "Marcar como listo", "Revertir estado".
- **Alertas de tiempo:** Código de colores por minutos transcurridos (Verde: < 10 min, Amarillo: 10-20 min, Rojo: > 20 min).

---

## 10. Módulo de Cobro y Pagos

### 10.1 Métodos de Pago
- Efectivo (calcula cambio automáticamente).
- Tarjeta de Crédito / Débito (Terminal bancaria externa/física).
- Transferencia SPEI.
- Monedero / Crédito de la casa.

### 10.2 Pagos Divididos (Split Bill)
- Por partes iguales (ej. cuenta entre 4 personas).
- Por consumo individual (seleccionar ítems específicos por persona).

### 10.3 Fórmula del Total
$$\text{Total} = \text{Subtotal Ítems} - \text{Descuentos} + \text{Cargos Servicio} + \text{Propina}$$

---

## 11. Cargos por Servicio y Propina

- **Cargo por Servicio:** Obligatorio/Estructurado (ej. "Evento Especial 10%"). **Grava IVA** y forma parte de la base facturable.
- **Propina:** Voluntaria. No grava IVA, no forma parte del ingreso facturable del restaurante (se registra como pasivo temporal para repartición a empleados).

---

## 12. Descuentos y Promociones

- **Descuento Manual:** Porcentaje o monto fijo. Requiere PIN de autorización si supera el umbral configurado.
- **Promociones Automáticas:** 2x1, Hora Feliz, Descuento en Categoría por Día/Hora. Aplican automáticamente en el carrito.

---

## 13. Módulo de Inventario y Recetas (Planes Negocio y Restaurante)

- **Insumos / Materias Primas:** Unidad de medida (kg, lt, pza), costo promedio, stock mínimo, stock actual.
- **Recetas (BOM - Bill of Materials):** Relación de insumos por producto / opción de modificador.
- **Descuento Automático:** Se deduce el insumo al cambiar la orden a estado `pagada` o `enviada_cocina`.

---

## 14. Turno de Caja y Cierre (Corte Z)

- **Apertura de caja:** Registro de fondo inicial.
- **Movimientos de caja:** Entradas / Salidas de efectivo justificadas.
- **Cierre de caja (Corte Z):**
  - Arqueo a ciegas (el cajero ingresa lo que contó físicamente).
  - Comparativa contra sistema (Ventas efectivo, tarjetas, retiros).
  - Cálculo de sobrante o faltante.
  - Impresión de comprobante térmico de Cierre Z.

---

## 15. Sistema de Impresión (ESC/POS)

- Soporte para impresoras térmicas de tickets (58mm / 80mm).
- Impresión de comanda para cocina (sin precios, agrupado por preparador).
- Impresión de pre-cuenta para mesa.
- Impresión de ticket definitivo con código QR para autofacturación.

---

## 16. Módulo Multi-Sucursal

- Catálogos compartidos (Menú global) o independientes por sucursal.
- Precios e inventario diferenciados por sucursal.
- Transferencia de stock entre sucursales.
- Reporte consolidado para el Owner.

---

## 17. Autofacturación CFDI 4.0

### 17.1 Flujo
Ticket PAGADO -> Generación de token único (UUID) ->
QR en ticket apunta a /facturar/{token} -> Cliente captura sus datos fiscales ->
Sistema combina datos del Emisor (guardados en tenant) + datos del Receptor ->
API del PAC firma comprobante -> Descuento de 1 timbre en bolsa del tenant -> PDF/XML disponibles


### 17.2 Datos de Configuración Fiscal del Tenant (Campos en BD)
Para habilitar el módulo de autofacturación, el usuario con rol **Owner** debe completar los siguientes campos en **Configuración → Facturación**:

tenant_configuracion_fiscal
id, tenant_id
rfc_emisor                       VARCHAR(13)
razon_social_emisor              VARCHAR(300) (Coincidencia exacta con CSD)
regimen_fiscal_emisor            VARCHAR(3) (Clave del catálogo SAT)
codigo_postal_emisor             VARCHAR(5)
csd_certificado_path             TEXT (Archivo .cer encriptado)
csd_llave_privada_path           TEXT (Archivo .key encriptado)
csd_passphrase                   TEXT (Contraseña del CSD, encriptada en vault)
dias_vigencia_ticket_factura     INT (default: 3 días tras la compra o fin de mes)
timbres_incluidos_mes            INT (50 para Negocio, 150 para Restaurante)
timbres_consumidos_mes           INT
modo_excedente                   postpago_mensual | monedero_prepago
saldo_monedero_prepago           DECIMAL(10,2)


### 17.3 Modelo de Facturas Emitidas
facturas
id, tenant_id, sucursal_id, ticket_id,
token_publico (uuid, único, para el QR),
rfc_receptor, razon_social_receptor, cp_fiscal_receptor,
regimen_fiscal_receptor, uso_cfdi,
monto_subtotal, monto_iva, monto_total,
es_excedente (bool, true si se emitió fuera de la cuota base del mes),
costo_excedente_aplicado (DECIMAL: $1.30),
estado (disponible | facturada | cancelada | error_timbrado),
uuid_fiscal, xml_path, pdf_path, pac_response_raw (json),
facturado_at, cancelado_at


---

## 18. Reportes y Métricas (Dashboard)

- **Ventas:** Por periodo, por hora pico, por día de la semana.
- **Productos:** Más vendidos (Top 10), menos vendidos, margen de utilidad.
- **Rendimiento de personal:** Ventas por mesero, propinas acumuladas.
- **Inventario:** Insumos por agotarse, costo de mermas.

---

## 19. Pantallas del Sistema (Lista Completa de Vistas)

### 19.1 Pantallas Generales y Admin Multi-tenant
- **19.1.1 Login / Registro de Tenant:** Autenticación y creación de la cuenta del restaurante.
- **19.1.2 Dashboard Principal:** Resumen ejecutivo con gráficos de ventas, órdenes activas y accesos rápidos.
- **19.1.3 Configuración de Sucursales:** Gestión de direcciones, teléfonos e impresoras asociadas.

### 19.2 Punto de Venta (POS / Operativo)
- **19.2.1 Mapa de Mesas:** Vista gráfica interactiva con código de colores según el estado de la mesa.
- **19.2.2 Toma de Comanda:** Selección de productos, variantes, modificadores y asignación de separadores de comanda.
- **19.2.3 Pantalla de Cobro:** Selección de formas de pago, cálculo de cambio, split de cuenta y adición de propina/servicio.
- **19.2.4 Historial de Tickets:** Lista de tickets emitidos, estado de pago, opción de reimpresión o cancelación.

### 19.3 Cocina y Producción (KDS)
- **19.3.1 Pantalla de Cocina / Bar (KDS):** Cuadrícula de comandas en tiempo real con temporizador de alerta.

### 19.4 Catálogos y Menú
- **19.4.1 Gestión de Productos y Menú:** Carga de platillos, precios, imágenes, categorías y modificadores.
- **19.4.2 Gestión de Promociones:** Configuración de reglass tipo 2x1, horas felices y descuentos por fecha.

### 19.5 Inventarios y Caja
- **19.5.1 Control de Insumos y Recetas:** Alta de materia prima y vinculación de ingredientes a platillos.
- **19.5.2 Turno y Apertura/Cierre de Caja:** Registro de efectivo inicial, entrada/salida de dinero y formulario de Corte Z.

### 19.6 Facturación
- **19.6.1 Panel de Autofacturación y Saldo de Timbres:** Carga de certificados CSD, monitor de consumo de timbres del mes y recarga de saldo.
- **19.6.2 Portal Web Público de Autofacturación:** Pantalla accesible para el cliente final mediante el código QR para ingresar sus datos fiscales y descargar PDF/XML.

---

## 20. Requerimientos Fuera de Alcance (Out of Scope — MVP)

- Integración directa mediante API a plataformas de delivery (UberEats, Rappi, DidiFood).
- Módulo de Retail/Comercio con variantes de talla/color avanzadas.
- Permisos de usuario totalmente personalizables regla por regla (se manejarán únicamente roles fijos en el MVP).
- Módulo de reservaciones de mesas en línea con anticipo de pago.

---

## 21. Requerimientos No Funcionales

- **Disponibilidad:** $99.5\%$ de uptime en infraestructura VPS.
- **Tiempo de Respuesta:** $< 200\text{ ms}$ en peticiones del POS local; $< 1\text{ s}$ en consultas de reportería compleja.
- **Seguridad:** Encriptación SSL/TLS obligatoria; sellos fiscales (CSD) y claves privadas encriptadas en almacenamiento seguro.
- **Concurrencia:** Arquitectura lista para soportar múltiples terminales POS operando simultáneamente en la misma sucursal mediante Laravel Reverb.

---

## 22. Proveedores de PAC y Costos de Mayoreo (Referencias de Compra)

1. **Facturapi (`facturapi.io`):** API REST simplificada. Ideal para desarrollo rápido. Maneja el sellado y timbrado en un solo endpoint. Costo estimado: $0.50 – $0.90 MXN / timbre.
2. **SW SmarterWeb (`sw.com.mx`):** PAC directo autorizado por el SAT. Ofrece máxima estabilidad y tarifas por volumen alto ($0.20 – $0.35 MXN / timbre).
3. **Finkok (`finkok.com`):** PAC enfocado en desarrolladores de software con consumo masivo de timbrado multi-tenant.

---

## 23. Firma y Aprobación

**Aprobado por:** Juan Carlos Santín  
**Rol:** Product Owner / Arquitecto de Software  
**Fecha de Aprobación:** 03 de agosto de 2026