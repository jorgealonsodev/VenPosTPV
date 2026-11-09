# Instalar VenPosTPV

Una instalación completa son tres piezas, en este orden: el **módulo de PrestaShop**, el **TPV en el hosting** y el **agente de impresión** en el ordenador de cada caja. Todas se descargan de la [última release](https://github.com/jorgealonsodev/VenPosTPV/releases/latest). En total se tarda unos 30 minutos.

## Camino rápido

1. **Módulo**: en PrestaShop, sube `tpvconnect.zip` e instálalo. Genera la clave del webservice y copia el secreto SSO.
2. **TPV**: crea un subdominio con SSL y una base de datos vacía. Sube `venpostpv-hosting.zip` al subdominio, extráelo y abre `https://tu-subdominio/install.php`.
3. **Unir los dos**: en el módulo, pon la dirección del TPV.
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
3. Pulsa **Generar clave** para crear la clave del webservice y cópiala.
4. Copia también el **secreto SSO**.

Guarda las dos: el instalador del TPV las pide.

## 2. TPV en el hosting

Necesitas:

- un subdominio (por ejemplo `tpv.tutienda.com`) con certificado SSL;
- PHP 8.4 o superior con `pdo_mysql`;
- una base de datos MySQL **vacía**, solo para el TPV;
- poder crear tareas programadas (cron).

Sube el zip, ejecuta el instalador web y crea las cuatro tareas programadas que te muestra al terminar. Los pasos completos están en [Instalar en un hosting](despliegue-hosting.md).

## 3. Unir PrestaShop y el TPV

En **TPV Connect**, pon como URL del TPV la del subdominio (`https://tpv.tutienda.com`). Si no pusiste el secreto SSO en el instalador, cópialo en `SSO_SHARED_SECRET` del archivo `.env` del TPV.

## 4. Agente de impresión en cada caja

1. En el TPV: **Ajustes → Cajas → generar código** de la caja de ese ordenador. Caduca a los 10 minutos.
2. En el ordenador de la caja, abre `VenPosAgent-<versión>-setup.exe`. Si Windows avisa con «Windows protegió su PC», pulsa **Más información → Ejecutar de todas formas**: el instalador todavía no va firmado.
3. Escribe la dirección del TPV y el código, elige la impresora de tickets (y la de etiquetas, si la tienes) y pulsa **Instalar**.

Cómo emparejar más tarde, cambiar de impresora o resolver problemas: [Agente de impresión](print-agent.md).

## Comprobación final

- [ ] El módulo **TPV Connect** aparece instalado en PrestaShop.
- [ ] `https://tu-subdominio/app/login` abre el TPV y entras con el usuario administrador.
- [ ] El catálogo tiene productos (la tarea `tpv:catalog:sync --full` se ha ejecutado al menos una vez).
- [ ] En **Ajustes → Cajas**, cada caja sale como «Conectado».
- [ ] Una venta de prueba imprime el ticket y aparece como pedido en PrestaShop al cabo de un minuto.

## Actualizar

Descarga la release nueva y repite solo lo que cambie:

| Pieza | Cómo se actualiza |
|---|---|
| Módulo | Vuelve a subir `tpvconnect.zip` en el Gestor de módulos. |
| TPV | Todavía no hay un procedimiento de actualización documentado. No subas el zip nuevo encima de una instalación en uso sin una copia de seguridad de la carpeta y de la base de datos. |
| Agente | Abre el instalador nuevo en cada caja: actualiza sin perder el emparejado. |
