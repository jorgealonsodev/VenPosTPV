# Terminal-owned cash session and isolated test database
Objective: a cash drawer belongs to the terminal, so any active cashier can operate a session another cashier opened; and the test suite stops destroying synced catalog prices. Branch: `fix/ttc-price-basis`. No PrestaShop module change.

## Specs
S1. "si esta abierta igula eso no deberia de salir, que opinas" — an already open cash session on the terminal must not produce the cash-open screen.
S2. "La caja es del terminal (recomendado)" / "La sesión abierta pertenece al terminal: cualquier empleado activo puede cobrar en ella. Los movimientos registran quién actúa. La UI muestra «caja abierta por X». Elimina el callejón sin salida."
S3. "BD de test dedicada (recomendado)" / "Crear una base de datos de pruebas (p. ej. venpostpv_test) con install.sql, y apuntar ahí los tests de integración y migración. Dejarían de borrar los precios sincronizados y se podrían activar los 5 tests rotos."
S4. "prueba con playwrigt a ver como va" — the change is verified in the real UI, without completing a real sale.

## Tasks
T1 | S1,S2,S4 | inline | done | the open session is resolved by terminal instead of by user: `findCurrentSession()` prefers the cached session id, then the terminal of the current shop, then a shop-wide lookup, and exposes `opened_by_user_id` / `opened_by`. `requireOpenSession()` keeps reporting the acting cashier in `user_id` and adds the opener. Verified in the browser.
T2 | S3 | inline | pending | dedicated test database: create it from `migrations/install.sql`, point the migration and integration tests at it, repair the 5 pre-existing broken integration tests (nonexistent `catalog_stock.id_shop`) and add `VenPosTPV\Migrations\` to the composer autoload map. Without this, every suite run wipes `price_ttc` and `price_net`, because the migration tests drop and recreate those columns.

## Log
L1. User: "si esta abierta igula eso no deberia de salir, que opinas". Diagnosis: `openSession()` enforces one open session per terminal (`WHERE terminal_id = ? AND closed_at IS NULL`) while `requireOpenSession()` and `/api/cash/current` looked it up per user, so a drawer opened by another cashier produced the worst of both: the UI hid the sale screen and the API refused to open a session ("Cash session already open for terminal"). Dead end: no sale, no open.
L2. Reproduction: session 10 open on terminal 1 owned by user 2 (Master Admin); cashier 1 (María) saw "APERTURA DE CAJA" and got HTTP 409 on "Abrir caja".
L3. Fix verified in the browser after the change: the sale screen renders for cashier 1, `/api/cash/current` reports `open: true`, `session_id: 10`, `opened_by: "Master Admin"`, and the grid shows the tax-included prices (t-shirt 23.14, sweater 34.75, mug 14.40).
L4. Playwright evidence of the money path, with `POST /api/checkout` intercepted so no real sale was created: cart 3 units for 88.63 with "IVA incluido 15.39" (7.35 from the 42.35 pack plus 2 x 4.02 from the t-shirt, exact to the cent; the old 21% constant would have said 15.38). Captured payload: `payment_method: cash`, `amount_tendered: 100`, `total: 88.63`, `iva: 15.39`, lines `{product 15, attr 0, qty 1, price 42.35, net 35}` and `{product 1, attr 1, qty 2, price 23.14, net 19.12}`. Every price is the tax-included one the checkout validates, and the combination keeps its attribute id.
L5. Found while testing, recorded here because it is a defect of this feature, not of the application: the two migration tests drop and recreate `price_ttc` / `price_net`, so each suite run leaves those columns empty and the POS silently falls back to the base basis. Re-syncing restores them. This is what T2 fixes.
L6. Not verified and not claimed: a real sale end to end (creating a PrestaShop order and a cash movement) was deliberately not performed. The server-side acceptance of this exact payload is covered by the unit tests that assert the price validation path.
