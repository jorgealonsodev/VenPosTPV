# PRD — Factura completa desde el TPV

- **Estado:** borrador para revisión
- **Fecha:** 2026-11-05
- **Relación:** complementa la numeración de tickets (`TicketNumberer`), los abonos (`docs/prd/devoluciones.md`) y el registro fiscal (`docs/verifactu.md`)

## 1. Objetivo

Que el cajero pueda entregar una **factura completa** (con los datos y el NIF del cliente) cuando el cliente la pide, ya sea en el momento de la venta o después, a partir de un ticket ya emitido, sin salir del TPV.

## 2. Situación actual

- El TPV solo emite **facturas simplificadas** (tickets `A26000123`) y sus rectificativas (abonos `R2026-000001`).
- No guarda el tipo de IVA de cada línea: la venta solo guarda el IVA total en `order_meta.payment_breakdown` (JSON). El catálogo sí tiene `price_net` por producto y combinación.
- Los clientes (`customer`) tienen nombre, email y teléfono, pero no NIF ni domicilio fiscal.
- El registro fiscal (`fiscal_record`) solo contempla los tipos `F2` (ticket) y `R5` (abono de ticket).

## 3. Marco legal (RD 1619/2012, verificado en el BOE consolidado)

- **Art. 2.2.a:** hay que expedir factura cuando el destinatario es un empresario o profesional, o cuando cualquier destinatario la exija para ejercer un derecho tributario.
- **Art. 6.1:** contenido de la factura completa: número y serie (correlativa), fecha, nombre o razón social y NIF del emisor, nombre y NIF del destinatario, domicilio de ambos, descripción de las operaciones con el precio unitario sin impuesto y los descuentos, tipo de IVA aplicado, cuota tributaria repercutida y fecha de la operación si es distinta.
- **Art. 6.1.a / 7.1.a:** las facturas completas y los tickets de un mismo año deben ir en **series separadas**.
- **Art. 7.2:** alternativa ligera: un ticket puede llevar además el NIF y el domicilio del destinatario y la cuota de IVA por separado.
- **Art. 15.6:** «las facturas que se expidan en sustitución o canje de facturas simplificadas expedidas con anterioridad no tendrán la condición de rectificativas».

## 4. Requisitos

### F1 — Datos fiscales del cliente
- El cliente puede tener: razón social o nombre completo, NIF/CIF/NIE (validado con `SpanishTaxId`), domicilio, código postal, población, provincia y país.
- Se rellenan al crear o editar el cliente en el TPV. Son obligatorios solo para emitir una factura completa.
- El NIF se valida antes de emitir; un NIF inválido impide emitir la factura con un mensaje claro.

### F2 — Factura en el momento de la venta
- En la pantalla de cobro, la opción «Factura completa» (desactivada por defecto) pide elegir o crear un cliente con datos fiscales.
- Al cobrar se emite una factura completa **en lugar del ticket**, con su propia serie y numeración.

### F3 — Factura a partir de un ticket ya emitido (canje)
- En Tickets → Cobrados → detalle de la venta, el botón «Factura completa» emite una factura que **sustituye** al ticket (art. 15.6), con referencia al ticket sustituido.
- Un ticket solo se puede canjear una vez. Un ticket con devoluciones se factura por el importe que queda tras ellas, o se impide; ver la pregunta P3.
- El ticket original no se modifica ni se anula: queda constancia de que fue sustituido.

### F4 — Serie y numeración
- Serie propia por año, distinta de los tickets y los abonos, por ejemplo `F2026-000001`.
- Numeración correlativa y sin huecos, con el mismo mecanismo que `TicketNumberer` (contador bloqueado dentro de la transacción).
- Se puede configurar el próximo número igual que el resto de series (Ajustes → Cajas, solo hacia delante y con registro).
- ¿Una serie por caja o una única serie para la tienda? Ver P1.

### F5 — Desglose de IVA
- Cada línea guarda, en el momento de la venta, su **tipo de IVA**, su **base imponible** y su **cuota**. Esto es necesario también para los tickets futuros (VERI\*FACTU pide el desglose).
- La factura muestra, por cada tipo de IVA, la base imponible y la cuota. Los descuentos aparecen en cada línea o en una línea de descuento.
- Las ventas antiguas sin tipo de IVA guardado se facturan con el tipo deducido de `price` / `price_net`. Si no se puede deducir, la factura no se emite y se avisa (P4).

