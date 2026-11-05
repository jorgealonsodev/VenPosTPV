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
- L4 T1 verifier: PASS, no blockers. Advisories: VAT rule is a 1-cent match on net×(1+rate) instead of P5's 0.5-point rule (kept: better for cheap items); exempt items < 0.13 € read as 4 % (fixed in 945f35c: equal prices → 0 %); 0 € lines marked approximate (accepted); test cleanups left orphan sale_line rows (fixed by FK ON DELETE CASCADE, 72ac742).
- L5 T2/T3 RED observed only for VatRate and SaleLine tests; backfill and ticket box tests written right after the code (no pre-implementation RED).
- L6 Decision: sale VAT (payment_breakdown.tax, fiscal_record.tax_total) = sum of the per-rate VAT box (factura-completa F5), replacing resolveVatAmount; 2×23.14 at 21 % now 8.03 instead of 8.04. Ticket prints «IVA 21 %: x € (base y €)» per rate; sales without lines keep «IVA: x €».
- L7 Test DB container venpos-test-mysql had to be recreated (mysql:8.0); a fresh DB runs 11 legacy tests that used to be skipped (list in scratchpad base-fail-freshdb.txt), unrelated to this feature.
- L8 Native review of af09dd0..945f35c (lenses risk, reliability; consent «Revisar este cambio»): CRITICAL R3 reprint of backfilled sales would change the printed VAT → corrected in 0e34d03 (box only when no approximate line and Σ equals stored tax); approved and acknowledged (lineage review-1a890fbc203528c9). Advisories fixed in the next commit: missing sale_line table on print, INSERT IGNORE in backfill, test of a backfilled reprint.
- L9 T4/T5 by a bounded writer: RED observed for T5 (43 class-not-found), T4 protected by existing cash tests plus a base-vs-change differential (X, z_report.totals and hashes identical). Full suite 1193 tests, only the 18 known fresh-DB failures. Decisions: cash by date via sessions open in the range + movement date; unusable filters → 422; CSV names resumen/dias/formas-pago; >366 days → 422. Criterion 12 Excel opening not tested by hand.
- L10 Native review of 0e34d03..cabcc0b (risk, reliability; consent «Revisar este cambio»): approved and acknowledged, no correction. Advisories: pending uploads double count → fixed 44065ba (COUNT DISTINCT, no dedicated test); TableNotFoundException import → false positive (imported at TicketDataResolver.php:9); till filter on refunds/vouchers in period mode untested → follow-up test in T7. Verifier advisories (range mode): movements with NULL created_at and exchanges with NULL completed_at drop out of date ranges; accepted.
