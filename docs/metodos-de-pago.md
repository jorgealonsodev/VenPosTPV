# Métodos de pago

En **Ajustes → Tienda → Métodos de pago** el administrador elige qué formas de cobro ofrece la tienda. La elección vale para todas las cajas.

| Método | Qué es |
|---|---|
| Efectivo | Cobro en metálico, con cambio. |
| Tarjeta | Cobro con el datáfono. |
| Mixto | Una parte en efectivo y el resto con tarjeta. Necesita Efectivo y Tarjeta activos. |
| Transferencia | La venta queda pendiente de pago hasta que llega el dinero (ver [Pago por transferencia](pago-transferencia.md)). |
| Vale regalo | Cobro con un cupón de PrestaShop. |

Marca los que uses y pulsa **Guardar métodos de pago**. Si cierras Ajustes con un cambio sin guardar, se guarda al cerrar, como el resto de la ventana.

Mientras no se elija nada, están todos activos: una instalación que nunca ha tocado este ajuste funciona como antes.

## Qué cambia en la caja

- La ventana de cobro muestra solo los métodos activos y se abre en el primero de ellos.
- Tras aplicar un cupón, el resto se puede pagar solo con los activos de entre Efectivo, Tarjeta y Transferencia.
- En un cambio de artículos, la diferencia a favor de la tienda se cobra con los métodos activos.
- El servidor rechaza un cobro con un método desactivado: «El método de pago «Tarjeta» no está activo en Ajustes → Tienda», o «El pago con vale regalo no está activo en Ajustes → Tienda» para un cupón.

Las devoluciones no cambian: se devuelve según cómo se pagó el ticket.

## Reglas

- Tiene que quedar al menos un método activo («Deja al menos un método de pago activo»).
- Mixto solo se puede activar con Efectivo y Tarjeta («El pago mixto necesita Efectivo y Tarjeta activos»).

## Detalles técnicos

- `GET /api/settings/payment-methods` (cualquier usuario con sesión) responde `{data: {enabled: [...], available: [...]}}`; `PUT` con `{enabled: [...]}` solo para administradores (`403` para el resto, `422` si la elección no es válida).
- Códigos: `cash`, `card`, `mixed`, `transfer` y `voucher`. Se guardan en `app_setting` con el nombre `payment.methods`; sin ese valor, todos están activos.
- La comprobación del servidor está en `CheckoutService` (`PaymentMethodsSettings`).