### F6 — Documento
- La factura completa se entrega **siempre en A4** (decisión del club, 2026-11-05: «una factura se entregara en A4»). Nunca se imprime en la impresora de tickets.
- PDF A4 con todos los datos del art. 6.1. Se imprime en la impresora de facturas (F6b) o, si la caja no tiene, se descarga o se envía por email desde la ventana que ya existe.
- Rectificativas de facturas completas: un abono de una venta facturada genera una rectificativa de factura completa (serie propia, tipo `R1`–`R4`), no un abono de ticket. Ver P5.

### F6b — Impresora de facturas (A4)
- Cada caja puede tener una **tercera impresora**, además de la de tickets y la de etiquetas: la **impresora de facturas**, normalmente una impresora A4 de oficina (láser o de tinta) conectada al ordenador de la caja o en la red.
- Se configura donde las otras dos: en el agente de impresión de esa caja (un destino nuevo, «facturas», junto a «ticket» y «etiqueta») y en Ajustes → Cajas, donde se ve su estado.
- Al emitir una factura completa:
  - con impresora de facturas configurada, se imprime en ella el PDF A4 y se sigue el trabajo en el historial de impresión, como los tickets;
  - sin ella, se ofrece la ventana de «Descargar PDF / Enviar por email» que ya existe.
- Opcional: imprimir también en esa impresora los informes Z y los abonos en A4.
- Aspectos técnicos a resolver:
  - el agente hoy envía órdenes de impresora de tickets (ESC/POS), y para A4 tiene que mandar un **PDF**;
  - en Linux, CUPS imprime PDF directamente (`lp`);
  - en Windows hace falta un programa que imprima PDF en silencio (por ejemplo SumatraPDF incluido en el instalador) o la API de impresión de Windows.

### F7 — Registro fiscal
- Cada factura completa genera su registro encadenado en `fiscal_record`, con el tipo que corresponda: `F1` para la factura emitida en la venta y `F3` para la que sustituye a tickets. Hay que confirmar los tipos con la lista L2 de la Orden HAC/1177/2024.

## 5. Fuera de alcance

- Facturas recapitulativas (varios tickets en una sola factura). Se puede añadir más adelante.
- Facturación electrónica entre empresas (Ley Crea y Crece), que tiene su propio calendario.
- Envío a la AEAT (va con VERI\*FACTU, T3).

## 6. Criterios de aceptación

1. Con un cliente con NIF válido, una venta marcada como «Factura completa» genera `F2026-000001` (o la serie acordada) y ningún ticket `A…`.
2. El canje de `A26000123` genera una factura que lo menciona. Un segundo canje del mismo ticket se rechaza.
3. El PDF contiene todos los datos del art. 6.1, con base y cuota por cada tipo de IVA, y los importes cuadran con la venta.
4. La numeración de facturas no tiene huecos aunque dos cajas facturen a la vez (test con dos conexiones).
5. Cada factura tiene su registro fiscal encadenado, y la comprobación de la cadena sigue siendo correcta.

## 7. Preguntas abiertas (para el club y su asesor)

- **P1.** ¿Una serie de facturas por caja (`FA26…`, `FB26…`) o una única serie para la tienda?
- **P2.** ¿Las facturas completas se suben a PrestaShop como el pedido normal, o PrestaShop emite su propia factura del pedido? Hay que evitar dos facturas del mismo pedido.
- **P3.** ¿Se permite canjear un ticket que ya tiene devoluciones?
- **P4.** ¿Qué tipos de IVA se usan (21 %, 10 %, exento para cuotas o servicios deportivos)? Afecta al desglose y a VERI\*FACTU.
- **P5.** ¿Las devoluciones de ventas facturadas deben generar rectificativas de factura completa?
- **P6.** ¿Qué impresora A4 habría en cada caja, y en qué sistema (Linux o Windows)? ¿Debe imprimirse ahí algo más que las facturas, como los informes Z?
