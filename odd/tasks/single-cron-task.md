# Single scheduled task (`tpv:cron`)

Objective: the hosting panel needs ONE scheduled task, every minute, instead of five. Branch `feat/single-cron-task`. Delivery: `ask-on-risk`.
Test runner: `.runtime/php-8.5.12/bin/php vendor/bin/phpunit tests/Unit` and `node --test tests/Functional/*.test.cjs` (plus the plain `node tests/Functional/<file>.test.cjs` runners).

## Specs

- S1. User: «Ejecutemos una cada minuto,que lance lo que tenga que lanzar en cada momento». One command, `tpv:cron`, scheduled every minute, decides what is due: uploads (`tpv:sync:outbox`) and stock (`tpv:catalog:sync --stock-only`) every run; full catalog (`tpv:catalog:sync --full`), customers (`tpv:customers:sync`) and backup (`tpv:backup:database`) once a night (03:00, 03:30, 04:00, shop time). It runs the existing commands, it does not re-implement them.
- S2. Proposal accepted by the user: «Bloqueos para que un trabajo largo no se solape consigo mismo». A job still running from a previous minute is skipped in this run; the other jobs still run.
- S3. Proposal accepted: «Un aviso en Ajustes si la tarea lleva tiempo sin ejecutarse». Ajustes → Acerca de shows whether the task is running and each job's last result.
- S4. Proposal accepted: «La pantalla final del instalador y la documentación, actualizadas a una sola tarea».
- S5. Proposal accepted: «Las órdenes actuales siguen funcionando, para que tu servidor no se rompa mientras cambias las tareas». The five commands keep their names, options, output and exit codes.
- S6 (assumption, from the 2026-11-14 incident, not a user sentence): a nightly job that fails is tried again later the same night instead of waiting a day, a bounded number of times; a nightly job that never ran (new install, or the task was down at its hour) runs at the next minute.

## Tasks

- [ ] T1 | S1 S2 S5 S6 | delegated (user: «delega tus tareas a agentes con opus») | scheduler, state, lock and the `tpv:cron` command, with unit tests | commit: see Log
- [ ] T2 | S3 | delegated, same writer as T1 | status in `GET /api/settings/provisioning` and in Ajustes → Acerca de | commit: see Log
- [ ] T3 | S4 | delegated, parallel writer (disjoint files) | installer final page, docs, `bin/cron-setup.sh` | commit: see Log

Route evidence: the user asked the orchestrator to delegate to Opus agents (L4). Two writers launched together with disjoint edit surfaces: A = T1+T2 (one behaviour with its status), B = T3 (installer page and docs). Risk items 4 (job locks) and 5 (scheduled tasks of the deployment): independent verifier at the end.

## Log

- L1 (2026-11-14, user): «habiamos echo que solo fuera necesaria una» / «pues hagamoslo, es muy incomodo crear tantas tareas» / «Ejecutemos una cada minuto,que lance lo que tenga que lanzar en cada momento».
- L2 (2026-11-14): verified before starting that the single task agreed on 2026-11-09 was never built (no command in the repository, in any branch or on the server).
- L3 (2026-11-14): job locks are files (`flock`), not the MySQL named lock of provisioning: the full catalog sync closes its database connection before writing (fix 45b9b41), which would free a connection-bound lock half way.
- L4 (2026-11-14, user): «eres el orquestasdor no llenes tu contexto, delega tus tareas a agentes con opus».
- L5 (2026-11-14): the parent wrote tests/Unit/Service/Cron/CronRunnerTest.php as the executable spec of T1 before delegating.
