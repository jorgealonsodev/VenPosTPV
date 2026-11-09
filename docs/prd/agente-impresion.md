# PRD — Agente de impresión de la tienda

- **Estado:** revisado (v2), pendiente de visto bueno
- **Fecha:** 2026-11-03
- **Relación:** necesario para desplegar el TPV en un subdominio de un hosting (ver `docs/prd/venta-local-primero.md`)
- **Revisión:** v1 analizada por cinco revisiones independientes (seguridad, programa en Rust, servidor y hosting, etiquetas, uso en tienda); sus propuestas aceptadas están incorporadas.

## 1. Objetivo

Que el TPV alojado en un hosting imprima tickets y etiquetas, y abra el cajón, en las impresoras de la tienda, igual que hoy con el servidor local: el ticket sale solo al cobrar y el cajón se abre con efectivo.

Para ello, un programa pequeño (el **agente**) se instala en el ordenador de cada caja. Se conecta al TPV por HTTPS hacia fuera, sin abrir puertos en la tienda, recoge los trabajos de su caja y los envía a sus impresoras.

## 2. Situación actual

- El servidor del TPV imprime él mismo: por CUPS (impresora USB enchufada al servidor) o por IP. El cajón se abre enviando `ESC p` a la impresora de tickets.
- El ticket de venta se encola en Messenger (`CheckoutService.php:246`); en el hosting esa cola solo se vacía con un cron cada minuto, así que no sirve para el agente.
- Algunas rutas toman la caja 1 si la sesión no tiene caja (`TicketController.php:98`, `RefundController.php:255`).
- El catálogo local ya tiene `ean13` y `reference` por producto y combinación, los valores de talla/color y `price_ttc` (precio con IVA y oferta vigente). No hay nombre corto ni precio anterior a la oferta.
- No existe impresión de etiquetas ni limitador de intentos en el servidor.

## 3. Decisiones del usuario

| Tema | Decisión |
|---|---|
| Alcance | **Tickets, cajón y etiquetas.** |
| Cajas por agente | **Un agente por caja.** |
| Conexión | **Escucha larga HTTPS** (hasta 20 s; el servidor mira cada segundo). |
| Sin agente al cobrar | **Cobra y ofrece el PDF.** La venta nunca se bloquea. |
| Ticket pendiente tras dar el PDF | **Se imprime si el agente vuelve a tiempo** (antes de caducar). |
| Caducidad | **Cajón 15 s, ticket 2 min, etiquetas 24 h** (al reconectar pregunta si imprimirlas). |
| Etiquetas | **Precio + código de barras**: nombre, talla/color, precio actual con IVA (sin tachado), código y su texto, con un QR del mismo código al lado. |
| Tamaño en la TM-T20 | **Ancho fijo (72 mm), alto ajustable** (25–60 mm, 30 mm por defecto). Plantillas 50×30/40×25 y medidas a mano solo para impresoras de etiquetas. |
| Varias etiquetas en la TM-T20 | **Tiras de 10 con corte** (2026-11-03, tras la verificación): hasta 10 etiquetas por tira, separadas por línea de puntos; cada tira con su corte. |
| Compatibilidad | «Hazlo lo máximo compatible posible»: ESC/POS (TM-T20) en fase 1; ZPL (Zebra) y TSPL (TSC, Xprinter) en fase 2; impresoras con driver (Brother QL, Dymo) en fase 2. |
| Origen de las etiquetas | **Ficha del producto y lista.** |
| Código de barras | **EAN si existe y es válido; si no, la referencia en Code128.** |
| Emparejar | **Código de 6 cifras**, 10 minutos, un solo uso. |
| Clave del agente | **No caduca; se vigila** (última fecha e IP de uso). |
| Instalación Windows | **Instalador con asistente.** |
| Instalación Linux | **Paquete .deb + script** (fase 2; en fase 1 binario y comando). |
| Firma del instalador | **Sin firma de momento.** |
| Actualizaciones | **Automáticas y firmadas, con la caja cerrada** (fase 2). Si la nueva no arranca, **vuelve a la anterior**. En fase 1, aviso y descarga manual. |
| Interfaz | **Icono en la bandeja en Windows y en Linux** (Linux en fase 2). |
| Historial | **30 días**, visible en Ajustes. |
| Errores de impresora | **Avisar cuando la impresora lo permita.** |
| Cajón desde el icono | **No.** |
| Reimpresión | **Sale por la caja que la pide.** |
| Cobrar sin internet en la tienda | **Fuera de este PRD.** |
| Lenguaje | **Rust.** |
| Entrega | **Dos fases.** |
| Pruebas de Windows | **GitHub Actions con una máquina Windows**; el icono lo revisa el usuario. |

