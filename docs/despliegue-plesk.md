# Instalar el TPV en Plesk

El paquete `venpostpv-plesk.zip` lleva el TPV con sus dependencias y un instalador web. Se genera con `bin/build-plesk.sh`, que deja también el módulo actualizado en `dist/tpvconnect.zip`.

## Antes de empezar

- Un subdominio (por ejemplo `tpv.tutienda.com`) con certificado SSL. En Plesk: **Certificados SSL/TLS → Let's Encrypt**.
- **PHP 8.4 o superior** en el subdominio, con `pdo_mysql`.
- Una **base de datos MySQL vacía** solo para el TPV (**Bases de datos → Añadir base de datos**). No uses la de PrestaShop: el instalador se niega a usar una base de datos con tablas que no son del TPV.
- En PrestaShop, el módulo **TPV Connect** instalado o actualizado con `tpvconnect.zip`. De él salen la clave del webservice («Generar clave») y el secreto SSO.

## Pasos

1. Sube `venpostpv-plesk.zip` a la carpeta del subdominio con el **Administrador de archivos**, extráelo ahí mismo y borra el zip. No hace falta cambiar la raíz del documento: el `.htaccess` del paquete sirve la aplicación desde dentro y deja fuera de la web `.env`, `vendor/` y `src/`.
2. (Opcional) Si prefieres, la raíz del documento también puede ser la carpeta `public` del paquete.
3. **Configuración de PHP** del subdominio: versión 8.4 o superior, `max_execution_time` = 35 como mínimo y `pm.max_children` ≥ número de cajas + 6.
4. Abre `https://tu-subdominio/install.php`. La primera visita crea el archivo `INSTALL_KEY` en la carpeta del subdominio. Ábrelo en el Administrador de archivos y copia la clave en el formulario.
5. Rellena la base de datos, el usuario administrador, la tienda y PrestaShop. Pulsa **Instalar**: el instalador prueba la conexión, crea las tablas, escribe `.env`, se bloquea y borra `install.php`.
6. Crea las tareas programadas que muestra la página final (**Tareas programadas → Añadir tarea**):
   - cada minuto: `tpv:sync:outbox` (sube las ventas a PrestaShop y limpia el historial de impresión) y `tpv:catalog:sync --stock-only`;
   - cada noche: `tpv:catalog:sync --full` y `tpv:customers:sync`. Ejecuta el catálogo completo una vez al terminar con «Ejecutar ahora».
7. En el módulo TPV Connect, pon como URL del TPV la del subdominio. Si no pusiste el secreto SSO en el instalador, cópialo en `SSO_SHARED_SECRET` del `.env`.
8. Entra en el TPV, empareja el agente de impresión de cada caja (**Ajustes → Cajas**, ver `docs/print-agent.md`) y haz una venta de prueba.

## Qué escribe el instalador

`.env` con `APP_ENV=prod`, secretos nuevos (`APP_SECRET`, `JWT_SECRET`), `TRUSTED_PROXIES=127.0.0.1,::1` (nginx delante de Apache) y los datos del formulario. El archivo queda con permisos 0600 y se puede editar después a mano.

`TPV_URL` debe ser la dirección pública del TPV tal como la abre el navegador (`https://tpv.tutienda.com`, con `https`). La API solo acepta las peticiones que modifican datos si vienen de esa dirección o del mismo host; si no coincide, la venta, el cierre de caja o el cierre de sesión responden 403 «Origin not allowed». Las páginas del TPV envían además una política de seguridad de contenidos (CSP) que no permite mostrarlas dentro de un marco de otra web.

## Reinstalar

Borra `.env` y `var/install.lock`, vuelve a subir `public/install.php` y abre de nuevo el instalador. Si la base de datos ya es del TPV, solo aplica las migraciones que falten.
