# Sales lines, reports, full invoice and free item

Objective: implement docs/prd/informes-ventas.md, docs/prd/factura-completa.md and docs/prd/articulo-libre.md, starting with the shared per-line VAT table.
Branch: feat/sales-reports (from feat/verifactu af09dd0). Delivery: ask-on-risk. Runner: PHPUnit (.runtime/php-8.5.12/bin/php vendor/bin/phpunit, docker MySQL 127.0.0.1:33307) and node --test for JS.

## Specs

- S1 — The three PRDs are the specification, read in full: `docs/prd/informes-ventas.md`, `docs/prd/factura-completa.md`, `docs/prd/articulo-libre.md`. Their open questions take the **recommended** answer (user choice «Usar las recomendadas (Recommended)»); anything that depends on the club's tax adviser (exempt VAT, redeeming tickets printed without VAT rate) stays disabled until confirmed.
- S2 — Shared base: `sale_line` table (informes-ventas I1), written inside the sale transaction, backfill command `tpv:sales:backfill-lines`; acceptance criteria 10 and 11 of informes-ventas.
- S3 — Ticket prints the VAT rate (factura-completa phase 0, art. 7.1.f RD 1619/2012).
- S4 — Reports (informes-ventas I2-I6, criteria 1-14), admin only, own screen from the sale screen header.
- S5 — Full invoice (factura-completa phases 1-3): «una factura se entregara en A4»; «para facturas igual deberiamos meter la opcion de añadir otro impresora a mayores».
- S6 — Free item (articulo-libre phases 1-2, published together).

## Tasks

| ID | Specs | Route | Status | Commit |
|---|---|---|---|---|
| T1 | S2 (I1 table + write on sale, criterion 10) | inline | pending | |
| T2 | S2 (backfill command, criterion 11) | inline | pending | |
| T3 | S3 (VAT rate on ticket PDF/ESC-POS) | inline | pending | |
| T4 | S4 (shared cash totals aggregator extracted from aggregateTotals) | inline | pending | |
| T5 | S4 (API summary/daily/payments + CSV + access; criteria 1-4, 7, 8, 12, 13) | inline | pending | |
| T6 | S4 (Informes screen) | inline | pending | |
| T7 | S4 (products, categories, VAT; criteria 6, 9) | inline | pending | |
| T8 | S4 (hourly, cashiers, previous period, performance; criteria 5, 14) | inline | pending | |
| T9 | S5 phase 1 (customer tax data, F series, redeem ticket, A4 PDF, fiscal F3) | tbd | pending | |
| T10 | S5 phase 2 (invoice at sale time) | tbd | pending | |
| T11 | S5 phase 3 (A4 invoice printer in the agent, email) | tbd | pending | |
| T12 | S6 phases 1-2 (free item sale + PrestaShop upload) | tbd | pending | |

## Log

- L1 (2026-11-05) «empieza hace los prds anteriores con odd»
- L2 (2026-11-05) Open questions: user chose «Usar las recomendadas (Recommended)».
- L3 Route: T1-T8 inline (sequential, shared files CheckoutService/migrations; no writer reason). T9-T12 routed when reached.
