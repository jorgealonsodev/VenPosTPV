# Instalar VenPosTPV

Una instalación completa son tres piezas, en este orden: el **módulo de PrestaShop**, el **TPV en el hosting** y el **agente de impresión** en el ordenador de cada caja. Todas se descargan de la [última release](https://github.com/jorgealonsodev/VenPosTPV/releases/latest). En total se tarda unos 30 minutos.

## Camino rápido

1. **Módulo**: en PrestaShop, sube `tpvconnect.zip` e instálalo. En su configuración pulsa **Emparejar con un TPV**: sale un código de 6 cifras.
2. **TPV**: crea un subdominio con SSL y una base de datos vacía. Sube `venpostpv-hosting.zip` al subdominio, extráelo y abre `https://tu-subdominio/install.php`.
3. **Unir los dos**: en el instalador, escribe la dirección de la tienda y el código. El TPV y la tienda se intercambian solos las claves y las direcciones.
4. **Cajas**: en cada ordenador de caja, instala el agente (`VenPosAgent-<versión>-setup.exe` en Windows, el `.deb` en Linux) y emparéjalo con el código de **Ajustes → Cajas**.
5. **Prueba**: haz una venta. Debe salir el ticket y abrirse el cajón.

## Qué descargar

| Fichero | Dónde va | Guía detallada |
|---|---|---|
| `tpvconnect.zip` | Tu PrestaShop (1.7 u 8) | Paso 1, abajo |
| `venpostpv-hosting.zip` | Un subdominio de tu hosting (cPanel, DirectAdmin u otro panel) | [Instalar en un hosting](despliegue-hosting.md) |
| `VenPosAgent-<versión>-setup.exe` | Cada ordenador de caja con Windows | [Agente de impresión](print-agent.md) |
| `venpos-agent_<versión>_amd64.deb` | Cajas con Debian 12, Ubuntu 22.04 o más nuevos | [Instalar en Linux](print-agent.md#instalar-en-linux) |
| `venpos-agent-<versión>-linux-x86_64.tar.gz` | Cualquier otro Linux de 64 bits (sin ventana ni icono) | [Instalar en Linux](print-agent.md#instalar-en-linux) |
| `SHA256SUMS.txt` | Opcional: para comprobar que las descargas están completas | — |

> Mientras el repositorio sea privado, para descargar hay que entrar en GitHub con una cuenta que tenga acceso.

## 1. Módulo de PrestaShop

1. En el panel de PrestaShop: **Módulos → Gestor de módulos → Subir un módulo**, y elige `tpvconnect.zip`. Si ya estaba instalado, la subida lo actualiza.
2. Abre **Configurar** en **TPV Connect**.
3. Pulsa **Emparejar con un TPV**. Sale un código de 6 cifras y la dirección de la tienda: los escribirás en el instalador del TPV. El código vale 10 minutos y una sola vez; si caduca, pulsa otra vez el botón.

No hay que copiar claves. Si no puedes emparejar, el instalador tiene el enlace **Introducir las claves a mano** y el módulo, el apartado **Configuración manual** («Generar clave API» y el secreto SSO). Con las claves a mano, el secreto SSO es obligatorio: firma todas las llamadas del TPV al módulo (pedidos, devoluciones, precios y la comprobación del diario fiscal) y, sin él, el instalador no podría preguntar a la tienda si ya tiene documentos fiscales. Si falta, el formulario vuelve a salir con el aviso y no se guarda nada.

## 2. TPV en el hosting

Necesitas:

- un subdominio (por ejemplo `tpv.tutienda.com`) con certificado SSL;
- PHP 8.4 o superior con `pdo_mysql`;
- una base de datos MySQL **vacía**, solo para el TPV;
- poder crear una tarea programada (cron) que se ejecute cada minuto.

Sube el zip, ejecuta el instalador web (con la dirección de la tienda y el código del módulo) y crea la tarea programada que te muestra al terminar: una sola, `tpv:cron`, cada minuto. Los pasos completos están en [Instalar en un hosting](despliegue-hosting.md).

## 3. Unir PrestaShop y el TPV

Con el código, el instalador ya los ha unido: el módulo tiene la dirección del TPV y el TPV, las claves de la tienda. Para unirlos más tarde (otra tienda, claves nuevas o una instalación con las claves a mano): pulsa **Emparejar con un TPV** en el módulo y escribe el código en el TPV, en **Ajustes → Acerca de → Volver a emparejar con la tienda**. Detalles y mensajes de error: [Emparejar el TPV con la tienda](despliegue-hosting.md#emparejar-el-tpv-con-la-tienda).

## 4. Agente de impresión en cada caja

1. En el TPV: **Ajustes → Cajas → generar código** de la caja de ese ordenador. Caduca a los 10 minutos.
2. En el ordenador de la caja, abre `VenPosAgent-<versión>-setup.exe`. Si Windows avisa con «Windows protegió su PC», pulsa **Más información → Ejecutar de todas formas**: el instalador todavía no va firmado.
3. Escribe la dirección del TPV y el código, elige la impresora de tickets (y la de etiquetas, si la tienes) y pulsa **Instalar**.

Cómo emparejar más tarde, cambiar de impresora o resolver problemas: [Agente de impresión](print-agent.md).

## Comprobación final

- [ ] El módulo **TPV Connect** aparece instalado en PrestaShop.
- [ ] `https://tu-subdominio/app/login` abre el TPV y entras con el usuario administrador.
- [ ] El catálogo tiene productos: la tarea `tpv:cron` lo carga sola a los pocos minutos de crearla (en **Ajustes → Acerca de → Estado de la instalación** se ve si la tarea funciona).
- [ ] En **Ajustes → Cajas**, cada caja sale como «Conectado».
- [ ] Una venta de prueba imprime el ticket y aparece como pedido en PrestaShop al cabo de un minuto.

### Reinstalación

Si instalas el TPV de nuevo con una base de datos vacía contra una tienda que ya tiene el diario fiscal de la instalación anterior, para el TPV es lo mismo que haber restaurado una copia antigua: el siguiente ticket repetiría un número que la tienda ya tiene. Por eso el instalador (con el código de emparejamiento, o con las claves a mano y su secreto SSO, que es obligatorio) pregunta a la tienda y, antes de guardar la configuración, se para en la página **«La tienda ya tiene documentos fiscales»** (con el último número de cada serie que guarda la tienda) y pide elegir:

- **«Voy a restaurar una copia de seguridad»**: la instalación termina sin empezar el diario fiscal. El paso «Diario fiscal preparado» queda pendiente y la caja no deja vender hasta que restaures la copia de la base de datos y, si Ajustes lo pide, pulses **Recuperar documentos**.
- **«Empezar de cero»**: pide marcar una casilla de confirmación y escribir el motivo. Los diarios que guarda la tienda quedan retirados, empieza un diario nuevo y queda anotado en el registro de auditoría con el motivo y el origen `installer`. La numeración empieza otra vez en 1: antes de la primera venta ajusta en **Ajustes → Cajas** el próximo número de cada serie o usa otra letra de caja. Nunca la elijas si vas a restaurar una copia de seguridad.

Si en ese momento no se puede preguntar a la tienda, el instalador no guarda nada y ofrece volver a intentarlo. Una vez instalado, el camino es la orden de consola `php bin/console tpv:fiscal:journal:reset --confirm "Reinstalación desde cero" --accept-shop-ahead --env=prod`. Detalles en [Diario fiscal](diario-fiscal.md#reinstalar-contra-una-tienda-que-ya-tiene-documentos).

## Actualizar

Descarga la release nueva y repite solo lo que cambie:

| Pieza | Cómo se actualiza |
|---|---|
| Módulo | Vuelve a subir `tpvconnect.zip` en el Gestor de módulos. |
| TPV | Todavía no hay un procedimiento de actualización documentado. No subas el zip nuevo encima de una instalación en uso sin una copia de seguridad de la carpeta y de la base de datos. |
| Agente | Abre el instalador nuevo en cada caja: actualiza sin perder el emparejado. |
