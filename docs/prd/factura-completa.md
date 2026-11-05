# PRD — Factura completa desde el TPV

- **Estado:** borrador para revisión (revisado contra el código y el BOE el 2026-11-05)
- **Fecha:** 2026-11-05
- **Relación:** complementa la numeración de tickets (`TicketNumberer`), los abonos (`docs/prd/devoluciones.md`), las líneas de venta (`docs/prd/informes-ventas.md`), el artículo libre (`docs/prd/articulo-libre.md`) y el registro fiscal (`docs/verifactu.md`)

## 1. Objetivo

Que el cajero pueda entregar una **factura completa** (con los datos y el NIF del cliente) cuando el cliente la pide, ya sea en el momento de la venta o después, a partir de un ticket ya emitido, sin salir del TPV.

## 2. Situación actual (verificada en el código)

| Tema | Qué hay hoy | Evidencia |
|---|---|---|
| Documentos | Solo **facturas simplificadas** (tickets `A26000123`: letra de caja + año + 6 cifras) y sus rectificativas (abonos `R2026-000001`, serie única de tienda) | `src/Service/Sale/TicketNumberer.php:48`, `src/Service/Refund/CreditNoteNumberer.php:56` |
| Numeración | Tickets: contador por letra de caja y año (`ticket_sequence`). Abonos: contador por año (`credit_note_sequence`). Ambos con `INSERT … ON DUPLICATE KEY UPDATE LAST_INSERT_ID(…)` dentro de la transacción de la venta | `migrations/install.sql:346-357`, `TicketNumberer.php:48-62` |
| IVA de la venta | Solo el **IVA total** en `order_meta.payment_breakdown.tax`. Las líneas guardan nombre, producto, combinación, cantidad, precio con IVA, `line_total` y `paid_total`; **no** guardan tipo de IVA, `price_net` ni el % de descuento | `src/Service/Checkout/CheckoutService.php:936-990` |
| Cálculo del IVA | Suma `(price − price_net) × qty × (1 − dto)` y la escala por `total / subtotal`. Si ninguna línea tiene `price_net`, supone **21 %** sobre el total | `CheckoutService.php:1022-1047`, `:52` |
| Catálogo | `price_ttc` y `price_net` por producto y combinación; **no** guarda el tipo de IVA. `price_net` se redondea a 2 decimales al cobrar | `install.sql:193-219`, `CheckoutService.php:821-834` |
| Referencia de la venta | `order_meta.reference VARCHAR(9)`; el módulo de PrestaShop solo acepta referencias `/^[A-Z]\d{8}$/` y la usa como referencia del pedido y clave de idempotencia | `install.sql:108`, `modules/tpvconnect/classes/ReferencedOrderCreator.php:98` |
| Clientes | `customer`: nombre, apellidos, email, teléfono. Sin NIF, razón social ni domicilio. La API solo permite **buscar y crear** (`GET/POST /api/customers`), no editar. El cliente de la venta no se guarda en `order_meta`, solo en la carga que se sube a PrestaShop | `install.sql:407-423`, `src/Controller/Api/CustomerController.php`, `CheckoutService.php:308-314` |
| Registro fiscal | `fiscal_record` solo usa `F2` (ticket) y `R5` (abono). `UNIQUE (order_id)`: una venta solo puede tener un registro. El NIF emisor sale de `terminal.tax_id` | `src/Service/Fiscal/FiscalRecorder.php:24-25`, `install.sql:538`, `FiscalRecorder.php:121` |
| Ticket impreso | Muestra «Subtotal», «IVA: x €» y «TOTAL», pero **no el tipo impositivo** | `src/Service/Print/EscPosTicketBuilder.php:174-176`, `templates/ticket/print.html.twig:103` |
| PDF | `TicketPdfGenerator` (Dompdf) genera PDF de **80 mm**, igual que el informe Z; no hay plantilla A4 | `src/Service/Print/TicketPdfGenerator.php:73`, `CashReportPdf.php:34` |
| Email | `TicketMailer::sendSale` envía el PDF del ticket (`POST /api/ticket/sale/{id}/email`) | `src/Service/Mail/TicketMailer.php:41`, `TicketEmailController.php` |
| Agente de impresión | Destinos `ticket` y `label`; todos los modos envían bytes en **RAW** (Spooler RAW, `lp -o raw`, dispositivo, TCP 9100). Tipos de trabajo: `ticket`, `drawer`, `label` | `agent/agent-core/src/config.rs:23-58`, `src/Service/PrintAgent/PrintJobQueue.php:45` |
| PrestaShop | Los pedidos del TPV se crean con `validateOrder` en el estado «Pagado en TPV» (`TPV_OS_PAID`), creado **sin** la marca «factura», así que PrestaShop **no** genera su propia factura al crearlos | `modules/tpvconnect/controllers/front/orders.php:171`, `modules/tpvconnect/install/install.php:67-77` |

