# PRD — Informes de ventas

- **Estado:** borrador para revisión
- **Fecha:** 2026-11-05
- **Relación:** complementa los informes X/Z de caja (`docs/checkout-cash-session.md`)

## 1. Objetivo

Que el responsable de la tienda vea, desde el TPV, cómo van las ventas: cuánto se vende por día, qué productos y categorías se venden más, quién vende y cómo se cobra. Debe poder exportarlo para la gestoría o una hoja de cálculo.

## 2. Situación actual

- Solo existen los informes de caja: el **X** (caja abierta) y el **Z** (cierre). Son por sesión de caja, no por periodo ni por producto.
- No hay ninguna pantalla de estadísticas.
- Las líneas de cada venta no están en una tabla propia: viven en el JSON `order_meta.payment_breakdown.lines` (nombre, cantidad, precio, total de la línea). Las devoluciones sí tienen tabla de líneas (`refund_line`).
- No se guarda la categoría ni el tipo de IVA de cada línea en el momento de la venta.

## 3. Requisitos

### I1 — Datos de origen fiables
- Al guardar una venta, sus líneas se guardan también en una **tabla de líneas de venta**, con producto, combinación, referencia, nombre, categoría, cantidad, precio unitario con IVA, descuento, total, tipo de IVA, base y cuota. Es la misma información que necesita la factura completa (`docs/prd/factura-completa.md`, F5).
- Una migración rellena la tabla con las ventas existentes a partir del JSON. La categoría y el IVA se toman del catálogo actual, y esas líneas quedan marcadas como aproximadas.

### I2 — Informes
Todos los informes tienen un filtro por **rango de fechas** (hoy, ayer, esta semana, este mes, mes anterior o personalizado) y por **caja**. Todos los importes se calculan netos de devoluciones, y las devoluciones se muestran también por separado.

| Informe | Contenido |
|---|---|
| Resumen | Ventas brutas, devoluciones, ventas netas, número de tickets, ticket medio, unidades vendidas; comparación con el periodo anterior |
| Por día | Una fila por día: tickets, unidades, importe; gráfico de barras |
| Por hora | Ventas por franja horaria (para saber cuándo hay más clientes) |
| Por producto | Unidades e importe por producto y talla/color; los más vendidos y los que no se venden |
| Por categoría | Importe y porcentaje por categoría |
| Por cajero | Tickets, importe y devoluciones por empleado |
| Por forma de pago | Efectivo, tarjeta, vale, mixto |
| IVA | Base y cuota por tipo de IVA (para la gestoría) |

### I3 — Exportación
- Cada informe se descarga en **CSV** (separador `;`, decimales con coma, UTF-8 con BOM para que Excel lo abra bien).
- Opcionalmente, en PDF.

### I4 — Acceso
- Solo los administradores. Ver P1 por si algún cajero debe ver su propio resumen del día.
- Dónde: una pantalla nueva «Informes» en la barra superior o dentro de Ajustes. Ver P2.

### I5 — Rendimiento
- Los informes de un año completo responden en menos de 2 segundos con el volumen de un comercio pequeño (decenas de miles de líneas). Se usan índices por fecha, caja y producto, sin precalcular tablas.

## 4. Fuera de alcance

- Informes de stock y valoración de inventario.
- Ventas online de PrestaShop (siguen en el panel de PrestaShop). Ver P3.
- Cuadros de mando en tiempo real o en la pantalla de venta.

## 5. Criterios de aceptación

1. Con ventas y devoluciones de prueba en varias cajas y días, cada informe da los mismos totales que una consulta manual sobre los datos.
2. La suma del informe por forma de pago de un día coincide con los informes Z de ese día.
3. El CSV se abre en Excel con los acentos y los importes correctos.
4. Un cajero no puede acceder a los informes (403).
5. Las ventas anteriores a la migración aparecen en los informes, marcadas como aproximadas si procede.

## 6. Preguntas abiertas

- **P1.** ¿Algún cajero debe ver informes, por ejemplo solo los suyos del día?
- **P2.** ¿Pantalla propia «Informes» en la barra superior o sección dentro de Ajustes?
- **P3.** ¿Se quieren incluir las ventas online de PrestaShop para ver el total de la tienda?
- **P4.** ¿Qué informes son imprescindibles para la primera versión? Propuesta: Resumen, Por día, Por producto y Por forma de pago.
