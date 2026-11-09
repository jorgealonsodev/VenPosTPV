# TPV Connect

Módulo de PrestaShop (1.7.7 a 9) que une la tienda con el TPV VenPosTPV: clave del webservice, inicio de sesión único (SSO) desde el back office, pedidos, devoluciones y cupones del TPV. Historial de cambios: [CHANGELOG.md](CHANGELOG.md).

## Instalar y emparejar con el TPV

1. **Módulos → Gestor de módulos → Subir un módulo** y elige `tpvconnect.zip`. Si ya estaba instalado, la subida lo actualiza.
2. Abre **Configurar** en **TPV Connect** y pulsa **Emparejar con un TPV**. Sale un código de 6 cifras y la dirección de la tienda.
3. Escríbelos en el instalador del TPV (`https://tu-tpv/install.php`, apartado PrestaShop) o, con el TPV ya instalado, en **Ajustes → Acerca de → Volver a emparejar con la tienda**.

El TPV y la tienda se intercambian solos las claves y las direcciones. El código vale 10 minutos y una sola vez; pulsar otra vez el botón lo sustituye y 5 códigos equivocados lo anulan.

### Qué hace el emparejamiento

`POST /module/tpvconnect/pair` `{"code": "123456", "tpv_url": "https://tpv.tutienda.com"}`, solo por HTTPS (salvo desde la propia máquina de la tienda, o en una tienda de pruebas cuyo dominio es `localhost` desde su red privada; la cabecera `Host` y el `X-Forwarded-Proto` de un cliente remoto no cuentan):

- activa el webservice de PrestaShop si estaba apagado;
- usa la clave del webservice del TPV (`TPVCONNECT_WS_KEY_ID`) si existe, sin cambiar su valor, la activa y le añade los permisos que le falten; si no hay, crea una;
- crea el secreto SSO si no había ninguno; nunca lo cambia (para cambiarlo, «Regenerar» y volver a emparejar);
- guarda la dirección del TPV (`TPVCONNECT_URL`);
- responde la clave, el secreto SSO (que también firma las llamadas del TPV a `orders`, `refunds`, `restock`, `orderlookup` y `freeitem`), el id de la tienda, su moneda, su zona horaria y la versión del módulo.

Un código equivocado, caducado, ya usado o inexistente recibe siempre la misma respuesta (`403 {"error": "invalid_code"}`). El código solo se guarda como hash, con su caducidad y el número de intentos. Las respuestas no se guardan en ninguna caché (`Cache-Control: no-store`). **Parámetros avanzados → Registros** anota cada código generado, cada emparejamiento (dirección del TPV y la que tenía antes, IP, clave creada o reutilizada) y cada código anulado, nunca el código ni las claves.

## Configuración manual

Si no se puede emparejar, el apartado **Configuración manual** de la configuración del módulo sigue permitiendo poner la URL del TPV, ver o regenerar el secreto SSO y generar la clave del webservice («Generar clave API», que sustituye la anterior), para copiarlos en el instalador del TPV («Introducir las claves a mano»).
