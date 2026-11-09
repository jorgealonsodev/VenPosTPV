# PRD — Artículo libre (concepto y precio manual)

- **Estado:** borrador para revisión (revisado contra el código el 2026-11-05)
- **Fecha:** 2026-11-05
- **Relación:** venta local primero (`docs/prd/venta-local-primero.md`), devoluciones (`docs/prd/devoluciones.md`), factura completa (`docs/prd/factura-completa.md`, F5), informes de ventas (`docs/prd/informes-ventas.md`, I1)

## 1. Objetivo

Poder vender algo que no está en el catálogo (un servicio, un arreglo, una cuota, un producto pendiente de dar de alta) escribiendo el **concepto**, el **precio** y el **IVA**, sin crear el producto en PrestaShop, con el mismo cobro, ticket, registro fiscal, subida y devolución que una línea normal.

## 2. Situación actual (verificada en el código)

| Tema | Hoy | Dónde |
|---|---|---|
| Precio de cada línea | El servidor lo recalcula desde `catalog_product`/`catalog_combination` (`price_ttc`) y rechaza una diferencia de más de 0,02 €. | `CheckoutService::resolveAuthoritativePricing`, `src/Service/Checkout/CheckoutService.php:730-786`; `CatalogPriceResolver::unitPrice`, `src/Service/Catalog/CatalogPriceResolver.php:32` |
| Producto desconocido | Un `product_id` sin fila en el catálogo lanza `ProductNotInCatalogException` → 422. Un `product_id` ≤ 0 no lo rechaza el precio (`unitPrice` devuelve `null` y se conserva el precio enviado), sino `validateStock` con «Invalid product in cart». | `CatalogPriceResolver.php:34-41`; `CheckoutService.php:759`, `:885-887` |
| Producto sin precio sincronizado | Si la fila existe pero sin precio usable, se acepta el precio del navegador. | `CheckoutService.php:757-759` |
| IVA de la venta | No se guarda por línea. Se calcula como `Σ (price − price_net) × qty × (1 − dto)` escalado por `total/subtotal`; si ninguna línea tiene `price_net`, se supone un 21 % fijo. | `resolveVatAmount`, `CheckoutService.php:1022-1048`; `ASSUMED_TAX_RATE`, `:52` |
| Líneas guardadas | Solo en `order_meta.payment_breakdown` (JSON): `name`, `product_id`, `id_product_attribute`, `qty`, `price`, `line_total`, `paid_total`. Sin tipo de IVA ni base. | `insertOrderMeta`, `CheckoutService.php:936-990` |
| Registro fiscal | `F2` con el total y la cuota que da `resolveVatAmount`. El abono `R5` deduce su cuota con el **tipo medio** de la venta (`tax / total`). | `FiscalRecorder::recordSale`, `src/Service/Fiscal/FiscalRecorder.php:44`; `recordCreditNote`, `:59-90` |
| Subida a PrestaShop | La venta deja un outbox con `products[{id_product, id_product_attribute, quantity, price}]`. El módulo crea el carrito con `Cart::updateQty` y valida el pedido con `getOrderTotal`: **el `price` enviado se ignora** y PrestaShop usa su propio precio. Los descuentos de línea y de ticket **no se suben**. | `orderPayload`, `CheckoutService.php:294-318`; `CouponCheckout::buildCart`, `modules/tpvconnect/classes/CouponCheckout.php:245-295`; `orders.php:156-166` |
| Validación del módulo | Cada `id_product` debe existir en PrestaShop (`Product not found`). | `modules/tpvconnect/controllers/front/orders.php:131-146` |
| Devolución de una línea | `planLines` rechaza una línea con `product_id` ≤ 0 («recorded before refunds were supported»). El módulo localiza el `order_detail` por `id_product` + `id_product_attribute` y limita el importe a `unit_price_tax_incl × qty`. | `RefundService.php:1292-1295`; `RefundProcessor::matchLines`, `modules/tpvconnect/classes/RefundProcessor.php:306-340` |
| Stock | `decrementStock` ignora `product_id` ≤ 0; el stock real lo baja PrestaShop al crear el pedido. | `CheckoutService.php:1063-1085` |
| Cupones | Solo con conexión; mandan las líneas al módulo para que PrestaShop calcule la regla del carrito. | `createCouponOrder`, `CheckoutService.php:367-495`; `couponPayload`, `:573` |
| Carrito y aparcados | El carrito vive en `localStorage` (`useCart.js`); `reprice` salta las líneas con `product_id` ≤ 0. Los aparcados guardan `product_id`, `name`, `price`, `qty`, `discount` y descartan el resto de campos. | `public/assets/js/stores/useCart.js:91-122`, `:210-219`; `ParkedTicketService::park`, `src/Service/Cart/ParkedTicketService.php:58-76` |
| Sin conexión | `api.js` encola `/api/checkout` ante un fallo de red y lo reenvía con el mismo `request_id`; el servidor es local primero. | `public/assets/js/api.js:1-80`; `CheckoutService::replayedSale`, `:149` |
| Roles | La sesión guarda `role` = `admin` o `cashier`. | `AuthController.php:139`, `:276` |

