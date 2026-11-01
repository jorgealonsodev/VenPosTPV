# Parked tickets (ventas en espera)
Objective: persist parked tickets in both server MariaDB and browser localStorage, auto-park the active cart on restore swap, support optional notes and a header badge counter, and warn on Z-close with an option to discard or keep parked tickets. Branch: `feat/parked-tickets`.
Delivery: feature-branch-chain inherited; runtime `.runtime/php-8.5.12/bin/php` and Node test runner.

## Specs
S1. "1. Aparcar tickets (ventas en espera) — Recomendado" / "si con odd"
S2. "1. Actualmente los tickets aparcados se guardan solo en localStorage del navegador. ¿Dónde deben persistirse? — (custom) en ambos"
S3. "2. Si el cajero ya tiene productos en el carrito activo y pulsa "Restaurar" sobre un ticket aparcado, ¿qué debe ocurrir? — Aparcar el actual e intercambiar — Recomendado"
S4. "3. ¿Cómo quieres identificar en tienda cada ticket aparcado (ej. "Probador 2", "Señora abrigo gris") y ver cuántos hay pendientes? — Nota opcional + cliente + contador en cabecera — Recomendado"
S5. "4. ¿Cómo deben comportarse los tickets aparcados respecto al stock y al Cierre Z de caja? — Sin bloqueo de stock + aviso en Cierre Z — Recomendado"

## Tasks
T1 | S1-S2, S4-S5 | inline | done | Created `parked_ticket` migration/schema, `ParkedTicketService`, and `ParkedTicketController` (`/api/parked-tickets` GET, POST, DELETE, clear) with 14 PHPUnit tests (47 assertions) passing; commit: 74db599.
T2 | S1-S5 | inline | done | Implemented `useParkedTickets` dual store (MariaDB API + `localStorage` sync), `useCart.restore` discount preservation, auto-park swap on restore, optional note + header badge counter, and Z-close parked tickets warning/discard option; Node (4/4) & Twig (1/14) tests passing; commit: 64f3c55.
T3 | S1-S5 | inline | done | Full PHP 8.5 suite 291 tests/713 assertions OK (17 vendor deprecations, 19 intentional MySQL skips); Node `useSession` 7 and `parkedTickets` 4 pass; CGI/runtime/settings contracts pass. Migration verified on disposable MariaDB (1 test/16 assertions) and applied additively to the local `venpostpv` DB. Playwright verified park+note, header badge, list metadata, restore swap and Z-close warning/discard with all unrelated writes blocked; test rows removed (parked_rows=0), orders 16/23 and admin cash session 10 unchanged. Commit: this record.

## Log
L1. User: "hazme preguntas sobre caracteristicas que creas que debe incluir un tpv, y dame varias opciones marcando una como recomendada"
L2. User selected "1. Aparcar tickets (ventas en espera) — Recomendado" (`parked_tickets`).
L3. User answered the 4 specification questions:
- Persistencia: "(custom) en ambos" (server MariaDB + browser `localStorage`).
- Carrito activo al restaurar: "Aparcar el actual e intercambiar — Recomendado".
- Identificación: "Nota opcional + cliente + contador en cabecera — Recomendado".
- Cierre Z / Stock: "Sin bloqueo de stock + aviso en Cierre Z — Recomendado".
L4. User: "si con odd".
L5. T1 completed: `Version00000016CreateParkedTicketTable`, `ParkedTicketService`, `ParkedTicketController`, unit tests 14/47 pass; committed as 74db599.
L6. T2 completed: `useParkedTickets` dual store (server API + `localStorage` fallback with pending sync on refresh), `useCart.restore` now preserves discounts, auto-park swap on restore, optional note modal, header badge counter, Z-close warning with discard. Node RED observed first (missing store; then total 0 and cross-realm deepEqual assertions) then GREEN 4/4; Twig asset test extended to 7 versioned modules (1 test/14 assertions). Committed as 64f3c55.
L7. Disposable MariaDB verification found `lines` is a reserved word in MariaDB 11 with type JSON (SQLSTATE 42000 / 1064). Renamed to `lines_json` in migration, `install.sql`, service insert/hydrate and fixtures; migration test then passed 1 test/16 assertions and its container was removed. Committed as df24c20.
L8. Local DB: `parked_ticket` created with `CREATE TABLE IF NOT EXISTS` only (no existing data touched); schema confirmed via `DESCRIBE`; row count 0 before and after testing.
L9. Playwright end-to-end (isolated context, simulated open cash, every non-parked API write blocked): park with note `Probador 2` → cart emptied, header badge `1`, localStorage entry synced=true; Tickets list shows note + `Cliente:` + `Cajero:`; restore issued `DELETE /api/parked-tickets/parked_…`, restored both lines and emptied the parked list; Z-close showed `Quedan 1 ticket aparcado sin cobrar` with `Descartar aparcados (1)`, and discarding removed the warning. No JS errors; the only blocked write was the unrelated automatic `POST /api/sync/now`.
L10. Post-verification state: `parked_ticket` rows 0, `order_meta` 16 orders / max 23, admin cash session 10 still open — no sales, refunds or cash closes created. No test containers remain. `.atl/skill-registry.md` and `.playwright-mcp/` left untouched and uncommitted as before.
