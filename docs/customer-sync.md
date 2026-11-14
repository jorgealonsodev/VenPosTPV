# Local customers (PRD C1, C2)

"Copia local de clientes; las altas nuevas se crean en local y se suben con la venta."

## The local copy

- Table `customer` (migration `Version00000034LocalCustomers`): `id` (local), `ps_customer_id` (unique, NULL until a till-created customer is uploaded), `firstname`, `lastname`, `email`, `phone` (the PrestaShop customer resource has none: it comes from the customer's addresses, below), `active`, `outbox_id` (the upload of a till-created customer), `synced_at`.
- `bin/console tpv:customers:sync` refreshes it from the PrestaShop webservice (`GET /api/customers?display=[id,firstname,lastname,email,active]&filter[deleted]=0`), like `tpv:catalog:sync`: the read happens before the write transaction, rows are upserted by PrestaShop id, customers PrestaShop no longer returns are deactivated (never deleted), and till-created customers not uploaded yet are left alone. It runs every night (03:30, shop time) from the single scheduled task `tpv:cron`; it can still be run by hand.
- Phones (TPV Connect 0.8.2): the same sync reads the addresses (`GET /api/addresses?display=[id,id_customer,phone,phone_mobile]&filter[deleted]=0`) and takes, for each customer, the mobile or else the landline of the newest address (up to 32 characters). A customer without an address phone keeps the phone already stored (for example, one typed at the till). When the key cannot read addresses (a module older than 0.8.2), the sync still runs and phones stay as they are.
- `GET /api/customers?q=` searches the active rows by name, email or phone (up to 20). It never calls PrestaShop, so it works offline.

## Customers created at the till

- `POST /api/customers` `{firstname, lastname, email, phone?}` stores the customer at once and queues an outbox row of type `customer` in the same transaction. Answers 201 with the customer (`local_id`, `id: null`, `synced: false`), 200 with `existing: true` when an active customer already has that email, and 422 with `error` when the form is not valid. PrestaShop requires first name, last name and email, and rejects names with digits or symbols, so the TPV checks the same before storing.
- The sale screen's customer (`cart_data.customer`) carries `local_id` and `id` (PrestaShop id). The server takes the PrestaShop id from the local copy, never from the browser; a customer with only `id` (older parked tickets) behaves as before.
- A sale for a customer not uploaded yet is queued with `id_customer: 0`, `_customer_local_id` in its payload and `outbox.depends_on` = the customer's outbox row. The sync does not claim the sale until that row is `done`, then sends it with the PrestaShop customer id read at that moment (`_customer_local_id` is never sent to the module).
- Upload (`OutboxSync`, type `customer`): the customer is looked up by email first (`filter[email]`), so a retry after an unknown outcome, or a customer that already exists in the shop, is linked instead of created twice; otherwise `POST /api/customers` (XML, random password, no group sent: PrestaShop's default group is expected to apply, to confirm on the live shop). A synced copy of the same PrestaShop customer is merged into the till-created row.
- Errors: network and 5xx wait 1, 2, 5, 15, 30, 60 minutes like any upload. 400/422 (data refused) and 401/403/404/405 (the webservice key cannot read or create customers) become an incident; the customer's sales keep waiting, without spending attempts, until the administrator fixes and retries it.

## Deployment

1. Run the migrations (`Version00000034LocalCustomers`): `OutboxSync` reads `outbox.depends_on`.
2. Give the webservice key GET and POST on `customers` and GET on `addresses` in PrestaShop (Advanced Parameters > Webservice). Pairing and TPV Connect 0.8.2 (`upgrade-0.8.2.php`) grant both to the TPV's key.
3. Nothing to schedule: the `tpv:cron` task runs `tpv:customers:sync` every night from 03:30 (shop time), and on its next minute when it has never run.