Consecuencia: hoy no existe ninguna línea sin producto, y el total del pedido en PrestaShop **ya no coincide** con el del ticket cuando hay descuentos manuales. El artículo libre no debe empeorar eso, pero tampoco lo arregla (fuera de alcance).

## 3. Requisitos

### A1 — Añadir un artículo libre (pantalla de venta)
1. Botón «Artículo libre» en la pantalla de venta, visible solo si la función está activa y el rol lo permite (A7).
2. Ventana táctil con cuatro campos y teclado numérico en pantalla:

| Campo | Regla |
|---|---|
| Concepto | Obligatorio. 1–100 caracteres tras quitar espacios al principio y al final y unir espacios repetidos. Sin caracteres de control ni `< > ; = # { }` (los que PrestaShop prohíbe en un nombre de producto). Solo caracteres representables en Windows-1252, que es lo que imprime la impresora de tickets; un emoji se rechaza con el mensaje «El concepto tiene caracteres que no se pueden imprimir». |
| Precio unitario con IVA | Obligatorio. Acepta coma o punto, máximo 2 decimales. Entre 0,01 € y el máximo configurado (por defecto 1.000,00 €). |
| Tipo de IVA | Botones con los tipos configurados (A8). El primero es el predeterminado (21 %). |
| Cantidad | Entero entre 1 y 999. Por defecto 1. |

3. «Añadir» crea una línea nueva cada vez: dos artículos libres con el mismo concepto **no se fusionan** (a diferencia de `useCart.add`, que suma cantidad al mismo producto).
4. La línea se ve como las demás, con una marca «Libre» y sin miniatura. Admite cambiar cantidad, descuento de línea y eliminarla. **No** se puede editar su precio después: se elimina y se vuelve a añadir.
5. El IVA en pantalla usa el tipo de la línea (`net_price = price / (1 + tipo)`), nunca el 21 % de reserva (`IVA_RATE`, `useCart.js:16`).

### A2 — Cobro e integridad del precio
1. La línea viaja a `/api/checkout` con `free_item: true`, `concept`, `price`, `vat_rate`, `qty`, `discount` y `product_id: 0`. El servidor **no acepta** del navegador ningún id de producto de PrestaShop para un artículo libre.
2. En `resolveAuthoritativePricing` las líneas se separan en dos caminos:
   - **Catálogo** (sin `free_item`): exactamente como hoy. Además se rechaza cualquier línea de catálogo cuyo `product_id` sea uno de los productos genéricos (A5), para que nadie use su precio.
   - **Libre** (`free_item: true`): se valida A1.2 en el servidor (mismos límites, no solo en pantalla), se comprueba que `vat_rate` está en la lista configurada y que el rol puede usarlo. Si algo falla: 422 con el campo y el motivo.