## 3. Marco legal (verificado en los textos consolidados del BOE)

**RD 1619/2012 (Reglamento de facturación)**

- **Art. 2.2.a:** factura obligatoria cuando el destinatario es empresario o profesional, y en las operaciones en que «el destinatario así lo exija para el ejercicio de cualquier derecho de naturaleza tributaria».
- **Art. 6.1:** contenido: a) número y serie, «La numeración de las facturas dentro de cada serie será correlativa»; b) fecha de expedición; c) nombre o razón social de emisor y destinatario; d) NIF del emisor y, entre otros casos, del destinatario cuando la operación se entienda realizada en España y el emisor esté establecido aquí (6.1.d.3.º; es el caso de la tienda); e) domicilio de ambos; f) descripción, «incluyendo el precio unitario sin Impuesto … así como cualquier descuento o rebaja que no esté incluido en dicho precio unitario»; g) tipo o tipos; h) cuota «que deberá consignarse por separado»; i) fecha de la operación si es distinta de la de expedición; j) si está exenta, la referencia al precepto o la «indicación de que la operación está exenta».
- **Art. 6.2.c:** la base de cada tipo de IVA se especifica por separado.
- **Art. 6.1.a, 2.º:** las rectificativas van en **series específicas**.
- **Art. 7.1.a, último párrafo:** si en un mismo año se expiden simplificadas y completas, es «obligatoria la expedición mediante series separadas de unas y otras».
- **Art. 7.1.f:** el ticket debe llevar el «Tipo impositivo aplicado y, opcionalmente, también la expresión «IVA incluido»».
- **Art. 7.2 y 7.3:** alternativa ligera: el ticket puede llevar además NIF y domicilio del destinatario y la cuota por separado (en VERI\*FACTU sería tipo `F1`, ver abajo).
- **Art. 9.2:** la factura electrónica (también el PDF por email) «estará condicionada a que su destinatario haya dado su consentimiento».
- **Art. 11.1:** «Las facturas deberán ser expedidas en el momento de realizarse la operación»; si el destinatario es empresario, «antes del día 16 del mes siguiente».
- **Art. 15.6:** «las facturas que se expidan en sustitución o canje de facturas simplificadas expedidas con anterioridad no tendrán la condición de rectificativas, siempre que las facturas simplificadas expedidas en su día cumpliesen los requisitos establecidos en el artículo 7.1». **Consecuencia:** como los tickets actuales no muestran el tipo impositivo (7.1.f), el canje de esos tickets podría no acogerse al 15.6; hay que corregir el ticket (fase 0) y consultar al asesor por los tickets ya emitidos (P8).

**RD 1007/2023 (RRSIF), art. 10.1.h:** el registro de alta debe incluir, si la factura sustituye a simplificadas, la «identificación de las facturas simplificadas sustituidas».

**Orden HAC/1177/2024, anexo (lista L2 y bloque «RegistroAlta», págs. 18-19 del consolidado):**

