# Agente de impresión

El agente es un programa pequeño que se instala en el ordenador de cada caja. Se conecta al TPV por HTTPS, recoge los trabajos de su caja (tickets, apertura del cajón, etiquetas) y los envía a las impresoras de la tienda. Así el TPV puede estar en Plesk y seguir imprimiendo en la tienda. La especificación completa está en `docs/prd/agente-impresion.md` (v2).

## Qué es

El agente tiene dos partes:

- **El servicio «VenPos Agent»**: trabaja solo, sin ventana, desde que se enciende el ordenador. Es el que imprime.
- **El icono de la bandeja** (junto al reloj): enseña cómo va y sirve para emparejar la caja más tarde. Verde: conectado. Ámbar: sin conexión con el TPV. Rojo: la impresora tiene un problema. Si cierras el icono, el servicio sigue imprimiendo.

El agente nunca abre el cajón por su cuenta: solo cuando el TPV lo pide al cobrar.

## Requisitos

- Windows 10 u 11 de 64 bits (o Linux, ver más abajo).
- La impresora instalada en Windows **para todo el equipo**, con un driver **v3** o con el driver **«Generic / Text Only»**. Los driver v4 a veces no dejan imprimir tickets. Si la impresora no imprime con su driver, instálala con «Generic / Text Only».
- La dirección del TPV (empieza por `https://`) y acceso de administrador en Ajustes → Cajas para sacar el código.

## Instalar en Windows

1. Descarga `VenPosAgent-<versión>-setup.exe` y ábrelo con doble clic.
2. Windows avisará con **«Windows protegió su PC»** porque el instalador todavía no va firmado. Pulsa **Más información** y luego **Ejecutar de todas formas**. Si Windows pregunta si quieres permitir cambios en el equipo, pulsa **Sí**.
3. Si el antivirus lo bloquea o lo pone en cuarentena, márcalo como permitido (es un programa nuevo y el antivirus no lo conoce todavía) y vuelve a abrirlo.
4. **Conectar con el TPV**: escribe la dirección del TPV y el código de 6 cifras (ver «Emparejar» abajo) y pulsa Siguiente. Si sale un error, léelo: casi siempre es el código caducado (dura 10 minutos) o la dirección mal escrita. Si todavía no tienes el código, marca **«Emparejar más tarde»**.
5. **Elegir impresoras**: elige la impresora de tickets y, si la tienes, la de etiquetas. Deja marcada **«Imprimir una página de prueba»**.
6. Pulsa Instalar. Al terminar sale una hoja de prueba por la impresora de tickets y el icono aparece junto al reloj (puede estar escondido en la flechita «^»).

El agente se instala en `C:\Program Files\VenPos Agent`. Su configuración y su registro están en `C:\ProgramData\VenPos Agent`.

## Emparejar la caja

1. En el TPV, entra en **Ajustes → Cajas** y genera el código de emparejado de la caja de ese ordenador.
2. Escríbelo en el instalador antes de 10 minutos. Cada código sirve una sola vez.
3. En Ajustes → Cajas la caja pasa a «Conectado».

Si elegiste «Emparejar más tarde», haz clic con el botón derecho en el icono → **Conectar con el TPV…**, escribe la dirección del TPV y el código de 6 cifras y pulsa **Conectar**. Si va bien, el icono dice «Conectado a la caja <nombre>». Si sale «Código caducado o incorrecto», genera otro; si sale «No se puede conectar con el TPV», revisa la dirección y la conexión a Internet. No hace falta ser administrador: el código es el permiso.

También puedes volver a abrir el instalador (no borra nada) y escribir el código, o, en una ventana de «Símbolo del sistema» abierta como administrador:

```
"C:\Program Files\VenPos Agent\venpos-agent.exe" pair 123456 --server https://tu-tpv
```

Para pasar el agente a otra caja o si en el icono pone «Vuelve a conectar el agente», genera un código nuevo y empareja otra vez desde el icono → **Conectar con el TPV…**.

