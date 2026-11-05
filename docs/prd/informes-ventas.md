# PRD — Informes de ventas

- **Estado:** borrador para revisión (revisado contra el código el 2026-11-05)
- **Fecha:** 2026-11-05
- **Relación:** complementa los informes X/Z de caja (`docs/checkout-cash-session.md`). Comparte la tabla de líneas de venta con la factura completa (`docs/prd/factura-completa.md`, F5). Tiene en cuenta el artículo libre (`docs/prd/articulo-libre.md`).

## 1. Objetivo

Que el responsable de la tienda vea, desde el TPV, cómo van las ventas: cuánto se vende por día y por hora, qué productos y categorías se venden más, quién vende y cómo se cobra, y la base y cuota de IVA para la gestoría. Todo se puede exportar a CSV.

Los informes leen solo la base de datos local del TPV: funcionan sin conexión con PrestaShop e incluyen las ventas aún no subidas.

## 2. Situación actual (verificada en el código)

| Tema | Dónde está hoy |
|---|---|
| Cabecera de la venta | `order_meta` (`migrations/install.sql`, tabla 6): `id`, `reference` (código de ticket `A26000123` o referencia PrestaShop en ventas con cupón), `terminal_id`, `session_id`, `user_id`, `created_at`, `synced`. **No tiene índice por `created_at`.** |
| Líneas de la venta | Solo en el JSON `order_meta.payment_breakdown.lines`, escrito en `CheckoutService::insertOrderMeta` (`src/Service/Checkout/CheckoutService.php:920-1006`). Cada línea guarda `name`, `product_id`, `id_product_attribute`, `qty`, `price` (unitario con IVA, antes de descuentos), `line_total` (= `qty × price`, **antes** de descuentos) y `paid_total` (lo pagado de verdad: descuento de línea y descuento del ticket o cupón repartidos, `:953-957`). |
| Lo que no se guarda por línea | Referencia, categoría, `price_net`, tipo de IVA, base, cuota y el % de descuento de línea. El `price_net` sí se conoce al cobrar (`resolveAuthoritativePricing`, `:761`), pero no se persiste. El descuento del ticket (`cart_data.discount`) tampoco se guarda en el JSON. |
| IVA de la venta | Un solo importe, `payment_breakdown.tax` (`resolveVatAmount`, `:1022-1048`). **Hallazgo:** el descuento de línea se aplica dos veces (en `:1037` y otra vez en el factor `total / subtotal` de `:1045`, porque `subtotal` es la suma de `line_total` sin descontar, `:959-962`). Con descuentos de línea, `tax` sale por debajo del real. Los informes **no** deben usar `payment_breakdown.tax`; el arreglo es aparte (también afecta a `fiscal_record.tax_total`, `:1000`). |
| Formas de cobro | `payment_method` ∈ `cash`, `card`, `mixed` y `exchange` (cambio pagado entero con crédito, `src/Service/Exchange/ExchangeService.php:57`). El reparto real está en `payment_breakdown.tender` = `{cash, card, exchange_credit?}` (`:984-987`, calculado en `:202-209`). **No existe «vale» como forma de cobro de una venta:** el vale es una forma de *devolución* (`refund.voucher_amount`). Un vale de PrestaShop canjeado entra como cupón (descuento), no como tender. |
| Ventas con cupón | `createCouponOrder` (`:367-470`): se crean primero en PrestaShop; `order_meta.total` es lo pagable y `payment_breakdown.coupon` guarda `gross_total`, `discount_amount`, `cash_collected`, `card_collected`. Las líneas guardan el precio sin cupón y `paid_total` reparte lo pagable. |
| Ventas locales | `createLocalOrder` (`:184-282`): `order_meta`, movimiento de caja `sale` (solo la parte en efectivo), stock, registro fiscal y outbox **en la misma transacción** (`:218-233`). No hay estado «anulada»: si la venta existe en `order_meta`, está cobrada. |
| Devoluciones | `refund` + `refund_line` (`src/Service/Refund/RefundService.php`). Solo cuentan las `status = 'done'`; la fecha es `completed_at` (`:627-633`). `refund_line` guarda `line_index` (línea de la venta), producto, combinación, nombre, cantidad e `amount` con IVA (`:537-554`). Tipos: devolución de ticket TPV (`order_id`), sin ticket (`order_id` NULL, precio de catálogo actual), de pedido online (`online = 1`: el TPV no paga, queda `web_refund_amount`) y la parte devuelta de un cambio (`exchange_credit`). |
| Cambios | `exchange` = devolución (crédito) + venta nueva con `tender.exchange_credit` (`ExchangeService.php:9-47`). Estados `pending`, `credit_open`, `done`, `failed`, `closed_voucher`. |
| Caja X/Z | `CashSessionService::aggregateTotals` (`src/Service/Cash/CashSessionService.php:318-432`): efectivo desde `movement`, tarjeta desde `order_meta.tender.card` (con respaldo `payment_method = 'card'` para ventas antiguas, `:352-360`), devoluciones desde `refund`, crédito de cambio aparte, vales de cambios cerrados por `exchange.voucher_session_id`. El Z se guarda en `z_report.totals` (JSON) al cerrar (`:195-200`). Es **por sesión**, y una sesión puede cruzar la medianoche. |
| Cajas, cajeros, catálogo | `terminal` (`name`, `ticket_letter` única y modificable), `user` (`name`, `role_id` → `admin` / `cashier`), `catalog_product.id_category_default`, `catalog_category` (`id_parent`, sin nivel). La sincronización no borra productos (`CatalogSyncService` no hace `DELETE`). |
| Pendientes de subir | `outbox` (`status` `pending`, `processing`, `incident`, `done`, `discarded`). Una venta en `incident` o `discarded` sigue siendo una venta válida del TPV (tiene ticket y registro fiscal). |
| Hora | `created_at`, `completed_at` y `movement.created_at` se escriben desde PHP en hora local (`new \DateTimeImmutable()`), con `date.timezone = Europe/Berlin` (`config/runtime/php85.ini:10`), mismo horario que `PS_SHOP_TIMEZONE = Europe/Madrid` (`config/services.yaml:9`). |
| Interfaz | No hay pantalla de estadísticas ni librería de gráficos (`public/assets/vendor` solo tiene Vue y Tailwind). Las pantallas completas de administración se abren desde la cabecera de `SaleScreen` (`public/assets/js/components/SaleScreen.js:335-337`, incidencias solo admin; botón de Ajustes solo admin, `:379`). Patrón de paginación: `PrintHistoryPanel.js:4-5, 48-72` (páginas de 25 con cursor, «Anterior» / «Siguiente»). |
| API | Rutas bajo `/api` (`config/routes.yaml:10`), `JsonResponse` con `{data}` o `{error}`; administrador = `session.role === 'admin'` y 403 en caso contrario (`src/Controller/Api/NumberingController.php:88-91, 107-110`). |

