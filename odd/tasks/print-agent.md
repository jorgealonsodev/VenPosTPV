# Print agent — tickets, drawer and labels when the TPV runs on Plesk (Phase 1)
Branch: feat/print-agent (from feat/local-first-sales). Delivery: ask-on-risk, one commit per task. Runners: PHPUnit (.runtime/php-8.5.12/bin/php vendor/bin/phpunit), Node harnesses (tests/Functional/*.test.cjs), Rust (`cargo test` in agent/, targets x86_64-unknown-linux-gnu and x86_64-pc-windows-gnu).
Binding spec: docs/prd/agente-impresion.md v2 (rules E*, T*, C*, L*, S*, A*, I*, U*, API tables, AC*). Only Phase 1 (PRD §4) is in scope.

## Specs
S1. User approval (2026-11-03): "si, adelante con la fase 1". Scope = PRD §4 "Fase 1": emparejar, desconectar y vigilar la clave; tickets, cajón, reimpresión, abonos, prueba; avisos en caja y PDF de reserva; instalador de Windows con servicio e icono, elección de impresoras; binario de Linux con comandos; etiquetas en ESC/POS en la TM-T20, desde la ficha y desde la lista, con vista previa; estado del agente y de la cola, avisos por trabajo fallido; historial de 30 días con filtros; aviso de versión nueva y descarga manual; pruebas de Windows en GitHub Actions.
S2. Server core: PRD E1-E9 (server side), E6, E7, T0-T11, S1, S4 (retention), U1, API tables (agent v1 + POS), migration V37.
S3. POS UX: PRD C1-C5, S3, S5 (display), E1 steps 1-4 (Ajustes → Cajas), history filters (S4), U1 notice.
S4. Labels: PRD L1-L9, AC5, AC6, AC14, AC15.
S5. Agent (Rust): PRD A1-A10, E10, E11, T3-T8 (agent side), S1, S2 (phase 1 part), S5, AC10, AC11.
S6. Windows delivery: PRD I1, I2, AC9, GitHub Actions windows-latest (silent install, sc query, exact RAW bytes to a Generic / Text Only printer to file); Linux binary + commands (I3 phase 1).
S7. Acceptance: AC1-AC4, AC7 (phase 1 part), AC10-AC13, AC16 live on this machine with the TM-T20 where possible; independent verifier (security item 2).

## Tasks
T1 | S2 | delegated (parallel with T4) | pending | Server core: V37, queue, agent API v1, pairing + rate limiter, routing (T0/T9), drawer audit, POS endpoints.
T2 | S3 | pending (after T1) | POS UX: payment modal states, drawer warning, offline banner, Ajustes Cajas pairing/status/history/version.
T3 | S4 | pending (after T1) | Labels: ESC/POS generation, preview, product sheet + Etiquetas screen.
T4 | S5 | delegated (parallel with T1) | Rust agent: service, client, printing (Windows RAW / CUPS / direct), dedupe, IPC, Windows tray, CLI.
T5 | S6 | delegated | in progress | NSIS installer, GitHub Actions Windows workflow, Linux binary packaging.
T6 | S7 | pending | Live acceptance + independent verifier.

## Log
L1. 2026-11-03 user: "si esta pagina la despliego online me imprimira tickets y abrira el cajon?" → explained the server prints via CUPS/IP and cannot reach the shop from Plesk. User: "lo quiero desplegar en un subdominio en plesk, al lado de la tienda pricipal de prestahop". Chose "Agente en la tienda (Recomendado)"; OS: "Crea el de windows y el de linux". Noted: with the TPV online, a shop internet outage stops checkout (local-first protects only against PrestaShop failures); browser offline mode would be a separate feature.
L2. 2026-11-03 user: "para un momento" (both writers stopped, nothing committed; partial Go agent/ removed), then "para crear ese servicio vamos a crear un prd, en condiciones" and "tu hazme todas las preguntas que necesites pero indicando cual es la optima". Answers recorded in docs/prd/agente-impresion.md §3 (tickets+drawer+labels; one agent per till; HTTPS long-poll; PDF fallback; labels price+barcode, max printer compatibility starting with the TM-T20; 6-digit pairing; Windows installer wizard; .deb+script; unsigned; signed auto-update with till closed; tray icon on Windows and Linux; per-type expiry; 30-day history; printer status when readable; no drawer from tray; reprint on requesting till; offline-internet out of scope). User: "podriamos crear el servicio con rust?" -> "si, hazlo en rust". The PRD supersedes S1-S6 above; tasks to be re-planned from it after the user reviews the PRD.
L3. 2026-11-03 user: "corre 5 agentes que lo analizen y lo mejoren" -> five read-only reviews (security, Rust agent, server/Plesk, labels, shop UX). User decisions: token never expires but last use shown; update rollback only if the new one does not start; TM-T20 full width + adjustable height; one strip with a single cut; driver printers phase 2; current price only; expiry drawer 15 s / ticket 2 min / labels 24 h with prompt; after the PDF the pending ticket still prints if the agent returns in time ("Se imprime si vuelve a tiempo"); two phases; Windows tested on GitHub Actions. Adopted without asking: long-poll wait 20 s with 1 s server checks. PRD rewritten to v2.
L4. 2026-11-03 user: "si, adelante con la fase 1". T1 (server) and T4 (agent) launched in parallel; user asked "por que ahora el tpv no necesita el servicio para imprimir?" (answered: server and printer on the same PC today). T4 done; contract notes sent to T1 (RFC 3339 expires_at + Date header, error code field, job_expired as failed result, max one label job per GET, printer state values, pair 201/result 200/status 204). makensis not installed locally: installer built and tested in GitHub Actions windows-latest (T5).
