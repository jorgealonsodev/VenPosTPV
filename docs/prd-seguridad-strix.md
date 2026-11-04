# PRD: corrección de los hallazgos de seguridad (Strix, run `venpostpv_e94f`)

**Estado:** implementado (ver §8) · **Fecha:** 2026-11-04 · **Rama base:** `feat/print-agent`

## 1. Contexto

Strix hizo una auditoría *white-box* del TPV y generó 17 hallazgos (`strix_runs/venpostpv_e94f/`). Cinco revisiones independientes los contrastaron con el código de HEAD. Este documento recoge **lo que se ha verificado**, no lo que dice el informe.

Datos del informe que no son fiables:

- Dice "PHP 8.1"; el TPV necesita 8.4 o superior. El error sale de `composer.json:7`, que sigue declarando `>=8.1`.
- Dice que los arreglos de dinero están "aplicados y validados". En realidad son cambios **sin commit** que Strix dejó en el árbol de trabajo, y el de checkout es incorrecto (ver R1).
- Dice que `terminal.ps_shop_id` no se usa; sí se usa (DeviceRegistry, CashSessionService, TerminalService, TerminalController).
- La CSP que propone rompería la aplicación (ver R10).
- No menciona `twig/twig`, que `composer audit` marca con 1 aviso crítico y 5 altos.
- vuln-0008 (carrera entre la venta y el cierre Z) es un falso positivo: los bloqueos actuales lo impiden y hay tests que lo cubren.

Hallazgo nuevo, que no está en el informe: **el login por SSO probablemente no llega a la pantalla del TPV** (ver R4).

## 2. Objetivos

1. Que ningún dato que envíe el navegador pueda alterar el cuadre de caja (X/Z).
2. Que los endpoints anónimos no expongan la plantilla de empleados ni permitan bloquear cuentas en masa.
3. Que las sesiones sigan las buenas prácticas: cookie protegida, ID nuevo tras el login y comprobación de origen.
4. Que `composer audit` quede limpio.
5. Que ninguna de estas correcciones cambie el uso normal: venta mixta con cambio, primer login en un ordenador nuevo, agente de impresión y módulo de PrestaShop.

**Fuera de alcance:** multitienda, precompilar las plantillas Vue y alojar Vue y Tailwind en el propio servidor (se apunta como trabajo futuro en R10).

## 3. Requisitos

Prioridades: **P0** antes de desplegar en Plesk; **P1** en la misma entrega si es posible; **P2** endurecimiento posterior.

### R1 · P0 · Venta mixta: el efectivo debe estar acotado (vuln-0006, real)

**Problema.** `CheckoutService::resolveCashImpactAmount` (`src/Service/Checkout/CheckoutService.php:~1128`) apunta en caja el `cart_data.cash_amount` que llega del navegador, sin límite superior. Con `cash_amount=1000000` en una venta de 70 € se apuntan 1.000.000 € en el movimiento, y de ahí al efectivo esperado del X y del Z.

**El cambio de Strix empeora el problema y hay que deshacerlo.** Con `cash=-20` sobre 70 € apunta 50 € de efectivo que nadie ha entregado, y sigue sin poner límite superior.

**Solución.**
- Rechazar un efectivo negativo o no finito con un 422.
- Recortar el efectivo al total (`round(min($cash, $total), 2)`). No se debe rechazar: el TPV permite entregar más efectivo que el total y devolver el cambio (`PaymentModal.js`). La caja solo se queda con el importe cobrado.
- Mantener `cash_amount=0` como venta mixta toda con tarjeta; hay un test que lo exige.
- Opcional: calcular `change_due` en el servidor para la venta mixta.

**Aceptación.**
- Venta de 70 € con `cash_amount=1e6`: un único movimiento de 70,00 €, `tender={cash:70, card:0}`, y efectivo esperado = apertura + 70.
- Con `cash_amount=-20`: 422 y ningún movimiento.

**Tests.**
- `CheckoutServiceTest`: `testMixedPaymentCashAboveTotalBooksOnlyThePayable` y `testMixedPaymentNegativeCashIsRejected`.
- `LocalFirstCheckoutMySqlIntegrationTest`: el caso de 1e6 contra base de datos real.

### R2 · P0 · Las devoluciones no pueden apuntarse en una caja cerrada (vuln-0007, real)