| Caso | `TipoFactura` | Bloques que debe llevar el registro |
|---|---|---|
| Factura completa emitida en la venta | `F1` «Factura (art. 6, 7.2 y 7.3 del RD 1619/2012)» | `Destinatarios/IDDestinatario` (NombreRazon + `NIF` o `IDOtro`) |
| Factura que canjea un ticket | `F3` «Factura emitida en sustitución de facturas simplificadas facturadas y declaradas» | `Destinatarios` + `FacturasSustituidas/IDFacturaSustituida` (1-1000): `IDEmisorFactura`, `NumSerieFactura`, `FechaExpedicionFactura` del ticket |
| Rectificativa de una factura completa | `R1` (error fundado en derecho y art. 80 Uno, Dos y Seis LIVA) o `R4` (resto) | `TipoRectificativa` (`S`/`I`), `FacturasRectificadas`; si es por sustitución, `ImporteRectificacion` |
| Abono de ticket (ya existe) | `R5` | sin cambios |

Además, todo registro lleva `DescripcionOperacion` (obligatorio, hasta 500 caracteres), `Desglose` con 1-12 `DetalleDesglose` (`Impuesto`, `ClaveRegimen` L8A, `CalificacionOperacion` L9 **o** `OperacionExenta` L10, `TipoImpositivo`, `BaseImponibleOimporteNoSujeto`, `CuotaRepercutida`), `CuotaTotal`, `ImporteTotal` y, si la fecha de la operación difiere, `FechaOperacion`. Para clientes sin NIF español, `IDOtro` lleva `CodigoPais` (ISO 3166-1 alfa-2), `IDType` (L7: `02` NIF-IVA, `03` pasaporte, `04` documento oficial del país de residencia, `05` certificado de residencia, `06` otro documento probatorio, `07` no censado) e `ID` (hasta 20 caracteres). `NumSerieFactura` admite hasta 60 caracteres.

## 4. Requisitos

### F1 — Datos fiscales del cliente
- Campos nuevos del cliente: razón social o nombre completo fiscal, país (por defecto `ES`), tipo de documento, número de documento, domicilio, código postal, población y provincia.
- **Cliente español** (`país = ES`): NIF/NIE/CIF validado con `SpanishTaxId::isValid` (`src/Service/Fiscal/SpanishTaxId.php:21`) y normalizado (mayúsculas, sin espacios ni guiones).
- **Cliente extranjero:** tipo de documento obligatorio de la lista L7 (`02` NIF-IVA, `03` pasaporte, `04`, `05`, `06`); número de 1 a 20 caracteres sin validación de dígito de control; domicilio libre con país.
- Se rellenan al crear el cliente o **editándolo** (nuevo `PUT /api/customers/{id}`). Solo son obligatorios para emitir una factura completa; si faltan, el TPV abre el formulario con los campos que faltan marcados.
- La factura guarda una **copia** de los datos del destinatario en el momento de emitirla; editar el cliente después no cambia facturas emitidas.
- Los datos fiscales se quedan en el TPV (no se suben a PrestaShop en esta versión; ver P2).
- Clientes existentes: la migración solo añade columnas vacías. Opcional (fase 4): importar NIF, empresa y domicilio de las direcciones de PrestaShop (`address.dni`, `vat_number`, `company`) como sugerencia que el cajero confirma.

### F2 — Factura en el momento de la venta
1. En la pantalla de cobro, el interruptor «Factura completa» (desactivado por defecto) abre el selector de cliente con datos fiscales (buscar o crear).
2. Si faltan datos o el NIF no es válido, no deja cobrar con factura y lo dice: «Falta el NIF del cliente» / «El NIF no es válido».
3. Al cobrar se emite una factura completa (`F1`) **en lugar del ticket**, con su propia serie (F4), y ningún número de ticket.
4. La referencia de la venta y del pedido de PrestaShop es el número de factura, que por eso tiene 9 caracteres y el formato `/^[A-Z]\d{8}$/` (F4).
5. Se imprime en A4 (F6/F6b), no en la impresora de tickets; si la caja tiene cajón, se abre como siempre.

