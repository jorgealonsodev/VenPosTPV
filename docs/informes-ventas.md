# Informes de ventas

Los informes muestran cómo van las ventas a partir de la base de datos local del TPV: funcionan sin conexión con PrestaShop e incluyen las ventas que aún no se han subido. Solo los administradores pueden verlos.

Especificación completa: `docs/prd/informes-ventas.md`.

## Pantalla «Informes»

En la cabecera de la pantalla de venta, el botón **Informes** (solo lo ven los administradores) abre la pantalla completa; **Volver** regresa a la venta.

- **Pestañas:** Resumen, Por día, Por hora, Por producto, Por categoría, Por cajero, Por forma de pago e IVA.
- **Periodo:** Hoy, Ayer, Esta semana (de lunes a domingo), Este mes, Mes anterior, Este año o Personalizado (elige «Desde» y «Hasta» y pulsa «Aplicar»; como máximo 366 días).
- **Caja:** todas o una.
- **Sesión de caja** (solo en «Por forma de pago»): «Por fechas» o uno de los últimos cierres de caja; por sesión no se aplican las fechas ni la caja, y los importes coinciden con el Z de esa sesión.
- Los filtros se mantienen al cambiar de pestaña.
- **Descargar CSV** descarga el informe de la pestaña con los mismos filtros.
- «Resumen» compara cada dato con el periodo anterior de la misma duración (diferencia y %); «Por día» muestra además un gráfico de barras de las ventas netas, con los días negativos por debajo de la línea.
- «Por producto» tiene dos listas: **Vendidos** (con «Ordenar por» importe o unidades y «Agrupar» por combinación o por producto) y **Sin ventas** (productos activos del catálogo sin ninguna venta en el periodo y la caja elegida). Muestra 50 filas por página con «Anterior» y «Siguiente»; cambiar un filtro o una opción vuelve a la primera página.
- Si el periodo no tiene movimientos sale «No hay ventas en este periodo». Si la consulta falla, el motivo y el botón «Reintentar». Un aviso amarillo indica importes aproximados o ventas sin líneas (ver [Avisos](#avisos)).

## Informes disponibles

| Informe | `type` | Contenido |
|---|---|---|
| Resumen | `summary` | Ventas brutas, descuentos, devoluciones, netas, tickets, ticket medio, unidades vendidas, devueltas y netas, crédito de cambio, devoluciones online y operaciones pendientes de subir. Se compara con el periodo anterior de la misma duración (diferencia y %). |
| Por día | `daily` | Una fila por día con tickets, unidades, ventas brutas, devoluciones y ventas netas. Los días sin ventas salen con 0. |
| Por hora | `hourly` | 24 franjas (00:00 a 23:00) con los tickets y las ventas brutas, sumando todos los días del periodo. |
| Por producto | `products` | Una fila por producto y combinación: referencia, nombre, unidades vendidas, devueltas y netas, ventas, devoluciones, importe neto y % del total. El artículo libre se agrupa por nombre como «Artículo libre: <nombre>». Con `view=unsold`, la lista «Sin ventas». |
| Por categoría | `categories` | Unidades, ventas, devoluciones, importe neto y % por la categoría guardada en la venta (con su nombre de entonces); sin categoría, «Sin categoría». |
| Por cajero | `cashiers` | Por empleado, con su nombre actual: tickets, ventas brutas, ticket medio, devoluciones (de quien las hizo) y ventas netas. |
| Por forma de pago | `payments` | Ventas en efectivo y con tarjeta, crédito de cambio, devoluciones en efectivo, con tarjeta y con vale, vales de cambios cerrados, número de tickets por forma de pago y neto cobrado. |
| IVA | `vat` | Por tipo de IVA: base, cuota y total de las ventas (suma de las bases de cada línea), de las devoluciones y el neto. |

## Qué cuenta

- **Ventas brutas:** lo pagado de verdad en los tickets del periodo, con IVA, después de descuentos y cupones. La venta nueva de un cambio cuenta por su total, también la parte pagada con crédito.
- **Descuentos:** la diferencia entre el importe antes de descuentos (cantidad × precio) y lo pagado.
- **Devoluciones:** las devoluciones terminadas, en la fecha en que se hicieron, no en la de la venta original. Por eso un día puede tener ventas netas negativas. Las devoluciones de pedidos online no cuentan (el TPV no pagó nada) y se muestran aparte, solo como información.
- **Ventas netas:** ventas brutas menos devoluciones.
- **Crédito de cambio:** el dinero de un cambio que ni entra ni sale; se muestra aparte, como en el cierre Z.
- **Pendientes de subir:** ventas y devoluciones del periodo que siguen en la cola de subida a PrestaShop (pendiente, en proceso o incidencia). Cuentan en el informe igualmente.
- No cuentan los tickets aparcados ni las devoluciones pendientes o fallidas.
- **Devoluciones por producto, categoría e IVA:** una devolución con ticket toma la categoría y el tipo de IVA de la línea de venta que devuelve; una devolución sin ticket, los del catálogo actual. Un producto que ya no está en el catálogo sale como «Sin categoría» al 21 %, y su importe cuenta como aproximado en el informe de IVA.

El periodo va del día «desde» a las 00:00:00 hasta el final del día «hasta», en la hora local de la tienda. Como máximo, 366 días.

### Cuadre con el cierre Z

«Por forma de pago» usa el mismo cálculo que el informe Z. Filtrado por una sesión de caja (`session_id`), cada importe coincide con el del Z de esa sesión. Filtrado por fechas, el efectivo se toma de los movimientos de caja de cada día. Una sesión que cruza la medianoche reparte sus ventas entre los dos días, mientras que el Z las cuenta juntas.

### Avisos

Cada respuesta incluye:

- `approximate_amount`: importe que procede de líneas aproximadas (ventas antiguas completadas con datos del catálogo actual).
- `missing_lines`: ventas del periodo que todavía no tienen líneas (falta ejecutar `tpv:sales:backfill-lines`). Esas ventas cuentan como tickets, pero no en importes ni unidades.

«Por forma de pago» no usa las líneas de venta, así que en ese informe los dos valores son siempre 0. En «IVA», `approximate_amount` suma además las devoluciones cuyo tipo de IVA es supuesto (producto que ya no está en el catálogo o sin precios).

## API

`GET /api/reports/{type}`

| Parámetro | Valor |
|---|---|
| `from`, `to` | Fechas `AAAA-MM-DD`, obligatorias salvo en `payments` con `session_id` |
| `terminal_id` | Solo una caja (opcional) |
| `user_id` | Solo un cajero (todos menos `payments`) |
| `session_id` | Solo `payments`: una sesión de caja, en lugar de fechas y caja |
| `sort` | Solo `products`: `amount` (importe neto, por defecto) o `units` (unidades netas) |
| `group` | Solo `products`: `combination` (por defecto) o `product` (suma las combinaciones, con el nombre del catálogo) |
| `view` | Solo `products`: `sold` (por defecto) o `unsold` («Sin ventas») |
| `cursor` | Solo `products`: la primera fila de la página (`0`, `50`, …). La respuesta trae `page` con `next_cursor` y `previous_cursor` (`null` si no hay) y `total_rows` |
| `format` | `json` (por defecto) o `csv` |

Respuesta JSON: `{"data": {...}, "approximate_amount": 0.0, "missing_lines": 0}`.

Errores:

| Código | Cuándo |
|---|---|
| 401 | Sin sesión iniciada |
| 403 | El usuario no es administrador: `{"error": "Solo un administrador puede ver los informes"}` |
| 404 | Informe desconocido o sesión de caja inexistente |
| 422 | Fecha no válida, «desde» posterior a «hasta», más de 366 días o filtro no admitido por el informe |

## Exportación CSV

Con `format=csv` se descarga el informe con los mismos filtros. El archivo está preparado para abrirse en Excel con configuración española:

- UTF-8 con BOM, separador `;` y fin de línea CRLF.
- Importes con coma decimal, sin separador de miles ni símbolo `€` (`1234,50`). Fechas `dd/mm/aaaa`.
- Primera fila con las cabeceras; la última fila es «Total» en todos los informes salvo «Resumen» y «Sin ventas».
- El CSV de «Por producto» tiene **todas** las filas, no solo la página que se ve.
- Los textos que empiezan por `=`, `+`, `-` o `@` llevan delante un `'` para que Excel no los ejecute como fórmula.
- Nombre del archivo: `informe-<tipo>-<desde>-<hasta>.csv`, con tipo `resumen`, `dias`, `horas`, `productos`, `productos-sin-ventas`, `categorias`, `cajeros`, `formas-pago` o `iva` (por ejemplo `informe-dias-2026-03-01-2026-03-31.csv`). Por sesión de caja: `informe-formas-pago-sesion-<id>.csv`.

Cada exportación queda registrada en el registro de auditoría (acción `report_export`, con el informe y los filtros). Las consultas en pantalla no se registran.

## Rendimiento

Todas las consultas filtran por un rango semiabierto sobre la columna de fecha indexada (`sale_line.sold_at`, `order_meta.created_at`, `refund.completed_at`); `DATE()` y `HOUR()` solo aparecen en `GROUP BY`. El test opcional `SalesReportPerformanceMySqlIntegrationTest` (se ejecuta con `TPV_PERF_TEST=1`) genera el volumen de referencia (20 000 tickets, 60 000 líneas y 2 000 devoluciones en un año, más un año anterior), comprueba que cada informe del año responde en menos de 1 s y el CSV de productos en menos de 3 s, y que el `EXPLAIN` usa los índices de fecha.