## 3. Definiciones de las métricas

Todas las cifras son importes con IVA salvo «Base» y «Cuota». Redondeo a céntimos en cada línea guardada; los informes solo suman.

| Métrica | Definición |
|---|---|
| **Importe antes de descuentos** | Σ `sale_line.gross_amount` (= `qty × unit_price`). |
| **Descuentos** | Σ (`gross_amount − paid_amount`): descuento de línea, descuento del ticket y cupón. |
| **Ventas brutas** | Σ `sale_line.paid_amount` de las ventas del periodo (= Σ total de los tickets). Incluye la venta nueva de un cambio por su total completo, también la parte pagada con crédito. |
| **Devoluciones** | Σ `refund.total` con `status = 'done'` y `online = 0`, por fecha `completed_at`. Incluye devoluciones sin ticket y la parte devuelta de los cambios. |
| **Ventas netas** | Ventas brutas − Devoluciones. |
| **Tickets** | Número de filas de `order_meta` del periodo. **Ticket medio** = Ventas brutas / Tickets (0 si no hay tickets). |
| **Unidades** | Vendidas = Σ `sale_line.quantity`; devueltas = Σ `refund_line.quantity` de devoluciones válidas; netas = vendidas − devueltas. |
| **Crédito de cambio** | Σ `tender.exchange_credit` de las ventas: dinero que no entra ni sale. Se muestra aparte, como en el Z. |
| **Devoluciones online** | Σ `refund.web_refund_amount` con `online = 1`. Solo informativo: el TPV no pagó nada. No entra en ninguna otra cifra. |

Decisiones:

- **Las devoluciones cuentan en la fecha en que se hacen** (`completed_at`), no en la de la venta original. Motivos: es lo que hace el Z y la caja, la factura rectificativa lleva esa fecha (el IVA se declara así) y un periodo ya cerrado no cambia después. Consecuencia: un día puede tener ventas netas negativas.
- **Los cambios no se netean entre sí:** la devolución cuenta como devolución y la venta nueva como venta. El neto coincide con el del Z, que excluye el crédito en ambos lados.
- **Fuera de todos los informes:** tickets aparcados (`parked_ticket`), devoluciones `pending` o `failed`, cambios `pending` o `failed` sin venta nueva y pedidos online de PrestaShop. Un cobro con cupón cuya escritura local falló (`audit_log` `checkout_orphan`, `CheckoutService::logCheckoutOrphan`, `:1123`) no tiene `order_meta` y no aparece; se resuelve en incidencias, no aquí.
- **Ventas aún no subidas a PrestaShop sí cuentan.** El resumen muestra cuántas ventas y devoluciones siguen en el outbox (`pending`, `processing`, `incident`) para explicar diferencias con PrestaShop.

## 4. Requisitos

### I1 — Tabla de líneas de venta

Nueva tabla `sale_line`, una fila por línea de ticket:

| Campo | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `order_id` | BIGINT UNSIGNED NOT NULL | `order_meta.id` |
| `line_index` | SMALLINT UNSIGNED NOT NULL | posición en `payment_breakdown.lines`; la misma que `refund_line.line_index` |
| `sold_at` | DATETIME NOT NULL | copia de `order_meta.created_at`, para indexar |
| `terminal_id`, `session_id`, `user_id` | INT UNSIGNED NOT NULL | copias de la cabecera |
| `product_id` | INT UNSIGNED NULL | NULL para el artículo libre o líneas antiguas sin producto |
| `id_product_attribute` | INT UNSIGNED NOT NULL DEFAULT 0 | |
| `reference` | VARCHAR(64) NULL | de la combinación o del producto en el momento de la venta |
| `name` | VARCHAR(255) NOT NULL | tal como salió en el ticket |
| `category_id` | INT UNSIGNED NULL | `catalog_product.id_category_default` al vender |
| `category_name` | VARCHAR(255) NULL | copia, por si la categoría cambia o desaparece |
| `quantity` | INT NOT NULL | |
| `unit_price` | DECIMAL(10,2) NOT NULL | con IVA, antes de descuentos |
| `line_discount_pct` | DECIMAL(5,2) NOT NULL DEFAULT 0 | NULL en líneas antiguas (desconocido) |
| `gross_amount` | DECIMAL(10,2) NOT NULL | `= line_total` |
| `paid_amount` | DECIMAL(10,2) NOT NULL | `= paid_total` |
| `vat_rate` | DECIMAL(5,2) NULL | ver abajo |
| `base_amount`, `vat_amount` | DECIMAL(10,2) NULL | `base + cuota = paid_amount` exacto |
| `approximate` | TINYINT(1) NOT NULL DEFAULT 0 | 1 si algún dato vino del catálogo actual |

Índices: `UNIQUE (order_id, line_index)`, `(sold_at)`, `(terminal_id, sold_at)`, `(product_id, id_product_attribute, sold_at)`, `(category_id, sold_at)`. Además, en tablas existentes: `order_meta (created_at)`, `order_meta (terminal_id, created_at)` y `refund (status, completed_at)`.

Reglas:

- **Escritura en la venta:** `insertOrderMeta` inserta las líneas justo después de `order_meta` y antes del registro fiscal, dentro de la transacción que ya existe en las dos rutas (local `:218` y cupón `:422`). Si falla, falla la venta entera (misma excepción `orderMetaFailed`). Nunca hay venta sin líneas ni líneas sin venta.
- **IVA por línea:** `vat_rate = (price / price_net − 1) × 100`, redondeado al tipo conocido más cercano (21, 10, 4, 0; ver P5). Si no hay `price_net`, se usa el 21 % de `ASSUMED_TAX_RATE` (`:52`) y la línea queda `approximate = 1`. `base_amount = round(paid_amount / (1 + rate/100), 2)`, `vat_amount = paid_amount − base_amount`. Es el mismo dato que pide F5 de la factura completa: un solo cálculo para los dos PRD.
- **Relleno de ventas antiguas:** comando `tpv:sales:backfill-lines`, no dentro de la migración (puede tardar y debe poder repetirse). Recorre `order_meta` por `id` en lotes de 500, cada lote en su transacción, con `INSERT IGNORE` sobre la clave única: es **idempotente** y se puede interrumpir y relanzar. Reglas del relleno:
  - `paid_amount` = `paid_total`; si la venta es anterior a `paid_total`, se reparte `payment_breakdown.total` en proporción a `line_total` con el mismo `prorate` (`:1095`), y la línea queda aproximada.
  - Categoría, referencia y `price_net` se toman del catálogo actual → `approximate = 1`. Producto que ya no está en el catálogo: categoría NULL («Sin categoría»), IVA 21 %, aproximada.
  - `line_discount_pct` queda NULL: en ventas antiguas solo se conoce el descuento total de la línea.
  - Al terminar informa: ventas procesadas, líneas creadas, líneas aproximadas, ventas con JSON ilegible (se saltan y se listan por `id`).