### F3 — Factura a partir de un ticket ya emitido (canje)
1. En Tickets → Cobrados → detalle de la venta, el botón «Factura completa» pide el cliente (F1) y muestra un resumen: ticket, fecha, importe, cliente.
2. Al confirmar se emite una factura `F3` que **sustituye** al ticket (art. 15.6), con la mención «Sustituye a la factura simplificada A26000123 de 03/11/2026» y la **fecha de operación** del ticket (art. 6.1.i) si es distinta de la de hoy.
3. Un ticket solo se canjea una vez; un segundo intento responde «Este ticket ya se canjeó por la factura F26000004».
4. El ticket original no se modifica ni se anula: su registro `F2` y su QR siguen válidos. El detalle de la venta muestra «Sustituido por F26000004» y una reimpresión del ticket lo indica al pie.
5. Ticket con devoluciones: ver P3 (recomendado: impedirlo en la fase 1).
6. Funciona aunque la venta aún no se haya subido a PrestaShop: la factura es solo local.
7. Las devoluciones posteriores de una venta canjeada rectifican la **factura**, no el ticket (F8).

### F4 — Serie y numeración
- **Una única serie de facturas para la tienda** (recomendado, ver P1), por año, distinta de tickets y abonos (art. 7.1.a): `F` + año con 2 cifras + 6 cifras, p. ej. `F26000001`.
- La letra `F` queda **reservada**: Ajustes → Cajas no deja asignarla como letra de ticket, y la migración se detiene con un aviso si alguna caja ya la usa.
- Numeración correlativa y sin huecos, con el mismo mecanismo que `TicketNumberer::next` (tabla `invoice_sequence (series, year, last_number)`, número tomado dentro de la transacción que crea la factura y su registro fiscal). Si la transacción falla, no se consume número.
- F1 y F3 comparten la serie (ambas son facturas completas).
- Ajustes → Cajas → Numeración: «Próxima factura», solo hacia delante y con motivo, como `NumberingSettings::setCreditNoteNext` (`src/Service/Numbering/NumberingSettings.php:125`).

### F5 — Desglose de IVA y redondeo
- Al cobrar, cada línea guarda su **tipo de IVA**, **base** y **cuota** en la tabla de líneas de venta de `docs/prd/informes-ventas.md` (una sola tabla para los dos PRD). El tipo sale de `price_ttc / price_net` del catálogo, **ajustado al tipo configurado más cercano** (21, 10, 4, 0), porque `price_net` llega redondeado a 2 decimales. El artículo libre guarda el tipo que eligió el cajero.
- **Redondeo por tipo, no por línea:** para cada tipo, `total_tipo = Σ paid_total`, `base = round(total_tipo / (1 + tipo), 2)`, `cuota = total_tipo − base`. Así base + cuota cuadran al céntimo con lo cobrado y el IVA total es la suma de las cuotas. El precio unitario sin IVA de cada línea se muestra con 2 decimales a título informativo.
- **Descuentos:** cada línea muestra precio unitario sin IVA, cantidad, descuento (línea + parte prorrateada del descuento del carrito o cupón, `line_total − paid_total`, sin IVA) e importe neto (art. 6.1.f).
- **Crédito de cambio** (`tender.exchange_credit`): es una forma de pago; no reduce la base. La factura lo muestra en «Forma de pago».
- **Vales de devolución canjeados como cupón:** hoy entran como descuento del carrito y reducen la base. Ver P9.
- **Ventas antiguas** (sin líneas con tipo): el tipo se deduce del catálogo **actual** y la factura se emite solo si la suma de bases y cuotas recalculada difiere del IVA guardado en ≤ 0,02 €; si no, se avisa «No se puede deducir el IVA de esta venta» y no se emite (P4).
- **Exento o no sujeto:** una línea con tipo 0 % debe llevar causa: `E1`…`E6` (exenta, con la mención del precepto, art. 6.1.j) o `N1`/`N2` (no sujeta). Sin causa configurada, la venta no se puede facturar (P4).

