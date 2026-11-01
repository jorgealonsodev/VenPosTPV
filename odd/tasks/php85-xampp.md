# PHP 8.5 for XAMPP
Objective: use the latest patched PHP for VenPosTPV, launched by XAMPP Apache. Branch: `feat/php85-xampp`.
Delivery: ask-on-risk; forecast ~350 authored lines plus generated lockfile. Checks: runtime CLI/CGI, Composer, focused PHP/Node tests, Playwright; full suite failures reported separately.

## Specs
S1. "pon la version mas reciente con los ultimos patchs"
S2. "haz lo que consideres, mas optimo pero que pueda lanzarlo desde xampp control panel"

## Tasks
T1 | S1-S2 | delegated + inline correction | done | Built PHP8.5.12 CLI/CGI; runtime extension/CGI-hardening smoke and idempotent install passed; commit: pending (verification/review boundary).
T2 | S1 | delegated | in_progress | Composer platform check on PHP8.5 reproduces locked CSS parser constraint; resolve minimal compatible dependency chain and check PDF/QR; commit: pending.
T3 | S2 | delegated + inline checks | pending | Connect Apache-managed CGI to this TPV only, document start/rollback, and verify runtime, browser, security boundaries and unchanged PrestaShop handler; commit: pending.

## Log
L10. Build job-1 compiled successfully but exited1 writing php.ini before etc existed. Corrected mkdir order and materialized configuration without repeating compile. CGI smoke initially expected CLI text but php-cgi -i renders HTML; corrected assertion. tests/Functional/php85-runtime-contract.sh passes including actual extensions and force_redirect. bin/build-php85.sh idempotently verifies existing runtime. Composer check-platform-reqs --lock correctly fails on sabberworm PHP8.5 restriction.
L11. User: "con playwright vet probando cosas y encuentra fallos" then "aunque primero resuelve los problemas de compatibilidad". General UI audit postponed until compatibility is resolved. One initial read-only Playwright visit saw existing sale screen; no checkout/cash writes performed. UI audit task kept in visible projection as postponed.
L1. User: "una cosa actualiza el php que usamos a la ultima version patchada"
L2. User: "pon la version mas reciente con los ultimos patchs"
L3. User: "haz lo que consideres, mas optimo pero que pueda lanzarlo desde xampp control panel"
L4. Official https://www.php.net/releases/index.php?json&version=8 reports 8.5.12, security release 21 Oct 2026. XAMPP uses PHP8.1.25; system PHP8.3.6 lacks GD. Composer locked sabberworm/php-css-parser8.9.0 excludes PHP8.5. No dependency changes yet.
L5. Architecture choice: project-local CLI/CGI invoked by Apache actions/cgi (already loaded); /opt/lampp/etc/httpd.conf allows ExecCGI and AllowOverride All under htdocs. Avoid replacing shared mod_php used by PrestaShop8.1.7 or adding a standalone service. CGI runtime remains outside public/. Need test path-info, direct wrapper access protection, base-path routing and credentials safety. No out-of-repository writes are authorized. XAMPP config reads were explicitly authorized; config not writable and sudo not available.
L6. Existing uncommitted checkout changes and .atl/skill-registry.md/.playwright-mcp/ must be preserved and excluded from PHP work-unit commits. Cash bug fix is NOT complete: independent verifier found post-order no-session409 risk and close/hydrate race. Do not claim it complete or mutate real orders/cash. User authorized daemon cache ACL only in var/cache/dev; applied before runtime migration, health200.
L8. T1 worker delivered bin/build-php85.sh, config/runtime/php85*.ini and tests/Functional/php85-runtime-contract.sh. Static/preflight checks passed; runtime smoke skipped because not built. Actual build launched as job-1; not complete yet. PHP mbregex and zip intentionally omitted (host dependency unavailable); required mbstring/GD included.
L9. Official PrestaShop8 docs compatibility row8.0~8.2 supports PHP7.2.5 through8.1 and rejects>=8.2. PrestaShop9.1 docs add PHP8.5 support. No authorization to migrate PrestaShop; preserve its runtime. User asked: "prestashop no puedes usar 8.5.12?"
L7. Risk: item5(environment/dependencies), item2(CGI execution boundary). Runtime compatibility may reveal additional blockers; do not silently broaden business code changes. No meaningful RED for version installation; establish pre-upgrade version8.1.25 and Composer conflict as baseline; test CGI boundaries before enabling handler. No push or PR authorized.
