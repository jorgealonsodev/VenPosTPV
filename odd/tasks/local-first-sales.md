# Local-first sales — the TPV sells, refunds and exchanges without depending on PrestaShop
Branch: feat/local-first-sales (from feat/tpv-refunds). Delivery: ask-on-risk, one commit per task. Runners: PHPUnit (.runtime/php-8.5.12/bin/php vendor/bin/phpunit), module PHPUnit (modules/tpvconnect/tests/phpunit.xml), Node harnesses tests/Functional/*.test.cjs.
Product spec: docs/prd/venta-local-primero.md (rules V1-V5, S1-S6, D1-D3, C1-C2, I1-I3, acceptance A1-A8). The PRD is binding; this document only splits it.

## Specs
S1. Ticket code = PrestaShop order reference (PRD V3, decision table "Número del ticket"): "Un único código, igual en el TPV y en PrestaShop: 9 caracteres `<caja><año><número>`, p. ej. `A26000123` (caja A, 2026, nº 123)". 1 terminal letter, 2 year digits, 6 digits correlative without gaps per terminal and year. Each terminal has its own unique letter, editable in Settings.
S2. Module (PRD S3, S5): the module creates the order "con esa misma referencia, a través del gancho de PrestaShop `actionGenerateDocumentReference`. La referencia es también la clave de idempotencia: si ya existe un pedido con ella, el módulo lo devuelve en vez de crear otro." Module version 0.6.0. Web orders keep PrestaShop's own references.
S3. Sale (PRD V1, V2, V4): at checkout the sale is stored in one local transaction (lines with paid amounts, cash/card split, cash movement, local stock, ticket number, outbox row with a unique request_id); ticket printed and drawer opened "sin esperar a PrestaShop"; stock 0 or less: "Avisar y permitir". order_meta gets a local key; ps_order_id and the PrestaShop reference become nullable. Existing sales keep their reference and are "sincronizadas".
S4. Sync (PRD S1, S2, S4): "intento inmediato tras cada operación + tarea cada minuto, aunque el TPV esté cerrado"; operations of one order upload in order (sale before its refund/exchange); temporary errors retry with growing wait; definitive rejections (404/422) go to the "Lista de incidencias" without touching the local sale or cash; a lock prevents two processes uploading the same row.
S5. Refunds and exchanges (PRD D1-D3): work offline, also on unsynced sales; credit note numbered and printed at once; their PrestaShop upload is queued behind their sale. Vouchers need PrestaShop: offline "se avisa y se puede elegir otro medio". Replaces refunds PRD R21.
S6. Coupons (PRD V5): "No admitirlos sin conexión. El resto de la venta sigue."
S7. Customers (PRD C1-C2): "Copia local de clientes; las altas nuevas se crean en local y se suben con la venta."
S8. Visibility (PRD I1-I3): sync indicator with pending and incident counts; "Indicador de sincronización + aviso al cerrar caja", without blocking the close; admin-only incidents screen to see the error, correct and retry.
S9. Search (PRD S6): searching the code in the TPV or in PrestaShop finds the same ticket and order.

## Tasks
T1 | S2 | delegated (parallel with T2, context backstop) | pending | Module 0.6.0: reference hook + idempotent create by reference.
T2 | S1 | delegated (parallel with T1, context backstop) | pending | Terminal letter + gapless ticket number sequence per terminal/year.
T3 | S3 | pending | Local-first checkout: order_meta local key migration, outbox, print without PS. High risk (items 1, 4).
T4 | S4 | pending | Outbox sync: immediate attempt, tpv:sync:outbox every minute, lock, backoff, incidents.
T5 | S5 | pending | Refunds/exchanges queued behind their sale; offline voucher warning.
T6 | S6, S7 | pending | Offline coupon rejection; local customers copy and queued creation.
T7 | S8, S9 | pending | UI: sync indicator, cash-close warning, incidents screen, stock-0 warning; search by code.
T8 | all | pending | Live acceptance A1-A8 against PrestaShop + final independent verifier.

## Log
L1. User (2026-11-03): "Por que igual podriamos crar una base de datos mysql o mariadb propia del tpv, que fuera guardando todo, por si no se puede sincronizar contra prestashop o hay algun problema, ademas de guardar en el navegador tambien guardara en un base de datos propia. que opinas." Then "si", and "el codigo del ticket y de la venta de preshop tiene que ser el mismo o por lo menos tienen que estar unidos, si metes la referencia de presashop, en el tpv aparece el ticket igualmente y cuando sicronice tambien tiene que aparecer el codigo de prestashop en el ticket, a ver que se te ocurre". Decisions recorded in docs/prd/venta-local-primero.md.
L2. 2026-11-03 user: "haz lo que consideres mas optimo" after the refunds feature closed; started this feature. Open point kept: gestoría should validate the series (A26000123 tickets, R… credit notes) before going live; numbering stays configurable by terminal letter.
