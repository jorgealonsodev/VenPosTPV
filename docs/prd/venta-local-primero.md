# PRD — Venta local primero (el TPV no depende de PrestaShop para cobrar)

- **Estado:** borrador para revisión
- **Fecha:** 2026-11-03
- **Relación:** amplía `docs/prd/devoluciones.md` (R21) y el flujo de cobro actual

## 1. Objetivo

Que la tienda pueda **cobrar, devolver y cambiar aunque PrestaShop no responda**. Cada operación se guarda primero en la base de datos propia del TPV, se imprime y mueve caja al momento, y se sube a PrestaShop en segundo plano, sin pérdidas ni duplicados.

## 2. Situación actual

- El TPV ya tiene base de datos propia (MariaDB, conexión `TPV_DB_*`): caja, movimientos, informes X/Z, abonos (serie R), cambios, tickets aparcados y la copia del catálogo.
- **Venta:** primero se crea el pedido en PrestaShop (módulo `tpvconnect`, `PaymentModule::validateOrder`). Solo después se guarda en el TPV (`order_meta` usa `ps_order_id` como clave). Sin PrestaShop no se puede cobrar.
- **Devolución y cambio:** se escriben como pendientes y se envían a PrestaShop. Si PrestaShop cae, se bloquean (decisión R21 del PRD de devoluciones).
- El módulo ya es idempotente para devoluciones (`request_id`), pero **no para pedidos**: reintentar una venta podría crear dos pedidos.
- La tabla `offline_queue` existe pero no se usa. Messenger está configurado con el transporte Doctrine, sin worker.

## 3. Decisiones del usuario

| Tema | Decisión |
|---|---|
| Alcance sin conexión | Ventas, devoluciones y cambios. Solo los **vales** esperan a PrestaShop. |
| Modo | **Siempre primero en local**: un único camino, con y sin conexión. |
| Número del ticket | **Un único código, igual en el TPV y en PrestaShop**: 9 caracteres `<caja><año><número>`, p. ej. `A26000123` (caja A, 2026, nº 123). Se decide al cobrar y PrestaShop crea el pedido con esa misma referencia. |
| Stock local 0 | **Avisar y permitir.** |
| Pendientes de subir | **Indicador de sincronización + aviso al cerrar caja**, sin bloquear el cierre. |
| Cupones de descuento | **No admitirlos sin conexión.** El resto de la venta sigue. |
| Rechazo definitivo de PrestaShop | **Lista de incidencias** para el administrador, que corrige y reintenta. |
| Clientes | **Copia local de clientes**; las altas nuevas se crean en local y se suben con la venta. |
| Devolver una venta aún no subida | **Se sube la venta y después su abono**, en orden. |
| Quién sincroniza | **El servidor**: intento inmediato tras cada operación + tarea cada minuto, aunque el TPV esté cerrado. |

## 4. Reglas

### 4.1 Venta
- **V1.** Al cobrar, la venta se guarda en una sola transacción local: líneas con lo pagado, reparto efectivo/tarjeta, movimiento de caja, stock local, número de ticket de la serie del terminal y un registro de **salida pendiente** (outbox) con un `request_id` único.
- **V2.** El ticket se imprime y el cajón se abre **sin esperar a PrestaShop**.
- **V3.** El código del ticket es la **referencia definitiva del pedido** y tiene 9 caracteres, el máximo de `orders.reference` en PrestaShop 8.1.7:
  - 1 letra de caja (`A` = caja 1, `B` = caja 2…);
  - 2 cifras de año;
  - 6 cifras correlativas sin huecos por caja y año.

  Por ejemplo, `A26000123`. Lleva cifras, así que nunca coincide con las referencias de la web, que son 9 letras. Se imprime al momento en el texto y en el código de barras.
- **V4.** Si el stock local de una línea es 0 o menos, se avisa al cajero y se permite vender.
- **V5.** Un cupón de PrestaShop solo se acepta si PrestaShop responde en ese momento. Si no, se avisa y se cobra sin él.

### 4.2 Sincronización
- **S1.** Tras cada operación se intenta subir enseguida. Una tarea del servidor reintenta lo pendiente **cada minuto**, con o sin TPV abierto.
- **S2.** Las operaciones de un mismo pedido se suben **en orden**: la venta antes que su abono o su cambio.
- **S3.** El módulo crea el pedido con **esa misma referencia**, a través del gancho de PrestaShop `actionGenerateDocumentReference`. La referencia es también la clave de idempotencia: si ya existe un pedido con ella, el módulo lo devuelve en vez de crear otro.
- **S4.** Errores temporales (red, PrestaShop caído): reintento con espera creciente. Rechazos definitivos (404 o 422, por ejemplo un producto borrado): la operación pasa a la **lista de incidencias**, sin tocar la venta local ni la caja.
- **S5.** Al subir, se guarda en el TPV el `ps_order_id`. Si PrestaShop devolviera una referencia distinta (otro módulo que imponga la suya), se guarda también: la búsqueda la acepta y las reimpresiones la muestran como «Pedido web: XXXX». La unión nunca se pierde.
- **S6.** Buscar por el código, en el TPV o en PrestaShop, encuentra el mismo ticket y el mismo pedido.

