# Factura completa

Cuando un cliente pide factura con sus datos (por ejemplo, una empresa), se cobra con ticket como siempre y, en el momento o más tarde, el ticket se **canjea** por una factura completa. La factura sustituye al ticket (art. 15.6 del Reglamento de facturación) sin anularlo: el ticket y su registro fiscal siguen siendo válidos.

Esta es la fase 1 del PRD (`docs/prd/factura-completa.md`). Todavía no hay factura en el momento de cobrar (fase 2), impresora de facturas A4 en el agente (fase 3) ni rectificativas de facturas (fase 4).

## Antes de empezar

- **Datos de la tienda.** La factura lleva la razón social, el NIF y el domicilio de la tienda de **Ajustes → Tienda**. Sin NIF válido, razón social o domicilio, el TPV no emite facturas y dice qué falta.
- **Letra F.** Las facturas usan su propia serie, `F` + año + 6 cifras (`F26000001`), una sola para toda la tienda. Ninguna caja puede usar la letra F para sus tickets: Ajustes → Cajas responde «La letra F está reservada para las facturas». Si al actualizar alguna caja ya la usa, la actualización se detiene y pide cambiarla.

## Canjear un ticket por factura

1. **Tickets → Cobrados**, abre la venta y pulsa **Factura completa**.
2. **Cliente.** Busca por nombre, NIF, email o teléfono, o pulsa **+ Nuevo cliente**. Si al cliente le faltan datos fiscales, se abre el formulario con los campos que faltan marcados:
   - razón social o nombre completo, NIF/NIE/CIF, domicilio, código postal y población (la provincia es opcional);
   - **cliente extranjero:** cambia el país (código de dos letras, p. ej. `FR`) y elige el tipo de documento: `02` NIF-IVA, `03` pasaporte, `04` documento oficial del país de residencia, `05` certificado de residencia o `06` otro documento. El número no se comprueba.
   - El NIF español se comprueba con su letra o dígito de control: «El NIF no es válido».
3. **Resumen.** Muestra el ticket, su fecha e importe, el cliente, el cuadro de IVA (base, tipo y cuota por cada tipo) y el número que tendrá la factura. Marca **«El cliente acepta recibir la factura por email»** si el cliente quiere recibirla por email.
4. Pulsa **Emitir factura**. Se abre la ventana para **descargar el PDF** (A4), **enviarlo por email** o **por WhatsApp** ([enviar-por-whatsapp.md](enviar-por-whatsapp.md)).

El detalle de la venta muestra desde entonces **«Sustituido por F26000001»** y el botón **Factura F26000001**, que vuelve a abrir esa ventana. Una copia (descarga o reenvío) es siempre el mismo documento, con los datos guardados al emitirla: editar después el cliente no cambia facturas ya emitidas.

## Qué lleva la factura (A4)

Número y fecha de expedición; fecha de operación (la del ticket) si es otro día; emisor y cliente con NIF y domicilio; cada línea con precio unitario sin IVA, cantidad, descuento sin IVA (el de la línea más la parte del descuento del carrito o cupón) e importe sin IVA; el cuadro por tipo de IVA; el total; la forma de pago (el crédito de cambio aparece como forma de pago y no reduce la base); y la mención «Sustituye a la factura simplificada A26000123 de 03/11/2026».

El IVA sale de las líneas guardadas al cobrar, con la misma regla que el ticket (se redondea una vez por tipo), así que ticket y factura coinciden al céntimo.

## Email

Solo con el consentimiento del cliente (art. 9.2): la casilla del resumen o la de la ventana de envío. El consentimiento queda guardado con la factura. Sin él, el TPV no la envía: «El cliente no ha aceptado recibir la factura por email». Hace falta la cuenta de correo de Ajustes → Email.

## WhatsApp

Con el mismo consentimiento que el email (la casilla de la ventana de envío dice «El cliente acepta recibir la factura por email o WhatsApp» y se guarda igual con la factura). Sin él no se crea el enlace: «El cliente no ha aceptado recibir la factura en formato electrónico». No hace falta cuenta de correo. Ver [enviar-por-whatsapp.md](enviar-por-whatsapp.md).

## Cuándo no se puede canjear

| Situación | Mensaje |
|---|---|
| El ticket ya se canjeó | «Este ticket ya se canjeó por la factura F26000001» |
| El ticket tiene devoluciones | «Este ticket tiene devoluciones; no se puede canjear» |
| Venta anterior a la factura completa, o con artículos de IVA desconocido | «Este ticket no tiene registrado el IVA de cada línea…» |
| Artículos al 0 % (sin causa de exención configurada) | «La venta tiene artículos sin IVA (0 %)…» |
| Venta de 0 € | «No hay importe que facturar» |
| Faltan datos del cliente | «Falta el NIF del cliente» / «Faltan datos fiscales del cliente: …» |
| Faltan datos de la tienda | «Faltan datos de la tienda para facturar (Ajustes → Tienda): …» |

Un ticket de otro año se canjea con número del año en curso y la fecha de operación del ticket. Un ticket de más de 4 años se canjea con un aviso para confirmarlo con la gestoría. Si dos cajas canjean el mismo ticket a la vez, solo una obtiene la factura; la otra recibe «Este ticket ya se canjeó…» y no se gasta ningún número.

Funciona aunque la venta no se haya subido aún a PrestaShop o PrestaShop no responda: la factura es solo del TPV. Los datos fiscales del cliente tampoco se suben a PrestaShop.

## Numeración

**Ajustes → Cajas → Numeración → Próxima factura**: solo puede avanzar, pide un motivo y queda registrado en «Cambios de numeración», igual que tickets y abonos. Si una factura no llega a emitirse (error), su número no se gasta: la serie no tiene huecos.

## Registro fiscal

Cada factura genera un registro `F3` encadenado en `fiscal_record` en la misma transacción que le da el número (ver `docs/verifactu.md`). El registro `F2` del ticket no cambia.

## Pendiente de confirmar con el asesor

- **Tickets sin tipo de IVA impreso** (anteriores a la fase 0): no se pueden canjear por ahora (P8).
- **Exenciones al 0 %** (p. ej. art. 20.Uno.13.º LIVA): hasta configurar su causa, esas ventas no se facturan (P4).
- **Tickets con devoluciones** (P3) y **devoluciones de un ticket ya canjeado**: la rectificativa de la factura llega en la fase 4; hasta entonces, consultar con la gestoría.
- **Plazo** para canjear (P7): se permite siempre, con aviso a partir de 4 años.

## API

| Método y ruta | Uso |
|---|---|
| `PUT /api/customers/{id}` | datos fiscales del cliente (`tax_name`, `country`, `tax_id_type`, `tax_id`, `address`, `postcode`, `city`, `province`) |
| `GET /api/invoices/from-sale/{orderId}/preview?customer_id=` | resumen sin emitir |
| `POST /api/invoices/from-sale/{orderId}` | canje `{customer_id, email_consent, request_id}`; 201, o 200 si el `request_id` ya emitió esa factura; 409 si ya se canjeó; 422 con `missing` si faltan datos |
| `GET /api/invoices/by-sale/{orderId}` | factura que sustituyó al ticket, o `null` |
| `GET /api/invoices/{id}` y `GET /api/invoices/{id}/pdf` | factura y PDF A4 |
| `POST /api/invoices/{id}/email` | `{email, consent}`; 422 sin consentimiento |
| `PUT /api/numbering/invoices` | próximo número `{next_number, reason}` (solo administradores) |