- El despliegue ejecuta el comando una vez tras la migración; los informes muestran un aviso si existe alguna venta sin líneas en el periodo.

### I2 — Informes

Filtros comunes: **rango de fechas** (Hoy, Ayer, Esta semana —lunes a domingo—, Este mes, Mes anterior, Este año, Personalizado; máximo 366 días), **caja** (todas o una) y, en los informes que lo admiten, **cajero**. El rango es semiabierto: `desde 00:00:00` ≤ fecha < `hasta + 1 día 00:00:00`, en hora local de la tienda.

| Informe | Contenido | Consulta (esquema) |
|---|---|---|
| **Resumen** | Ventas brutas, descuentos, devoluciones, netas, tickets, ticket medio, unidades vendidas/devueltas/netas, crédito de cambio, devoluciones online, pendientes de subir; comparación con el periodo anterior de la misma duración (diferencia y %) | `sale_line` por `sold_at`; `refund` por `(status, completed_at)`; `refund_line` unida a esas devoluciones |
| **Por día** | Fila por día con tickets, unidades, brutas, devoluciones, netas; los días sin ventas salen con 0; gráfico de barras de netas | `GROUP BY DATE(sold_at)` y `DATE(completed_at)`; el relleno de días vacíos en PHP |
| **Por hora** | 24 franjas (0-23) con tickets e importe bruto, sumando todos los días del rango | `GROUP BY HOUR(sold_at)` |
| **Por producto** | Producto + combinación: referencia, nombre, unidades vendidas, devueltas y netas, importe neto, % del total; orden por importe o unidades; paginado de 50; pestaña «Sin ventas»: productos activos del catálogo sin ninguna línea en el rango | `GROUP BY product_id, id_product_attribute`; devoluciones por `refund_line` |
| **Por categoría** | Importe neto, unidades y % por categoría (`category_id` guardado en la venta; NULL = «Sin categoría») | `GROUP BY category_id`; devolución con ticket toma la categoría de su `sale_line` (`order_id` + `line_index`); sin ticket, la del catálogo actual |
| **Por cajero** | Tickets, ventas brutas, ticket medio, devoluciones (por quien la hizo, `refund.user_id`), netas; nombre actual del empleado | `GROUP BY user_id` en ventas y en devoluciones |
| **Por forma de pago** | Ventas: efectivo, tarjeta, crédito de cambio; nº de tickets por `payment_method` (incluido mixto). Devoluciones: efectivo, tarjeta, vale. Vales de cambios cerrados. Neto cobrado | Mismas reglas que `aggregateTotals` (§5), por fecha o por sesión |
| **IVA** | Por tipo de IVA: base, cuota y total de ventas, de devoluciones y neto; aviso con el importe que procede de líneas aproximadas | `sale_line GROUP BY vat_rate`; devoluciones: `refund_line.amount` con el tipo de su `sale_line`; sin ticket, tipo del catálogo actual |

Las cantidades de `refund_line` se cuentan solo si su `refund` está `done` y `online = 0`.

### I3 — Exportación CSV

- Cada informe (con sus filtros) se descarga con el mismo endpoint y `format=csv`.
- Formato: UTF-8 con BOM, separador `;`, fin de línea CRLF, comillas dobles según RFC 4180 cuando el texto lleve `;`, `"` o saltos de línea.
- Números: coma decimal, sin separador de miles, sin símbolo `€` (`1234,50`). Fechas `dd/mm/aaaa`; horas `HH:MM`.
- Primera fila: cabeceras en español. Última fila: «Total» en los informes que suman.
- Las celdas de texto (nombre, referencia, empleado, categoría) que empiecen por `=`, `+`, `-` o `@` se escapan anteponiendo `'` (inyección de fórmulas en Excel). Las celdas numéricas no se tocan.
- Nombre del archivo: `informe-<tipo>-<desde>-<hasta>.csv` (por ejemplo `informe-productos-2026-03-01-2026-03-31.csv`). El de producto exporta **todas** las filas, no solo la página visible.
- PDF: fuera de la primera versión (P8).