### 4.3 Devoluciones y cambios
- **D1.** Se pueden hacer sin conexión, también sobre ventas aún no subidas. Las reglas del PRD de devoluciones no cambian (importes, motivos, plazo, reposición), salvo R21.
- **D2.** El abono R se numera y se imprime al momento. Su subida a PrestaShop (`OrderSlip` y reposición de stock) queda en la cola, detrás de su venta (S2).
- **D3.** Los vales (devolución sin ticket con vale, saldo de un cambio) requieren PrestaShop. Sin conexión se avisa y se puede elegir otro medio.

### 4.4 Clientes
- **C1.** Se mantiene una copia local de clientes, sincronizada como el catálogo.
- **C2.** Un cliente dado de alta sin conexión se crea en local y se sube a PrestaShop antes que la venta que lo usa.

### 4.5 Visibilidad
- **I1.** El indicador de sincronización muestra cuántas operaciones hay pendientes y cuántas en incidencia.
- **I2.** Al cerrar caja se avisa si quedan pendientes, sin bloquear el cierre.
- **I3.** Pantalla de incidencias (solo administrador): ver el error, corregir y reintentar.

## 5. Cambios técnicos previstos

- **Tabla de cola:** `outbox` (o reutilizar `offline_queue`), con tipo, `request_id`, orden por pedido, estado, intentos y último error.
- **`CheckoutService`:**
  - guarda primero en local, sin `ps_order_id`;
  - `order_meta` pasa a tener clave local;
  - `ps_order_id` y referencia de PrestaShop quedan como columnas que pueden ir vacías.
- **Devoluciones y cambios:** encolan su envío en lugar de llamar a PrestaShop en el momento.
- **Módulo `tpvconnect` (0.5.0):**
  - gancho `actionGenerateDocumentReference` para usar la referencia del TPV en los pedidos que vienen del TPV;
  - creación de pedidos idempotente por esa referencia.
- **Sincronizador:**
  - intento al terminar la petición;
  - comando `tpv:sync:outbox` cada minuto (cron o worker);
  - bloqueo para que dos procesos no suban lo mismo.
- **Clientes:** copia local y alta encolada.
- **UI:**
  - indicador con pendientes e incidencias;
  - aviso al cerrar caja;
  - pantalla de incidencias;
  - aviso de stock 0 y de cupón sin conexión.
- **Migración de datos:** las ventas existentes conservan su referencia de PrestaShop y quedan como «sincronizadas».

## 6. Criterios de aceptación

- **A1.** Con PrestaShop parado, se cobra una venta: se imprime el ticket `T1-…` y la caja se mueve. Al arrancar PrestaShop, en menos de 2 minutos aparece el pedido y el stock baja una sola vez.
- **A2.** Se corta la red justo después de que PrestaShop cree el pedido y antes de la respuesta: tras el reintento hay **un solo** pedido.
- **A3.** Con PrestaShop parado, se vende y se devuelve esa misma venta: al volver la conexión se crean el pedido y después el abono, y el stock final es correcto.
- **A4.** Se vende un producto borrado en PrestaShop: la operación aparece en incidencias y la caja local no cambia.
- **A5.** Con PrestaShop parado, un cupón se rechaza con aviso y la venta sin cupón se cobra.
- **A6.** Al cerrar caja con pendientes se ve el aviso y el cierre se permite.
- **A7.** Dos terminales venden a la vez sin conexión: cada una con su letra de caja, sin huecos ni duplicados.
- **A8.** Una venta impresa como `A26000123` aparece en PrestaShop con la referencia `A26000123`, y buscar `A26000123` en el TPV abre ese ticket.

## 7. Riesgos

- **Sobreventa en la web** mientras haya ventas de tienda sin subir.
- **Cambio de modelo de datos:** `order_meta` deja de tener como clave `ps_order_id`. Migración delicada (riesgo alto, punto 1).
- **Contrato del módulo:** nueva idempotencia de pedidos (riesgo alto, punto 3).
- **Concurrencia:** sincronizador frente a operaciones nuevas del mismo pedido (riesgo alto, punto 4).

## 8. Puntos abiertos

- Que la gestoría valide el formato de numeración por caja (`A26000123`) y su relación con la serie R de abonos, que hoy es única.
- Cómo se ejecuta la tarea cada minuto en producción: cron del sistema o servicio worker.
