# Instalar el TPV en un hosting

Sirve para cualquier hosting con panel (cPanel, DirectAdmin y otros) que permita un subdominio con PHP 8.4, una base de datos MySQL y tareas programadas (cron). Los nombres de los menús cambian de un panel a otro.

El paquete `venpostpv-hosting.zip` lleva el TPV con sus dependencias y un instalador web. Se descarga de la release de GitHub, junto con el módulo `tpvconnect.zip` y el instalador del agente de impresión (ver «Publicar una versión» al final). También se genera a mano con `bin/build-hosting.sh`, que deja los dos zips en `dist/`.

## Antes de empezar

- Un subdominio (por ejemplo `tpv.tutienda.com`) con certificado SSL (la mayoría de los paneles lo emiten gratis con Let's Encrypt).
- **PHP 8.4 o superior** en el subdominio, con `pdo_mysql`.
- Una **base de datos MySQL vacía** solo para el TPV (en el apartado de bases de datos del panel). No uses la de PrestaShop: el instalador se niega a usar una base de datos con tablas que no son del TPV.
- En PrestaShop, el módulo **TPV Connect** instalado o actualizado con `tpvconnect.zip`. De él salen la clave del webservice («Generar clave») y el secreto SSO.

## Pasos

1. Sube `venpostpv-hosting.zip` a la carpeta del subdominio con el **administrador de archivos** del panel (o por FTP), extráelo ahí mismo y borra el zip. No hace falta cambiar la raíz del documento: el `.htaccess` del paquete sirve la aplicación desde dentro y deja fuera de la web `.env`, `vendor/` y `src/`.
2. (Opcional) Si prefieres, la raíz del documento también puede ser la carpeta `public` del paquete.
3. **Configuración de PHP** del subdominio en el panel: versión 8.4 o superior, `max_execution_time` = 35 como mínimo y `pm.max_children` ≥ número de cajas + 6.
4. Abre `https://tu-subdominio/install.php`. La primera visita crea el archivo `INSTALL_KEY` en la carpeta del subdominio. Ábrelo en el administrador de archivos y copia la clave en el formulario.
5. Rellena la base de datos, el usuario administrador, la tienda y PrestaShop. Pulsa **Instalar**: el instalador prueba la conexión, crea las tablas, escribe `.env`, prepara el resto de la instalación (por ejemplo, los productos genéricos del artículo libre en PrestaShop), se bloquea y borra `install.php`. La página final muestra en «Estado de la instalación» qué pasos están hechos y cuáles siguen pendientes y por qué. Es normal que los de PrestaShop queden pendientes hasta configurar el módulo TPV Connect (paso 7): la tarea `tpv:sync:outbox` de cada minuto los completa sola en cuanto la tienda responde (ver [Actualizar el TPV](#actualizar-el-tpv)).
6. Crea las tareas programadas que muestra la página final (en Plesk, paso a paso: `docs/despliegue-plesk.md`) en el apartado de tareas programadas (cron) del panel. Si el panel ofrece ejecutar un script PHP, usa la ruta `bin/console` del TPV, la versión de PHP del subdominio y la orden como argumentos; si solo admite un comando, usa la línea completa que muestra la página (`php <ruta>/bin/console <orden> --env=prod`; en algunos paneles el binario se llama `php8.4` o tiene una ruta propia):
   - cada minuto: `tpv:sync:outbox` (sube las ventas a PrestaShop, limpia el historial de impresión y completa los pasos pendientes de la instalación o de una actualización) y `tpv:catalog:sync --stock-only`;
   - cada noche: `tpv:catalog:sync --full` y `tpv:customers:sync`. Ejecuta el catálogo completo una vez al terminar (o ejecútalo a mano desde el panel).
7. En el módulo TPV Connect, pon como URL del TPV la del subdominio. Si no pusiste el secreto SSO en el instalador, cópialo en `SSO_SHARED_SECRET` del `.env`.
8. Entra en el TPV, empareja el agente de impresión de cada caja (**Ajustes → Cajas**, ver `docs/print-agent.md`) y haz una venta de prueba.

## Qué escribe el instalador

`.env` con `APP_ENV=prod`, secretos nuevos (`APP_SECRET`, `JWT_SECRET`), `TRUSTED_PROXIES=127.0.0.1,::1` (para los paneles que ponen nginx delante de Apache) y los datos del formulario. El archivo queda con permisos 0600 y se puede editar después a mano.

`TPV_URL` debe ser la dirección pública del TPV tal como la abre el navegador (`https://tpv.tutienda.com`, con `https`). La API solo acepta las peticiones que modifican datos si vienen de esa dirección o del mismo host; si no coincide, la venta, el cierre de caja o el cierre de sesión responden 403 «Origin not allowed». Las páginas del TPV envían además una política de seguridad de contenidos (CSP) que no permite mostrarlas dentro de un marco de otra web.

## Actualizar el TPV

1. Si puedes, hazlo fuera del horario de venta. Sube el nuevo `venpostpv-hosting.zip` a la carpeta del subdominio y extráelo **encima**, sustituyendo los archivos. No borres `.env`, `var/install.lock` ni la carpeta `backups/`.
2. Borra la carpeta `var/cache/prod` (o ejecuta `php bin/console cache:clear --env=prod`), para que la aplicación use el código nuevo.
3. Ya está: la tarea `tpv:sync:outbox` de cada minuto aplica en su siguiente ejecución todo lo que la nueva versión necesita, en orden, y solo cuando falta algo:
   - **las migraciones de la base de datos**, siempre después de una copia de seguridad automática `backups/venpostpv-premigrate-<fecha>-<hora>.sql.gz` (aparte de las 7 copias diarias, que nunca la borran; se guardan las 3 últimas). Si la copia no se puede escribir, **no migra** y lo reintenta cada 15 minutos. Dos ejecuciones nunca migran a la vez;
   - **lo que haya que preparar en PrestaShop** (por ejemplo, los productos genéricos del artículo libre). Si la tienda no responde, lo reintenta cada 15 minutos.

   Para no esperar al minuto siguiente, o para ver el detalle, ejecuta `php bin/console tpv:provision --env=prod` (en Plesk, una tarea «Ejecutar un script PHP» con esos argumentos y **Ejecutar ahora**). Con `--status` solo informa.
4. Comprueba en el TPV **Ajustes → Acerca de → Estado de la instalación** que todos los pasos dicen «Hecho». Un paso «Pendiente» muestra debajo el motivo y qué hacer.

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