Desde el icono solo se empareja por `https://` y con el mismo TPV al que ya está conectado el agente. Para cambiar de TPV sale «Para cambiar de TPV, vuelve a ejecutar el instalador como administrador».

## Elegir o cambiar las impresoras

Haz clic con el botón derecho en el icono → **Elegir impresoras** → **Tickets** o **Etiquetas**, y marca la impresora. Para comprobarla: botón derecho → **Imprimir prueba**.

## Comprobar el estado

- Pasa el ratón por encima del icono o ábrelo con el botón derecho: dice si está conectado y cómo están las impresoras.
- En el TPV, Ajustes → Cajas muestra si el agente está conectado y si la impresora tiene algún problema.
- Desde «Símbolo del sistema»: `"C:\Program Files\VenPos Agent\venpos-agent.exe" status`.

## No imprime: qué hago

Ve paso a paso y para en cuanto vuelva a imprimir:

1. **¿La impresora está encendida, con papel y la tapa cerrada?** Apágala y enciéndela.
2. **¿El icono está rojo?** El problema es la impresora: mira en Windows, Configuración → Impresoras, que no esté «en pausa» ni «sin conexión», y borra los documentos atascados en la cola.
3. **¿El icono está ámbar?** No hay conexión con el TPV: comprueba Internet abriendo el TPV en el navegador. El agente vuelve a conectar solo en cuanto hay red.
4. **¿No ves el icono?** Puede estar escondido en la flechita «^». Si no está, reinicia el ordenador; el servicio y el icono arrancan solos.
5. **Botón derecho en el icono → Imprimir prueba.** Si la prueba sale pero los tickets no, revisa en Ajustes → Cajas que la caja usa «Agente de impresión».
6. **Si en el icono pone «Vuelve a conectar el agente»**: genera un código nuevo en Ajustes → Cajas y empareja otra vez desde el icono → **Conectar con el TPV…**.
7. **Si cambiaste el nombre de la impresora en Windows**, elígela de nuevo desde el icono (Ajustes mostrará «No encontrada»).
8. **Mientras tanto, cobra igual**: el TPV ofrece el ticket en PDF si no se imprime.
9. Si nada funciona: botón derecho en el icono → **Ver registro**, y envía ese fichero a quien te da soporte.

## Instalar en Linux

Con el paquete `venpos-agent_0.1.0_amd64.deb` (vale para cualquier Debian o Ubuntu de 64 bits):

```
sudo apt install ./venpos-agent_0.1.0_amd64.deb
sudo venpos-agent pair <código> --server https://tu-tpv
sudo venpos-agent list-printers
sudo venpos-agent set-printer --target ticket --cups Epson_TM-T20II   # o --device /dev/usb/lp0
sudo venpos-agent test-print
```

El paquete crea el usuario `venpos-agent` (grupo `lp`) y deja el servicio en marcha con systemd. Al actualizarlo con una versión nueva se conserva el emparejado.

Sin el paquete, también se puede instalar con el binario `venpos-agent` y el script `agent/packaging/linux/install.sh` en la misma carpeta:

```
sudo ./install.sh ./venpos-agent --server https://tu-tpv --code 123456
sudo venpos-agent list-printers
sudo venpos-agent set-printer --target ticket --cups Epson_TM-T20II   # o --device /dev/usb/lp0
sudo venpos-agent test-print
sudo venpos-agent status
```

El script copia el programa a `/usr/local/bin`, crea el usuario `venpos-agent` (grupo `lp`) y deja el servicio `venpos-agent` en marcha con systemd. Sin `--code` se instala sin emparejar; empareja después con `sudo venpos-agent pair <código> --server https://tu-tpv`. El registro se ve con `journalctl -u venpos-agent` y en `/var/log/venpos-agent`.

## Desinstalar