**Problema.** El ID de sesión de caja se lee fuera de la transacción y después viene una llamada HTTP (vale o PrestaShop). Si la caja se cierra entre medias, el movimiento de devolución se apunta en una sesión ya cerrada. Pasa en dos sitios:
- `completeLocally` (`RefundService.php:~642`);
- `complete()` en la devolución online (`RefundService.php:~1113`). Este sitio **no lo cubre el cambio de Strix**.

**Solución.**
- Crear `CashSessionService::lockOpenSessionInTransaction(int $sessionId)`, que hace el `SELECT … closed_at IS NULL FOR UPDATE` y lanza `NoOpenCashSessionException`. Ya hay dos copias de esa consulta; esta sería la única.
- Usarlo en `recordMovementInTransaction`, en `completeLocally` (traducido a `RefundException::conflict`, 409) y al principio de la función de `complete()`. Su `catch` ya deja la devolución pendiente con `saga_error`.
- El cambio de Strix en `RefundService` se sustituye por este.

**Aceptación.** Si el cierre ocurre en mitad de la llamada HTTP:
- no se apunta ningún movimiento en la sesión cerrada;
- la devolución queda pendiente;
- el Z no cambia;
- `retryPending` la completa después en la sesión nueva.

**Tests.**
- `OnlineRefundMySqlIntegrationTest`: `testShopRefundFinishingAfterCloseBooksNothingInTheClosedSession`.
- `RefundServiceMySqlIntegrationTest`: `testVoucherIssuedAfterCloseDoesNotCompleteInTheClosedSession`.
- Un test unitario del 409.

### R3 · P0 · Actualizar dependencias con avisos de seguridad (vuln-0009…0017 y twig)

**Problema.** `composer audit` encuentra avisos en twig (1 crítico, 5 altos), symfony/http-foundation, routing, yaml, cache, config, filesystem, http-client, http-kernel y validator. El de **http-foundation** (ReDoS en la cabecera `Cache-Control`) **se puede explotar sin iniciar sesión**. Los de twig afectan al modo *sandbox*, que el TPV no usa, pero conviene actualizar igualmente.

**Solución.**
- `composer update 'symfony/*' twig/twig --with-dependencies`. No hace falta tocar `composer.json`.
- Cambiar `composer.json` a `"php": ">=8.4"` y poner `config.platform.php`.

**Aceptación.**
- `composer audit --locked` solo muestra el aviso de php-jwt que ya está ignorado.
- La suite da el mismo resultado que antes: 864 tests, 2265 aserciones y 224 omitidos (comprobado en una copia aparte).

### R4 · P0 · El SSO debe usar la sesión de Symfony (vuln-0002 y fallo funcional)

**Problema.** `SsoController.php:76-90` usa `session_start()` y escribe directamente en `$_SESSION`. Eso provoca dos fallos:
- la cookie sale sin HttpOnly, SameSite ni Secure;
- `PosController` lee la sesión de Symfony, que guarda los datos en otro sitio (`_sf2_attributes`). Por eso el login por SSO **probablemente devuelve al usuario a `/app/login`**.

**Solución.** `$session = $request->getSession(); $session->start(); $session->migrate(true);` y después `$session->set(...)` para cada clave.

**Aceptación.**
- **Primero se reproduce el fallo** con un SSO real o simulado.
- Después de la corrección, el SSO lleva a `/app/pos` y la cookie sale con HttpOnly, SameSite=Lax y Secure en HTTPS.

**Tests.** Reescribir `SsoControllerTest::testValidTokenRedirectsToApp` sobre `MockArraySessionStorage`, comprobando:
- `user_id` en la sesión de Symfony;
- que el ID de sesión ha cambiado;
- que no se escribe nada en `$_SESSION` directamente.

### R5 · P1 · Directorio de empleados y límite de intentos (vuln-0001, real)

**Problema.** Dos endpoints anónimos exponen la plantilla:
- `GET /api/auth/employees-public` devuelve id, nombre, rol y estado de todos los empleados, incluido el admin maestro;
- `POST /api/auth/employee-lookup` dice si un nombre existe y si la cuenta está bloqueada.

Además, cualquiera puede bloquear a cualquier empleado con 3 PIN erróneos, porque los ID son correlativos. Ni el login de admin ni el de empleado tienen límite de intentos, y el admin se compara con `!==`.

