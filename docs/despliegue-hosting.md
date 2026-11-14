# Instalar el TPV en un hosting

Sirve para cualquier hosting con panel (cPanel, DirectAdmin y otros) que permita un subdominio con PHP 8.4, una base de datos MySQL y tareas programadas (cron). Los nombres de los menús cambian de un panel a otro.

El paquete `venpostpv-hosting.zip` lleva el TPV con sus dependencias y un instalador web. Se descarga de la release de GitHub, junto con el módulo `tpvconnect.zip` y el instalador del agente de impresión (ver «Publicar una versión» al final). También se genera a mano con `bin/build-hosting.sh`, que deja los dos zips en `dist/`.

## Antes de empezar

- Un subdominio (por ejemplo `tpv.tutienda.com`) con certificado SSL (la mayoría de los paneles lo emiten gratis con Let's Encrypt).
- **PHP 8.4 o superior** en el subdominio, con `pdo_mysql`.
- Una **base de datos MySQL vacía** solo para el TPV (en el apartado de bases de datos del panel). No uses la de PrestaShop: el instalador se niega a usar una base de datos con tablas que no son del TPV.
- En PrestaShop, el módulo **TPV Connect 0.8.0 o superior**, instalado o actualizado con `tpvconnect.zip`. No hay que copiar ninguna clave: el instalador del TPV se empareja con el módulo con un código (ver [Emparejar el TPV con la tienda](#emparejar-el-tpv-con-la-tienda)).

## Pasos

1. Sube `venpostpv-hosting.zip` a la carpeta del subdominio con el **administrador de archivos** del panel (o por FTP), extráelo ahí mismo y borra el zip. No hace falta cambiar la raíz del documento: el `.htaccess` del paquete sirve la aplicación desde dentro y deja fuera de la web `.env`, `vendor/` y `src/`.
2. (Opcional) Si prefieres, la raíz del documento también puede ser la carpeta `public` del paquete.
3. **Configuración de PHP** del subdominio en el panel: versión 8.4 o superior, `max_execution_time` = 35 como mínimo y `pm.max_children` ≥ número de cajas + 6.
4. Abre `https://tu-subdominio/install.php`. La primera visita crea el archivo `INSTALL_KEY` en la carpeta del subdominio. Ábrelo en el administrador de archivos y copia la clave en el formulario.
5. Rellena la base de datos, el usuario administrador y la tienda. En **PrestaShop** escribe la **Dirección de la tienda** (`https://tutienda.com`) y el **Código de emparejamiento**: en el panel de PrestaShop, **Módulos → Gestor de módulos → TPV Connect → Configurar → Emparejar con un TPV**. El código tiene 6 cifras, vale 10 minutos y una sola vez. Pulsa **Instalar**: el instalador prueba la base de datos, se empareja con la tienda (le da la dirección del TPV y recibe sus claves), crea las tablas, escribe `.env`, prepara el resto de la instalación (por ejemplo, los productos genéricos del artículo libre en PrestaShop), se bloquea y borra `install.php`. La página final muestra en «Estado de la instalación» qué pasos están hechos y cuáles siguen pendientes y por qué. Un paso pendiente lo completa sola la tarea `tpv:cron` de cada minuto (con `tpv:sync:outbox`) en cuanto la tienda responde (ver [Actualizar el TPV](#actualizar-el-tpv)).
6. Crea la tarea programada que muestra la página final (en Plesk, paso a paso: `docs/despliegue-plesk.md`) en el apartado de tareas programadas (cron) del panel. Es **una sola tarea, cada minuto** (`* * * * *`), y conviene que avise solo de los errores. Si el panel ofrece ejecutar un script PHP, usa la ruta `bin/console` del TPV, la versión de PHP del subdominio y `tpv:cron --env=prod` como argumentos; si solo admite un comando, usa la línea completa que muestra la página (`php <ruta>/bin/console tpv:cron --env=prod`; en algunos paneles el binario se llama `php8.4` o tiene una ruta propia). Cada minuto, `tpv:cron` lanza lo que toca (horas de la tienda):
   - cada minuto: `tpv:sync:outbox` (sube las ventas a PrestaShop, limpia el historial de impresión y completa los pasos pendientes de la instalación o de una actualización) y `tpv:catalog:sync --stock-only` (stock);
   - cada noche: `tpv:catalog:sync --full` desde las 03:00, `tpv:customers:sync` desde las 03:30 y `tpv:backup:database` (copia de la base de datos en `backups/`) desde las 04:00.

   Un trabajo de la noche que todavía no se ha hecho nunca (instalación nueva) o que perdió su hora se hace en el minuto siguiente, así que el catálogo se carga solo a los pocos minutos de crear la tarea. Si falla, se vuelve a intentar a los 30 minutos, hasta 3 veces esa noche. **Ajustes → Acerca de → Estado de la instalación** muestra si la tarea está funcionando y cómo acabó cada trabajo.
7. Si emparejaste en el paso 5, no hay nada que hacer en el módulo: ya tiene la dirección del TPV. Si usaste «Introducir las claves a mano», empareja ahora desde el TPV: **Ajustes → Acerca de → Volver a emparejar con la tienda** con un código nuevo del módulo.
8. Entra en el TPV, empareja el agente de impresión de cada caja (**Ajustes → Cajas**, ver `docs/print-agent.md`) y haz una venta de prueba.

## Emparejar el TPV con la tienda

El módulo TPV Connect y el TPV se intercambian solos las claves y las direcciones con un código de un solo uso:

1. En PrestaShop, **TPV Connect → Configurar → Emparejar con un TPV**. El módulo muestra un código de 6 cifras y la dirección de la tienda. Vale 10 minutos y una sola vez; pulsar otra vez el botón anula el anterior. Con 5 códigos equivocados se anula también.
2. En el instalador del TPV (o, con el TPV ya instalado, en **Ajustes → Acerca de → Volver a emparejar con la tienda**), escribe la dirección de la tienda y el código.

El TPV llama a `POST /module/tpvconnect/pair` de la tienda (solo por `https://`, salvo `localhost`). El módulo activa el webservice si estaba apagado, usa la clave del webservice del TPV si ya existe (no cambia su valor; le añade los permisos que le falten) o crea una, crea el secreto SSO solo si no había ninguno (nunca lo cambia), guarda la dirección del TPV y responde la clave, el secreto, el id de la tienda, la moneda y la zona horaria. El TPV los guarda en `.env` (`PS_SHOP_URL`, `PS_API_KEY`, `SSO_SHARED_SECRET`, `PS_DEFAULT_SHOP`, `PS_DEFAULT_CURRENCY`, `PS_SHOP_TIMEZONE`). El registro de PrestaShop (**Parámetros avanzados → Registros**) anota cada código generado y cada emparejamiento, sin el código ni las claves.

Si algo falla, el instalador lo dice y no escribe nada:

| Mensaje | Qué hacer |
|---|---|
| «El código de emparejamiento no es correcto o ha caducado» | Pulsa otra vez «Emparejar con un TPV» y escribe el código nuevo antes de 10 minutos. |
| «No se pudo conectar con la tienda» | Revisa la dirección de la tienda (con `https://`) y que la tienda esté en línea. |
| «La tienda no tiene el módulo TPV Connect 0.8.0 o superior» | Sube `tpvconnect.zip` en **Módulos → Gestor de módulos → Subir un módulo** (actualiza el que hay) y vuelve a intentarlo. |
| «La tienda redirige a …» | Escribe la dirección que dice el mensaje (por ejemplo, con `www`). |
| «La tienda está ocupada o ha recibido demasiados intentos» | Espera un minuto. |

**Volver a emparejar** (Ajustes → Acerca de, solo administradores) sirve para cambiar de tienda o cuando el TPV deja de conectar con ella. Reescribe en `.env` solo esas seis claves, de golpe (archivo temporal y cambio de nombre), y deja el anterior al lado como `.env.bak-<fecha>-<hora>` (permisos 0600; bórralo cuando compruebes que todo va bien). No muestra ninguna clave en el navegador. Se niega si alguna de esas claves está en `.env.local`, en `.env.local.php` o en una variable de entorno del servidor, porque el cambio no se aplicaría.

Sin emparejar, el enlace **Introducir las claves a mano** del instalador pide la clave del webservice («Generar clave API» en el módulo, apartado «Configuración manual»), el secreto SSO, los ids de moneda y tienda y la zona horaria, como antes.

## Qué escribe el instalador

`.env` con `APP_ENV=prod`, secretos nuevos (`APP_SECRET`, `JWT_SECRET`), `TRUSTED_PROXIES=127.0.0.1,::1` (para los paneles que ponen nginx delante de Apache) y los datos del formulario. El archivo queda con permisos 0600 y se puede editar después a mano.

`TPV_URL` debe ser la dirección pública del TPV tal como la abre el navegador (`https://tpv.tutienda.com`, con `https`). La API solo acepta las peticiones que modifican datos si vienen de esa dirección o del mismo host; si no coincide, la venta, el cierre de caja o el cierre de sesión responden 403 «Origin not allowed». Las páginas del TPV envían además una política de seguridad de contenidos (CSP) que no permite mostrarlas dentro de un marco de otra web.

## Actualizar el TPV

1. Si puedes, hazlo fuera del horario de venta. Sube el nuevo `venpostpv-hosting.zip` a la carpeta del subdominio y extráelo **encima**, sustituyendo los archivos. No borres `.env`, `var/install.lock` ni la carpeta `backups/`.
2. Borra la carpeta `var/cache/prod` (o ejecuta `php bin/console cache:clear --env=prod`), para que la aplicación use el código nuevo.
3. Ya está: la tarea `tpv:cron` de cada minuto (con `tpv:sync:outbox`) aplica en su siguiente ejecución todo lo que la nueva versión necesita, en orden, y solo cuando falta algo:
   - **las migraciones de la base de datos**, siempre después de una copia de seguridad automática `backups/venpostpv-premigrate-<fecha>-<hora>.sql.gz` (aparte de las 7 copias diarias, que nunca la borran; se guardan las 3 últimas). Si la copia no se puede escribir, **no migra** y lo reintenta cada 15 minutos. Dos ejecuciones nunca migran a la vez;
   - **lo que haya que preparar en PrestaShop** (por ejemplo, los productos genéricos del artículo libre). Si la tienda no responde, lo reintenta cada 15 minutos.

   Para no esperar al minuto siguiente, o para ver el detalle, ejecuta `php bin/console tpv:provision --env=prod` (en Plesk, una tarea «Ejecutar un script PHP» con esos argumentos y **Ejecutar ahora**). Con `--status` solo informa.
4. Comprueba en el TPV **Ajustes → Acerca de → Estado de la instalación** que todos los pasos dicen «Hecho». Un paso «Pendiente» muestra debajo el motivo y qué hacer.

**Si tu instalación tiene las cinco tareas de antes** (`tpv:sync:outbox`, `tpv:catalog:sync --stock-only`, `tpv:catalog:sync --full`, `tpv:customers:sync` y `tpv:backup:database`): siguen funcionando igual, pero ahora basta una. Crea la tarea `tpv:cron` como en el paso 6 de la instalación y después borra las cinco antiguas. Con las dos a la vez no se rompe nada, pero el trabajo se hace dos veces.

**Si las migraciones quedan «Bloqueado».** Si una migración falla, o la comprobación de la base de datos de la copia previa encuentra problemas (copia `…-REVISAR.sql.gz`), el paso queda bloqueado: la tarea de cada minuto **deja de intentarlo** (no hace más copias ni vuelve a migrar) y Ajustes y `php bin/console tpv:provision --status --env=prod` muestran «Bloqueado»/`blocked` con el motivo y el nombre de la copia de antes. MySQL no deshace los cambios de estructura, así que una migración que falló a medias deja la base de datos a medio cambiar y repetirla tal cual vuelve a fallar. Para salir:

   1. Restaura la copia que nombra el motivo (`backups/venpostpv-premigrate-….sql.gz`; cómo restaurar: «Copias de seguridad y restauración» en `docs/despliegue-plesk.md`) o corrige la base de datos a mano. Con un aviso de comprobación (`-REVISAR`), repara antes las tablas que indica.
   2. Ejecuta `php bin/console tpv:provision --env=prod`: vuelve a intentarlo una vez, con una copia previa nueva. Si vuelve a fallar, queda bloqueado otra vez con el nuevo motivo.

   Subir una versión nueva con otras migraciones pendientes también hace que la tarea de cada minuto lo intente de nuevo.

Las nuevas funcionalidades que necesitan preparar algo añaden su paso a esta misma lista (ver `docs/provisioning.md`), así que una actualización nunca requiere órdenes manuales aparte.

## Reinstalar

Borra `.env` y `var/install.lock`, vuelve a subir `public/install.php` y abre de nuevo el instalador. Si la base de datos ya es del TPV, solo aplica las migraciones que falten.

## Publicar una versión

Al subir un tag `vX.Y.Z` (o `vX.Y.Z-rc.N` para una versión de prueba), el workflow `.github/workflows/release.yml` pasa las pruebas del agente, genera los paquetes y publica una release de GitHub con:

- `VenPosAgent-<versión>-setup.exe`: instalador del agente de impresión para Windows;
- `venpostpv-hosting.zip`: el TPV para el hosting;
- `tpvconnect.zip`: el módulo de PrestaShop;
- `SHA256SUMS.txt`: sumas SHA-256 de los tres.

```sh
git tag v1.0.0
git push origin v1.0.0
```

Las notas de la release salen de la plantilla `.github/release-notes.md`, que enlaza la [guía de instalación](instalacion.md) de esa versión.

Lanzado a mano desde la pestaña Actions («Run workflow»), genera los paquetes como artefactos sin publicar nada. Mientras el repositorio sea privado, las descargas de la release necesitan una cuenta con acceso.
