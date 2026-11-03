# Agente de impresión

El agente es un programa pequeño que se instala en el ordenador de cada caja. Se conecta al TPV por HTTPS, recoge los trabajos de su caja (tickets, apertura del cajón, etiquetas) y los envía a las impresoras de la tienda. Así el TPV puede estar en Plesk y seguir imprimiendo en la tienda. La especificación completa está en `docs/prd/agente-impresion.md` (v2).

## Puesta en marcha en el servidor

1. Aplicar la migración `Version00000037PrintAgent` (`php bin/console doctrine:migrations:migrate`). Crea `print_job`, `print_agent`, `agent_pairing`, `drawer_audit` y `rate_limit`, y añade el tipo de impresora `agent`. Hasta aplicarla, las cajas con CUPS, red o PDF funcionan igual que antes; las rutas del agente responden 503 `print_agent_unavailable`.
2. En Ajustes → Cajas, poner la impresora de la caja en «Agente de impresión» (`printer_type: agent`).
3. Opcional, en el entorno del servidor:
   - `TPV_AGENT_MIN_VERSION`: versión mínima admitida (por debajo, 426 `agent_update_required`). Vacío: ninguna.
   - `TPV_AGENT_LATEST_VERSION` y `TPV_AGENT_DOWNLOAD_URL`: última versión y su enlace de descarga (admite `{os}`, `{arch}` y `{version}`). Ajustes muestra «Hay una versión nueva».
4. Apache y PHP deben pasar la cabecera `Authorization` a PHP (con CGI: `CGIPassAuth On`, o `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`).
5. Plesk: `max_execution_time` ≥ 35 s (la escucha larga dura hasta 20 s) y `pm.max_children` ≥ número de cajas + 6.

La limpieza del historial (contenido a las 24 h, trabajos a los 30 días) la hace la tarea de cada minuto `tpv:sync:outbox`. El registro de aperturas del cajón (`drawer_audit`) no se borra nunca.

## API del agente (para desarrolladores)

Todas las rutas cuelgan de `/api/agent/v1/`. Solo aceptan `Authorization: Bearer <clave>`: nunca la clave en la dirección ni en una cookie, sin sesión y sin cookies. Todas las respuestas llevan `Cache-Control: no-store`. Los errores son JSON `{"error": "texto", "code": "...", "retry": false}` (`retry` es `true` en 429 y 503). Las fechas van en RFC 3339 (`2026-11-03T10:02:00+01:00`), y la cabecera `Date` lleva la hora del servidor.

| Método | Ruta | Petición | Respuesta | Códigos |
|---|---|---|---|---|
| POST | `/api/agent/v1/pair` | `{code, hostname, os, version}` | `{key, terminal_id, terminal_name}` | 201; 422 `pairing_code_invalid`; 429 `rate_limited` |
| GET | `/api/agent/v1/jobs?wait=0..20` | — | `{jobs:[{id, type, target, payload_b64, sha256, claim_token, expires_at}], poll_after_ms}` | 200; 401 `agent_key_invalid`; 426 `agent_update_required`; 429 |
| POST | `/api/agent/v1/jobs/{id}/result` | `{claim_token, status: done\|failed, error_code?, error?}` | `{status}` | 200 (también repetido, y `failed` + `error_code: job_expired` → `{"status": "expired"}`); 404 `job_not_found`; 409 `claim_stale`; 410 `job_expired` |
| POST | `/api/agent/v1/jobs/{id}/extend` | `{claim_token}` | `{lease_until}` | 200; 404; 409 `claim_stale`; 410 |
| PUT | `/api/agent/v1/status` | `{version, hostname, printers:[{target, name, state, detail}]}` | — | 204; 401 |
| GET | `/api/agent/v1/update?os=&arch=&version=` | — | `{manifest:{os, arch, version, url}, signature: null}` | 200; 204 sin novedad |

Cualquier ruta puede responder 401 `agent_key_invalid` («Vuelve a conectar el agente desde Ajustes → Cajas») y, tras 10 claves inválidas por minuto desde la misma IP, 429 con `Retry-After: 60`.

### Emparejar

- Un administrador genera en Ajustes → Cajas un código de 6 cifras (10 minutos, un solo uso, uno vivo por caja: generar otro anula el anterior).
- El agente lo envía a `pair` y recibe su clave: 256 bits en base64url sin relleno (43 caracteres). El TPV solo guarda su sha256; del código, solo un HMAC.
- Emparejar admite 5 intentos por minuto y por IP. Cada fallo cuenta contra los códigos vivos; con 5 fallos un código queda anulado.
- Emparejar de nuevo la caja sustituye la clave anterior, que deja de valer al momento. «Desconectar» en Ajustes también.