### I4 — Acceso y API

- Solo administradores (`session.role === 'admin'`). Un cajero recibe 403 `{"error": "Solo un administrador puede ver los informes"}`; sin sesión, 401. El botón no se muestra a los cajeros.
- Endpoint: `GET /api/reports/{type}` con `type` ∈ `summary`, `daily`, `hourly`, `products`, `categories`, `cashiers`, `payments`, `vat`; parámetros `from`, `to` (`AAAA-MM-DD`), `terminal_id`, `user_id`, `session_id` (solo `payments`), `sort`, `cursor`, `format` (`json` | `csv`). Respuesta JSON `{data: {...}, approximate_amount, missing_lines}`.
- Errores: fecha inválida, `from > to` o rango > 366 días → 422 con `{error}`; `type` desconocido → 404.
- Se registra en `audit_log` cada exportación CSV (acción `report_export`, tipo y rango), no cada consulta.

### I5 — Interfaz

- Pantalla completa «Informes», como la de incidencias: botón en la cabecera de la pantalla de venta, visible solo para administradores, con «Volver» (P2).
- Arriba: selector de informe (pestañas), filtros de fecha y caja, botón «Descargar CSV». Los filtros se mantienen al cambiar de pestaña.
- Tablas con importes alineados a la derecha en formato `1.234,50 €`; gráfico de barras hecho con HTML/CSS (sin librería nueva).
- Estados: cargando, sin datos («No hay ventas en este periodo»), error con «Reintentar», y aviso amarillo si hay datos aproximados o ventas sin líneas.
- Por producto: paginación «Anterior» / «Siguiente» con cursor, como `PrintHistoryPanel.js`.

### I6 — Rendimiento

- Volumen de referencia: 1 año, 20 000 tickets, 60 000 líneas, 2 000 devoluciones.
- Objetivo: cada informe de un año responde en < 1 s en el servidor y el CSV en < 3 s. Sin tablas precalculadas.
- Todas las consultas filtran por rango en la columna indexada (`sold_at`, `created_at`, `completed_at`), nunca con funciones sobre la columna en el `WHERE`; `DATE()` y `HOUR()` solo en `GROUP BY`. El informe de forma de pago lee `payment_breakdown` solo de las filas del rango.
- Un test de rendimiento opcional genera ese volumen y comprueba el `EXPLAIN` (uso de índice, sin recorrido completo de `order_meta`).

## 5. Cuadre con el Z

El informe «Por forma de pago» filtrado por **sesión de caja** reproduce el Z de esa sesión, columna a columna:

| Columna del informe | Clave de `z_report.totals` | Origen |
|---|---|---|
| Ventas en efectivo | `sales_cash` | Σ `movement.amount` tipo `sale` de la sesión |
| Ventas con tarjeta | `sales_card` | Σ `tender.card` (o `total` si `payment_method = 'card'` y no hay `tender`) |
| Devoluciones efectivo / tarjeta / vale | `refunds_cash` / `refunds_card` / `refunds_voucher` | `movement` `refund`; `refund.card_amount`; `refund.voucher_amount` + vales de cambios cerrados en la sesión |
| Crédito de cambio | `exchange_credit` | `refund.exchange_credit` sin cambio `closed_voucher` |
| Neto | `net_total` | |

- La implementación reutiliza la lógica de `aggregateTotals` (extraída a una clase compartida) en lugar de copiarla, para que no se separen.
- Por fechas, la suma de un día coincide con la suma de los Z **solo si todas las sesiones abrieron y cerraron ese día**. Una sesión que cruza la medianoche reparte sus ventas entre dos días en el informe y no en el Z; es correcto y la pantalla lo explica.
- Las ventas netas del Resumen coinciden con `net_total` del Z en esas mismas condiciones, salvo un cambio en `credit_open` (devolución hecha, venta nueva aún no): el Resumen ya cuenta la devolución.

## 6. Casos límite

