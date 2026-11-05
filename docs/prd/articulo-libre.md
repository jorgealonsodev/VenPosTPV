# PRD — Artículo libre (concepto y precio manual)

- **Estado:** borrador para revisión
- **Fecha:** 2026-11-05
- **Relación:** venta local primero (`docs/prd/venta-local-primero.md`), devoluciones (`docs/prd/devoluciones.md`)

## 1. Objetivo

Poder vender algo que no está en el catálogo (un servicio, un arreglo, una cuota, un producto pendiente de dar de alta) escribiendo el **concepto**, el **precio** y el **IVA**, sin tener que crear el producto en PrestaShop.

## 2. Situación actual

- Solo se venden productos del catálogo sincronizado. El cobro recalcula el precio de cada línea desde el catálogo (`CheckoutService::resolveAuthoritativePricing`) y rechaza los productos que no están en él.
- La venta se sube a PrestaShop como pedido (`outbox`), y un pedido de PrestaShop necesita productos de PrestaShop.
- No hay ninguna línea de venta sin producto.

## 3. Requisitos

### A1 — Añadir un artículo libre
- En la pantalla de venta, un botón «Artículo libre» abre una ventana con:
  - **concepto** (obligatorio, hasta 100 caracteres);
  - **precio con IVA** (obligatorio, mayor que 0, con un máximo configurable; por defecto 1.000 €);
  - **tipo de IVA**, a elegir de una lista configurada (por defecto 21 %; ver P2);
  - **cantidad** (por defecto 1).
- La línea entra en el ticket como cualquier otra y admite cantidad, descuento de línea y eliminación.

### A2 — Cobro e integridad del precio
- El servidor acepta el precio enviado solo para las líneas marcadas como artículo libre. El resto de líneas siguen recalculándose desde el catálogo, como hoy.
- Cada artículo libre queda registrado con concepto, precio, IVA, cajero y caja, para poder auditarlo.
- No descuenta stock.

### A3 — Ticket y registro fiscal
- El ticket muestra el concepto, la cantidad y el precio como cualquier línea.
- El IVA del artículo libre cuenta en el IVA total del ticket y, cuando exista, en el desglose por tipo (`docs/prd/factura-completa.md`, F5).
- El registro fiscal de la venta no cambia: el ticket sigue siendo un `F2` con su total y su cuota.

### A4 — Subida a PrestaShop
El pedido de PrestaShop necesita un producto por línea. Se propone:
- El módulo `tpvconnect` crea al instalarse (o en una actualización) un producto oculto «Artículo libre TPV», sin stock ni visibilidad en la tienda.
- Cada artículo libre se sube como una línea de ese producto, con el concepto en el nombre de la línea y el precio de la venta.

Alternativa: subir el pedido sin esas líneas y anotarlas en un mensaje del pedido. Es más simple, pero el total del pedido no cuadraría con el ticket. Ver P1.

### A5 — Devoluciones
- Un artículo libre se puede devolver como cualquier línea, por el precio cobrado, sin reponer stock.

### A6 — Permisos
- Por defecto pueden usarlo todos los cajeros. Se puede limitar a administradores con un ajuste (ver P3).

## 4. Fuera de alcance

- Crear el producto de verdad en PrestaShop desde el TPV.
- Artículos libres con talla o color.

## 5. Criterios de aceptación

1. Un artículo libre «Arreglo de bañador» de 12,00 € con IVA del 21 % se cobra, aparece en el ticket y suma 2,08 € de IVA.
2. Si alguien manipula el precio de una línea de catálogo haciéndola pasar por artículo libre, el servidor lo rechaza.
3. El pedido llega a PrestaShop con el total igual al del ticket (con la opción A4 elegida).
4. Un artículo libre devuelto genera un abono con su importe y no toca el stock.
5. La venta con artículo libre funciona también sin conexión y se sube después, como el resto.

## 6. Preguntas abiertas

- **P1.** ¿Producto genérico oculto en PrestaShop (recomendado) o líneas fuera del pedido?
- **P2.** ¿Qué tipos de IVA debe ofrecer la lista (21 %, 10 %, 4 %, exento)? ¿Hay servicios exentos, como cuotas o cursos?
- **P3.** ¿Pueden usarlo todos los cajeros o solo los administradores? ¿Importe máximo por artículo?
