# Pago por transferencia

En la ventana de cobro hay un método más: **Transferencia**, junto a Efectivo, Tarjeta, Mixto y Vale regalo.

## Cobrar

1. El cajero elige **Transferencia** y pulsa **Registrar venta pendiente de transferencia**.
2. Se emite el ticket en ese momento, como cualquier venta: lleva su número y su registro fiscal, porque el IVA se devenga al entregar la mercancía. El cajón no se abre y no entra efectivo en caja.
3. El ticket indica «Pendiente de pago por transferencia», el **número de cuenta** de la tienda y el **concepto** que debe poner el cliente (el número del ticket, por ejemplo `A26000070`).
4. La venta queda **pendiente de pago** hasta que se vea el dinero en el banco.

El número de cuenta se configura en **Ajustes → Tienda → Número de cuenta (IBAN)**. Se comprueba su dígito de control, y vale para todas las cajas de la tienda. Sin número de cuenta, el ticket solo indica que está pendiente.

Con un cupón de descuento, el resto también se puede pagar por transferencia (botón **Transferencia** junto a Efectivo y Tarjeta). En un cambio de artículos, la diferencia a favor de la tienda también se puede pagar así. En los dos casos la venta queda igualmente pendiente de pago.

El pedido llega a PrestaShop con el método de pago `transfer`.

## Confirmar que ha llegado

**Tickets → Pendientes de pago** lista las ventas por transferencia que aún no han llegado, de la más antigua a la más reciente, con el número de ticket, la fecha, el cajero, la caja y el importe. La pestaña muestra cuántas hay.

Un **administrador** pulsa **Marcar como recibida** cuando ve el ingreso en el banco. Los cajeros ven la lista, pero no pueden marcarlas. Se guarda quién la marcó y cuándo, y no se puede marcar dos veces.

Al marcarla se abre el detalle de esa venta, que permite **imprimir** el ticket, abrir el **PDF** o enviarlo por **email** o **WhatsApp**. El ticket ya no dice «Pendiente de pago».

## Devolver o cambiar un ticket pagado por transferencia

Mientras la transferencia esté **pendiente de pago**, el ticket no se puede devolver ni cambiar: no ha entrado dinero que devolver. El TPV avisa de que primero hay que marcarla como recibida en **Tickets → Pendientes de pago**.

Una vez recibida, la pantalla de devoluciones pregunta **cómo se devuelve**:

- **Por transferencia** (la opción por defecto): el TPV solo registra la devolución. La transferencia al cliente la hace el administrador desde el banco.
- **En efectivo**: sale de la caja como cualquier devolución en efectivo.

El abono indica «Transferencia: X €» o «Efectivo: X €» según lo elegido. En un cambio, se aplica lo mismo a la diferencia que se devuelve al cliente.

## Cierre de caja e informes

Las transferencias nunca cuentan en el efectivo esperado.

- **Informes X y Z** (ticket y PDF): «Ventas transferencia» y «Devol. transferencia», solo en las cajas que han tenido transferencias. Debajo de las ventas va «Pendiente de pago», con la parte que aún no ha llegado. El Z guarda lo que estaba pendiente en el momento del cierre. Los informes de las demás cajas no cambian.
- **Cierre de caja**: «Ventas por transferencia», «· pendientes de pago» y «Devoluciones por transferencia», solo si las hay.
- **Informes → Por forma de pago** (pantalla y CSV): ventas por transferencia, transferencias pendientes de pago (incluidas en las anteriores), devoluciones por transferencia y número de tickets por transferencia.

## Datos

- La venta guarda el importe en `order_meta.payment_breakdown.tender.transfer`, con efectivo y tarjeta a 0, y una fila en `transfer_payment` (`pending` → `received`, con `received_at` y `received_by_user_id`).
- `terminal.bank_account` guarda el IBAN sin espacios. La tabla y la columna llegan con la migración `Version00000052TransferPaymentAndBankAccount`, que se aplica sola al actualizar.
- `GET /api/transfers/pending` lista las pendientes, y `POST /api/transfers/{id}/received` las marca como recibidas (solo administradores; 409 si ya lo estaba).
- La devolución guarda la parte devuelta por transferencia en `refund.transfer_amount` (migración `Version00000051RefundTransferAmount`, que se aplica sola al actualizar).
- La API de cobro rechaza un método de pago desconocido (antes se apuntaba como tarjeta).
- `POST /api/refund` y `POST /api/exchange` aceptan `transfer_refund`: `transfer` (por defecto) o `cash`.
