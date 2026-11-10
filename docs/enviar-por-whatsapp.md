# Enviar por WhatsApp

Junto a **Enviar por email**, el TPV ofrece **WhatsApp** para mandar al cliente su documento en PDF:

| Dónde | Documento |
|---|---|
| Detalle de la venta → **PDF** (o **Factura F…**) | el ticket, o la factura completa que lo sustituyó |
| Cobro terminado sin impresora (junto al email del ticket) | el ticket de la venta |
| **Crear cupón** → tras «Código creado» | el cupón |

## Cómo se usa

1. Pulsa **WhatsApp**. Escribe el teléfono del cliente o pulsa **Elegir cliente** y búscalo por nombre o teléfono: salen todos los que coinciden, primero los que tienen teléfono; los que no lo tienen pone «sin teléfono» y no se pueden elegir, y si no hay ninguno lo dice. El teléfono de los clientes de la tienda sale de sus direcciones en PrestaShop (el móvil, o si no el fijo, de la dirección más reciente), que el TPV copia en la sincronización de clientes (`tpv:customers:sync`; el módulo TPV Connect 0.8.2 da permiso para leer las direcciones); un teléfono escrito en el TPV no se borra. En el cobro se rellena con el teléfono del cliente de la venta, si lo tiene.
2. Pulsa **Abrir WhatsApp**. Se abre WhatsApp (la aplicación del móvil, o WhatsApp Web / la aplicación de escritorio en un ordenador) en el chat de ese número con el mensaje escrito, por ejemplo: «Hola, aquí tienes tu ticket R26000012 de Nada Más: https://…/d/…».
3. En WhatsApp, pulsa **Enviar**. El TPV no envía nada por su cuenta y no necesita ninguna cuenta de WhatsApp Business ni de Meta.

Si el navegador bloquea la ventana nueva, el TPV lo dice y deja el enlace para tocarlo.

**Teléfono.** Se ignoran espacios, puntos, guiones y paréntesis. Con `+` o `00` delante se respeta el prefijo del país; 9 cifras que empiezan por 6, 7, 8 o 9 son un número español (+34); cualquier otro número necesita de 8 a 15 cifras con su prefijo. Si no, «Número de teléfono no válido».

**Factura completa.** Solo con el consentimiento del cliente (RD 1619/2012 art. 9.2), el mismo que para el email: ver [factura-completa.md](factura-completa.md#whatsapp).

## El enlace

El mensaje no adjunta el PDF: lleva un enlace `<TPV_URL>/d/<código>` que abre el PDF sin iniciar sesión, durante **30 días**. El código va firmado (HMAC-SHA256 con una clave derivada de `APP_SECRET`) con el tipo de documento, su número y la caducidad: no se puede adivinar ni cambiar para abrir otro documento. Un enlace caducado, alterado o de un documento que ya no existe responde «no encontrado», sin más detalle. El PDF es el mismo que la descarga y el email.

## Instalación

No necesita ningún paso de provisioning ni ajuste nuevo: usa el `APP_SECRET` que escribe el instalador y la URL pública del TPV (`TPV_URL`, o `APP_URL` sin ella), las mismas que los enlaces de verificación del ticket. Para que el cliente pueda abrir el enlace desde su móvil, esa URL tiene que ser accesible desde Internet (el TPV en el hosting). Cambiar `APP_SECRET` invalida los enlaces ya enviados.
