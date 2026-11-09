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
| T1 | S2 (I1 table + write on sale, criterion 10) | inline | done; verifier PASS | 582cf3c, 72ac742 (FK) |
| T2 | S2 (backfill command, criterion 11) | inline | done | 8a79cf5 |
| T3 | S3 (VAT rate on ticket PDF/ESC-POS) | inline | done | 945f35c |
| T4 | S4 (shared cash totals aggregator extracted from aggregateTotals) | delegated (context backstop) | done; verifier PASS (X/Z totals and hash byte-identical on a wide probe) | 21769e3 |
| T5 | S4 (API summary/daily/payments + CSV + access; criteria 1-4, 7, 8, 12, 13) | delegated (context backstop) | done | cabcc0b |
| T6 | S4 (Informes screen) | delegated (context backstop) | done; templates compile with @vue/compiler-dom 3.5.44, no browser run | 868733d |
| T7 | S4 (products, categories, VAT; criteria 6, 9) | delegated (context backstop) | done | 1ce9b3a |
| T8 | S4 (hourly, cashiers, previous period, performance; criteria 5, 14) | delegated (context backstop) | done | 1ce9b3a |
| T9 | S5 phase 1 (customer tax data, F series, redeem ticket, A4 PDF, fiscal F3) | delegated (context backstop) | done; verifier running | c304992 |
| T10 | S5 phase 2 (invoice at sale time) | tbd | pending | |
| T11 | S5 phase 3 (A4 invoice printer in the agent, email) | tbd | pending | |
| T12a | S6 phase 1 server (settings, checkout path, explicit VAT per line, sale_line, coupon/slot limits, upload held until T12c) | inline | done | 30d91ce |
| T12b | S6 phase 1 screen (button, dialog, «Libre» line, parked tickets keep the fields) | inline | pending | |
| T12c | S6 phase 2 (module generic products + slots + per-cart price + line name, OutboxSync resolution, catalog exclusion; spike on PrestaShop 8.1.7 first) | inline | pending | |

## Log