3. Una línea con `free_item` y `product_id > 0` se rechaza («Un artículo libre no puede llevar producto»). Así no se puede hacer pasar una línea de catálogo por libre.
4. El servidor calcula `price_net = price / (1 + vat_rate)` con 6 decimales; `resolveVatAmount` la usa como cualquier otra línea, así que un ticket solo con artículos libres al 10 % no cae en el 21 % de reserva.
5. `validateStock` y `decrementStock` saltan las líneas libres (no hay stock). No generan aviso de stock.
6. Idempotencia: sin cambios. El `request_id` del intento de cobro (`CheckoutService.php:117-128`) protege también al artículo libre; un reintento devuelve la misma venta.

### A3 — Datos que se guardan
1. Cada línea libre del `payment_breakdown` guarda: `free_item: true`, `name` (= concepto), `product_id: 0`, `id_product_attribute: 0`, `qty`, `price`, `price_net`, `vat_rate`, `vat_amount`, `line_total`, `paid_total`.
2. `vat_amount` por línea = `round(paid_total − paid_total / (1 + vat_rate), 2)`, es decir, sobre lo realmente pagado tras descuentos de línea y de ticket.
3. Auditoría sin tabla nueva: la venta ya guarda cajero (`order_meta.user_id`), caja (`terminal_id`), sesión y fecha; la línea guarda concepto, precio y tipo. Un listado de administración «Artículos libres» filtra por fechas, caja y cajero (A9).
4. Cuando exista la tabla de líneas de venta (`informes-ventas.md`, I1), la línea libre se guarda allí con `product_id` nulo, `free_item = 1`, tipo, base y cuota exactos (no «aproximados»).

### A4 — Ticket, ticket regalo y registro fiscal
1. Ticket ESC/POS y PDF: concepto, cantidad, precio e importe como cualquier línea (`TicketDataResolver::resolve` ya lee `name`, `qty`, `price`, `line_total`, `src/Service/Print/TicketDataResolver.php:64-72`). El ticket regalo muestra el concepto sin precio, como el resto (`EscPosTicketBuilder::build($giftMode)`).
2. El IVA de la línea suma en el IVA total del ticket y, cuando exista, en el desglose por tipo (`factura-completa.md`, F5).
3. El registro fiscal no cambia de forma: un `F2` con total y cuota (`FiscalRecorder.php:44-56`); la cuota ya incluye la de la línea libre por A2.4.
4. Etiquetas: no aplican. Un artículo libre no tiene código ni etiqueta.

### A5 — Subida a PrestaShop: productos genéricos ocultos
PrestaShop calcula el IVA de cada línea con la regla de impuestos del producto y no permite cambiarla por línea del carrito, y el módulo hoy ignora el precio enviado. Por eso:

1. **Un producto genérico por tipo de IVA** («Artículo libre TPV 21 %», «… 10 %», «… 4 %», «… exento»; referencia `TPV-LIBRE-21`…), creado por el módulo cuando el TPV lo pide (`tpv:free-item:setup` → `POST /module/tpvconnect/freeitem`; ver §10). Cada uno con:
   - su grupo de reglas de impuestos (exento: sin impuestos);
   - `visibility = none`, `available_for_order = 1`, activo (para que `Cart::updateQty` lo admita), sin indexar en la búsqueda;
   - venta sin stock permitida (`out_of_stock = 1`);
   - precio base 99.999 € (su página se abre con la URL directa, §10);
   - **N combinaciones «hueco»** (por defecto 20, atributo «Línea TPV» 1…20), para que cada artículo libre del mismo tipo sea un `order_detail` distinto con su propio precio y su propia devolución.