### F6 — Documento
- La factura completa se entrega **siempre en A4** (decisión del club, 2026-11-05: «una factura se entregara en A4»). Nunca se imprime en la impresora de tickets.
- Plantilla A4 nueva (`InvoicePdfGenerator`, Dompdf, `setPaper('A4')`) con: título «Factura» o «Factura rectificativa», número y fecha, fecha de operación si difiere, emisor (razón social, NIF y domicilio de la tienda), destinatario (copia de F1), líneas (F5), cuadro por tipo (base, tipo, cuota), total, forma de pago, mención de sustitución (F3) o de factura rectificada (F8), mención de exención si procede y, cuando VERI\*FACTU se active, QR y leyenda.
- Se imprime en la impresora de facturas (F6b). Sin ella, se abre la ventana «Descargar PDF / Enviar por email», que hoy envía el ticket de 80 mm y debe aceptar también la factura.
- **Email:** solo con el consentimiento del cliente (art. 9.2): casilla «El cliente acepta recibir la factura por email», que se guarda con la factura. El email se envía desde `TicketMailer` con el PDF A4 adjunto.
- **Copias:** reimprimir o reenviar produce el mismo PDF (mismos datos guardados), sin número nuevo.

### F6b — Impresora de facturas (A4)
- Cada caja puede tener una **tercera impresora**, además de la de tickets y la de etiquetas: la **impresora de facturas**, normalmente una impresora A4 de oficina (láser o de tinta) conectada al ordenador de la caja o en la red.
- Se configura donde las otras dos: en el agente de impresión de esa caja (un destino nuevo, «facturas», junto a «ticket» y «etiqueta») y en Ajustes → Cajas, donde se ve su estado.
- Al emitir una factura completa:
  - con impresora de facturas configurada, se imprime en ella el PDF A4 y se sigue el trabajo en el historial de impresión, como los tickets;
  - sin ella, se ofrece la ventana de «Descargar PDF / Enviar por email» que ya existe.
- Opcional: imprimir también en esa impresora los informes Z y los abonos en A4.
- Cambios técnicos:
  - servidor: tipo de trabajo `invoice` y destino `invoice` en `PrintJobQueue` (hoy `ticket`, `drawer`, `label`), con caducidad larga (como `label`, 24 h) y carga PDF;
  - agente: `Target::Invoice` en `config.rs` y un modo de destino que **no** sea RAW: los cuatro modos actuales envían bytes en crudo;
  - Linux: CUPS imprime PDF directamente con `lp -d <cola>` (sin `-o raw`);
  - Windows: el Spooler RAW no interpreta PDF; hace falta un programa que imprima PDF en silencio (p. ej. SumatraPDF incluido en el instalador) o la API de impresión de Windows;
  - fallo de impresión: el trabajo queda en el historial con su error y la ventana de descarga sigue disponible.

### F7 — Registro fiscal
- Cada factura genera su registro encadenado en `fiscal_record`, en la misma transacción que le da el número:
  - `F1` en la venta con factura (no se genera `F2`);
  - `F3` en el canje, que **no** sustituye ni anula el `F2` del ticket;
  - `R1`/`R4` en la rectificativa de una factura (F8).
- `fiscal_record` necesita una columna `invoice_id` (única) porque `uk_fiscal_record_order` impide un segundo registro de la misma venta: el `F3` se graba con `invoice_id` y `order_id = NULL`.
- La huella no cambia (`FiscalHash::alta` usa NIF, número, fecha, tipo, cuota y total). Los datos que el envío a la AEAT necesitará (destinatario, desglose, ticket sustituido, `FechaOperacion`) quedan en la tabla `invoice`.

### F8 — Rectificativas de facturas completas
- Una devolución de una venta facturada (F1) o canjeada (F3) emite una **factura rectificativa** de la factura, no un abono `R5` del ticket.
- Serie propia distinta de la de abonos de ticket (recomendado `G` + año + 6 cifras; ver P5), tipo `R1` (devoluciones y descuentos posteriores, art. 80 LIVA) y por diferencias (`I`), con importes negativos, como los abonos actuales.
- Lleva el número y la fecha de la factura rectificada, el motivo y el desglose por tipo de lo devuelto.
- Los cambios (devolución + venta nueva) siguen el mismo camino: rectificativa de la factura y, si se pide, factura nueva.

