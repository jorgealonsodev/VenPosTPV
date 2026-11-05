# VERI*FACTU fiscal records

Objective: chained, unalterable fiscal record per issued ticket and credit note (always on); VERI*FACTU QR, legend and AEAT submission behind a switch that ships off.
Branch: feat/verifactu (from feat/print-agent). Delivery: ask-on-risk. Tests: PHPUnit with `PATH=.runtime/php-8.5.12/bin:$PATH php vendor/bin/phpunit`.

## Specs

- S1 — "genere un registro de cada ticket encadenado con una huella (hash) con el anterior". Every issued simplified invoice (local sale `A26000123`, coupon sale with PrestaShop reference) and every credit note (`R2026-000001`) writes one record in the same DB transaction that issues it, chained by the previous record's hash. Hash per AEAT "Detalle de las especificaciones técnicas para generación de la huella o hash de los registros de facturación" v0.1.2: fields `IDEmisorFactura, NumSerieFactura, FechaExpedicionFactura (dd-mm-yyyy), TipoFactura, CuotaTotal, ImporteTotal, Huella (previous, empty for the first), FechaHoraHusoGenRegistro` joined `name=value&...`, trimmed, UTF-8, SHA-256, 64 upper-case hex. Must reproduce the official vectors (3C464DAF…12F60, F7B94CFD…02A2B97, 177547C0…F90C68).
- S2 — "impida modificar o borrar registros". No code path updates or deletes a record; the database refuses UPDATE/DELETE on the record table where the host allows triggers (best effort, never blocks install); a verification recomputes the whole chain and reports the first broken record.
- S3 — "lo verifactu lo podemos implementar pero de momento desactivado". A VERI*FACTU switch, off by default. User decision: "Registro siempre activo (Recommended)" — S1/S2 run with the switch off; the switch only governs the QR, the «VERI*FACTU» legend and the AEAT submission.
- S4 — With the switch on, tickets and credit notes print «QR tributario», the AEAT cotejo QR (nif, numserie, fecha, importe) and «VERI*FACTU» (ESC/POS and PDF).
- S5 — With the switch on, records are submitted to the AEAT web service (certificate, retries, incidents). Separate state table; records themselves stay immutable.

Assumptions (to confirm with the club's adviser): TipoFactura F2 for tickets, R5 for credit notes; CuotaTotal of a sale = stored breakdown `tax`; of a credit note = proportional to the source sale's tax/total, else 21 % included; NIF = `terminal.tax_id`.

## Tasks

- T1 — S1, S2: fiscal_record table, hash builder, chain writer hooked into sale/coupon sale/credit note issuance, immutability triggers, chain verification (GET /api/fiscal/chain). Route: inline (no Writer trigger reason). Status: done, commit: see Log L6. Risk: items 1 (migration), 4 (chain-head lock).
- T2 — S3, S4: switch in Ajustes + QR/legend on ESC/POS and PDF. Route: inline. Status: pending.
- T3 — S5: AEAT submission (needs the club's certificate and the AEAT test environment). Route: inline. Status: pending.

## Log

- L1 (2026-11-05) user: "podemos hacer esto?" about the VERI*FACTU list, then narrowed to: "genere un registro de cada ticket encadenado con una huella (hash) con el anterior; - impida modificar o borrar registros;"
- L2 (2026-11-05) user: "lo verifactu lo podemos implementar pero de momento desactivado". Answer to "¿qué debe seguir funcionando?": "Registro siempre activo (Recommended)".
- L3 Exploration: TicketNumberer::next only in CheckoutService::createLocalOrder (217); coupon sales use PS reference (createCouponOrder 360/406); credit notes in RefundService::completeLocally (648) and ::complete (1124), all inside transactional. No tax columns: sale VAT only in payment_breakdown JSON. No triggers exist in migrations. TestDatabase runs a fixed migration list without SQL parameters.
- L4 Official hash vector 1 reproduced with sha256sum.
- L5 T1 evidence: FiscalHashTest reproduces the 3 official AEAT vectors (RED: class missing → GREEN). Integration on a throwaway MySQL 8 (binlog on): chain links, rollback leaves no record, verifier detects edit/removal/tail truncation, triggers refuse UPDATE/DELETE when allowed and are skipped without error when the user lacks the privilege (as on shared hosting). Real refund/sale flows wrote 52 chained records. Full suite: 1007 tests; the 7 failing integration tests fail identically on the base commit (diff of failure lists empty). Container: FiscalRecorder gets doctrine.dbal.tpv_connection, the same connection as CheckoutService/RefundService.
- L6 Commit a22710c holds the employees fix (needed by TestDatabase's migration list); T1 is the next commit.
- Pending for production: the server only runs migrations from install.php, so Version00000042 must be applied there before records are written (until then sales go on and the gap is logged).