**Solución.**
- Eliminar `employees-public`: el frontend no lo usa (solo aparece en `api.js:50`).
- `employee-lookup` devuelve solo `{id, name}`. El aviso de cuenta bloqueada sigue saliendo, pero después de teclear el PIN (403 `locked:true`, que el frontend ya gestiona).
- Excluir al admin maestro de la búsqueda y del login de empleado.
- Limitar los intentos por IP reutilizando `src/Service/PrintAgent/RateLimiter.php` (tabla `rate_limit`) y devolver 429 con `Retry-After`, igual que `AgentApiController`:
  - búsqueda: unos 20 por minuto;
  - login de empleado: unos 10 por minuto y 30 cada 15 minutos;
  - login de admin: unos 5 por minuto.
  Si la tabla no existe, se deja pasar (como hace el agente).
- Admin: `hash_equals`.
- **No** exigir la cookie de dispositivo en la búsqueda: rompería el primer login en un ordenador nuevo (`adoptOnlyTill`).

**Aceptación.** `employees-public` responde 404. La búsqueda devuelve exactamente `id` y `name`. Al pasar el límite responde 429 y no cambia `login_failures`.

**Tests.**
- Nuevo `tests/Integration/Controller/Api/AuthControllerTest.php` con unos 10 casos.
- En `DeviceGateTest`, añadir `employee-lookup` a las rutas que deben seguir accesibles sin caja asignada.

**Ojo:** el límite va por IP, así que depende de que `TRUSTED_PROXIES` esté bien configurado. Si está mal, todas las cajas comparten el mismo límite.

### R6 · P1 · Nuevo ID de sesión al iniciar sesión (vuln-0003, real)

**Solución.** En el login de admin y en el de empleado (`AuthController.php:~130` y `~258`): `$session->start(); $session->migrate(true);`. El `start()` es necesario porque, sin él, `migrate` no hace nada.

**Aceptación.** El ID de sesión es distinto antes y después del login, y el carrito se conserva.

**Test.** El ID cambia en los dos tipos de login.

### R7 · P1 · Comprobar el origen en las peticiones que modifican datos (vuln-0005, real)

**Problema.** La API del navegador se autentica solo con la cookie. SameSite=Lax no protege frente a otro subdominio del mismo dominio, por ejemplo PrestaShop en `tienda.ejemplo.com` y el TPV en `tpv.ejemplo.com`. Un formulario `text/plain` se acepta como JSON.

**Solución.** Crear `src/EventListener/OriginCheckListener.php` (prioridad 9) para los métodos que modifican datos en `/api/*`, excepto `/api/agent/v1/*`.
- Si la cabecera `Origin` (o, en su defecto, `Referer`) no coincide con el host del TPV ni con `TPV_URL`, responde 403.
- Si no viene ninguna de las dos, deja pasar: no es un navegador.
- Opcional: responder 415 si el `Content-Type` no es JSON.

**Aceptación y tests.** Unos 8 casos:
- un origen ajeno recibe 403;
- el mismo origen pasa;
- una petición sin origen pasa;
- `Referer` sirve como alternativa;
- el agente con un origen ajeno pasa;
- los GET no se bloquean nunca;
- `TPV_URL` sobre http pasa;
- `AgentApiNoSessionTest` sigue en verde.

### R8 · P1 · PIN cortos antiguos (vuln-0004: error de funcionamiento, no un acceso indebido)

**Problema.** `AuthController.php:203-206` rechaza los PIN de menos de 8 caracteres antes de `password_verify`, sin contar el intento como fallido. No permite entrar a nadie, pero deja sin acceso a los usuarios antiguos con PIN corto.

**Solución.** Quitar esa comprobación: comprobar siempre el PIN y contar los fallos. El mínimo de 8 caracteres se exige solo al dar de alta o cambiar el PIN; eso ya está hecho en `EmployeeController`.

**Decisión pendiente (D1).** Ver la sección 5.

### R9 · P2 · Escapado del JSON de sesión en la plantilla

`pos.html.twig:30` pinta `session_data|raw`. Hoy es seguro de rebote, porque `json_encode` escapa la barra de `</script>`.

**Solución.** Añadir `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` en `PosController.php:88`.

**Test.** Con `cashier_name='</script><!--<script>x'`, la respuesta contiene `<` y el número de etiquetas `<script` es el esperado.

### R10 · P2 · Cabeceras de seguridad (recomendación 8 del informe)