2. El módulo devuelve los ids (producto y combinaciones por tipo) en una respuesta firmada; el TPV los guarda en ajustes. La sincronización del catálogo **excluye** esos productos, para que nunca aparezcan en la búsqueda ni en `catalog_product`.
3. El outbox de la venta guarda la línea libre con `free_item`, `vat_rate`, `concept`, `price`, `qty` y su número de hueco. `OutboxSync::processSale` (`src/Service/Sync/OutboxSync.php:239`) resuelve en ese momento el producto y la combinación: así una venta hecha antes de configurar los ids se sube después sin rehacerla. Si faltan los ids, la fila se **reintenta** con el error «Falta configurar el artículo libre en PrestaShop» (no es incidencia definitiva).
4. El módulo, para cada línea libre: añade el hueco al carrito y crea un precio específico **solo para ese carrito** (`SpecificPrice` con `id_cart`, `id_product`, `id_product_attribute`, `price` = neto con 6 decimales, `reduction = 0`). Después de `validateOrder` cambia `order_detail.product_name` por el concepto y borra los precios específicos del carrito.
5. Más de 20 artículos libres del mismo tipo en un ticket: el cobro lo rechaza con 422 («Máximo 20 artículos libres del mismo IVA por ticket»).
6. En el back office el pedido se ve con la referencia del ticket, una línea por artículo libre con su concepto, el IVA correcto y el importe sin descuento manual (igual que las líneas de catálogo hoy).

Alternativa descartada: subir el pedido sin esas líneas y anotarlas en un mensaje. El total y el IVA del pedido no cuadrarían con el ticket y no se podría devolver la línea en PrestaShop (ver P1).

### A6 — Devoluciones y cambios
1. Un artículo libre se devuelve con ticket como cualquier línea, por su `paid_total` proporcional (`RefundService::planLines`, `RefundService.php:1255-1320`). Hay que aceptar `product_id = 0` cuando la línea tiene `free_item` (hoy da «recorded before refunds were supported», `:1293`).
2. `restock` se fuerza a `false` en la línea libre, aunque el navegador pida reponer.
3. El abono se sube a PrestaShop sobre el `order_detail` del hueco (producto genérico + combinación), así el emparejamiento de `RefundProcessor::matchLines` sigue funcionando.
4. La cuota del abono: hasta que exista el IVA por línea (F5), `recordCreditNote` usa el tipo medio de la venta (`FiscalRecorder.php:72-84`). Con tipos mezclados (p. ej. un libre al 4 % y ropa al 21 %) la cuota del abono parcial sería inexacta. Por eso **A6.4 depende de F5**: el abono debe sumar la cuota de las líneas devueltas. Ver P5.
5. Devolución **sin ticket** de un artículo libre: no se admite (no hay precio de catálogo, `planNoTicketLines` usa `CatalogPriceResolver`).
6. Cambios (`ExchangeService`): un artículo libre puede ser parte de lo devuelto (regla A6.1) o de lo nuevo (pasa por `quoteCart`/`createOrder`, regla A2).

### A7 — Permisos y límites
1. Ajuste «Artículo libre»: activado o no (por defecto activado), y quién puede usarlo: `todos` (por defecto) o `solo administradores`.
2. El servidor comprueba el rol de la sesión en el cobro, no solo el botón.
3. Precio máximo por unidad configurable (por defecto 1.000,00 €). Un importe por encima no se puede autorizar en la caja: se cambia el ajuste.

### A8 — Tipos de IVA
- Lista configurable entre 21 %, 10 %, 4 % y exento (0 %). Por defecto solo 21 %. Solo se pueden activar tipos cuyo producto genérico exista en PrestaShop.
- «Exento» exige elegir el motivo de exención para la futura factura y VERI\*FACTU (P2).

