# PRD — Agente de impresión de la tienda

- **Estado:** borrador para revisión
- **Fecha:** 2026-11-03
- **Relación:** necesario para desplegar el TPV en un subdominio de Plesk (ver `docs/prd/venta-local-primero.md`)

## 1. Objetivo

Que el TPV alojado en Plesk imprima tickets y etiquetas, y abra el cajón, en las impresoras de la tienda, igual que hoy con el servidor local: el ticket sale solo al cobrar y el cajón se abre con efectivo.

Para ello, un programa pequeño (el **agente**) se instala en el ordenador de cada caja. Se conecta al TPV por HTTPS hacia fuera, sin abrir puertos en la tienda, recoge los trabajos de su caja y los envía a sus impresoras.

## 2. Situación actual

- El servidor del TPV imprime él mismo: por CUPS (impresora USB enchufada al servidor) o por IP (impresora de red). El cajón se abre enviando `ESC p` a la impresora de tickets.
- En Plesk el servidor no ve las impresoras de la tienda. Sin agente, solo quedaría el PDF.
- No existe impresión de etiquetas.

## 3. Decisiones del usuario

| Tema | Decisión |
|---|---|
| Alcance | **Tickets, cajón y etiquetas.** |
| Cajas por agente | **Un agente por caja.** |
| Conexión | **Escucha larga HTTPS**: el agente pregunta y el servidor responde en cuanto hay trabajo, o a los 25 s. |
| Sin agente al cobrar | **Cobra y ofrece el PDF.** La venta nunca se bloquea. |
| Etiquetas | **Precio + código de barras**: nombre, talla/color, precio con IVA, código y su texto. |
| Impresora de etiquetas | Hoy **la misma Epson TM-T20** (papel adhesivo). «Hazlo lo máximo compatible posible»: ESC/POS, ZPL (Zebra), TSPL (TSC y similares) e imagen para cualquier impresora con driver (Brother, Dymo…). |
| Origen de las etiquetas | **Desde la ficha del producto y desde una lista** (escanear o buscar varios productos e imprimirlos de golpe). |
| Tamaño | **Configurable con plantillas** (50×30, 40×25, ancho de ticket 80 mm) y medidas a mano. |
| Código de barras | **EAN13 si existe; si no, la referencia en Code128.** |
| Emparejar | **Código de 6 cifras** válido 10 minutos, generado en Ajustes → Cajas. |
| Instalación Windows | **Instalador con asistente** (setup.exe). |
| Instalación Linux | **Paquete .deb + script** para otras distribuciones. |
| Firma del instalador | **Sin firma de momento**; la guía explica el aviso de Windows. |
| Actualizaciones | **Automáticas desde el TPV**, con firma, **solo con la caja cerrada** y sin trabajos pendientes. |
| Interfaz | **Icono en la bandeja en Windows y en Linux.** |
| Trabajos pendientes al reconectar | **Caducan según tipo**: cajón 60 s, ticket 10 min, etiquetas no caducan. |
| Historial | **30 días**, visible en Ajustes. |
| Errores de impresora | **Avisar de «sin papel», «tapa abierta»… cuando la impresora lo permita**; si no, cuando falla un trabajo. |
| Cajón desde el icono | **No.** Solo desde el TPV, que deja constancia. |
| Reimpresión | **Sale por la caja que la pide.** |
| Cobrar sin internet en la tienda | **Fuera de este PRD**; irá en uno aparte. |
| Lenguaje del agente | **Rust**: un binario por sistema operativo. |

## 4. Reglas

### 4.1 Emparejar y seguridad
- **E1.** En Ajustes → Cajas, un administrador pulsa «Conectar agente de impresión» y el TPV muestra un código de 6 cifras válido 10 minutos y de un solo uso.
- **E2.** El agente envía el código; el servidor responde con una clave larga (256 bits) que el agente guarda protegida. El servidor solo guarda su huella (sha256).
- **E3.** Cada clave vale para una sola caja. Con ella el agente solo ve y confirma trabajos de su caja.
- **E4.** El administrador puede desconectar el agente desde Ajustes; la clave deja de valer al momento. Volver a emparejar revoca la anterior.
- **E5.** La clave nunca aparece en direcciones, registros ni pantallas. Fallos de autenticación repetidos se frenan.

