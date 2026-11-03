# PRD — Devoluciones y cambios en el TPV

- **Estado:** borrador para revisión
- **Fecha:** 2026-11-03
- **Relación:** amplía RF-31 de `PRD_TPV_PrestaShop_PHP_v1.3.md`

## 1. Objetivo

Permitir que cualquier cajero gestione en el TPV devoluciones y cambios de forma trazable. Cada operación debe dejar coherentes cuatro cosas: el dinero en caja, el stock en PrestaShop, el documento fiscal (abono con serie propia) y el pedido de origen.

## 2. Situación actual

Ya existe un flujo básico: `RefundScreen.js` → `POST /api/refund` → `RefundService` → módulo `tpvconnect` (`controllers/front/refunds.php`), que crea un `OrderSlip` y cambia el estado del pedido. Tiene estos defectos, que esta versión corrige:

| # | Defecto | Dónde |
|---|---------|-------|
| D1 | El stock devuelto no vuelve a PrestaShop. `OrderSlip::create` no lo repone, y el TPV solo suma en su copia local, que la siguiente sincronización sobrescribe. | `RefundService::incrementStock`, `refunds.php` |
| D2 | La reposición local ignora la combinación: suma a todas las tallas y colores del producto. | `RefundService.php:202` |
| D3 | No hay límite de cantidad por línea. Se puede devolver dos veces la misma línea mientras el importe total no se supere. | `RefundService`, `refunds.php` |
| D4 | El TPV valida importes enviados por el navegador, mientras que PrestaShop calcula los suyos. | `RefundService::buildRefundLines` |
| D5 | En pagos mixtos, la parte en efectivo se calcula con lo entregado (incluye el cambio). En tarjeta se crea un movimiento de caja de 0 €. | `RefundService::resolveCashImpactAmount` |
| D6 | Solo se buscan ventas del TPV (`order_meta`). No hay devolución sin ticket, ni cambios, ni ticket de abono. | `OrderSearchService` |

Nota técnica: al **vender**, PrestaShop ya descuenta el stock al crear el pedido (`OrderDetail::updateProductQuantityInStock`), y el TPV no envía stock (corregido el 2026-11-03). Al **devolver** ocurre lo contrario: hay que reponer explícitamente.

## 3. Alcance

**Incluido**
- Devolución con ticket, total o parcial, sobre ventas del TPV de cualquier terminal.
- Devolución de pedidos online de PrestaShop en tienda.
- Devolución sin ticket.
- Cambio de producto con ticket (TPV u online).
- Ticket de abono impreso con serie R propia.
- Búsqueda del ticket escaneando un código.
- Devoluciones como línea separada en los informes X/Z.

**Fuera de alcance**
- Devolución integrada con el datáfono.
- Funcionamiento sin conexión con PrestaShop.
- Reembolso automático a la pasarela de pago web.
- Devolución de gastos de envío.

## 4. Reglas de negocio

### 4.1 Permisos y plazo
- **R1.** Cualquier cajero con sesión y caja abierta puede devolver. No se pide PIN.
- **R2.** El plazo es configurable en Ajustes, con 30 días por defecto. Fuera de plazo la devolución **se bloquea**.
- **R3.** El plazo cuenta desde la fecha del ticket en ventas del TPV, y desde la **fecha de entrega** en pedidos online. Solo se admiten pedidos online en estado entregado.

### 4.2 Qué se puede devolver
- **R4.** Cada línea se puede devolver hasta su cantidad vendida, en una o varias devoluciones parciales. Se lleva la cuenta de lo ya devuelto por línea.
- **R5.** El importe de cada línea lo calcula el servidor; nunca se acepta del navegador. Es el **precio realmente pagado**: si el pedido tuvo cupón o descuento, este se reparte proporcionalmente entre las líneas.
- **R6.** El motivo es obligatorio y se elige de esta lista: Talla o color, Defectuoso, No le gusta, Error de cobro, No coincide con la web, Regalo o duplicado, Llegó tarde y Otro. Con «Otro», el texto es obligatorio.

### 4.3 Stock
- **R7.** Cada línea tiene la casilla «Reponer stock», marcada por defecto. Con el motivo «Defectuoso» se desmarca por defecto, y el cajero puede cambiarla.
- **R8.** Reponer suma la cantidad devuelta al `stock_available` **en vivo** de PrestaShop, para la combinación exacta (producto + `id_product_attribute`). Nunca escribe una cantidad absoluta. La copia local se actualiza en la misma operación.

### 4.4 Dinero
- **R9.** Ventas del TPV: se devuelve **por el mismo medio** con el que se pagó.
  - Efectivo: salida de caja.
  - Tarjeta: el cajero hace la devolución en el datáfono y **confirma** en el TPV que la ha hecho. No genera movimiento de efectivo.
  - Mixto: se reparte en la proporción del pago original, calculada sobre importes cobrados y no sobre lo entregado.
- **R10.** Pedidos online: el TPV **no devuelve dinero**. Crea el abono en PrestaShop y pone el pedido en el estado «Devuelto en tienda – reembolso pendiente». La oficina hace el reembolso desde el back office.
- **R11.** Sin ticket: el cajero elige el medio (efectivo, tarjeta o vale) y el importe es el **precio actual** del producto.
- **R12.** Vale: un cupón nativo de PrestaShop por el importe, con caducidad de 1 año y **uso parcial** (PrestaShop conserva el saldo restante). Se reutiliza el servicio de creación de cupones. El código se imprime en el ticket de abono.

