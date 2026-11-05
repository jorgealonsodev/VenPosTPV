# VERI*FACTU fiscal records

Objective: chained, unalterable fiscal record per issued ticket and credit note (always on); VERI*FACTU QR, legend and AEAT submission behind a switch that ships off.
Branch: feat/verifactu (from feat/print-agent). Delivery: ask-on-risk → feature-branch-chain (user choice); slices: T1 = a22710c..4c7d6d1. Tests: PHPUnit with `PATH=.runtime/php-8.5.12/bin:$PATH php vendor/bin/phpunit`.

## Specs

- S1 — "genere un registro de cada ticket encadenado con una huella (hash) con el anterior". Every issued simplified invoice (local sale `A26000123`, coupon sale with PrestaShop reference) and every credit note (`R2026-000001`) writes one record in the same DB transaction that issues it, chained by the previous record's hash. Hash per AEAT "Detalle de las especificaciones técnicas para generación de la huella o hash de los registros de facturación" v0.1.2: fields `IDEmisorFactura, NumSerieFactura, FechaExpedicionFactura (dd-mm-yyyy), TipoFactura, CuotaTotal, ImporteTotal, Huella (previous, empty for the first), FechaHoraHusoGenRegistro` joined `name=value&...`, trimmed, UTF-8, SHA-256, 64 upper-case hex. Must reproduce the official vectors (3C464DAF…12F60, F7B94CFD…02A2B97, 177547C0…F90C68).
- S2 — "impida modificar o borrar registros". No code path updates or deletes a record; the database refuses UPDATE/DELETE on the record table where the host allows triggers (best effort, never blocks install); a verification recomputes the whole chain and reports the first broken record.
- S3 — "lo verifactu lo podemos implementar pero de momento desactivado". A VERI*FACTU switch, off by default. User decision: "Registro siempre activo (Recommended)" — S1/S2 run with the switch off; the switch only governs the QR, the «VERI*FACTU» legend and the AEAT submission.
- S4 — With the switch on, tickets and credit notes print «QR tributario», the AEAT cotejo QR (nif, numserie, fecha, importe) and «VERI*FACTU» (ESC/POS and PDF).
- S5 — With the switch on, records are submitted to the AEAT web service (certificate, retries, incidents). Separate state table; records themselves stay immutable.