## 4. Alcance

**Fase 1 (impresión funcionando en el hosting con la TM-T20):**
- emparejar, desconectar y vigilar la clave;
- tickets, cajón, reimpresión, abonos, prueba;
- avisos en caja y PDF de reserva;
- instalador de Windows con servicio e icono, elección de impresoras;
- binario de Linux con comandos (para desarrollo y la prueba real aquí);
- etiquetas en ESC/POS en la TM-T20, desde la ficha y desde la lista, con vista previa;
- estado del agente y de la cola, avisos por trabajo fallido;
- historial de 30 días con filtros;
- aviso de versión nueva y descarga manual;
- pruebas de Windows en GitHub Actions.

**Fase 2:**
- etiquetas ZPL y TSPL (cuando haya impresora de etiquetas) e impresoras con driver;
- leer «sin papel», «tapa abierta» directamente de la impresora;
- actualización automática firmada con vuelta atrás;
- paquete `.deb`, script para otras distribuciones e icono en Linux.

**Fuera de alcance:** macOS; dos cajas con una sola impresora USB; etiquetas desde el back office de PrestaShop o pedidos a proveedor; cobrar sin internet; imprimir desde móvil o tableta.

## 5. Reglas

### 5.1 Emparejar y seguridad
- **E1. Paso a paso:**
  1. En Ajustes → Cajas → la caja: «Descargar programa de impresión (Windows / Linux)».
  2. «Conectar agente»: código grande de 6 cifras con cuenta atrás de 10 minutos y «Generar otro código».
  3. Al emparejar, la pantalla cambia sola a «Conectado: PC-CAJA1».
  4. Si la caja ya tenía agente: «Esto desconectará el agente de PC-CAJA1. ¿Continuar?».