| Caso | Comportamiento |
|---|---|
| Venta a las 23:59:59 y otra a las 00:00:00 | Cada una en su día; filtro semiabierto |
| Cambio de hora (octubre) | La franja 02:00 puede sumar dos horas reales; se acepta (DATETIME local sin zona) |
| PHP con zona distinta a la de la tienda | Las horas salen desplazadas. La instalación comprueba que `date_default_timezone_get()` tiene el mismo desfase que `PS_SHOP_TIMEZONE` y avisa si no |
| Varias cajas | Filtro por `terminal_id`; «Todas» suma |
| Caja renombrada o con letra cambiada | Se agrupa por `terminal_id` y se muestra el nombre actual; la letra antigua sigue en `reference` |
| Empleado renombrado o desactivado | Se agrupa por `user_id`, con el nombre actual |
| Producto renombrado o borrado del catálogo | Se muestra el `name` guardado en la venta; categoría guardada |
| Categoría movida o borrada | Se usa `category_name` guardado |
| Combinaciones (talla/color) | Fila por `product_id` + `id_product_attribute`, con opción de agrupar por producto |
| Artículo libre | `product_id` NULL; en «Por producto» se agrupa como «Artículo libre» por nombre; categoría «Sin categoría» |
| Ticket con importe 0 (todo descuento o cupón) | Cuenta como ticket; importe 0 |
| Devolución sin ticket | Cuenta en devoluciones y por producto; categoría e IVA del catálogo actual |
| Devolución de una venta anterior al periodo | Cuenta en el periodo de la devolución |
| Venta en `incident` o `discarded` en el outbox | Cuenta (es un ticket emitido) |

## 7. Criterios de aceptación

Datos de prueba (todos con IVA 21 %; Camiseta 25,00 €, Gorro 10,00 €, Bañador 40,00 €). Caja A, sesión 1 abierta el 10/03/2026 y cerrada el 11/03 a las 12:00; caja B, sesión 2 del 10/03 y sesión 3 del 12/03.

| Ticket | Fecha y hora | Caja / cajero | Contenido | Cobro |
|---|---|---|---|---|
| T1 | 10/03 09:15 | A / Ana | 2 Camiseta + 1 Gorro | efectivo 60,00 |
| T2 | 10/03 18:40 | B / Luis | 1 Bañador, 10 % descuento de línea → 36,00 | tarjeta 36,00 |
| T3 | 10/03 23:59:30 | A / Ana | 1 Gorro | mixto: 4,00 efectivo + 6,00 tarjeta |
| T4 | 11/03 00:00:10 | A / Ana | 1 Camiseta | efectivo 25,00 |
| D1 | 11/03 10:00 | A / Ana | devuelve 1 Camiseta de T1 | efectivo 25,00 |
| C1 | 12/03 11:00 | B / Luis | cambio: devuelve el Bañador de T2 (crédito 36,00) y se lleva otro Bañador 40,00 | crédito 36,00 + tarjeta 4,00 |

1. **Resumen 10/03:** tickets 3; antes de descuentos 110,00; descuentos 4,00; brutas 106,00; devoluciones 0,00; netas 106,00; ticket medio 35,33; unidades 5.
2. **Resumen 11/03:** tickets 1; brutas 25,00; devoluciones 25,00; netas 0,00; unidades vendidas 1, devueltas 1. El Resumen del 10/03 sigue igual después de D1.
3. **Resumen 12/03:** brutas 40,00; devoluciones 36,00; netas 4,00; crédito de cambio 36,00.
4. **Por día 10–12/03:** tres filas (106,00 / 0,00 / 4,00 netas); con el rango 09–12/03 sale además el 09/03 con 0.
5. **Por hora 10/03:** 09 h 60,00; 18 h 36,00; 23 h 10,00; el resto 0.
6. **Por producto 10–11/03:** Camiseta 3 vendidas, 1 devuelta, 2 netas, 50,00 €; Gorro 2 / 0 / 2, 20,00 €; Bañador 1 / 0 / 1, 36,00 €.
7. **Por forma de pago 10/03:** efectivo 64,00; tarjeta 42,00; tickets mixtos 1.
8. **Cuadre Z:** para las sesiones 1, 2 y 3, cada columna del informe filtrado por sesión es igual a la clave correspondiente de su `z_report.totals` (sesión 1: `sales_cash` 89,00, `refunds_cash` 25,00, `net_total` 70,00; sesión 3: `sales_card` 4,00, `exchange_credit` 36,00, `net_total` 4,00). Test de integración MySQL que cierra las sesiones con `CashSessionService::closeSession` y compara.
9. **IVA 10/03:** tipo 21 %: base 87,59, cuota 18,41, total 106,00 (suma de líneas: 41,32 + 8,26 + 29,75 + 8,26 de base).
10. **Consistencia:** si falla la inserción de una línea, no queda ni `order_meta`, ni movimiento, ni registro fiscal, ni fila de outbox.
11. **Relleno:** una venta antigua con JSON sin categoría genera sus líneas con `approximate = 1` y la categoría actual; relanzar el comando no cambia el número de filas; un producto que ya no existe sale como «Sin categoría» con IVA 21 % aproximado.
12. **CSV:** el de producto abre en Excel (Windows, configuración española) con acentos, `ñ` y `€` correctos, importes como números y una fila «Total»; un nombre que empieza por `=` aparece como texto.
13. **Acceso:** un cajero recibe 403 en todos los `type` y no ve el botón; sin sesión, 401; `from > to` o 400 días → 422.
14. **Rendimiento:** con el volumen de I6, el Resumen y Por producto de un año responden en < 1 s y el `EXPLAIN` usa los índices.