### F9 — Modelo de datos (borrador)

| Tabla | Cambio |
|---|---|
| `customer` | `tax_name VARCHAR(120)`, `country CHAR(2) DEFAULT 'ES'`, `tax_id_type CHAR(2)` (`NI` NIF español o L7), `tax_id VARCHAR(20)`, `address VARCHAR(255)`, `postcode VARCHAR(12)`, `city VARCHAR(100)`, `province VARCHAR(100)` |
| `invoice` (nueva) | `id`, `number VARCHAR(20) UNIQUE`, `series`, `year`, `seq`, `invoice_type CHAR(2)` (F1/F3/R1/R4), `order_id` (venta; `UNIQUE` en F1/F3 mediante columna `sale_order_id`), `refund_id UNIQUE NULL` (R), `rectified_invoice_id NULL`, `replaced_reference VARCHAR(9) NULL` + `replaced_date` (F3), `customer_id`, `recipient JSON` (copia), `lines JSON`, `tax_breakdown JSON`, `tax_total`, `total`, `issue_date`, `operation_date`, `email_consent_at NULL`, `terminal_id`, `user_id`, `created_at` |
| `invoice_sequence` (nueva) | `series CHAR(1)`, `year SMALLINT`, `last_number INT`, PK (`series`, `year`) |
| `sale_line` (de `informes-ventas.md`) | añade `vat_rate DECIMAL(5,2)`, `vat_base`, `vat_amount`, `vat_cause CHAR(2) NULL`, `approximate TINYINT` |
| `fiscal_record` | `invoice_id BIGINT UNSIGNED NULL UNIQUE` |
| `print_job` | admite `type = 'invoice'`, `target = 'invoice'` |
| `terminal` / ajustes | impresora de facturas de la caja (estado que informa el agente) |

### F10 — API (borrador)

| Método y ruta | Uso |
|---|---|
| `PUT /api/customers/{id}` | editar cliente y datos fiscales (F1) |
| `POST /api/checkout` (existente) | acepta `invoice: { customer_id, email_consent }` (F2) |
| `POST /api/invoices/from-sale/{orderId}` | canje (F3); idempotente con `request_id` |
| `GET /api/invoices?from=&to=&q=` | listado para la gestoría |
| `GET /api/invoices/{id}/pdf` | PDF A4 |
| `POST /api/invoices/{id}/print` | encola en la impresora de facturas |
| `POST /api/invoices/{id}/email` | envía con consentimiento |
| `PUT /api/numbering/invoices` | próximo número (F4) |

## 5. Casos límite

| Caso | Comportamiento |
|---|---|
| Venta de 0 € o solo con artículo regalado | No se factura: «No hay importe que facturar». |
| Venta pagada en parte con crédito de cambio | Base y cuota por el total de la venta; el crédito aparece como forma de pago. |
| Ticket de otro año | Se canjea; la factura toma número del año en curso y fecha de operación la del ticket. |
| Dos cajeros canjean el mismo ticket a la vez | Uno obtiene la factura; el otro, «Este ticket ya se canjeó…» (clave única `sale_order_id`). |
| Cliente sin NIF ni documento | No se emite; la alternativa es el ticket. |
| Cliente creado en la caja y aún no subido a PrestaShop | Se factura; no depende de PrestaShop. |
| PrestaShop inaccesible | F2 y F3 funcionan igual; el pedido se sube después como cualquier venta. |
| Impresora de facturas apagada | Trabajo en «error» en el historial; ventana de descarga/email. |
| NIF válido de forma pero inexistente | Se emite; cuando se envíe a la AEAT su respuesta lo señalará (fuera de alcance). |
| Venta con IVA antiguo no deducible | No se emite (F5). |

## 6. Fuera de alcance