### A9 — Cupones, descuentos, aparcados y sin conexión
| Situación | Regla |
|---|---|
| Descuento de línea | Sí, como hoy (0–100 %). |
| Descuento de ticket | Sí; se reparte en proporción (`prorate`) y reduce la base y la cuota de la línea libre. |
| Cupón de PrestaShop | **No** en un ticket con artículo libre (fase 1): 422 «No se puede usar un cupón con un artículo libre». Las reglas de carrito de PrestaShop podrían aplicarse o excluir el producto genérico de formas difíciles de cuadrar. |
| Ticket aparcado | Se aparca y recupera con todos sus campos. `ParkedTicketService::park` hoy descarta `free_item`, `vat_rate` y `concept`: hay que conservarlos. |
| Sin conexión con PrestaShop | Se cobra igual (local primero) y se sube después. |
| Sin conexión con el servidor del TPV | Igual que hoy: `api.js` encola el cobro y lo repite con el mismo `request_id`. |
| Recalcular precios (`reprice`) | No toca la línea libre (ya salta `product_id` ≤ 0). |

### A10 — Informes
- Informe X/Z: las ventas con artículo libre cuentan en el total y el IVA como las demás.
- Informes de ventas: «Artículo libre» aparece como categoría propia y en el IVA por tipo (`informes-ventas.md`, I2).

## 4. Cambios técnicos

**Datos.** Sin tabla nueva en la fase 1: campos nuevos en las líneas del JSON (A3.1) y ajustes (`free_item_enabled`, `free_item_role`, `free_item_max_price`, `free_item_vat_rates`, `free_item_ps_products` = mapa tipo → producto y combinaciones). Migración solo para los ajustes.

**API.**
- `POST /api/checkout`: líneas con `free_item` (A2.1). Errores 422 nuevos: concepto, precio, tipo, rol, huecos, cupón.
- Endpoint nuevo de solo lectura con los ajustes que necesita la pantalla (activado, rol, máximo, tipos); nombre a fijar en la implementación.
- `POST /api/parked-tickets` (`ParkedTicketController.php:32`): conserva los campos de la línea libre.
- Módulo: `POST /module/tpvconnect/freeitem` (firmado HMAC): crea o comprueba los productos genéricos y devuelve sus ids. `orders`: acepta `free_item` en `products` y aplica A5.4.

**Pantalla.** Botón, ventana (A1), marca «Libre» en la línea, sin edición de precio.

## 5. Fuera de alcance
- Crear el producto de verdad en PrestaShop desde el TPV.
- Artículos libres con talla, color, código de barras o etiqueta.
- Precios negativos (un «descuento libre» se hace con el descuento de ticket).
- Subir a PrestaShop los descuentos manuales (problema ya existente, sección 2).

## 6. Criterios de aceptación
1. Un artículo libre «Arreglo de bañador», 12,00 €, IVA 21 %, cantidad 1: se cobra, aparece en el ticket y el IVA del ticket y del registro `F2` es 2,08 € (12,00 − 12,00/1,21 = 2,0826).
2. Ticket con un libre de 10,00 € al 10 % y nada más: IVA 0,91 €, no 1,74 € (el 21 % de reserva).
3. Una línea con `free_item: true` y `product_id` de catálogo → 422. Una línea de catálogo con el `product_id` de un genérico → 422. Una línea de catálogo con precio manipulado → 422, como hoy.
4. Concepto vacío, de 101 caracteres, con `<` o con un emoji → 422 con el motivo; precio 0, 1.000,01 € o con 3 decimales → 422; tipo no configurado → 422; cajero con el ajuste «solo administradores» → 403.
5. Dos libres del mismo concepto y tipo con precios distintos se suben a PrestaShop como dos líneas, cada una con su concepto, su precio con IVA igual al del ticket (antes de descuentos manuales) y el IVA de su tipo.
6. Una venta con artículo libre hecha con PrestaShop caído se sube después; si faltan los ids de los genéricos, la fila se reintenta y no crea incidencia.
7. Repetir el cobro con el mismo `request_id` devuelve la misma venta y un solo pedido en PrestaShop.
8. Devolver un libre genera un abono con su importe pagado, no repone stock aunque se pida, y en PrestaShop el abono cae sobre su línea.
9. Aparcar y recuperar un ticket con un libre conserva concepto, precio, tipo y cantidad.
10. Un ticket con artículo libre y cupón → 422 con el mensaje de A9.
11. Los productos genéricos no aparecen en la búsqueda del TPV ni en la tienda web.