## 8. Entrega por fases

| Fase | Contenido | Tamaño |
|---|---|---|
| **F1** | Tabla `sale_line` e índices, escritura en `insertOrderMeta`, comando de relleno, tests (criterios 10 y 11) | M |
| **F2** | API + pantalla «Informes» con Resumen, Por día y Por forma de pago, CSV, acceso (criterios 1-4, 7, 8, 12, 13) | M |
| **F3** | Por producto (con «Sin ventas» y paginación), Por categoría, IVA (criterios 6, 9) | M |
| **F4** | Por hora, Por cajero, comparación con el periodo anterior, test de rendimiento (criterios 5, 14) | S |

F1 desbloquea también F5 de la factura completa; conviene hacerla una sola vez para los dos PRD.

## 9. Riesgos

- **IVA de cabecera mal calculado con descuentos de línea** (§2): los informes lo evitan, pero el registro fiscal y el ticket siguen usando `payment_breakdown.tax` hasta que se arregle aparte.
- **Datos aproximados en ventas antiguas:** categoría e IVA del catálogo actual. Se señala siempre con el importe afectado.
- **Diferencias de un céntimo** entre la suma de líneas (este PRD) y un desglose calculado por tipo de IVA y ticket. Se fija una sola regla en F1 y la factura completa la reutiliza.
- **Zona horaria del servidor** distinta a la de la tienda (§6).
- **Deriva con el Z** si se copia la lógica de `aggregateTotals` en vez de compartirla (§5).

## 10. Fuera de alcance

- Informes de stock y valoración de inventario.
- Pedidos online de PrestaShop (P3).
- Cuadros de mando en tiempo real o en la pantalla de venta.
- PDF de los informes (P8).

## 11. Preguntas abiertas

- **P1.** ¿Algún cajero debe ver informes? **Recomendado:** no en la primera versión; el X de caja ya le da su día.
- **P2.** ¿Pantalla propia o sección de Ajustes? **Recomendado:** pantalla propia desde la cabecera, como incidencias; Ajustes es configuración y su ventana es pequeña para tablas.
- **P3.** ¿Incluir pedidos online de PrestaShop? **Recomendado:** no. Si se añade más adelante, en columna aparte, excluyendo los pedidos que creó el propio TPV (si no, se cuentan dos veces) y nunca en el informe de IVA del TPV.
- **P4.** ¿Qué entra en la primera versión? **Recomendado:** F1 + F2 (Resumen, Por día, Por forma de pago).
- **P5.** ¿Qué tipos de IVA se usan (21, 10, 4, exento)? **Recomendado:** redondear el tipo calculado a esa lista; si queda a más de 0,5 puntos de todos, guardar el calculado y marcar la línea aproximada. Misma pregunta que P4 de la factura completa.
- **P6.** ¿Categoría por defecto o agrupada por categoría padre? **Recomendado:** la categoría por defecto guardada en la venta; agrupar por padre, más adelante.
- **P7.** ¿Devoluciones en la fecha de la devolución? **Recomendado:** sí (§3).
- **P8.** ¿Hace falta PDF? **Recomendado:** no; el CSV cubre a la gestoría.