- **E2.** El código queda ligado a la caja y al administrador que lo generó. Solo hay un código vivo por caja; generar otro anula el anterior.
- **E3.** El agente envía el código y recibe una clave de 256 bits. El código se marca usado en la misma transacción que crea la clave. La respuesta lleva `Cache-Control: no-store`. El servidor solo guarda la huella (sha256) de la clave.
- **E4.** Cada clave vale para una sola caja: solo ve y confirma trabajos de su caja.
- **E5.** Desconectar el agente en Ajustes invalida la clave al momento. Volver a emparejar revoca la anterior.
- **E6.** La clave no caduca. Ajustes muestra la última fecha e IP de uso para detectar copias.
- **E7.** Límites: emparejar admite 5 intentos por minuto y por IP (después, 429). Los fallos de todas las IP se cuentan juntos: 20 fallos en 10 minutos anulan todos los códigos vivos de todas las cajas. Una clave inválida recibe 429 tras 10 fallos por minuto y por IP. Detrás del nginx del hosting la IP es la real del cliente (`TRUSTED_PROXIES`).
- **E7b.** Desde el icono (canal local) solo se empareja por HTTPS, sin excepción para localhost, y solo con el TPV al que ya está conectado el agente; otro TPV: «Para cambiar de TPV, vuelve a ejecutar el instalador como administrador».
- **E8.** La clave, el código y la cabecera `Authorization` nunca aparecen en direcciones, registros ni pantallas. El texto de error de un trabajo se recorta a 500 caracteres.
- **E9.** `/api/agent/v1/*` no usa sesión ni cookies (fuera del vínculo de equipo); solo acepta `Authorization: Bearer`.
- **E10.** Clave guardada en Windows cifrada con DPAPI de ámbito de máquina en `%ProgramData%\VenPos Agent\`, con permisos solo para el servicio y Administradores; en Linux en `/etc/venpos-agent/key`, propietario el usuario de servicio `venpos-agent`, permisos 0600. El icono nunca lee la clave.
- **E11.** Solo HTTPS con validación completa del certificado del sistema, sin opción de desactivarla. Se respeta el proxy del sistema. Cambiar la dirección del TPV exige volver a emparejar.

### 5.2 Trabajos de impresión
- **T0.** Con impresora «Agente», el trabajo se crea en `print_job` dentro de la misma petición (cobro, reimpresión, abono, cajón), sin pasar por Messenger.
- **T1.** Todo lo que hoy se imprime o abre el cajón se convierte en un trabajo de la caja que lo pide: ticket de venta, regalo, reimpresión, abono, prueba, cajón y etiquetas. El contenido de tickets es el mismo ESC/POS de hoy.
- **T2.** Tipos: `ticket`, `drawer`, `label`. Destinos del agente: impresora de tickets e impresora de etiquetas (pueden ser la misma).
- **T3.** «Hecho» significa entregado a la cola del sistema o al dispositivo. Si la impresora no informa de su estado, el TPV dice «Enviado», no «Impreso».
- **T4.** Entrega al menos una vez: cada recogida lleva un `claim_token`; si no se confirma en 60 s (30 s si se recogió en una escucha larga), el trabajo vuelve a la cola (el agente puede pedir más tiempo para lotes largos). La escucha larga nota que el agente se ha ido (escribe un espacio por segundo) y entonces no recoge nada. El agente guarda en disco los trabajos impresos de las últimas 24 h y nunca los repite. Confirmar es idempotente.
- **T5.** Un fallido se reintenta a los 2, 5 y 15 s, sin pasar su caducidad.
- **T6.** Caducidad: cajón 15 s, ticket 2 min, etiquetas 24 h. Lo caducado no se imprime. Un «hecho» de un ticket o una etiqueta con su `claim_token` hasta 30 s después de caducar se acepta (sí se imprimió); el cajón, no. Si un trabajo de cajón sigue en la cola del sistema pasados sus 15 s, el agente lo cancela.
- **T7.** Prioridad: ticket y cajón pasan por delante de las etiquetas. Un lote de etiquetas se interrumpe entre tira y tira (10 etiquetas como mucho).
- **T8.** El agente solo escribe en sus destinos configurados en el propio equipo; el servidor no puede elegir impresora, ruta ni dispositivo. El trabajo `drawer` no lleva datos: el pulso ESC p lo genera el agente. Límites: 1 MB por trabajo, 10 etiquetas por trabajo (L6), una apertura de cajón cada 5 s.
- **T9.** Sin caja resuelta en la sesión no se crea el trabajo (400 `terminal_not_resolved`); nunca se usa la caja 1 por defecto.
- **T10.** La reimpresión sale por la caja que la pide.
- **T11.** El cajón solo se abre desde el TPV (venta en efectivo o mixto, o «Abrir cajón»). Cada apertura guarda usuario, caja, motivo (venta nº o botón) y resultado. Este registro no se borra con el historial.

### 5.3 En la caja
- **C1.** La pantalla de cobro muestra «Imprimiendo ticket…» y después:
  - «Ticket impreso» o «Ticket enviado», según T3;
  - «La impresora no está conectada», si a los 15 s no está hecho (aunque se haya recogido);
  - «La impresora tiene un problema: sin papel» u otro motivo, si el trabajo falla.
- **C2.** En los dos últimos casos hay dos botones grandes (mínimo 48 px): «Reintentar» y «Descargar PDF». Si se cierra el cobro antes, el aviso queda en la pantalla de venta con «Reimprimir último ticket».
- **C3.** Si la apertura del cajón no se confirma en 5 s: «El cajón no se ha abierto. Ábrelo con la llave.» en ámbar, junto al cambio.
- **C4.** Si el agente lleva más de 2 minutos sin conectar, la pantalla de venta muestra un aviso permanente hasta que vuelva.
- **C5.** Dar el PDF no cancela el ticket pendiente: si el agente vuelve antes de 2 minutos, el ticket se imprime.

### 5.4 Etiquetas
- **L1.** Desde la ficha: tabla de tallas/colores con una cantidad por fila, botón «Cantidad = stock», vista previa y «Imprimir N etiquetas».
- **L2.** Pantalla «Etiquetas»: añadir productos escaneando o buscando, cantidad por línea, vista previa e imprimir todo. La lista se conserva hasta imprimir y se vacía al terminar.
- **L3.** Contenido: nombre del producto en un máximo de 2 líneas; combinación («M · Azul»); precio actual con IVA en grande («23,14 €»), ya con la oferta vigente y sin precio tachado; código de barras y su texto y, a su lado y en las mismas líneas, un QR pequeño con el mismo código (las cifras del EAN, o la referencia si va en Code128). Refleja el precio de la última sincronización.
- **L4.** Código: el `ean13` de la combinación y, si no hay, el del producto. Se valida el dígito de control: 13 cifras EAN-13, 8 EAN-8, 12 UPC-A. Si no es válido o no existe, la referencia en Code128. Si el código no cabe con un módulo mínimo de 0,25 mm, o no hay ni EAN ni referencia, no se imprime y se avisa con el motivo.
- **L5.** En la TM-T20: ancho completo (72 mm, 576 puntos a 203 ppp), alto configurable de 25 a 60 mm (30 mm por defecto).
- **L6.** En la TM-T20, hasta 10 etiquetas por tira; cada tira con su corte. Cada trabajo es una tira: las etiquetas separadas por una línea de puntos y un corte al final (200 etiquetas, 20 tiras). Opción «cortar cada etiqueta» desactivada por defecto.
- **L7.** ESC/POS: tabla de caracteres 16 (WPC1252) para «€», como el ticket. La zona del código (barras, su texto debajo y el QR al lado) va como una sola imagen de trama (`GS v 0`, 1 bit por punto, 576 puntos de ancho), porque los comandos de código de la impresora (`GS k`) no permiten poner un QR al lado de las barras. La vista previa pinta la misma imagen, punto por punto.
  - Barras: cada módulo es un número entero de puntos (3, o 2 = 0,25 mm), sin escalar, con sus zonas en blanco; mismo alto que antes (40 a 255 puntos). El texto va dibujado en la imagen, centrado bajo las barras.
  - QR: corrección de errores M, módulos de un número entero de puntos (2 a 5), margen en blanco de 2 módulos alrededor, centrado en vertical, como mucho 15 mm (120 puntos) y nunca más alto que las barras y su texto (en 30 mm, unos 13 mm). Barras y QR se centran juntos.
  - Si un Code128 no deja sitio para el QR, se estrecha su módulo hasta el mínimo (0,25 mm); si aun así no cabe, esa etiqueta sale sin QR y con las barras de siempre.
  - Sin configuración nueva: no hay nada que instalar ni ajustar (docs/provisioning.md no cambia).
- **L8.** Límites: 100 por línea y 10 por trabajo (una tira, L6); más se reparte en varios trabajos. Ajustes → Cajas: «Imprimir etiqueta de prueba».
- **L9.** Al reconectar con etiquetas pendientes, el TPV pregunta «Hay N etiquetas pendientes. ¿Imprimirlas?».
- **L10 (fase 2).** ZPL (`^CI28`, `^BE`, `^BC`) y TSPL (`CODEPAGE 1252`, `BARCODE "EAN13"`/`"128"`, `SIZE`, `GAP`/`BLINE`, `DENSITY`), resolución 203 o 300 ppp, plantillas 50×30 y 40×25 y medidas a mano, con calibración inicial. Impresoras con driver (Brother QL, Dymo) mediante el driver del sistema.

### 5.5 Estado, errores e historial
- **S1.** Cada petición del agente actualiza su última conexión. El estado de las impresoras se envía aparte solo cuando cambia.
- **S2.** Fase 1: estado de la cola del sistema (desconectada, en pausa, error) y trabajos fallidos. Fase 2: «sin papel», «poco papel», «tapa abierta» leídos directamente de la impresora cuando habla con ella sin cola (en Linux `/dev/usb/lp*`, o por red puerto 9100).
- **S3.** Ajustes → Cajas por caja: «Agente: Conectado» o «Sin conexión desde las 17:05»; impresora «Lista», «Poco papel», «Sin papel», «Tapa abierta», «Desconectada» o «No encontrada»; versión y si hay versión nueva. En la lista de cajas el tipo se llama «Agente de impresión».
- **S4.** Historial de 30 días con fecha, caja, tipo, estado y error, filtrable por caja y estado. El contenido de cada trabajo (puede llevar datos del cliente) se borra a las 24 h; los datos del trabajo, a los 30 días. La limpieza se hace en la tarea de cron de cada minuto que ya existe.
- **S5.** La impresora de tickets, la de etiquetas y su lenguaje se eligen en el agente (asistente o icono → «Elegir impresoras»). Ajustes los muestra. Si una impresora se renombra o desaparece: «Impresora no encontrada: elígela de nuevo desde el icono».

### 5.6 El programa
- **A1.** Rust. Dos programas por sistema: un **servicio** sin ventana que se conecta, imprime y registra, y un **icono de bandeja** por usuario que solo muestra el estado y pide al servicio la prueba de impresión. Cerrar el icono no para el servicio.
- **A2.** Windows: servicio con cuenta virtual `NT SERVICE\VenPosAgent` (no LocalSystem). Linux: servicio systemd con usuario `venpos-agent` del grupo `lp`.
- **A3.** El icono habla con el servicio por tubería con nombre (Windows) o socket Unix 0660 (Linux), solo con órdenes fijas (estado, prueba, elegir impresoras, ver registro). No abre ningún puerto TCP.
- **A4.** Icono: verde conectado, ámbar sin conexión, rojo impresora con error. Menú: «Imprimir prueba», «Elegir impresoras», «Ver registro», «Abrir Ajustes del TPV», «Acerca de». No abre el cajón.
- **A5.** Impresión por destino, un modo por impresora: cola del sistema (Windows RAW por el administrador de impresión; Linux CUPS `lp -o raw`) o directo (`/dev/usb/lp*` o IP:9100). En Windows el driver debe ser v3 o «Generic / Text Only» (los v4 pueden rechazar RAW).
- **A6.** Comandos: `pair <código>`, `status`, `list-printers`, `test-print`, `install`, `uninstall`, `run`, `version`.
- **A7.** Conexión: espera del servidor hasta 20 s, tiempo de lectura del cliente 40 s; si una petición tarda más de 30 s (proxy), baja a esperas de 5 s. Reconexión con espera creciente (1, 2, 5, 10, 30 s). Clave rechazada: «Vuelve a conectar el agente desde Ajustes → Cajas».
- **A8.** Registro en fichero con rotación (5 MB × 3), sin datos sensibles (E8).
- **A9.** Los datos de un trabajo son bytes opacos hacia la impresora: nunca se ejecutan ni se interpretan como órdenes del sistema.
- **A10.** Tamaño orientativo: 4–6 MB el servicio, 2–3 MB el icono. Sin OpenSSL (TLS con rustls).

### 5.7 Instalación
- **I1.** Windows (fase 1): instalador con asistente, generado con NSIS. Pide la dirección del TPV y el código (se puede saltar y emparejar después desde el icono). Deja elegir impresora de tickets y de etiquetas entre las instaladas para todo el equipo, hace una prueba e instala el servicio y el icono al iniciar sesión.
- **I2.** El instalador no va firmado de momento; la guía explica «Más información → Ejecutar de todas formas» y el posible aviso del antivirus.
- **I3.** Linux: fase 1, binario y comandos; fase 2, paquete `.deb` sin dependencias gráficas obligatorias (`cups-client` recomendado), binario estático (musl) con script para otras distribuciones, e icono para escritorios con StatusNotifierItem (KDE, XFCE, Cinnamon, MATE; GNOME con la extensión AppIndicator).

### 5.8 Actualizaciones
- **U1 (fase 1).** Ajustes → Cajas muestra «Hay una versión nueva» y el enlace de descarga.
- **U2 (fase 2).** El TPV publica un manifiesto firmado (sistema, versión, sha256, tamaño) con Ed25519. Los binarios se guardan en el subdominio, fuera de git.
- **U3.** La clave privada nunca está en el hosting ni en el repositorio: se guarda fuera de línea en el equipo del desarrollador y se firma al publicar. Si se pierde o compromete, se publica a mano un agente con otra clave pública.
- **U4.** El agente comprueba la firma y rechaza versiones iguales o anteriores a la instalada. El servidor responde 426 si el agente está por debajo de la mínima admitida.
- **U5.** Solo se actualiza con la caja cerrada y sin trabajos pendientes. Windows: un actualizador aparte sustituye los programas y, si en 60 s no hay conexión correcta, restaura la versión anterior. Linux: el agente descarga y verifica; una unidad systemd de root aplica el cambio.

## 6. Cambios técnicos previstos

- **TPV (PHP):**
  - tipo de impresora `agent` y rutas de impresión sin Messenger (T0) ni caja por defecto (T9);
  - migración V37: `print_job`, `print_agent`, `agent_pairing` y registro de aperturas de cajón;
  - `print_job`: id, terminal_id, type, target, status (pending, claimed, done, failed, expired, cancelled), attempts, claim_token, lease_until, next_attempt_at, expires_at, payload MEDIUMBLOB (1 MB), sha256, error_code, error, fechas; índices (terminal_id, status, id) y (created_at); recogida con UPDATE condicional por caja en orden de id, como la cola de salida;
  - limitador de intentos para emparejar y claves inválidas;
  - generación de etiquetas ESC/POS y vista previa (imagen);
  - pantalla «Etiquetas» y botón en la ficha; Ajustes → Cajas con emparejar, estado, impresoras, historial y versión.
- **Agente (Rust, `agent/`):**
  - crates previstos: `windows-service`, `windows` (impresión), `tray-icon` (Windows), `ksni` (Linux, fase 2), `interprocess`, `ureq` con `rustls`, `ed25519-dalek`, `sha2`, `tracing` y `tracing-appender`, `clap`, `winresource`;
  - compilación cruzada a Windows (`x86_64-pc-windows-gnu`) desde Linux, sin DLL externas;
  - instalador NSIS (`makensis`); `.deb` con `cargo-deb` (fase 2).
- **Pruebas:** el sistema operativo detrás de interfaces (impresora, reloj, red) para probar en Linux; GitHub Actions `windows-latest`: instalación silenciosa, `sc query`, y bytes exactos sobre una impresora «Generic / Text Only» con salida a fichero.

### API del agente (v1, Bearer, sin sesión)

| Método | Ruta | Petición | Respuesta | Códigos |
|---|---|---|---|---|
| POST | `/api/agent/v1/pair` | `{code, hostname, os, version}` | `{key, terminal_id, terminal_name}` | 201; 422 `pairing_code_invalid`; 429 |
| GET | `/api/agent/v1/jobs?wait=0..20` | — | `{jobs:[{id, type, target, payload_b64, sha256, claim_token, expires_at}], poll_after_ms, server_time_ms}` | 200; 401 `agent_key_invalid`; 426 `agent_update_required`; 429 |
| POST | `/api/agent/v1/jobs/{id}/result` | `{claim_token, status: done\|failed, error_code?, error?}` | `{status}` | 200 (también repetido); 404; 409 `claim_stale`; 410 `job_expired` |
| POST | `/api/agent/v1/jobs/{id}/extend` | `{claim_token}` | `{lease_until}` | 200; 409 |
| PUT | `/api/agent/v1/status` | `{version, hostname, printers:[{target, name, state, detail}]}` | — | 204; 401 |
| GET | `/api/agent/v1/update?os=&arch=&version=` | — | `{manifest, signature}` | 200; 204 sin novedad |

### API del TPV (sesión; las marcadas solo administrador)

| Método | Ruta | Respuesta | Códigos |
|---|---|---|---|
| GET | `/api/print-jobs/{id}` | `{status, error}` | 200; 404 |
| GET | `/api/print-jobs?terminal_id&status&cursor&limit≤50` | `{items, next_cursor}` | 200 |
| GET | `/api/terminals/{id}/print-agent` | `{connected, last_seen, last_ip, version, printers, update_available}` | 200 |
| POST | `/api/terminals/{id}/print-agent/pairing-code` (admin) | `{code, expires_at}` | 201; 403 |
| DELETE | `/api/terminals/{id}/print-agent` (admin) | — | 204; 403 |

## 7. Puesta en marcha

1. Instalar el agente en el ordenador de la caja A con el TPV todavía en local y probarlo.
2. Cambiar el tipo de impresora de la caja A a «Agente de impresión», manteniendo CUPS como alternativa una semana.
3. Desplegar en el hosting (requiere acceso y permiso) con `pm.max_children` ≥ número de cajas + 6 y `max_execution_time` ≥ 35 s.
4. Guía para la dueña: instalación (aviso de Windows y antivirus) y una hoja «No imprime: qué hago».

## 8. Criterios de aceptación

- **AC1.** Con el agente de Linux en esta máquina en modo directo USB: una venta en efectivo imprime el ticket en la TM-T20 y abre el cajón en menos de 2 s.
- **AC2.** Con el agente parado: la venta se cobra, a los 15 s se ofrece el PDF y el aviso del cajón aparece a los 5 s. Si el agente arranca al minuto, el ticket se imprime y el cajón no se abre; si arranca a los 3 minutos, no se imprime nada.
- **AC3.** Emparejar con un código caducado, usado o tras 20 fallos en 10 minutos falla; con uno válido funciona una sola vez. Desconectar en Ajustes corta el agente en la siguiente petición.
- **AC4.** Un agente de la caja A no puede ver ni confirmar trabajos de la caja B.
- **AC5.** Imprimir 10 etiquetas de una camiseta M azul desde la ficha: salen en una tira con un solo corte (11 serían dos tiras), con su EAN (o la referencia en Code128) y el precio con IVA; la pistola de la tienda las lee.
- **AC6.** Etiquetas ESC/POS comprobadas con ficheros de referencia exactos en las pruebas. (Fase 2: ZPL y TSPL igual, y ZPL en un visor.)
- **AC7.** Fase 1: con la cola de Windows o CUPS en pausa o sin impresora, Ajustes muestra el problema. Fase 2: sin papel en la TM-T20 en modo directo USB, Ajustes dice «Impresora sin papel» y el icono se pone rojo.
- **AC8.** Fase 2: una actualización con firma inválida o versión anterior se rechaza; una válida se instala solo con la caja cerrada; si no arranca, vuelve a la anterior.
- **AC9.** El instalador de Windows deja el servicio en marcha y el icono visible (en GitHub Actions el servicio; el icono lo comprueba el usuario).
- **AC10.** Cortar la red en mitad de una venta y reconectar: un solo ticket.
- **AC11.** Reiniciar Windows: el agente vuelve a conectar solo y no repite nada.
- **AC12.** Emparejar: la cuenta atrás corre, la confirmación aparece al reemparejar y el estado pasa a «Conectado» sin recargar.
- **AC13.** El historial muestra fecha, caja, tipo y estado, filtra por caja y estado, y borra lo de más de 30 días.
- **AC14.** Un lote de 200 etiquetas se interrumpe por una venta: el ticket sale primero.
- **AC15.** Pantalla Etiquetas: escanear 3 productos, cambiar cantidades, imprimir; la lista se vacía.
- **AC16.** Renombrar la impresora en Windows: Ajustes muestra «No encontrada».

## 9. Riesgos

- **Seguridad (alto):** clave del agente, emparejamiento, límites de intentos y, en fase 2, actualizaciones firmadas. Requiere verificación independiente.
- **Windows sin máquina propia:** se cubre con GitHub Actions; servicio en sesión 0 e icono por usuario se prueban por separado; el icono lo revisa el usuario.
- **Estado de la impresora:** a través de la cola de Windows o CUPS no se puede leer «sin papel»; solo en modo directo (fase 2).
- **Hosting:** cada agente conectado ocupa un proceso PHP mientras espera; revisar `pm.max_children` y los tiempos del proxy al desplegar.
- **Papel adhesivo en la TM-T20:** Epson no lo lista para este modelo; comprobar el grosor admitido y que el pegamento no ensucie la cuchilla.

## 10. Puntos abiertos

- Dirección final del subdominio y despliegue en el hosting (requiere acceso y permiso).
- Comprar papel térmico adhesivo continuo de 80 mm compatible con la TM-T20, o una impresora de etiquetas de 203 ppp con ZPL o TSPL y detección de separación.