## 7. Plan por fases
| Fase | Contenido | Tamaño |
|---|---|---|
| 1 — Venta local | A1, A2, A3, A4, A7, A8 (solo 21 % si P2 no se resuelve), A9 salvo subida. Las ventas con libre quedan **retenidas** en el outbox hasta la fase 2. | M (~400 líneas + tests) |
| 2 — Subida | A5: módulo (genéricos, huecos, precio por carrito, nombre de línea), `OutboxSync`, exclusión del catálogo. Incluye un **spike** previo en PrestaShop 9.2 (la versión de la tienda) para confirmar A5.4 (hecho, §10). | M–L |
| 3 — Devoluciones | A6 (con abono exacto cuando F5 esté hecho), listado de auditoría, informes. | S–M |

La fase 1 sola no se debe publicar si el cierre de caja exige todo subido: retener ventas en el outbox genera avisos de pendientes (`venta-local-primero.md`). Recomendado: publicar 1 y 2 juntas.

## 8. Riesgos
- **Precio por carrito en PrestaShop** (A5.4): `SpecificPrice` con `id_cart` es un mecanismo estándar, pero el redondeo (`PS_ROUND_TYPE`, `PS_PRICE_ROUND_MODE`) puede dar ±0,01 € frente al ticket. Mitigación: neto con 6 decimales y comprobar en el spike que `total_price_tax_incl` coincide.
- **Reescribir `order_detail.product_name`** tras `validateOrder`: la factura y el correo de PrestaShop pueden haberse generado ya con el nombre genérico. El estado de pedido del TPV no envía correo; comprobarlo en el spike.
- **Abuso**: precios libres son una vía para cobrar menos. Mitigación: A7, listado de auditoría y precio máximo.
- **IVA mezclado en abonos parciales** hasta F5 (A6.4).

## 9. Preguntas abiertas
- **P1.** ¿Productos genéricos ocultos (uno por tipo de IVA, con huecos) o líneas fuera del pedido? **Recomendado:** genéricos con huecos (A5); es lo único que cuadra IVA, total y devoluciones en PrestaShop.
- **P2.** ¿Qué tipos de IVA? ¿Hay servicios exentos (cuotas de socio, cursos)? **Recomendado:** 21 % y 10 % al principio; «exento» solo cuando la gestoría confirme el artículo de exención (p. ej. art. 20.Uno.13.º LIVA para servicios deportivos de entidades sin ánimo de lucro) y haya sitio para el motivo en el registro fiscal.
- **P3.** ¿Todos los cajeros o solo administradores? **Recomendado:** todos, con precio máximo de 1.000 € y listado de auditoría; el club puede restringirlo con el ajuste.
- **P4.** ¿Cuántos huecos por tipo? **Recomendado:** 20; un ticket real no tiene más de 20 artículos libres del mismo IVA.
- **P5.** ¿Se espera a F5 (IVA por línea) para habilitar la devolución de artículos libres con tipo distinto del 21 %? **Recomendado:** sí; hacer antes la parte de F5 que guarda tipo, base y cuota por línea, que es compartida con `factura-completa.md` e `informes-ventas.md` (I1).
- **P6.** ¿Se admite cupón con artículo libre en el futuro? **Recomendado:** no hasta que haya demanda real.

## 10. Spike en PrestaShop 9.2 y lo que queda sin verificar