### 4.2 Trabajos de impresión
- **T1.** Cuando una caja usa la impresora tipo **«Agente»**, todo lo que hoy se imprime o abre el cajón se convierte en un trabajo en la cola de esa caja: ticket de venta, ticket regalo, reimpresión, abono, prueba y apertura de cajón. El contenido es el mismo ESC/POS de hoy.
- **T2.** Tipos de trabajo: `ticket`, `drawer`, `label`. Cada trabajo va dirigido a un **destino** del agente: impresora de tickets o impresora de etiquetas.
- **T3.** El agente recoge hasta 10 trabajos por petición, los imprime en orden y confirma cada uno (hecho o fallido con el motivo). Un trabajo recogido y no confirmado en 60 s vuelve a la cola. Un fallido se reintenta hasta 3 veces.
- **T4.** Caducidad: apertura de cajón a los 60 s, ticket a los 10 min, etiquetas nunca. Un trabajo caducado no se imprime.
- **T5.** El agente nunca imprime dos veces el mismo trabajo, aunque se reconecte.
- **T6.** La reimpresión sale por la impresora de la caja que la pide.
- **T7.** El cajón solo se abre desde el TPV (venta con efectivo o mixto, o el botón «Abrir cajón»), que registra quién y cuándo. El icono del agente no abre el cajón.

### 4.3 Cobro sin agente
- **C1.** Si nadie recoge el ticket en 15 s, la pantalla de cobro dice «La impresora no está conectada» y ofrece el PDF. La venta queda hecha.
- **C2.** Si el agente lleva más de 2 minutos sin conectar, la pantalla de venta muestra un aviso permanente hasta que vuelva.

### 4.4 Etiquetas
- **L1.** Desde la ficha de un producto: «Imprimir etiquetas», eligiendo talla/color y cantidad.
- **L2.** Pantalla «Etiquetas»: añadir productos escaneando o buscando, con cantidad por línea, e imprimir todo.
- **L3.** Contenido: nombre corto, combinación («M · Azul»), precio con IVA en grande («23,14 €»), código de barras y su texto.
- **L4.** Código: EAN13 del producto o de la combinación si existe y es válido; si no, la referencia en Code128.
- **L5.** Plantillas de tamaño: 50×30 mm, 40×25 mm y ancho de ticket (80 mm); medidas a mano en Ajustes.
- **L6.** El servidor genera la etiqueta según el lenguaje de la impresora de etiquetas de esa caja: ESC/POS, ZPL, TSPL o imagen (PNG a la resolución de la impresora, que el agente envía por el driver). Si la impresora de etiquetas es la misma de tickets, se usa ESC/POS.

### 4.5 Estado y errores
- **S1.** El agente informa en cada petición: versión, nombre del equipo, impresoras configuradas y su estado.
- **S2.** Cuando la impresora lo permite (Epson por USB, estado ESC/POS), el agente informa «sin papel», «poco papel», «tapa abierta» o «impresora desconectada». Si no se puede leer, solo informa de los trabajos fallidos.
- **S3.** Ajustes → Cajas muestra por caja: «Conectado · TM-T20 · v1.2» o «Sin conexión desde 17:05», el estado de cada impresora y los últimos errores.
- **S4.** Historial de trabajos de 30 días en Ajustes (fecha, caja, tipo, estado, error), con filtro por caja y estado. Lo más antiguo se borra solo.

### 4.6 Agente (programa)
- **A1.** Escrito en Rust. Un binario por sistema: Windows x64 y Linux x64.
- **A2.** Funciona como servicio: servicio de Windows y servicio systemd en Linux. Arranca solo con el ordenador.
- **A3.** Icono en la bandeja en Windows y en Linux (escritorios con bandeja). Colores: verde conectado, ámbar sin conexión, rojo impresora con error. Menú: «Imprimir prueba», «Ver registro», «Abrir Ajustes del TPV», «Acerca de». Cerrar el icono no para el servicio.
- **A4.** Impresión: en Windows a través del administrador de impresión en modo RAW; en Linux a través de CUPS en modo raw o directo al dispositivo USB.
- **A5.** Comandos: `pair <código>`, `status`, `list-printers`, `test-print`, `install`, `uninstall`, `run`, `version`.
- **A6.** Registro en fichero con rotación (5 MB × 3), sin datos sensibles.
- **A7.** Reconexión con espera creciente (1, 2, 5, 10, 30 s). Ante clave rechazada: «Vuelve a conectar el agente desde Ajustes → Cajas».