### Trabajos

- `type`: `ticket`, `drawer` o `label`. `target`: `ticket` o `label` (la impresora de etiquetas puede ser la misma).
- `payload_b64`: los bytes exactos para la impresora (el mismo ESC/POS que hoy), en base64. Un trabajo `drawer` no lleva datos: `payload_b64` es `""` y `sha256` el de la cadena vacía; el pulso `ESC p` lo genera el agente.
- `sha256`: huella de los bytes decodificados.
- Caducidad (`expires_at`): cajón 15 s, ticket 2 min, etiquetas 24 h. Lo caducado no se entrega ni se imprime.
- `jobs` espera hasta `wait` segundos (máximo 20), mirando cada segundo, y devuelve hasta 5 tickets o cajones y como mucho una etiqueta, en orden de creación, para que un ticket nuevo nunca espere detrás de un lote de etiquetas. Con trabajos o tras una espera larga, `poll_after_ms` es 0; con `wait=0` y nada pendiente, 2000.
- Cada recogida lleva un `claim_token` y una reserva de 60 s. Sin confirmar en ese tiempo, el trabajo vuelve a la cola con otro `claim_token`, y el anterior recibe 409 `claim_stale`. `extend` alarga la reserva otros 60 s para lotes largos.
- `result` es idempotente: repetir la misma confirmación devuelve el mismo `status`. Un `failed` se reintenta a los 2, 5 y 15 s si no pasa de su caducidad; mientras tanto la respuesta es `{"status": "pending"}`, y al agotar los reintentos, `{"status": "failed"}`. `error` se recorta a 500 caracteres; `error_code` es `[a-z0-9_]{1,64}` (otro valor se guarda como `unknown`).
- Si el agente no imprime un trabajo porque ya caducó, lo confirma como `failed` con `error_code: job_expired`: se acepta (200, `{"status": "expired"}`) y no se reintenta.
- `printers[].state` en `status`: `ready`, `paused`, `offline`, `error`, `not_found`, `not_configured` o `unknown` (otro valor se guarda como `unknown`).
- Una clave solo ve y confirma trabajos de su caja: el trabajo de otra caja es 404.
- Cada petición autenticada actualiza la última conexión e IP del agente. Ajustes lo da por conectado si la última fue hace menos de 90 s.

### Rutas del TPV (sesión)

| Método | Ruta | Respuesta | Códigos |
|---|---|---|---|
| GET | `/api/print-jobs/{id}` | `{id, terminal_id, terminal_name, type, target, source, status, attempts, error_code, error, created_at, claimed_at, finished_at, expires_at}` | 200; 401; 404 |
| GET | `/api/print-jobs?terminal_id&status&cursor&limit≤50` | `{items, next_cursor}` (30 días, más recientes primero) | 200; 401; 422 estado no válido |
| GET | `/api/terminals/{id}/print-agent` | `{paired, connected, last_seen, last_ip, version, hostname, os, paired_at, printers, update_available, latest_version, download_url}` | 200; 401; 404 |
| POST | `/api/terminals/{id}/print-agent/pairing-code` (administrador, JSON) | `{code, expires_at}` | 201; 403; 404; 415 |
| DELETE | `/api/terminals/{id}/print-agent` (administrador) | — | 204; 403; 404 |
| POST | `/api/terminals/{id}/print-agent/test-print` (administrador, JSON) | `{print_status: "queued", print_job_id}` | 201; 403; 409 `printer_not_agent`; 415 |

Quien no es administrador solo ve los trabajos de la caja de su sesión.

En una caja con agente, las rutas que imprimen crean el trabajo en la misma petición y lo indican junto a sus campos de siempre: el cobro (`print_status: "queued"`, `print_job_id`, y con efectivo `drawer_status`, `drawer_job_id`), la reimpresión (`data.print_status`, `data.print_job_id`), los abonos y cambios (`print_status: "queued"`, `print_job_id`, `drawer_job_id`). Sin caja resuelta en la sesión no se crea ningún trabajo: la reimpresión responde 400 `terminal_not_resolved`, y en cobros y abonos (que ya se han hecho) el aviso va en `print_error: "terminal_not_resolved"`. Nunca se usa la caja 1 por defecto.