Crear `src/EventListener/SecurityHeadersListener.php`, que genera un *nonce* por petición y envía estas cabeceras en `/app/*` y `/sso`:

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{N}' 'unsafe-eval' https://cdn.tailwindcss.com https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'
X-Frame-Options: DENY · X-Content-Type-Options: nosniff · Referrer-Policy: same-origin
```

- Hay que añadir `nonce` a los 5 `<script>` incrustados en `pos.html.twig` y `login.html.twig`.
- `'unsafe-eval'` es necesario porque Vue compila las plantillas `template:` en el navegador.
- Fijar la versión exacta de `vue@3` con SRI; ahora se carga sin versión fija desde unpkg.

**Tests.**
- Las cabeceras están presentes y el nonce cambia en cada petición.
- La API del agente no cambia.
- Todos los `<script>` incrustados llevan nonce.

**Trabajo futuro:** precompilar las plantillas Vue y alojar Vue y Tailwind en el propio servidor, para poder quitar `unsafe-eval`.

### R11 · P2 · dompdf sin acceso remoto ni a ficheros

`TicketPdfGenerator.php:44` activa `setIsRemoteEnabled(true)`. El `chroot` abarca todo el proyecto, `.env` incluido. El ticket solo usa imágenes `data:`.

**Solución.** `setIsRemoteEnabled(false)` y `setAllowedProtocols(['data://'])`, y quitar el `chroot`.

**Test.** Comprobar esas opciones. `TicketPdfGeneratorTest` ya comprueba que se incrustan las 3 imágenes.

### R12 · P2 · php-jwt 7

**Solución.**
- Cambiar a `"firebase/php-jwt": "^7.0"`.
- Las claves de los tests deben tener al menos 32 bytes: `TicketVerificationServiceTest` (4 tests) y `ExchangeServiceMySqlIntegrationTest:679`.
- Quitar `PKSA-y2cr-5h3j-g3ys` de `audit.ignore`.
- Al arrancar, avisar si `APP_SECRET` tiene menos de 32 bytes. El instalador genera 64.

**Aceptación.** `composer audit` sin avisos.

### R13 · P2 · Instalador de Plesk (no estaba en el alcance de Strix)

1. Si `realpath(DOCUMENT_ROOT)` falla, tratarlo como error, no saltarse la comprobación.
2. Crear `.env` con `fopen($env, 'x')`, que no sobrescribe un fichero que ya exista, en lugar de `rename`.
3. Mostrar un mensaje genérico en vez de la excepción tal cual, y guardar el detalle en el log.
4. Documentar cómo limpiar la base de datos si una migración se queda a medias (en MySQL, los cambios de estructura no se deshacen solos).
5. Ver la decisión D3 (contraseña de admin en claro en `.env`).

## 4. Qué no se va a corregir

| Hallazgo | Motivo |
|---|---|
| vuln-0008 (carrera entre la venta y el Z) | Falso positivo. Los bloqueos `FOR UPDATE` lo impiden y lo cubre `CashSessionServiceConcurrencyIntegrationTest`. Las únicas escrituras sin bloqueo eran las de las devoluciones, y las arregla R2. |
| `terminal.ps_shop_id` | Sí se usa; el informe se equivoca. |
| `role.permissions` sin usar | Es cierto que no se usa. Quitar la columna supone una migración sobre datos existentes, y queda a decisión (D4). |
| vuln-0016 (php-jwt, CVE en disputa) | No aplica con secretos de 64 bytes. Se cierra con R12. |

## 5. Decisiones pendientes

- **D1 (R8).** Un usuario antiguo con PIN corto que lo introduce bien: ¿puede entrar (y se le marca para cambiar el PIN) o se le rechaza hasta que un admin se lo restablezca? *Recomendado: rechazarlo y que el admin restablezca el PIN.* Así el frontend no cambia y el mínimo de 8 caracteres se mantiene.
- **D2 (R6).** ¿`migrate(true)` (conserva el carrito) o `invalidate()` (empieza de cero)? *Recomendado: `migrate`.*
- **D3 (R13).** ¿Guardar `APP_ADMIN_PASSWORD` como hash (`password_hash`/`password_verify`)? Cambia el formato de `.env` y necesita un paso de migración para las instalaciones existentes. *Recomendado: sí, en una entrega aparte.*
- **D4.** ¿Eliminar la columna `role.permissions`? *Recomendado: no por ahora.*

## 6. Plan de entrega

Cada bloque se entrega en su propio commit, con sus tests:

| # | Commit | Requisitos | Riesgo |
|---|---|---|---|
| 0 | Deshacer los cambios de Strix en `CheckoutService` y `RefundService` | — | — |
| 1 | `fix(checkout): bound the mixed cash split to the payable` | R1 | 1 (cuadre de caja) |
| 2 | `fix(refund): lock the open cash session before booking a refund` | R2 | 1, 4 (bloqueos) |
| 3 | `chore(deps): security update of symfony and twig` | R3 | 5 (dependencias) |
| 4 | `fix(sso): use the Symfony session and rotate its id` | R4, R6 | 2 (autenticación) |
| 5 | `fix(auth): stop exposing staff to anonymous callers and rate-limit logins` | R5, R8 | 2, 3 (se elimina un endpoint) |
| 6 | `feat(security): origin check on unsafe API requests` | R7 | 2 |
| 7 | `feat(security): CSP and security headers on app pages` | R9, R10 | 2 |
| 8 | `fix(print): disable remote and file access in dompdf` | R11 | 2 |
| 9 | `chore(deps): firebase/php-jwt 7` | R12 | 5 |
| 10 | `fix(install): installer hardening` | R13 | 5 |

Los commits 0 a 4 (P0) tienen que estar hechos **antes de desplegar en Plesk**. Después hay que volver a generar `dist/venpostpv-plesk.zip`.

## 7. Verificación

- Cada commit pasa sus propios tests y la suite completa. Los tests de integración con MySQL se ejecutan contra una MariaDB desechable, igual que con el instalador, y la carrera de caja con `run-cash-close-atomicity.sh`.
- Las pruebas de concepto de Strix se repiten a mano contra una instancia local: venta mixta con 1e6, devolución con la caja cerrada a mitad, `employees-public` sin sesión, POST con un origen ajeno.
- Al terminar, se repite Strix limitado a los cambios: `strix -n -t ./ --scan-mode quick --scope-mode diff --diff-base <rama base> --max-budget 5`. Hay que confirmar en `run.json` que la ejecución llegó hasta el final (`completed`) y no se cortó por presupuesto.
- Al final, `composer audit --locked` sin avisos.

## 8. Estado de la implementación

Todo está en `feat/print-agent`, desde `699a5e6`. Una verificación independiente lo revisó y no encontró bloqueos.

| Requisito | Commit | Nota |
|---|---|---|
| R3 dependencias | `e3d62dc` | `composer audit` limpio |
| R11 dompdf | `dea97f1` | |
| R13 instalador | `5df55b2`, `1dfec66` | `.env` se crea con `fopen(…, 'x')` |
| R4 SSO | `8ca8003` | Se confirmó con un test que el SSO devolvía al login |
| R5, R6, R8 | `c2583ca` | También se rechaza el login de admin si sus credenciales no están configuradas en `.env` |
| R1 venta mixta | `d579699` | |
| R2 devoluciones | `8cf2b1a` | También bloquea la sesión en la planificación de la devolución (orden: sesión → `order_meta`) |
| R7 origen | `e102f3e` | |
| R9, R10 cabeceras y CSP | `b73e4b4` | Vue fijado a 3.5.44 con SRI; los 34 `onmouseover` pasan a `@mouseover`; comprobado en el navegador |
| R12 php-jwt 7 | `a1b54fc` | Un `APP_SECRET` de menos de 32 bytes se alarga con SHA-256 en lugar de avisar al arrancar: la impresión de tickets sigue funcionando |

**Hallazgos nuevos, ya corregidos:**
- **R14 · `3fc66d2`.** Varios servicios leían `shop_id` y `user_id` directamente de `$_SESSION`, así que con el login por SSO tomaban la tienda 1 y no apuntaban usuario en la auditoría. Ahora `SessionValue` lee primero la sesión de Symfony.
- **R15 · `e65d63f`.** La URL de las imágenes del catálogo llevaba `?ws_key=`, de modo que **la clave del webservice de PrestaShop llegaba al navegador de las cajas**. Ahora se guarda la miniatura pública (`img/p/…-home_default.jpg`) y la migración 39 reescribe las URL ya guardadas. **Hay que regenerar la clave** en el módulo TPV Connect, porque ya ha estado expuesta.

**Avisos que quedan, sin bloquear:**
- El límite de intentos va por IP pública, así que todas las cajas de una tienda comparten el cupo.
- Las imágenes del catálogo dan por hecho que el tema de la tienda tiene la miniatura `home_default`. Si no la tiene, la ficha muestra «IMG».
- Una devolución con tarjeta que coincide con el cierre de caja queda pendiente y aparece en `audit_log` como `refund_orphan`. `retryPending` la completa en la sesión siguiente.
- Ya existía antes de estos cambios: `TicketNumbererMySqlIntegrationTest::testConcurrentWorkersGetUniqueGaplessNumbers` falla también en `699a5e6`.

**Queda por hacer:** D3 (guardar la contraseña de admin cifrada) y volver a pasar Strix limitado a los cambios (§7).
