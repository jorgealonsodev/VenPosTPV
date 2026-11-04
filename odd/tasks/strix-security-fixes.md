Objective: fix the verified Strix findings (run venpostpv_e94f) per docs/prd-seguridad-strix.md.
Branch: feat/print-agent · Delivery: work-unit commits, no push · Runner: `.runtime/php-8.5.12/bin/php vendor/bin/phpunit` (integration: disposable MariaDB, `-d variables_order=EGPCS`, TPV_DB_* env).

## Specs

The full requirements are R1–R13 in `docs/prd-seguridad-strix.md` §3. S# = R#.
- S1 = R1 mixed cash bounded · S2 = R2 refund session lock · S3 = R3 symfony/twig update + php >=8.4
- S4 = R4 SSO via Symfony session · S5 = R5 staff directory + rate limit · S6 = R6 session id rotation
- S7 = R7 Origin check · S8 = R8 short PIN (D1: reject, admin resets) · S9 = R9 JSON_HEX flags
- S10 = R10 security headers + CSP nonce · S11 = R11 dompdf · S12 = R12 php-jwt 7 · S13 = R13 installer (D3 deferred)
- Decisions: D1 reject legacy short PIN; D2 migrate(true); D3 deferred (separate delivery); D4 keep role.permissions.

## Tasks

- [x] T0 revert Strix working-tree edits — inline (git checkout of both files)
- [x] T1 S3 — inline · e3d62dc · audit clean except ignored php-jwt; unit 864/224 skipped, integration same as baseline
- [x] T2 S1+S2 money — delegated writer (parallel unit A) · d579699, 8cf2b1a
- [x] T3 S4+S5+S6+S8 auth — delegated writer (parallel unit B) · 8ca8003, c2583ca
- [x] T4 S7+S9+S10 listeners/templates — delegated writer (parallel unit C) · e102f3e, b73e4b4 (browser check: no CSP violations, hover OK)
- [x] T5 S11+S13 — inline · dea97f1 (dompdf, RED: 2 local images embedded → 0) · 5df55b2 (installer, RED 2 tests; E2E install on scratch copy OK)
- [x] T6 S12 — inline · a1b54fc
- [x] T7 full suite + independent verifier (no blockers) · 1dfec66 (fopen x per advisory) · zips rebuilt
- [x] T8 new findings: raw session readers 3fc66d2; ws_key in image URLs e65d63f

Route evidence: T2–T4 are independent units with disjoint edit surfaces, each several files and 100+ lines → Writer trigger (a) parallelism.

## Log

- L1 (2026-11-04) "crea un prd para solucionar los problemas" → "haz lo que consideres mas optimo"
- L2 Baseline integration suite on MariaDB 11: 243 tests, 1 failure already present (TicketNumbererMySqlIntegrationTest::testConcurrentWorkersGetUniqueGaplessNumbers).
- L3 Independent verifier: all R# PASS (R12/R13 partial by design: SHA-256 stretch instead of warning; link → fopen x fixed in 1dfec66). Unit 939 tests OK; integration 266, only the baseline failure; node 27/27; composer audit clean.
- L4 Next: user decides push; regenerate the PrestaShop webservice key; D3 pending; Strix diff re-scan.
