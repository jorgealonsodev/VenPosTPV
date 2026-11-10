# TPV Connect

Módulo de PrestaShop (1.7.7 a 9) que une la tienda con el TPV VenPosTPV: clave del webservice, inicio de sesión único (SSO) desde el back office, pedidos, devoluciones, cupones, artículos libres y el modo edición del catálogo del TPV. Versión actual: **0.8.4**. Historial de cambios: [CHANGELOG.md](CHANGELOG.md). En la lista de módulos sale con el logo de VenPosTPV.

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

## Qué usa el TPV

| Endpoint (firmado con HMAC) | Para qué | Desde |
|---|---|---|
| `POST /module/tpvconnect/orders` | Pedidos de la caja, con o sin cupón (vista previa y pedido) | — |
| `POST /module/tpvconnect/refunds`, `restock`, `orderlookup`, `freeitem` | Devoluciones, reposición de stock, búsqueda de pedidos y artículo libre | — |
| `POST /module/tpvconnect/price` | Precio con IVA y tipo de IVA del [modo edición](../../docs/modo-edicion.md) | 0.8.3 |
| `POST /module/tpvconnect/codes` | EAN y referencia del modo edición; vacío los borra | 0.8.4 |

`price` y `codes` escriben solo esas columnas del producto o de la combinación, no el `Product` entero (PrestaShop validaría todos sus campos y un producto con algún campo fuera de límite no se podría guardar), y después lanzan `actionProductUpdate`. No tocan los precios específicos (reducciones).

La clave del webservice del TPV recibe los permisos que necesita al emparejar o al actualizar el módulo; desde 0.8.2 incluye `GET` en `addresses`, de donde el TPV saca el teléfono de los clientes.

### Los pedidos del TPV no mandan los e-mails de PrestaShop (0.8.1)

Mientras se crea un pedido de la caja, el hook `actionEmailSendBefore` impide los e-mails propios de PrestaShop (confirmación del pedido, vale con el saldo que sobra): el TPV entrega el ticket y el vale. Así un hosting que desactiva `proc_open` ya no rompe el pedido a medias. Los pedidos de la web siguen mandando sus e-mails; en un hosting así necesitan SMTP (**Parámetros avanzados → E-mail**).

### Actualizar

Sube el `tpvconnect.zip` nuevo en **Módulos → Gestor de módulos → Subir un módulo**. Los scripts de `upgrade/` hacen lo necesario (por ejemplo, registrar el hook de 0.8.1 o dar el permiso de direcciones de 0.8.2); 0.8.3 y 0.8.4 no cambian nada en la tienda.