- Facturas recapitulativas (varios tickets en una sola factura; art. 13). Se puede añadir más adelante.
- Facturación electrónica obligatoria entre empresas (Ley Crea y Crece, RD 238/2026), que tiene su propio calendario.
- Envío a la AEAT (va con VERI\*FACTU, T3). Registros de anulación.
- Sincronizar los datos fiscales con PrestaShop.

## 7. Criterios de aceptación

1. **Venta con factura:** cliente «Deportes Ejemplo SL», NIF `B12345674` (válido), carrito de 2 × 24,20 € al 21 %. Al cobrar: se crea `F26000001`, `order_meta.reference = 'F26000001'`, no se consume número de ticket, `fiscal_record` tiene `invoice_type = 'F1'`, base 40,00 €, cuota 8,40 €, total 48,40 €, y el pedido de PrestaShop tiene referencia `F26000001`.
2. **NIF inválido:** `B12345675` → `422` «El NIF no es válido»; no hay factura, número ni venta.
3. **Canje:** ticket `A26000123` del 03/11/2026 por 12,10 € canjeado el 05/11/2026 → `F26000002`, tipo `F3`, `replaced_reference = 'A26000123'`, `operation_date = 2026-11-03`; el registro `F2` del ticket sigue intacto y la cadena se comprueba correcta.
4. **Doble canje:** repetir el punto 3 → `409` «Este ticket ya se canjeó por la factura F26000002»; ningún número consumido.
5. **Varios tipos:** 1 × 12,10 € (21 %) + 1 × 11,00 € (10 %) → cuadro con base 10,00 / cuota 2,10 y base 10,00 / cuota 1,00; total 23,10 €.
6. **Descuento de carrito del 10 %** sobre 3 × 9,99 € al 21 % → total 26,97 €; base `round(26,97 / 1,21, 2) = 22,29`, cuota 4,68; la línea muestra el descuento sin IVA.
7. **Concurrencia:** dos conexiones emiten facturas a la vez 50 veces → números 1…100 sin huecos ni duplicados.
8. **Rollback:** si falla el registro fiscal, no queda factura ni número consumido (la siguiente es la misma).
9. **PDF:** el PDF es A4 (595 × 842 pt) y contiene todos los datos del art. 6.1; los importes coinciden con la tabla `invoice`.
10. **Impresión:** con impresora de facturas configurada, `POST /api/invoices/{id}/print` crea un `print_job` `type = 'invoice'` que el agente imprime; sin ella, la respuesta indica `no_printer` y la interfaz ofrece descargar/enviar.
11. **Email sin consentimiento:** `POST /api/invoices/{id}/email` sin `email_consent_at` → `422`.
12. **Letra reservada:** asignar `F` a una caja → «La letra F está reservada para las facturas».
13. **Devolución de venta facturada:** devolver 1 línea de `F26000001` → rectificativa `G26000001`, tipo `R1`, importes negativos, con referencia a `F26000001`; no se emite abono `R`.

## 8. Plan de entrega (de lo más pequeño y útil a lo más grande)

| Fase | Contenido | Tamaño |
|---|---|---|
| 0 | Ticket con **tipo de IVA** por línea o por cuadro (art. 7.1.f) y tabla de líneas con tipo, base y cuota (compartida con `informes-ventas.md`) | M |
| 1 | Datos fiscales del cliente (F1) + **canje** F3 + serie F (F4) + PDF A4 descargable (F6) + registro `F3` (F7) | L |
| 2 | Factura en el momento de la venta (F2, `F1`) | M |
| 3 | Impresora de facturas en el agente (F6b) y email con consentimiento | L |
| 4 | Rectificativas de factura (F8), importación de datos fiscales de PrestaShop | M |

La fase 1 cubre ya el caso de un cliente que pide factura en caja: se cobra con ticket y se canjea al momento.

## 9. Riesgos