La tienda usa **PrestaShop 9.2** (no 8.1.7). Spike y prueba de extremo a extremo el 2026-11-09 en la instancia de pruebas docker `tpv-ps92` (PrestaShop 9.2.x, PHP 8.5, MariaDB 10.6, módulo `tpvconnect` 0.7.0). Esa instancia no traía impuestos: se crearon los grupos «21 %» y «10 %» para España como los tiene una tienda real.

**Verificado (en la instancia):**
- Un producto con `visibility = none`, activo, `available_for_order = 1`, `out_of_stock = 1` y sin filas en `search_index` entra en el carrito con `Cart::updateQty` (también sus combinaciones) y se valida con `PaymentModule::validateOrder`. No sale en la búsqueda ni en el listado de la categoría de la tienda.
- `SpecificPrice` con `id_cart` + `id_product` + `id_product_attribute`, `price` = neto con 6 decimales y `reduction = 0` da exactamente el precio del ticket: 12,00 € y 7,35 € × 3 al 21 % y 9,99 € × 2 al 10 % salieron en `order_detail` con `unit_price_tax_incl` 12,00 / 7,35 / 9,99, `total_price_tax_incl` 12,00 / 22,05 / 19,98, cuota 2,08 / 3,83 / 1,82 y el tipo de su grupo (con `PS_ROUND_TYPE = 2`, `PS_PRICE_ROUND_MODE = 2`). Total del pedido 77,17 € = total del ticket (con una línea de catálogo de 23,14 €).
- Dos artículos libres del mismo concepto y tipo con precios distintos quedan en dos `order_detail` (huecos 1 y 2), cada uno con su concepto (criterio 5).
- Cambiar `order_detail.product_name` después de `validateOrder` funciona; borrar los precios específicos del carrito después no cambia el pedido.
- Volver a subir la misma fila (misma referencia del ticket) devuelve el mismo pedido (criterio 7); en PrestaShop sigue habiendo un solo pedido con esa referencia.
- El estado de pedido del TPV (`TPV_OS_PAID`) tiene `send_email = 0` e `invoice = 0`: no genera factura con el nombre genérico.
- `PaymentModule::validateOrder` de 9.2 **sí** envía el correo `order_conf` al cliente (si su email es válido) con la lista de productos del carrito, sea cual sea el estado. Por eso el módulo registra `actionPaymentModuleProductVarTplAfter` y pone el concepto en esa lista para las líneas libres del pedido que está creando (comprobado con test unitario; la entrega real del correo no se pudo ver: la instancia no tiene servidor de correo).
- `Cart::getOrderTotal` fuera de una petición web necesita el contenedor de Symfony (`Kernel Container is not available` en CLI); en el endpoint del módulo no aplica.

**Hallazgos que cambian A5:**
- La página del producto genérico **se puede abrir** con su URL directa (`visibility = none` solo lo quita de listados y búsqueda), y desde ahí se podría añadir al carrito. Por eso su precio base es 99.999 €: el TPV siempre pone el precio por carrito y nadie lo compra en la web por 0 €.
- La actualización (`upgrade-0.7.0.php`) **no crea** los genéricos: depende de los grupos de impuestos de la tienda y no debe hacer fallar la actualización. Los crea el TPV con `php bin/console tpv:free-item:setup`, que llama a `POST /module/tpvconnect/freeitem` con los tipos de `free_item.vat_rates` y guarda los ids en `app_setting` (`free_item.ps_products`). Un tipo sin grupo de impuestos activo para el país por defecto → 422 sin crear nada.

**Supuestos / sin verificar:**
- Redondeo con `PS_ROUND_TYPE` 1 (por artículo) o 3 (total): no probado en la instancia; con neto de 6 decimales el error por unidad es < 0,000001 € y no cruza un 0,005, así que se espera el mismo resultado.
- La tienda de producción tiene grupos de impuestos activos al 21 % y 10 % para España (lo comprueba el propio endpoint).
- El encaje del motivo de exención en `fiscal_record` (VERI\*FACTU) depende de `factura-completa.md`, F7.
