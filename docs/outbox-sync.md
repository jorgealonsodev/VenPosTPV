# Local-first sales and the PrestaShop outbox

Product rules: `docs/prd/venta-local-primero.md` (V1-V5, S1-S6, D1-D3, I1-I3).

## What happens at checkout

`POST /api/checkout` stores the whole sale in one local transaction:

- `order_meta` row with its local key `id`, the ticket code in `reference`
  (`A26000123`: terminal letter, two year digits, six digits) and `synced = 0`;
- the cash movement (`movement.order_id` points at the sale);
- the local stock decrement (it may go below zero);
- one `outbox` row (`type = sale`, `sequence = 1`, unique `request_id`).

The answer comes back at once with `order_id` (local), `reference`, `synced: false`,
`ps_order_id: null` and `stock_warnings` (one entry per line the local stock does not
cover: `line_index`, `product_id`, `id_product_attribute`, `requested`, `available`).
The ticket is printed and the drawer opened without waiting for PrestaShop.

The request carries the browser's `request_id` of the payment attempt (8 to 64 letters,
digits, `-` or `_`; PaymentModal keeps it across retries). A repeated request with the
same `request_id` stores nothing new and answers the sale already created, with
`replayed: true` (a fresh sale answers `replayed: false`). Any other `request_id` type
answers 422.