- **Tickets sin tipo de IVA** (7.1.f): el canje de tickets ya emitidos podría tener que tratarse como rectificativa. Mitigación: fase 0 antes de la 1 y P8.
- **IVA deducido del catálogo actual** para ventas antiguas: si el producto cambió de tipo, la factura sería incorrecta. Mitigación: tolerancia de 0,02 € frente al IVA guardado (F5).
- **Facturas duplicadas en PrestaShop:** si alguien pasa un pedido del TPV a un estado con factura (p. ej. «Pago aceptado») o genera la factura a mano en el back office, PrestaShop emitiría otra factura con su numeración. Mitigación: P2.
- **Impresión PDF en Windows:** depende de un programa externo o de la API de impresión; requiere pruebas por modelo de impresora.
- **Contrato con el módulo:** el formato `F26000001` cumple el patrón actual de referencias, así que no hay que cambiar el módulo; otro formato (p. ej. `F2026-000001`) obligaría a cambiar `ReferencedOrderCreator::PATTERN` y `order_meta.reference`.

## 10. Preguntas abiertas (para el club y su asesor)

| # | Pregunta | Respuesta recomendada |
|---|---|---|
| **P1** | ¿Una serie de facturas por caja (`FA26…`, `FB26…`) o una única serie para la tienda? | **Una sola serie de tienda** (`F26000001`). Por caja: sin bloqueos entre cajas y la caja se ve en el número, pero son N series que vigilar y el número no cabe en 9 caracteres con dos letras. De tienda: una lista para la gestoría y las facturas son pocas, así que el bloqueo del contador no se nota. El art. 6.1.a permite ambas. |
| **P2** | ¿Las facturas completas se suben a PrestaShop como el pedido normal, o PrestaShop emite su propia factura del pedido? Hay que evitar dos facturas del mismo pedido. | El pedido se sube como siempre (con referencia `F…` en F2) y **PrestaShop no factura** los pedidos del TPV: el estado «Pagado en TPV» no tiene la marca de factura. Recomendado: indicar en el manual que no se generen facturas de PrestaShop para pedidos del TPV y, opcionalmente, una nota en el pedido «Facturado en TPV: F26000001». |
| **P3** | ¿Se permite canjear un ticket que ya tiene devoluciones? | **No en la fase 1** («Este ticket tiene devoluciones; no se puede canjear»). Facturar «lo que queda» mezcla el abono `R5` con una factura `F3` que no lo refleja; si el club lo necesita, se estudia con el asesor. |
| **P4** | ¿Qué tipos de IVA se usan (21 %, 10 %, exento para cuotas o servicios deportivos)? Afecta al desglose y a VERI\*FACTU. | Configurar la lista 21/10/4/0 con causa obligatoria para el 0 %. Que el asesor confirme si hay servicios exentos (p. ej. art. 20.Uno.13.º LIVA, servicios deportivos de entidades sin ánimo de lucro) y su código `E1`. |
| **P5** | ¿Las devoluciones de ventas facturadas deben generar rectificativas de factura completa? | **Sí** (F8), en serie propia `G…` y tipo `R1` por diferencias. Que el asesor confirme si basta la serie `R` de abonos o debe ser otra. |
| **P6** | ¿Qué impresora A4 habría en cada caja, y en qué sistema (Linux o Windows)? ¿Debe imprimirse ahí algo más que las facturas, como los informes Z? | Empezar por la caja principal; si es Windows, incluir SumatraPDF en el instalador. Informes Z en A4: opcional, después. |
| **P7** | ¿Plazo para canjear un ticket? | El art. 15.6 no fija plazo; recomendado permitirlo siempre y avisar si el ticket tiene más de 4 años (art. 15.3, por analogía). Confirmar con el asesor. |
| **P8** | ¿Pueden canjearse por `F3` los tickets emitidos sin tipo de IVA impreso? | Preguntar al asesor; mientras tanto, permitir el canje solo de tickets emitidos tras la fase 0, o asumir su criterio. |
| **P9** | ¿Un vale de devolución canjeado en una compra es descuento o forma de pago? | **Forma de pago**: la devolución ya restó su IVA en el abono, así que restarlo otra vez como descuento reduciría la base dos veces. Afecta también a los tickets; tratarlo en `devoluciones.md`. |
