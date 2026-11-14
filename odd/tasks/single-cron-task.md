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

- [x] T1 | S1 S2 S5 S6 | delegated (user: «delega tus tareas a agentes con opus») | scheduler, state, lock and the `tpv:cron` command, with unit tests | commits 3f90a2d, f69fab2, fe8007b
- [x] T2 | S3 | delegated, same writer as T1 | status in `GET /api/settings/provisioning` and in Ajustes → Acerca de | commit 3f90a2d
- [x] T3 | S4 | delegated, parallel writer (disjoint files) | installer final page, docs, `bin/cron-setup.sh` | commits de6d9bd, 6cd04c9, 414d9ea, 4f89b02

Route evidence: the user asked the orchestrator to delegate to Opus agents (L4). Two writers launched together with disjoint edit surfaces: A = T1+T2 (one behaviour with its status), B = T3 (installer page and docs). Risk items 4 (job locks) and 5 (scheduled tasks of the deployment): independent verifier at the end.

## Log

- L1 (2026-11-14, user): «habiamos echo que solo fuera necesaria una» / «pues hagamoslo, es muy incomodo crear tantas tareas» / «Ejecutemos una cada minuto,que lance lo que tenga que lanzar en cada momento».
- L2 (2026-11-14): verified before starting that the single task agreed on 2026-11-09 was never built (no command in the repository, in any branch or on the server).
- L3 (2026-11-14): job locks are files (`flock`), not the MySQL named lock of provisioning: the full catalog sync closes its database connection before writing (fix 45b9b41), which would free a connection-bound lock half way.
- L4 (2026-11-14, user): «eres el orquestasdor no llenes tu contexto, delega tus tareas a agentes con opus».
- L5 (2026-11-14): the parent wrote tests/Unit/Service/Cron/CronRunnerTest.php as the executable spec of T1 before delegating.
- L6 (2026-11-14): writers returned; seam check by the parent: unit 1156 and frontend 236 green. Commits 3f90a2d (T1+T2) and de6d9bd (T3).
- L7 (2026-11-14): independent verification (items 4 and 5). Locks held under two real processes and `kill -9`; S5 intact. Blockers: B1 a failed state write stopped the run and lost the retry count; B2 the docs' "nothing breaks" note was false for `--keep=N` users. Correction f69fab2: connection refreshed before each launch and state write, writes swallowed, state saved before the lock is released, future timestamps ignored, one line per failed job on STDERR, shared `cron-nightly` lock, corrected notes.
- L8 (2026-11-14): recheck. B2 fixed. B1 fixed for a dropped connection, still failing when the database refuses writes all night (181 launches in a 3-hour simulation) and when a state read throws. Second correction fe8007b: the nightly attempt count also lives in `var/cron-<id>.state` and a nightly job runs only when database and marker agree; a failing read is "no information". Simulation now 3 / 1 / 1. Unit 1182, frontend 236 green.
- L9 (2026-11-14, user): «en la instalacion la pantalla […] tiene que mostrar la explicacion de como añadir la tarea con un cuadro como este […] ayuda mucho» → the final page shows the «Campo | Qué poner» table (6cd04c9). «en el formulario no pongas clubnatacion.com, o pones la url de donde se instale o algo anonimo» and «deja tutienda.com, así está bien» → no customer or hosting-provider name left in the repository (414d9ea, 4f89b02).
- L10 (2026-11-14, user): «mejor cada minuto por que hay cosas que lo necesitan» (the task stays every minute).
- L11 (2026-11-14): end-to-end run on real MySQL delegated (fresh install for the final page; happy path, concurrency and kill on the local sandbox). Pending its result. Not merged, not deployed.
- Known, out of scope: `status()` reads the database without catching errors; the backup kept by `tpv:cron` is fixed at 7 copies; tills whose printer is not the agent queue `PrintTicketMessage` and no task consumes that queue (unverified).
- Next feature agreed with the user (after this one): a fiscal journal outside the database so a restore never loses or repeats tickets (VERI*FACTU).
