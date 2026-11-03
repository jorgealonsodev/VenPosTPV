# Local-first sales and the PrestaShop outbox

Product rules: `docs/prd/venta-local-primero.md` (V1-V5, S1-S6).

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

A terminal without ticket letter, or a letter that used up its 999999 numbers of the
year, answers 422 with the cashier-facing message and stores nothing.

Still synchronous through PrestaShop, unchanged: native coupon checkout (PrestaShop
validates the coupon at that moment, PRD V5) and the new sale of an exchange (its
returned part already needs PrestaShop). Both are recorded as synced sales.

### Tickets of sales not uploaded yet

- `GET /api/ticket/sale/{order_id}/pdf` — PDF by the local id.
- `POST /api/ticket/reprint` with `{"order_id": 15}` — reprint by the local id.
  `{"ps_order_id": ...}` and `GET /api/ticket/{ps_order_id}/pdf` keep working for
  uploaded sales.
- `GET /api/orders/search?q=A26000123` finds the sale before and after the upload
  (also by a different PrestaShop reference, `ps_reference`).

Refunds of a sale not uploaded yet answer 409
`{"error": "La venta aún no se ha subido a PrestaShop", "code": "sale_not_synced"}`
when the request names it by `order_id` or `reference`, until refunds are queued
behind their sale.

## Upload to PrestaShop

`OutboxSync` sends each due row to the module's orders endpoint with
`reference` = ticket code (module 0.6.0). It runs:

1. once after the response of the request that queued the row (kernel.terminate);
2. every minute from cron, with or without the TPV open:

```cron
* * * * * php /path/to/VenPosTPV/bin/console tpv:sync:outbox
```

Use the PHP binary of the deployment (for example `.runtime/php-8.5.12/bin/php`) and
the absolute project path. `--limit=N` caps the uploads of one run (default 100).
Overlapping runs are safe.

| Module answer | Outcome |
|---|---|
| 201 created, 200 `replayed: true` | `order_meta.ps_order_id` set, `synced = 1`, cash movement linked, row `done`. A different reference is kept in `ps_reference`. |
| network error, timeout, 401, 409 (reference busy), 5xx | row back to `pending`, retried after 1, 2, 5, 15, 30, then every 60 minutes; `last_error` kept |
| 404, 422 | row `incident` with `last_error`; the local sale and the cash are not touched |

Rows of one sale upload in `sequence` order: a refund or exchange row waits until
its sale is `done`. A row is claimed by one process with a conditional UPDATE
(`pending` → `processing` with a claim token); a claim older than 5 minutes is taken
back (the module replays an order that already exists, so no duplicate is created).
Stock is never pushed: PrestaShop lowers it when it creates the order.

## Schema (Version00000031LocalFirstOrders)

- `order_meta`: `id` primary key, `reference VARCHAR(9)` unique, `ps_order_id` NULL
  and unique, `ps_reference VARCHAR(9)` NULL; `synced` is the sync flag. Existing
  sales keep their `ps_order_id`, are synced, and take their stored reference as
  `reference` when it is unique and at most 9 characters.
- `movement.order_id` (local sale of a sale movement).
- `outbox`: `type`, `order_id`, `sequence`, `request_id` (unique), `payload`, `status`
  (`pending`, `processing`, `done`, `incident`), `attempts`, `next_attempt_at`,
  `claim_token`, `claimed_at`, `last_error`, timestamps. The old, unused
  `offline_queue` table is left as it was.