- **Windows**: Configuración → Aplicaciones → **VenPos Agent** → Desinstalar. Al final pregunta si quieres borrar también la configuración, la clave de emparejado y el registro; contesta **No** si vas a reinstalarlo en la misma caja. Después, en Ajustes → Cajas, desconecta el agente de esa caja.
- **Linux**: con el paquete, `sudo apt remove venpos-agent` (conserva el emparejado) o `sudo apt purge venpos-agent` (borra también la configuración, el registro y el usuario). Instalado con el script: `sudo venpos-agent uninstall` y `sudo rm /usr/local/bin/venpos-agent`; la configuración queda en `/etc/venpos-agent`.

## Puesta en marcha en el servidor

1. Aplicar la migración `Version00000037PrintAgent` (`php bin/console doctrine:migrations:migrate`). Crea `print_job`, `print_agent`, `agent_pairing`, `drawer_audit` y `rate_limit`, y añade el tipo de impresora `agent`. Hasta aplicarla, las cajas con CUPS, red o PDF funcionan igual que antes; las rutas del agente responden 503 `print_agent_unavailable`.
2. En Ajustes → Cajas, poner la impresora de la caja en «Agente de impresión» (`printer_type: agent`).
3. Opcional, en el entorno del servidor:
   - `TPV_AGENT_MIN_VERSION`: versión mínima admitida (por debajo, 426 `agent_update_required`). Vacío: ninguna.
   - `TPV_AGENT_LATEST_VERSION` y `TPV_AGENT_DOWNLOAD_URL`: última versión y su enlace de descarga (admite `{os}`, `{arch}` y `{version}`). Ajustes muestra «Hay una versión nueva».
4. Apache y PHP deben pasar la cabecera `Authorization` a PHP (con CGI: `CGIPassAuth On`, o `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`).
5. Plesk: `max_execution_time` ≥ 35 s (la escucha larga dura hasta 20 s) y `pm.max_children` ≥ número de cajas + 6.
6. Plesk pone nginx delante de Apache: sin más, todas las peticiones llegan desde el proxy y los límites de intentos (por IP) y la «última IP» del agente verían la IP del proxy. Pon `TRUSTED_PROXIES=127.0.0.1,::1` en el entorno del servidor (ver `env.example`): Symfony toma entonces la IP real de `X-Forwarded-For` y el esquema de `X-Forwarded-Proto` solo cuando la petición viene de esos proxies. Vacío o sin definir (por defecto) no se confía en ningún proxy. Tras cambiar la configuración, vacía la caché (`php bin/console cache:clear`).
7. La escucha larga envía un espacio por segundo mientras espera (cabecera `X-Accel-Buffering: no` para que nginx no lo retenga) para notar enseguida que el agente se ha parado y no recoger trabajos hacia una conexión muerta. Si el proxy retiene la respuesta igualmente, la reserva de 30 s devuelve el trabajo a la cola y la caja avisa a los 15 s.

La limpieza del historial (contenido a las 24 h, trabajos a los 30 días) la hace la tarea de cada minuto `tpv:sync:outbox`. El registro de aperturas del cajón (`drawer_audit`) no se borra nunca.

## API del agente (para desarrolladores)

Todas las rutas cuelgan de `/api/agent/v1/`. Solo aceptan `Authorization: Bearer <clave>`: nunca la clave en la dirección ni en una cookie, sin sesión y sin cookies. Todas las respuestas llevan `Cache-Control: no-store`. Los errores son JSON `{"error": "texto", "code": "...", "retry": false}` (`retry` es `true` en 429 y 503). Las fechas van en RFC 3339 (`2026-11-03T10:02:00+01:00`), y la cabecera `Date` lleva la hora del servidor.

