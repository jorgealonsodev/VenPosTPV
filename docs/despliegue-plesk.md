# Instalar el TPV en Plesk

El paquete `venpostpv-hosting.zip` lleva el TPV con sus dependencias y un instalador web. Se genera con `bin/build-plesk.sh`, que deja también el módulo actualizado en `dist/tpvconnect.zip`.

## Antes de empezar

- Un subdominio (por ejemplo `tpv.tutienda.com`) con certificado SSL. En Plesk: **Certificados SSL/TLS → Let's Encrypt**.
- **PHP 8.4 o superior** en el subdominio, con `pdo_mysql`.
- Una **base de datos MySQL vacía** solo para el TPV (**Bases de datos → Añadir base de datos**). No uses la de PrestaShop: el instalador se niega a usar una base de datos con tablas que no son del TPV.
- En PrestaShop, el módulo **TPV Connect 0.8.0 o superior**, instalado o actualizado con `tpvconnect.zip`. No hay que copiar ninguna clave: el instalador del TPV se empareja con el módulo con un código.

## Pasos

1. Sube `venpostpv-hosting.zip` a la carpeta del subdominio con el **Administrador de archivos**, extráelo ahí mismo y borra el zip. No hace falta cambiar la raíz del documento: el `.htaccess` del paquete sirve la aplicación desde dentro y deja fuera de la web `.env`, `vendor/` y `src/`.
2. (Opcional) Si prefieres, la raíz del documento también puede ser la carpeta `public` del paquete.
3. **Configuración de PHP** del subdominio: versión 8.4 o superior, `max_execution_time` = 35 como mínimo y `pm.max_children` ≥ número de cajas + 6.
4. Abre `https://tu-subdominio/install.php`. La primera visita crea el archivo `INSTALL_KEY` en la carpeta del subdominio. Ábrelo en el Administrador de archivos y copia la clave en el formulario.
5. Rellena la base de datos, el usuario administrador y la tienda. En **PrestaShop** escribe la **Dirección de la tienda** (`https://tutienda.com`) y el **Código de emparejamiento**: en el panel de PrestaShop, **Módulos → Gestor de módulos → TPV Connect → Configurar → Emparejar con un TPV** (6 cifras, vale 10 minutos y una sola vez). Pulsa **Instalar**: el instalador prueba la base de datos, se empareja con la tienda (le da la dirección del TPV y recibe sus claves), crea las tablas, escribe `.env`, prepara el resto de la instalación (por ejemplo, los productos genéricos del artículo libre en PrestaShop), se bloquea y borra `install.php`. La página final muestra en «Estado de la instalación» qué pasos están hechos y cuáles siguen pendientes y por qué. Un paso pendiente lo completa sola la tarea `tpv:cron` de cada minuto (con `tpv:sync:outbox`) en cuanto la tienda responde (ver [Actualizar el TPV](#actualizar-el-tpv)). Qué se intercambia, los mensajes de error y cómo volver a emparejar: [Emparejar el TPV con la tienda](despliegue-hosting.md#emparejar-el-tpv-con-la-tienda). Sin emparejar, el enlace **Introducir las claves a mano** pide las claves como antes.
6. Crea la **tarea programada**: una sola, que se ejecuta cada minuto. En Plesk, dentro del dominio: **Tareas programadas → Añadir tarea**. Se abre la pantalla «Programación de una tarea»: rellénala como indica la tabla y pulsa **Aceptar**.

   Dos avisos:
   - El tipo de tarea tiene que ser **Ejecutar un script PHP**. «Ejecutar un comando» falla en muchos Plesk porque se ejecuta en un entorno aislado donde no existe `/opt/plesk/php`.
   - Cambia siempre **Usar la versión PHP** a la del subdominio (8.4 o superior; en loading.es, 8.5). Por defecto sale **5.3** y con ella la tarea falla con «Unsupported declare strict_types».

   **Tarea `tpv:cron` — Tareas del TPV.** Cada minuto lanza lo que toca en ese momento:

   | Cuándo | Qué hace |
   |---|---|
   | Cada minuto | Sube las ventas, devoluciones y clientes nuevos a PrestaShop, limpia el historial de impresión y completa los pasos pendientes de la instalación o de una actualización (`tpv:sync:outbox`, ver [Actualizar el TPV](#actualizar-el-tpv)). Actualiza el stock desde PrestaShop (`tpv:catalog:sync --stock-only`). |
   | Cada noche, desde las 03:00 | Catálogo completo: productos, categorías, precios, fotos y colores de las tallas/colores (`tpv:catalog:sync --full`). |
   | Cada noche, desde las 03:30 | Copia los clientes de PrestaShop al TPV (`tpv:customers:sync`). |
   | Cada noche, desde las 04:00 | Guarda una copia de la base de datos del TPV en la carpeta `backups/` y conserva las 7 más recientes (`tpv:backup:database`, ver [Copias de seguridad y restauración](#copias-de-seguridad-y-restauración)). |

   | Campo en Plesk | Qué poner |
   |---|---|
   | Activa | Marcado |
   | Tipo de tarea | **Ejecutar un script PHP** |
   | Ruta de acceso del script | `tpv.clubnatacion.com/bin/console` (la carpeta del subdominio + `/bin/console`) |
   | con argumentos (a la derecha de la ruta) | `tpv:cron --env=prod` |
   | Usar la versión PHP | **8.5** (la del subdominio; nunca la 5.3 que sale por defecto) |
   | Ejecutar | elige **Estilo cron** en el desplegable y escribe `* * * * *` (cada minuto) |
   | Descripción | Tareas del TPV |
   | Notificar | **Solo errores** |

   Las horas son las de la tienda. Un trabajo de la noche que todavía no se ha hecho nunca (instalación nueva) o que perdió su hora se hace en el minuto siguiente: al crear la tarea, el catálogo se carga solo en unos minutos, sin ejecutarlo a mano. Si un trabajo de la noche falla, se vuelve a intentar a los 30 minutos, hasta 3 veces esa noche. Si un trabajo largo sigue en marcha, ese minuto se salta y los demás se hacen igual.

   Comprueba la tarea con **Ejecutar ahora**, en la misma pantalla: el resultado aparece al momento y, si hay un error, Plesk lo muestra (y lo envía por correo con **Solo errores**). En el TPV, **Ajustes → Acerca de → Estado de la instalación** muestra si la tarea está funcionando y cómo acabó cada trabajo la última vez, con el error si lo hubo.
7. Si emparejaste en el paso 5, no hay nada que hacer en el módulo: ya tiene la dirección del TPV. Si usaste «Introducir las claves a mano», empareja ahora desde el TPV: **Ajustes → Acerca de → Volver a emparejar con la tienda** con un código nuevo del módulo.
8. Entra en el TPV, empareja el agente de impresión de cada caja (**Ajustes → Cajas**, ver `docs/print-agent.md`) y haz una venta de prueba.

## Qué escribe el instalador

`.env` con `APP_ENV=prod`, secretos nuevos (`APP_SECRET`, `JWT_SECRET`), `TRUSTED_PROXIES=127.0.0.1,::1` (nginx delante de Apache) y los datos del formulario. El archivo queda con permisos 0600 y se puede editar después a mano.

`TPV_URL` debe ser la dirección pública del TPV tal como la abre el navegador (`https://tpv.tutienda.com`, con `https`). La API solo acepta las peticiones que modifican datos si vienen de esa dirección o del mismo host; si no coincide, la venta, el cierre de caja o el cierre de sesión responden 403 «Origin not allowed». Las páginas del TPV envían además una política de seguridad de contenidos (CSP) que no permite mostrarlas dentro de un marco de otra web.

## Copias de seguridad y restauración

Cada noche, desde las 04:00, la tarea `tpv:cron` lanza `tpv:backup:database`, que guarda una copia completa de la base de datos del TPV en la carpeta `backups/` del subdominio (junto a `bin/`, `src/`…), con el nombre `venpostpv-AAAAMMDD-HHMMSS.sql.gz` (SQL comprimido con gzip). Al terminar bien deja solo las **7 más recientes**; las demás se borran. Si una copia falla, no se borra ninguna y Plesk envía el error por correo. `tpv:cron` conserva siempre esas 7; la opción `--keep=N` (otro número de copias) solo se aplica al ejecutar `tpv:backup:database` aparte. Las copias que se hacen antes de aplicar migraciones (`venpostpv-premigrate-…sql.gz`, ver «Actualizar el TPV») van aparte: no cuentan en esas 7 y se guardan las 3 últimas.

Antes de cada copia la tarea **comprueba la base de datos**: `CHECK TABLE` en todas las tablas y la verificación de la cadena de registros fiscales (la misma de Ajustes → VERI*FACTU). Si todo está bien, escribe una línea «Database checks passed» y la de la copia. Si encuentra un problema, la copia se guarda igualmente, pero con el nombre `venpostpv-AAAAMMDD-HHMMSS-REVISAR.sql.gz`; ese día **no se borra ninguna copia anterior** (pueden ser las últimas buenas), la tarea muestra cada problema (la tabla y el mensaje, o el motivo y el número del registro fiscal que rompe la cadena) y termina con error, así que Plesk lo envía por correo. Las copias `-REVISAR` cuentan dentro de las 7 cuando la limpieza vuelve a ejecutarse.

**Aviso por email.** En el TPV, **Ajustes → Email**, el campo «Avisar de copias de seguridad fallidas a:» recibe un correo, enviado desde la cuenta de la tienda, cuando la copia falla («VenPosTPV: copia de seguridad fallida») o hay que revisarla («VenPosTPV: revisar copia de seguridad»), con la fecha, el archivo y los problemas. Vacío, no se envía nada; cuando la copia sale bien tampoco. Si el aviso no se puede enviar (cuenta sin configurar, servidor que lo rechaza), la tarea lo añade como una línea más de error, sin cambiar la copia.

- La carpeta no se puede descargar desde la web: está fuera de `public/`, solo la puede leer el usuario de la suscripción (permisos 0700, archivos 0600) y lleva un `.htaccess` que lo deniega todo. La crea la propia tarea la primera vez; el paquete de instalación no la incluye.
- Las copias están en el mismo servidor que la base de datos. **Descarga de vez en cuando una copia** con el Administrador de archivos (carpeta `backups`, botón de descarga) y guárdala fuera del servidor.
- Guarda también una copia del archivo **`.env`**: tiene los secretos de la instalación y la contraseña del correo va cifrada con `APP_SECRET`. Sin ese `.env`, la base de datos restaurada no puede descifrarla.

Para **restaurar** una copia:

1. Descomprime el `.sql.gz` si la herramienta no acepta el archivo comprimido (Plesk y phpMyAdmin aceptan `.gz`).
2. En Plesk: **Bases de datos →** la base de datos del TPV **→ Importar volcado** y elige el archivo. También sirve phpMyAdmin (**Importar**) o, con acceso por consola, `gunzip -c copia.sql.gz | mysql base_de_datos`.
3. La copia borra y vuelve a crear cada tabla, así que se puede importar sobre la base de datos existente. Recrea también los disparadores que impiden modificar o borrar los registros fiscales; con un solo comando cada uno, sin `DELIMITER`, se importan con cualquier herramienta.

Aviso fiscal: restaurar una copia antigua **pierde las ventas y los registros fiscales posteriores** a esa copia. La numeración de los tickets sigue desde los contadores de la copia restaurada, así que usa siempre la copia más reciente. No instales nunca el TPV sobre una base de datos vacía para seguir vendiendo sin ajustar antes la numeración.

**Continuar la numeración.** Si se pierde la base de datos (o la copia restaurada es anterior a los últimos tickets), antes de la primera venta abre **Ajustes → Cajas**, edita cada caja y en «Próximo número de ticket» pulsa **Cambiar**: escribe el número siguiente al último ticket emitido con esa letra y el motivo. Haz lo mismo en «Abonos: próximo número» con el último abono (R2026-…). La numeración solo puede avanzar, nunca volver a un número ya emitido, y cada cambio queda registrado en «Cambios de numeración» con la fecha, el motivo y el usuario. Si no sabes cuál fue el último número, cambia la letra de todas las cajas: empiezan una serie nueva sin repetir números.

## Actualizar el TPV

1. Si puedes, hazlo fuera del horario de venta. Sube el nuevo `venpostpv-hosting.zip` a la carpeta del subdominio y extráelo **encima**, sustituyendo los archivos. No borres `.env`, `var/install.lock` ni la carpeta `backups/`.
2. Borra la carpeta `var/cache/prod` (o ejecuta `php bin/console cache:clear --env=prod`), para que la aplicación use el código nuevo.
3. Ya está: la tarea `tpv:cron` de cada minuto (con `tpv:sync:outbox`) aplica en su siguiente ejecución todo lo que la nueva versión necesita, en orden, y solo cuando falta algo:
   - **las migraciones de la base de datos**, siempre después de una copia de seguridad automática `backups/venpostpv-premigrate-<fecha>-<hora>.sql.gz` (aparte de las 7 copias diarias, que nunca la borran; se guardan las 3 últimas). Si la copia no se puede escribir, **no migra** y lo reintenta cada 15 minutos. Dos ejecuciones nunca migran a la vez;
   - **lo que haya que preparar en PrestaShop** (por ejemplo, los productos genéricos del artículo libre). Si la tienda no responde, lo reintenta cada 15 minutos.

   Para no esperar al minuto siguiente, o para ver el detalle, ejecuta `php bin/console tpv:provision --env=prod` (en Plesk, una tarea «Ejecutar un script PHP» con esos argumentos y **Ejecutar ahora**). Con `--status` solo informa.
4. Comprueba en el TPV **Ajustes → Acerca de → Estado de la instalación** que todos los pasos dicen «Hecho». Un paso «Pendiente» muestra debajo el motivo y qué hacer.

**Si tu instalación tiene las cinco tareas de antes** (`tpv:sync:outbox`, `tpv:catalog:sync --stock-only`, `tpv:catalog:sync --full`, `tpv:customers:sync` y `tpv:backup:database`): siguen funcionando igual, pero ahora basta una. Crea la tarea `tpv:cron` como en el paso 6 de la instalación y después borra las cinco antiguas. No las dejes todas a la vez: el trabajo se hace dos veces y cada noche salen dos copias de seguridad, así que las 7 que se guardan cubren solo 3 o 4 días. **Si tu tarea de copia lleva `--keep=N` para guardar más de 7 copias**, ten en cuenta que la copia que lanza `tpv:cron` deja cada noche solo las 7 más recientes y borra las demás, aunque mantengas también tu tarea `tpv:backup:database --keep=N`: desde la primera noche se pierden las copias más antiguas. Si necesitas más de 7, sigue con tus cinco tareas de antes (con tu `--keep=N`) en lugar de crear `tpv:cron`, o guarda antes en otro sitio las copias que quieras conservar.

**Si las migraciones quedan «Bloqueado».** Si una migración falla, o la comprobación de la base de datos de la copia previa encuentra problemas (copia `…-REVISAR.sql.gz`), el paso queda bloqueado: la tarea de cada minuto **deja de intentarlo** (no hace más copias ni vuelve a migrar) y Ajustes y `php bin/console tpv:provision --status --env=prod` muestran «Bloqueado»/`blocked` con el motivo y el nombre de la copia de antes. MySQL no deshace los cambios de estructura, así que una migración que falló a medias deja la base de datos a medio cambiar y repetirla tal cual vuelve a fallar. Para salir:

   1. Restaura la copia que nombra el motivo (`backups/venpostpv-premigrate-….sql.gz`, ver [Copias de seguridad y restauración](#copias-de-seguridad-y-restauración)) o corrige la base de datos a mano. Con un aviso de comprobación (`-REVISAR`), repara antes las tablas que indica.
   2. Ejecuta `php bin/console tpv:provision --env=prod`: vuelve a intentarlo una vez, con una copia previa nueva. Si vuelve a fallar, queda bloqueado otra vez con el nuevo motivo.

   Subir una versión nueva con otras migraciones pendientes también hace que la tarea de cada minuto lo intente de nuevo.

Las nuevas funcionalidades que necesitan preparar algo añaden su paso a esta misma lista (ver `docs/provisioning.md`), así que una actualización nunca requiere órdenes manuales aparte.

## Reinstalar

Borra `.env` y `var/install.lock`, vuelve a subir `public/install.php` y abre de nuevo el instalador. Si la base de datos ya es del TPV, solo aplica las migraciones que falten.

Si reinstalas sobre una base de datos nueva para seguir con la misma tienda, o instalas el TPV para **otra tienda** que ya emitía tickets, ajusta antes de la primera venta dónde continúa cada serie (ver [Continuar la numeración](#copias-de-seguridad-y-restauración)): en **Ajustes → Cajas**, el próximo número de ticket de cada caja y el de los abonos, o usa una letra de caja distinta de las que ya se usaron.

## Líneas de venta de las ventas anteriores

Desde la migración `Version00000046SaleLine`, cada venta guarda sus líneas con el tipo de IVA, la base y la cuota (tabla `sale_line`), que usan los informes de ventas y la factura completa. Las ventas anteriores a esa versión se rellenan **una vez**, después de actualizar, con una tarea programada de Plesk lanzada a mano:

| Campo en Plesk | Qué poner |
|---|---|
| Tipo de tarea | **Ejecutar un script PHP** |
| Ruta de acceso del script | `tpv.clubnatacion.com/bin/console` |
| con argumentos | `tpv:sales:backfill-lines --env=prod` |
| Usar la versión PHP | **8.5** |
| Ejecutar | cualquiera (se lanza con **Ejecutar ahora** y después se puede borrar la tarea) |

La salida dice cuántas ventas y líneas se han creado: `Sales processed: N, lines created: N, approximate lines: N`. La categoría, la referencia y el tipo de IVA de esas ventas salen del catálogo actual, así que los informes las marcan como aproximadas. Se puede parar y volver a lanzar: las ventas que ya tienen líneas no se tocan. Si alguna venta tiene las líneas ilegibles, se lista por su número interno (`order_meta.id`) y la tarea termina con error.