Assumptions (to confirm with the club's adviser): TipoFactura F2 for tickets, R5 for credit notes; CuotaTotal of a sale = stored breakdown `tax`; of a credit note = proportional to the source sale's tax/total, else 21 % included; NIF = `terminal.tax_id`.

## Tasks

- T1 — S1, S2: fiscal_record table, hash builder, chain writer hooked into sale/coupon sale/credit note issuance, immutability triggers, chain verification (GET /api/fiscal/chain). Route: inline (no Writer trigger reason). Status: done, commit: see Log L6. Risk: items 1 (migration), 4 (chain-head lock).
- T2 — S3, S4: switch in Ajustes + QR/legend on ESC/POS and PDF. Route: inline. Status: done, commit 55a486d.
- T4 — L8: review RD 1007/2023 and Orden HAC/1177/2024 against the implementation; list gaps. Route: inline research. Status: pending.
- T3 — S5: AEAT submission (needs the club's certificate and the AEAT test environment). Route: inline. Status: pending.

## Log

- L1 (2026-11-05) user: "podemos hacer esto?" about the VERI*FACTU list, then narrowed to: "genere un registro de cada ticket encadenado con una huella (hash) con el anterior; - impida modificar o borrar registros;"
- L2 (2026-11-05) user: "lo verifactu lo podemos implementar pero de momento desactivado". Answer to "¿qué debe seguir funcionando?": "Registro siempre activo (Recommended)".
- L3 Exploration: TicketNumberer::next only in CheckoutService::createLocalOrder (217); coupon sales use PS reference (createCouponOrder 360/406); credit notes in RefundService::completeLocally (648) and ::complete (1124), all inside transactional. No tax columns: sale VAT only in payment_breakdown JSON. No triggers exist in migrations. TestDatabase runs a fixed migration list without SQL parameters.
- L4 Official hash vector 1 reproduced with sha256sum.
- L5 T1 evidence: FiscalHashTest reproduces the 3 official AEAT vectors (RED: class missing → GREEN). Integration on a throwaway MySQL 8 (binlog on): chain links, rollback leaves no record, verifier detects edit/removal/tail truncation, triggers refuse UPDATE/DELETE when allowed and are skipped without error when the user lacks the privilege (as on shared hosting). Real refund/sale flows wrote 52 chained records. Full suite: 1007 tests; the 7 failing integration tests fail identically on the base commit (diff of failure lists empty). Container: FiscalRecorder gets doctrine.dbal.tpv_connection, the same connection as CheckoutService/RefundService.
- L6 Commit a22710c holds the employees fix (needed by TestDatabase's migration list); T1 is the next commit.
- Pending for production: the server only runs migrations from install.php, so Version00000042 must be applied there before records are written (until then sales go on and the gap is logged).
- L7 (2026-11-05) Delivery question answered: "Cadena sobre la rama (Recommended)" (feature-branch-chain). T1 commit 4c7d6d1; RDD assess: high, review due (escalated item 1).
- L8 (2026-11-05) user, pasted text about the developer's obligations (declaración responsable signed and given to the client; AEAT access to the event log, invoice sequence and chain integrity; the system shows its identification, commercial name, exact version and the declaration identifier; LGT art. 201 bis sanctions up to 150.000 € per year), with: "luego revisa toda la normativa he leido algo como esto" and then "busca la ley y compruebalo". → task T4 (regulation review against the implementation).
- L9 Independent verifier on T1: S2 met; S1 partial with blocker B1 (cash sale locked fiscal_chain before session, refunds the reverse → reproduced deadlock). Advisory A2: dates used the server zone. Correction 0777bd7: session locked first in both sale paths (regression test asserts order), records dated Europe/Madrid (integration test 23:30 UTC → next day). Recheck of B1 requested. Advisories A1 (CREATE TRIGGER IF NOT EXISTS needs MySQL ≥ 8.0.29), A3 (global chain lock serializes sales), A4 left open.
- L10 T2 commit 55a486d: FiscalQr (official URL-encoding example passes; production base www2.agenciatributaria.gob.es, QR spec v0.5.0), VerifactuSettings (app_setting verifactu.enabled, off by default), GET/PUT /api/fiscal/settings (admin), QR on ESC/POS (EC M, 6 dots/module) and PDF (32 mm) at the top, Ajustes → VERI*FACTU section with "Comprobar registros". With the switch off the ticket HTML is byte-identical to before (existing snapshot tests). Full suite 1018 tests, only the 7 baseline failures. RED was not observed separately for FiscalQr (test and class written together).
- L11 T4 research delegated (primary sources: BOE RD 1007/2023, Orden HAC/1177/2024, LGT 201 bis, AEAT FAQ).
- L12 B1 recheck: fixed (lock order session → counter → fiscal_chain → stock on every sale and refund path; two-session replay without deadlock).
- L13 T4 research (primary sources: BOE consolidated RD 1007/2023, Orden HAC/1177/2024, LGT, RD 1619/2012, LIS; AEAT FAQ; Hacienda note 5/10/2026). Parent spot check: Orden art. 3 verbatim excludes VERI*FACTU systems from arts. 6.b)-6.f), 7.f), 7.h)-7.j), 8 and 9 (event log, signature, export, navigation). Findings:
  - Dates in force: before 1/1/2027 (IS taxpayers), 1/7/2027 others (RDL 15/2025). Announced move to October 2028 not in BOE.
  - Pasted claims: declaración responsable true (RRSIF 13, Orden 15; visible in the system and given to the client); AEAT inspection access true (RRSIF 14, Orden 6.e/7.h); "show declaration identifier" not found as such (the declaration, with name/ID/version, must be accessible in the system); LGT 201 bis true: 150.000 €/year per system type (producer), 1.000 € per uncertified system sold, 50.000 €/year for the holder.
  - Switch OFF = NO VERI*FACTU mode once obligatory: requires XAdES signature, event log, per-record checks, navigation, export, full RegistroAlta content, QR on every ticket, declaración responsable. Mostly missing.
  - Switch ON today prints «VERI*FACTU» but nothing is sent: fails RRSIF 16.1 («remitir efectivamente») and misleads the cotejo.
  - Cheapest compliant route: VERI*FACTU-only system (TipoUsoPosibleSoloVerifactu=S) + sender (T3); then event log, signature and export are not required (Orden art. 3).
  - Gaps also in VERI*FACTU mode: full record content (SistemaInformatico block, tax breakdown, etc.), previous-record identity in each record (Orden 7.a), first-record mark, R5 TipoRectificativa + FacturasRectificadas, anulación records, sender, declaración responsable.
  - Adviser questions: exemption status of the club (LIS 9), SII, producer role for in-house software, 2027 vs 2028, R5 I/S, non-taxable operations, certificate/representation.
- L14 Native review (lenses risk, resilience, reliability; consent granted by the user) over e5fd21d..574a278: approved and acknowledged (gentle-ai.review-acknowledged/v1). Non-blocking follow-ups: R1-001/R4-nulllogger (gap logged only if a logger is injected; fallback recorder uses NullLogger), R4-first-head-insert-deadlock + R3-install-sql (install.sql does not seed fiscal_chain id=1 nor create triggers), R3/R4 verifier reads without a shared snapshot (false "tail removed" if a sale commits mid-check), R3-pdf-test needs a skip when pdftotext is missing, R3 refund/coupon fiscal hooks lack direct tests, R3-empty-nif (record and QR with empty NIF without a warning).
- L15 (2026-11-05) user: "haz lo que consideres mas optimo" (answer to: block the switch until AEAT submission exists + fix review follow-ups). Done:
  - Switch guard: VerifactuSettings::SUBMISSION_AVAILABLE=false; PUT enabled:true → 409 "VERI*FACTU no se puede activar todavía: esta versión del TPV aún no envía los registros a la AEAT"; a stored '1' is ignored; Ajustes shows the checkbox disabled with that reason. T3 flips the constant.
  - Empty NIF: recorded and logged as a warning; no QR printed without NIF.
  - Verifier reads records and head in one REPEATABLE READ snapshot (test: a sale committed mid-check is no false alarm).
  - install.sql seeds fiscal_chain id=1 (no first-ticket race on fresh installs).
  - Tests: credit note writes its chained R5 record (RefundService integration); coupon cash sale locks the session before its fiscal record; PDF test skips without pdftotext.
  - R4-nulllogger: no change — production DI injects FiscalRecorder with the logger service (debug:container); the fallback only applies to code built without DI.
  - Full suite 1023 tests, only the 7 baseline failures. RED was not observed separately for the snapshot and coupon-order tests.