| Método | Ruta | Petición | Respuesta | Códigos |
|---|---|---|---|---|
| POST | `/api/agent/v1/pair` | `{code, hostname, os, version}` | `{key, terminal_id, terminal_name}` | 201; 422 `pairing_code_invalid`; 429 `rate_limited` |
| GET | `/api/agent/v1/jobs?wait=0..20` | — | `{jobs:[{id, type, target, payload_b64, sha256, claim_token, expires_at}], poll_after_ms, server_time_ms}` | 200; 401 `agent_key_invalid`; 426 `agent_update_required`; 429 |
| POST | `/api/agent/v1/jobs/{id}/result` | `{claim_token, status: done\|failed, error_code?, error?}` | `{status}` | 200 (también repetido, y `failed` + `error_code: job_expired` → `{"status": "expired"}`); 404 `job_not_found`; 409 `claim_stale`; 410 `job_expired` |
| POST | `/api/agent/v1/jobs/{id}/extend` | `{claim_token}` | `{lease_until}` | 200; 404; 409 `claim_stale`; 410 |
| PUT | `/api/agent/v1/status` | `{version, hostname, printers:[{target, name, state, detail}]}` | — | 204; 401 |
| GET | `/api/agent/v1/update?os=&arch=&version=` | — | `{manifest:{os, arch, version, url}, signature: null}` | 200; 204 sin novedad |

Cualquier ruta puede responder 401 `agent_key_invalid` («Vuelve a conectar el agente desde Ajustes → Cajas») y, tras 10 claves inválidas por minuto desde la misma IP, 429 con `Retry-After: 60`.

### Emparejar

- Un administrador genera en Ajustes → Cajas un código de 6 cifras (10 minutos, un solo uso, uno vivo por caja: generar otro anula el anterior).
- El agente lo envía a `pair` y recibe su clave: 256 bits en base64url sin relleno (43 caracteres). El TPV solo guarda su sha256; del código, solo un HMAC.
- Emparejar admite 5 intentos por minuto y por IP (después, 429). Los fallos de todas las IP se cuentan juntos: con 20 fallos en 10 minutos se anulan todos los códigos vivos de todas las cajas y hay que generar otro.
- Emparejar de nuevo la caja sustituye la clave anterior, que deja de valer al momento. «Desconectar» en Ajustes también.

### Trabajos

- `type`: `ticket`, `drawer` o `label`. `target`: `ticket` o `label` (la impresora de etiquetas puede ser la misma).
- `payload_b64`: los bytes exactos para la impresora (el mismo ESC/POS que hoy), en base64. Un trabajo `drawer` no lleva datos: `payload_b64` es `""` y `sha256` el de la cadena vacía; el pulso `ESC p` lo genera el agente.
- `sha256`: huella de los bytes decodificados.
- Caducidad (`expires_at`): cajón 15 s, ticket 2 min, etiquetas 24 h. Lo caducado no se entrega ni se imprime.
- `jobs` espera hasta `wait` segundos (máximo 20), mirando cada segundo, y devuelve hasta 5 tickets o cajones y como mucho un trabajo de etiquetas, en orden de creación, para que un ticket nuevo nunca espere detrás de un lote de etiquetas. Un trabajo de etiquetas lleva como mucho 10 etiquetas (una tira con su corte): 200 etiquetas son 20 trabajos. Con trabajos o tras una espera larga, `poll_after_ms` es 0; con `wait=0` y nada pendiente, 2000. La respuesta lleva también `server_time_ms`, la hora del servidor (Unix, ms) al escribirla, que el agente usa en lugar de `Date`.
- Si no hay nada al llegar, la escucha larga responde 200 en el acto y envía un espacio por segundo mientras espera; después, el JSON (los espacios iniciales son JSON válido). Si el agente se ha ido, termina sin recoger nada. Una clave desconectada mientras espera recibe `{"jobs": []}` y 401 en la siguiente petición.
- Cada recogida lleva un `claim_token` y una reserva de 60 s (30 s si se recoge en una escucha larga). Sin confirmar en ese tiempo, el trabajo vuelve a la cola con otro `claim_token`, y el anterior recibe 409 `claim_stale`. `extend` alarga la reserva 60 s para lotes largos.
- Un `done` con su `claim_token` que llega hasta 30 s después de `expires_at` se acepta para tickets y etiquetas (sí se imprimieron); el cajón mantiene su caducidad estricta (410). Un `done` borra el error de un intento fallido anterior.
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