### 4.5 Cambios
- **R13.** Un cambio se hace en una sola operación: se devuelven las líneas, se añaden los artículos nuevos y se calcula el saldo.
  - Saldo a favor de la tienda: el cliente paga la diferencia con el cobro normal.
  - Saldo a favor del cliente: se le devuelve según R9 (ventas del TPV) o R10 (pedidos online).
- **R14.** Solo se admiten cambios con ticket, ya sea del TPV o un pedido online. Aplican el mismo plazo y las mismas reglas de stock.

### 4.6 Devolución sin ticket
- **R15.** No hay pedido de origen, así que no se crea documento en PrestaShop. Queda registrada solo en el TPV (caja, auditoría, informes X/Z) y repone el stock en PrestaShop según R8.

### 4.7 Documento y trazabilidad
- **R16.** Toda devolución o cambio emite un **abono con serie R propia** y correlativa (por ejemplo `R2026-000123`). Si tiene ticket de origen, lo referencia.
- **R17.** El ticket de abono se imprime **siempre**. Incluye las líneas devueltas, el importe, el medio, el motivo y, si lo hay, el código del vale.
- **R18.** Ventas del TPV y pedidos online con ticket: se crea el `OrderSlip` en PrestaShop y se cambia el estado del pedido.
- **R19.** Los informes X y Z muestran las devoluciones en una línea separada, desglosadas por medio, junto con el neto.

### 4.8 Búsqueda y errores
- **R20.** El ticket se busca por referencia, por número de pedido o **escaneando**. El ticket impreso llevará un Code128 con la referencia, además del QR actual. El TPV acepta los dos códigos (del QR extrae la referencia de la URL).
- **R21.** Si PrestaShop no responde, la devolución **se bloquea** con un mensaje claro y no se registra nada a medias.
- **R22.** Si PrestaShop crea el abono pero falla un paso local (caja, numeración, stock local), se registra para conciliar, sin duplicar el abono. Se mantiene el patrón de saga actual (`refund_orphan`).

## 5. Flujos de pantalla

1. **Devolver** → buscar o escanear el ticket, o elegir «Sin ticket».
2. Se muestran las líneas con la cantidad vendida, la ya devuelta y la disponible. El cajero elige cantidades, motivo y la casilla de reponer.
3. **Cambio** (opcional): añadir artículos nuevos y ver el saldo.
4. Resumen: importe, medio o medios y, si es tarjeta, la confirmación del datáfono.
5. Confirmar → abono R → impresión → vuelta a la venta.

## 6. Cambios técnicos previstos

- **Módulo `tpvconnect`:**
  - Validar cantidades contra lo ya devuelto (`product_quantity_refunded`).
  - Reponer stock con `StockAvailable::updateQuantity` cuando se pida.
  - Calcular importes con descuentos prorrateados.
  - Admitir pedidos online.
  - Añadir el estado «reembolso pendiente».
  - Exponer la búsqueda de pedidos online.
- **TPV backend:**
  - Abonos con serie R (nueva tabla y numeración atómica).
  - Cantidades devueltas por línea.
  - Plazo configurable.
  - Movimientos de caja por medio.
  - Endpoint de cambio que encadena devolución y venta.
  - Devolución sin ticket.
- **Impresión:** plantilla de ticket de abono y Code128 en el ticket de venta.
- **Informes X/Z:** desglose de devoluciones.

## 7. Criterios de aceptación

- **A1.** Vender 1 unidad de la talla M y devolverla con reposición: el stock de **la M** en PrestaShop vuelve a su valor anterior y la L no cambia.
- **A2.** Devolver dos veces la misma línea de 1 unidad: la segunda devolución se rechaza.
- **A3.** Un ticket de 100 € con un cupón de 10 € y dos líneas de 50 €: devolver una línea devuelve 45 €.
- **A4.** Un pago mixto de 30 € en efectivo + 70 € con tarjeta, devolviendo 50 €: salen 15 € de caja y se piden 35 € de devolución en el datáfono.
- **A5.** Un pedido online entregado hace 10 días: se crea el abono, el pedido pasa a «reembolso pendiente» y no hay movimiento de caja.
- **A6.** Un ticket de hace 31 días con el plazo en 30: se bloquea.
- **A7.** Con PrestaShop caído, la devolución se bloquea y no queda ningún abono, movimiento ni número R consumido.
- **A8.** Escanear el Code128 o el QR de un ticket abre su devolución.
- **A9.** Un cambio de 40 € por un artículo de 55 € cobra 15 €. Al revés, devuelve 15 € por el medio original.
- **A10.** El informe Z muestra ventas, devoluciones por medio y el neto.

## 8. Puntos abiertos

- Que la gestoría confirme el formato de la factura rectificativa simplificada (serie R, datos obligatorios).
- Cómo se obtiene la fecha de entrega de un pedido online: fecha del estado «Entregado» en el historial del pedido.
- Si conviene poner un importe máximo a las devoluciones sin ticket para limitar el fraude. Hoy no hay límite, por la decisión R1.