- L1 (2026-11-05) «empieza hace los prds anteriores con odd»
- L2 (2026-11-05) Open questions: user chose «Usar las recomendadas (Recommended)».
- L3 Route: T1-T8 inline (sequential, shared files CheckoutService/migrations; no writer reason). T9-T12 routed when reached.
- L4 T1 verifier: PASS, no blockers. Advisories: VAT rule is a 1-cent match on net×(1+rate) instead of P5's 0.5-point rule (kept: better for cheap items); exempt items < 0.13 € read as 4 % (fixed in 945f35c: equal prices → 0 %); 0 € lines marked approximate (accepted); test cleanups left orphan sale_line rows (fixed by FK ON DELETE CASCADE, 72ac742).
- L5 T2/T3 RED observed only for VatRate and SaleLine tests; backfill and ticket box tests written right after the code (no pre-implementation RED).
- L6 Decision: sale VAT (payment_breakdown.tax, fiscal_record.tax_total) = sum of the per-rate VAT box (factura-completa F5), replacing resolveVatAmount; 2×23.14 at 21 % now 8.03 instead of 8.04. Ticket prints «IVA 21 %: x € (base y €)» per rate; sales without lines keep «IVA: x €».
- L7 Test DB container venpos-test-mysql had to be recreated (mysql:8.0); a fresh DB runs 11 legacy tests that used to be skipped (list in scratchpad base-fail-freshdb.txt), unrelated to this feature.
- L8 Native review of af09dd0..945f35c (lenses risk, reliability; consent «Revisar este cambio»): CRITICAL R3 reprint of backfilled sales would change the printed VAT → corrected in 0e34d03 (box only when no approximate line and Σ equals stored tax); approved and acknowledged (lineage review-1a890fbc203528c9). Advisories fixed in the next commit: missing sale_line table on print, INSERT IGNORE in backfill, test of a backfilled reprint.
- L9 T4/T5 by a bounded writer: RED observed for T5 (43 class-not-found), T4 protected by existing cash tests plus a base-vs-change differential (X, z_report.totals and hashes identical). Full suite 1193 tests, only the 18 known fresh-DB failures. Decisions: cash by date via sessions open in the range + movement date; unusable filters → 422; CSV names resumen/dias/formas-pago; >366 days → 422. Criterion 12 Excel opening not tested by hand.
- L10 Native review of 0e34d03..cabcc0b (risk, reliability; consent «Revisar este cambio»): approved and acknowledged, no correction. Advisories: pending uploads double count → fixed 44065ba (COUNT DISTINCT, no dedicated test); TableNotFoundException import → false positive (imported at TicketDataResolver.php:9); till filter on refunds/vouchers in period mode untested → follow-up test in T7. Verifier advisories (range mode): movements with NULL created_at and exchanges with NULL completed_at drop out of date ranges; accepted.
- L11 T6: 14 JS tests (RED first), full JS suite 92/92 via `node --test tests/Functional/*.test.cjs`; templates compiled with the official compiler (0 errors); not rendered in a browser. Native assess medium, under_budget (pending in slice from 44065ba).
- L12 T7/T8: RED seen (14 errors, 12 failures; 4 JS), GREEN: report tests 76 OK, JS 95/95, template compile 0 errors, full suite only the 18 known. Perf test (TPV_PERF_TEST=1) passes: year reports < 1 s, products CSV < 3 s. Till filter on refunds/vouchers by date tested: no bug. Native assess medium, under_budget (slice from 44065ba pending). S4 complete except a browser run.
- L13 T9 (c304992): writer wrote code before tests; RED shown afterwards against a base copy (52/56 PHP, 7/7 JS failed there). Suites: 1273 tests, only the 18 known; JS 102/102; templates compile. Not built: «Sustituido por» on reprinted ticket (F3.4, outside surface), GET /api/invoices list, print endpoint. Open: refunds of a redeemed ticket still issue R5 against the ticket until F8; Version00000030 postUp could auto-assign F to a sixth till; OutboxSync customer merge may drop tax data of a duplicate; DescripcionOperacion not stored.
- L14 Native review of 44065ba..HEAD and of the invoice commit alone: lens_context_budget_exceeded. User chose «Dividir y revisar (Recommended)»: c304992 rebuilt locally into 58fe0ab (schema + F numbering), 2718624 (customer tax data), 0fa5079 (issuer + fiscal F3), 43e9906 (PDF + mail + API), cadfae9 (UI); tree identical to backup/sales-reports-before-split. Part 1 review (lineage review-12c10d79cf3ccc5b, run from a detached worktree) opened correction_required, then status stopped with corrupted_or_unverifiable_authority (terminal, findings not retrievable). User chose «Revisión manual de la parte 1 (Recommended)»: independent reviewer on 58fe0ab; parts 2-5 rely on the verifier PASS.
- L15 (2026-11-09) «hay que añadir la opcion de añadir al ticket un producto, que no este en el inventario, tien un producto si añadir a la web, pero necesita cobrarlo, que se te ocurre»; «Tiene que poder añadir a mano su nombre»; «continua con odd»; «haz que consideres mas optimo». The concept is typed by hand (A1.2). Recommended answers kept: VAT 21 % and 10 %, all cashiers, max unit price 1.000 € as a setting, 20 slots per rate, no coupon. T12 split into T12a-c, all inline (one behavior chain, each depends on the previous; no writer reason). Phase 1 and 2 are released together (PRD §7): until T12c a sale with a free item stays pending in the outbox with «Falta configurar el artículo libre en PrestaShop» (A5.3). Refunds of free items (phase 3) are out of S6.
- L16 (2026-11-09) T12a: RED 26 tests (8 errors, 18 failures) before the code, GREEN 26/170. Full PHP suite 1304 tests, only the 7 known base failures. Settings in app_setting free_item.* (defaults: on, all, 1000 €, 21 and 10 %); GET /api/checkout/free-item-settings; refunds of a free line still refused until phase 3. Before T12a every branch was merged into main (0c734d6) and deleted at the user's request; work continues on feat/free-item.