### 4.7 Instalación
- **I1.** Windows: setup.exe con asistente que pide la dirección del TPV y el código de 6 cifras, deja elegir la impresora de tickets y la de etiquetas de la lista de impresoras de Windows, hace una prueba e instala el servicio y el icono.
- **I2.** Linux: paquete `.deb` (Debian, Ubuntu, Mint) que instala el servicio, y script de instalación para otras distribuciones. La configuración se hace con `pair` y `list-printers` o desde el icono.
- **I3.** El instalador de Windows no va firmado de momento; la guía explica «Más información → Ejecutar de todas formas».

### 4.8 Actualizaciones
- **U1.** El TPV publica la última versión del agente para cada sistema en el propio subdominio, con su firma (Ed25519).
- **U2.** El agente la descarga, comprueba la firma con la clave pública que lleva dentro y solo instala si es válida.
- **U3.** Solo se actualiza con la caja de su agente cerrada y sin trabajos pendientes. Si falla, vuelve a la versión anterior.
- **U4.** Ajustes → Cajas muestra la versión de cada agente y si está pendiente de actualizar.

## 5. Cambios técnicos previstos

- **TPV (PHP):**
  - tipo de impresora `agent`;
  - tablas `print_job`, `print_agent` y `agent_pairing` (migración V37);
  - API del agente `/api/agent/*` con clave Bearer (fuera de la sesión y del vínculo de equipo);
  - generación de etiquetas en ESC/POS, ZPL, TSPL e imagen;
  - pantalla «Etiquetas» y botón en la ficha de producto;
  - Ajustes → Cajas con emparejar, estado, impresoras, historial y versión;
  - publicación de versiones firmadas.
- **Agente (Rust, carpeta `agent/`):**
  - servicio, cliente HTTPS, impresión RAW en Windows y raw en Linux, lectura de estado USB, icono de bandeja, actualización firmada y registro;
  - instalador Windows (Inno Setup o WiX) y paquete `.deb`.
- **API del agente (resumen):**
  - `POST /api/agent/pair {code}` devuelve la clave;
  - `GET /api/agent/jobs?wait=25` devuelve los trabajos y registra el latido; la cabecera incluye versión, equipo, impresoras y su estado;
  - `POST /api/agent/jobs/{id}/done` y `POST /api/agent/jobs/{id}/failed {error}`;
  - `GET /api/agent/update?os=…&version=…` devuelve la versión disponible con su firma.

## 6. Criterios de aceptación

- **AC1.** Con el agente de Linux en esta máquina y el TPV apuntando a él: una venta en efectivo imprime el ticket en la TM-T20 y abre el cajón en menos de 2 s.
- **AC2.** Con el agente parado: la venta se cobra; a los 15 s se ofrece el PDF; al arrancar el agente pasados 10 min, el ticket no se imprime y el cajón no se abre.
- **AC3.** Emparejar con un código caducado o ya usado falla; con uno válido funciona una sola vez. Revocar en Ajustes corta el agente en la siguiente petición.
- **AC4.** Un agente con la clave de la caja A no puede ver ni confirmar trabajos de la caja B.
- **AC5.** Imprimir 10 etiquetas de una camiseta M azul desde la ficha: salen 10 etiquetas con su EAN (o la referencia en Code128 si no tiene) y el precio con IVA; el lector de códigos las lee.
- **AC6.** La misma etiqueta generada en ZPL y TSPL se ve correcta en un visor de esos lenguajes; en imagen, a la resolución pedida.
- **AC7.** Sin papel en la TM-T20: Ajustes muestra «Impresora sin papel» y el icono se pone rojo.
- **AC8.** Una actualización con firma inválida se rechaza. Una válida se instala solo con la caja cerrada.
- **AC9.** El instalador de Windows deja el servicio en marcha y el icono visible (comprobación en un Windows real, a cargo del usuario).

## 7. Riesgos

- **Seguridad (alto):** claves del agente, emparejamiento y actualizaciones firmadas. Requiere verificación independiente.
- **Windows sin máquina de pruebas:** el binario se compila y se prueba en Linux; el comportamiento real en Windows (servicio, bandeja, instalador, cola RAW) lo verifica el usuario.
- **Estado de la impresora por USB:** depende del modelo y del driver; en Windows con el driver de Epson puede no ser legible y quedará en «solo errores de trabajo».
- **Tiempo de respuesta en Plesk:** la escucha larga mantiene un proceso PHP ocupado por agente conectado. Con pocas cajas no importa; con muchas, revisar el límite de procesos de PHP-FPM.

## 8. Puntos abiertos

- Dirección final del subdominio y despliegue en Plesk (requiere acceso y permiso).
- Comprar papel adhesivo de 80 mm para la TM-T20, o una impresora de etiquetas dedicada más adelante.