The sale screen names the products of `stock_warnings` after the sale ("Sin stock en el
TPV: <producto> — revisa el inventario") and marks, before paying, the lines with more
units than the local stock; neither blocks the sale (PRD V4).

A terminal without ticket letter, or a letter that used up its 999999 numbers of the
year, answers 422 with the cashier-facing message and stores nothing.

Still synchronous through PrestaShop, unchanged: native coupon checkout (PrestaShop
validates the coupon at that moment, PRD V5) and the new sale of an exchange (its
returned part already needs PrestaShop). Both are recorded as synced sales.

### Tickets of sales not uploaded yet

- `GET /api/ticket/sale/{order_id}/pdf` — PDF by the local id.
- `POST /api/ticket/reprint` with `{"order_id": 15}` — reprint by the local id; the
  order detail of the "Tickets → Cobrados" list reprints this way. A direct print marks
  `order_meta.printed` by the local id, also before the upload.
  `{"ps_order_id": ...}` and `GET /api/ticket/{ps_order_id}/pdf` keep working for
  uploaded sales and online orders.
- `GET /api/orders/search?q=A26000123` finds the sale before and after the upload
  (also by a different PrestaShop reference, `ps_reference`); the "Cobrados" list has
  this search box (PRD S6).
- When PrestaShop answered a reference other than the ticket code, reprints and PDFs
  show `Pedido web: XXXX` under `Ref: A26000123` (PRD S5). Other tickets are unchanged.

## Refunds and exchanges (PRD D1-D3)

Refunds and exchanges are done locally at once, also on sales not uploaded yet: the
credit note is numbered and printed, the cash moves and the local stock goes back up.
Their PrestaShop part is queued behind their sale:

- `refund` — the credit slip and restock of a refund of a TPV sale (`sequence` 2, 3…
  of its sale); its `ps_order_id` is read when it is sent, after the sale is `done`.
- `restock` — the restock of a refund without a ticket; it belongs to no sale
  (`order_id` NULL) and waits for nothing.

Refunds of online orders (no local sale) stay synchronous with PrestaShop.

Vouchers need PrestaShop (D3). When it cannot create one, the refund or exchange stores
nothing and answers 503
`{"error": "Sin conexión con PrestaShop: el vale no se puede crear ahora. Elige efectivo o tarjeta.", "code": "voucher_unavailable", "retry": false}`;
the request can be sent again with `voucher_fallback` naming another tender. Other
refund errors keep their status: 404 (not found), 409 (conflict, for example a refund
already in progress), 422 (validation); anything else is a retryable 502. A computer
not linked to a till answers 409 `{"code": "device_not_linked"}`.

## Upload to PrestaShop

`OutboxSync` sends each due row to the module's orders endpoint with
`reference` = ticket code (module 0.6.0). It runs:

1. once after the response of the request that queued the row (kernel.terminate);
2. every minute from cron, with or without the TPV open:

```cron
* * * * * php bin/console tpv:sync:outbox
```

In the crontab, run it from the project directory (for example
`* * * * * cd /path/to/VenPosTPV && php bin/console tpv:sync:outbox`).

Use the PHP binary of the deployment (for example `.runtime/php-8.5.12/bin/php`) and
the absolute project path. `--limit=N` caps the uploads of one run (default 100).
Overlapping runs are safe.

| Module answer | Outcome |
|---|---|
| 201 created, 200 `replayed: true` | `order_meta.ps_order_id` set, `synced = 1`, cash movement linked, row `done`. A different reference is kept in `ps_reference`. |
| network error, timeout, 401, 409 (reference busy), 5xx | row back to `pending`, retried after 1, 2, 5, 15, 30, then every 60 minutes; `last_error` kept |
| 404, 422 | row `incident` with `last_error`; the local sale and the cash are not touched |

Refund and restock rows follow the same table with the module's refund and restock
endpoints.

Rows of one sale upload in `sequence` order: a refund or exchange row waits until
its sale is `done`. A row is claimed by one process with a conditional UPDATE
(`pending` → `processing` with a claim token); a claim older than 5 minutes is taken
back (the module replays an order that already exists, so no duplicate is created).
Stock is never pushed for an upload: PrestaShop lowers it when it creates the order.

A `pending` row behind a `discarded` one (an earlier row of its sale, or the row in
its `depends_on`) can never upload. Each run moves it to `incident` with
`last_error` "Depende de una operación descartada", so the administrator sees it and
can discard it too, instead of it waiting forever.

### Manual sync and the POS timer

`POST /api/sync/now` uploads the due rows and then refreshes the catalog. The POS
calls it every minute without a body flag: only rows whose `next_attempt_at` has
come are sent, so the growing wait above applies while a POS is open. The header's
sync button sends `{"manual": true}`, which first makes every `pending` row due
(`OutboxSync::makePendingDue`) and so retries them all now.

### Stock of operations not uploaded yet

The local `catalog_stock` already counts every sale, refund and restock; PrestaShop
counts them only once their row is uploaded. For each product and combination,
`PendingUploadStock` adds the quantities of the `sale` rows still pending,
processing or in incident and subtracts the restocked lines of the `refund` and
`restock` rows in those statuses (`done` and `discarded` rows never count: one has
moved PrestaShop's stock already, the other never will):

- the absolute stock push (`POST /api/sync/push-stock`, every 30 s from the POS)
  sends `local + owed`, what PrestaShop should show before those uploads apply; the
  upload then moves PrestaShop's stock itself, so nothing is counted twice;
- the stock and catalog sync from PrestaShop store `PrestaShop − owed`, so a sale or
  restock not uploaded yet is not lost from the local copy.

Both read the outbox in the same transaction as `catalog_stock`. Between an upload
reaching PrestaShop and its row becoming `done`, one push or pull can be off by that
quantity; the next one (30 s / 1 min) corrects it.

## Pending uploads and incidents (PRD I1-I3)

- `GET /api/sync/outbox-status` (anyone logged in) answers
  `{"data": {"pending": 3, "incidents": 1}}`: `pending` counts `pending` and
  `processing` rows. The header's sync indicator shows both next to the catalog state
  (polled with `/api/sync/status`), and the cash close shows "Hay N operaciones
  pendientes de subir a PrestaShop" and the incidents, without blocking the close.
- The incidents screen (sync panel → "Ver incidencias", administrators only) lists the
  `incident` rows: type, ticket code and credit note number, date, `last_error`,
  attempts and how many rows of the sale wait behind. The data is corrected in
  PrestaShop or in the TPV; the screen only retries:
  - `GET /api/incidents` — the list;
  - `POST /api/incidents/{id}/retry` — that row back to `pending`, `attempts = 0`,
    due now (a conditional UPDATE on `status = 'incident'`);
  - `POST /api/incidents/order/{order_id}/retry` — every incident of one sale; they
    still upload in `sequence` order.

  - `POST /api/incidents/{id}/discard` with an optional `{"reason": "..."}` (up to 255
    characters; anything but text → 400 `invalid_reason`) — gives up uploading a row
    PrestaShop will never accept (a deleted product). A conditional UPDATE on
    `status = 'incident'` makes it `discarded` and stores `discarded_by_user_id` (the
    session's administrator), `discarded_at` and `discard_reason`; `last_error` and
    `attempts` stay. Answers `{"data": {"discarded": [id, ...]}}`, this row first.
    The local sale, its ticket and the cash do not change.
  - `GET /api/incidents/discarded` — the last 20 discarded rows, newest first, with
    who (`discarded_by`, the user's name), when and why; the screen shows them
    read-only under "Descartadas recientes".

  The POSTs take a JSON body only (415 otherwise), answer 401 without a session, 403
  to anyone but an administrator, and 409 `{"code": "not_incident"}` when there is
  nothing left to retry or discard (retried, uploaded or discarded meanwhile). Before
  migration Version00000036 is applied a discard answers 503 `discard_not_ready`.

- **Dependants of a discard.** OutboxSync only claims a `pending` row whose earlier
  rows of the same sale are `done` and whose `depends_on` row is `done`, so a row
  behind a discarded one could never upload. A discard therefore also discards, with
  the same who/when/why, every `pending` or `incident` row that waits behind it: the
  later rows of the same sale (higher `sequence`: its refunds and exchanges), the rows
  whose `depends_on` is it (the sales of a customer created at the till), and in turn
  what waits behind those. Each incident in `GET /api/incidents` carries `dependants`,
  that count, and the confirmation says it: "¿Descartar esta incidencia? La venta
  queda en el TPV (caja y ticket no cambian) pero no se subirá a PrestaShop, y N
  operaciones que dependen de ella."
- Discarded rows are terminal: never claimed (only `pending` is), never retried (the
  retry is conditional on `incident`), and left out of `pending`, `incidents`,
  `blocked`, the incident list and its `waiting_behind`.
- The screen shows a plain Spanish headline for each error (for example "Product not
  found" → "El producto ya no existe en PrestaShop", HTTP 401/403 → "La clave de
  conexión con PrestaShop no tiene permiso", "Failed N times in a row." → "El TPV no
  pudo guardar la respuesta de PrestaShop", otherwise "PrestaShop rechazó la
  operación"), with the raw `last_error` below it.

## Schema (Version00000031LocalFirstOrders)

- `order_meta`: `id` primary key, `reference VARCHAR(9)` unique, `ps_order_id` NULL
  and unique, `ps_reference VARCHAR(9)` NULL; `synced` is the sync flag. Existing
  sales keep their `ps_order_id`, are synced, and take their stored reference as
  `reference` when it is unique and at most 9 characters.
- `movement.order_id` (local sale of a sale movement).
- `outbox`: `type`, `order_id`, `sequence`, `request_id` (unique), `payload`, `status`
  (`pending`, `processing`, `done`, `incident`, `discarded`), `attempts`, `next_attempt_at`,
  `claim_token`, `claimed_at`, `last_error`, timestamps. The old, unused
  `offline_queue` table is left as it was.
- Version00000036OutboxDiscard adds `outbox.discarded_at`, `discarded_by_user_id` and
  `discard_reason` (all NULL; nothing existing is rewritten).
