# Informes de ventas

Los informes muestran cómo van las ventas a partir de la base de datos local del TPV: funcionan sin conexión con PrestaShop e incluyen las ventas que aún no se han subido. Solo los administradores pueden verlos.

Especificación completa: `docs/prd/informes-ventas.md`.

## Informes disponibles

| Informe | `type` | Contenido |
|---|---|---|
| Resumen | `summary` | Ventas brutas, descuentos, devoluciones, netas, tickets, ticket medio, unidades vendidas, devueltas y netas, crédito de cambio, devoluciones online y operaciones pendientes de subir. Se compara con el periodo anterior de la misma duración (diferencia y %). |
| Por día | `daily` | Una fila por día con tickets, unidades, ventas brutas, devoluciones y ventas netas. Los días sin ventas salen con 0. |
| Por forma de pago | `payments` | Ventas en efectivo y con tarjeta, crédito de cambio, devoluciones en efectivo, con tarjeta y con vale, vales de cambios cerrados, número de tickets por forma de pago y neto cobrado. |

Por hora, Por producto, Por categoría, Por cajero e IVA llegarán en una versión posterior; hoy responden 404.

## Qué cuenta

- **Ventas brutas:** lo pagado de verdad en los tickets del periodo, con IVA, después de descuentos y cupones. La venta nueva de un cambio cuenta por su total, también la parte pagada con crédito.
- **Descuentos:** la diferencia entre el importe antes de descuentos (cantidad × precio) y lo pagado.
- **Devoluciones:** las devoluciones terminadas, en la fecha en que se hicieron, no en la de la venta original. Por eso un día puede tener ventas netas negativas. Las devoluciones de pedidos online no cuentan (el TPV no pagó nada) y se muestran aparte, solo como información.
- **Ventas netas:** ventas brutas menos devoluciones.
- **Crédito de cambio:** el dinero de un cambio que ni entra ni sale; se muestra aparte, como en el cierre Z.
- **Pendientes de subir:** ventas y devoluciones del periodo que siguen en la cola de subida a PrestaShop (pendiente, en proceso o incidencia). Cuentan en el informe igualmente.
- No cuentan los tickets aparcados ni las devoluciones pendientes o fallidas.

El periodo va del día «desde» a las 00:00:00 hasta el final del día «hasta», en la hora local de la tienda. Como máximo, 366 días.

### Cuadre con el cierre Z

«Por forma de pago» usa el mismo cálculo que el informe Z. Filtrado por una sesión de caja (`session_id`), cada importe coincide con el del Z de esa sesión. Filtrado por fechas, el efectivo se toma de los movimientos de caja de cada día. Una sesión que cruza la medianoche reparte sus ventas entre los dos días, mientras que el Z las cuenta juntas.

### Avisos

Cada respuesta incluye:

- `approximate_amount`: importe que procede de líneas aproximadas (ventas antiguas completadas con datos del catálogo actual).
- `missing_lines`: ventas del periodo que todavía no tienen líneas (falta ejecutar `tpv:sales:backfill-lines`). Esas ventas cuentan como tickets, pero no en importes ni unidades.

«Por forma de pago» no usa las líneas de venta, así que en ese informe los dos valores son siempre 0.

## API

`GET /api/reports/{type}`

| Parámetro | Valor |
|---|---|
| `from`, `to` | Fechas `AAAA-MM-DD`, obligatorias salvo en `payments` con `session_id` |
| `terminal_id` | Solo una caja (opcional) |
| `user_id` | Solo un cajero (`summary` y `daily`) |
| `session_id` | Solo `payments`: una sesión de caja, en lugar de fechas y caja |
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
- Primera fila con las cabeceras; en «Por día» y «Por forma de pago», la última fila es «Total».
- Los textos que empiezan por `=`, `+`, `-` o `@` llevan delante un `'` para que Excel no los ejecute como fórmula.
- Nombre del archivo: `informe-<tipo>-<desde>-<hasta>.csv`, con tipo `resumen`, `dias` o `formas-pago` (por ejemplo `informe-dias-2026-03-01-2026-03-31.csv`). Por sesión de caja: `informe-formas-pago-sesion-<id>.csv`.

Cada exportación queda registrada en el registro de auditoría (acción `report_export`, con el informe y los filtros). Las consultas en pantalla no se registran.
