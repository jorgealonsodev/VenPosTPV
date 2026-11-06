Paquetes listos para instalar VenPosTPV @TAG@. Para una instalación nueva, sigue la [guía de instalación](@DOCS@/instalacion.md).

## Qué descargar

| Fichero | Versión | Dónde va |
|---|---|---|
| `tpvconnect.zip` | @MODULE@ | Tu PrestaShop: **Módulos → Gestor de módulos → Subir un módulo** |
| `venpostpv-hosting.zip` | @TAG@ | Un subdominio de tu hosting ([guía](@DOCS@/despliegue-hosting.md)) |
| `VenPosAgent-@AGENT@-setup.exe` | @AGENT@ | Cada ordenador de caja con Windows ([guía](@DOCS@/print-agent.md)) |
| `venpos-agent_@AGENT@_amd64.deb` | @AGENT@ | Cajas con Linux: Debian 12, Ubuntu 22.04 o más nuevos ([guía](@DOCS@/print-agent.md#instalar-en-linux)) |
| `venpos-agent-@AGENT@-linux-x86_64.tar.gz` | @AGENT@ | Cualquier otro Linux de 64 bits: binario estático con `install.sh`, sin ventana ni icono |
| `SHA256SUMS.txt` | — | Sumas SHA-256 para comprobar las descargas |

## Camino rápido

1. Instala el módulo en PrestaShop, genera la clave del webservice y copia el secreto SSO.
2. Sube `venpostpv-hosting.zip` al subdominio, extráelo y abre `https://tu-subdominio/install.php`.
3. En el módulo, pon la dirección del TPV.
4. En cada caja, instala el agente con el código de **Ajustes → Cajas**.
5. Haz una venta de prueba: sale el ticket y se abre el cajón.

> El instalador del agente todavía no va firmado: Windows avisará con «Windows protegió su PC». Pulsa **Más información → Ejecutar de todas formas**.
