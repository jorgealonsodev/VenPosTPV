# Pago por transferencia

En la ventana de cobro hay un método más: **Transferencia**, junto a Efectivo, Tarjeta, Mixto y Vale regalo.

## Cobrar

1. El cliente hace la transferencia y enseña el justificante (en el móvil, por ejemplo).
2. El cajero lo comprueba, elige **Transferencia** y pulsa **Registrar cobro por transferencia**.

La venta queda **cobrada en ese momento**, como cualquier otra. Se cobra el total entero, el cajón no se abre y no entra efectivo en caja. El ticket y la factura indican «Transferencia» como forma de pago. El pedido llega a PrestaShop con el método de pago `transfer`.

Con un cupón de descuento, el resto que queda por pagar también se puede cobrar por transferencia (botón **Transferencia** junto a Efectivo y Tarjeta). En un cambio de artículos, la diferencia a favor de la tienda también se puede cobrar así.

El TPV no comprueba en el banco que el dinero haya llegado: es responsabilidad del cajero mirar el justificante.

## Devolver o cambiar un ticket pagado por transferencia

La pantalla de devoluciones pregunta **cómo se devuelve**:

- **Por transferencia** (la opción por defecto): el TPV solo registra la devolución. La transferencia al cliente la hace el administrador desde el banco.
- **En efectivo**: sale de la caja como cualquier devolución en efectivo.

El abono indica «Transferencia: X €» o «Efectivo: X €» según lo elegido. En un cambio, se aplica lo mismo a la diferencia que se devuelve al cliente.

## Cierre de caja e informes

Las transferencias nunca cuentan en el efectivo esperado.

- **Informes X y Z** (ticket y PDF): «Ventas transferencia» y «Devol. transferencia», solo en las cajas que han tenido transferencias. Los informes de las demás cajas no cambian.
- **Cierre de caja**: «Ventas por transferencia» y «Devoluciones por transferencia», también solo si las hay.
- **Informes → Por forma de pago** (pantalla y CSV): ventas y devoluciones por transferencia y número de tickets por transferencia.

## Datos

- La venta guarda el importe en `order_meta.payment_breakdown.tender.transfer`, con efectivo y tarjeta a 0.
- La devolución guarda la parte devuelta por transferencia en `refund.transfer_amount` (migración `Version00000051RefundTransferAmount`, que se aplica sola al actualizar).
- La API de cobro rechaza un método de pago desconocido (antes se apuntaba como tarjeta).
- `POST /api/refund` y `POST /api/exchange` aceptan `transfer_refund`: `transfer` (por defecto) o `cash`.
