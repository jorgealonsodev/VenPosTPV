<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/brand/venpostpv-logo-dark.svg">
    <img src="docs/brand/venpostpv-logo.svg" alt="VenPosTPV" width="340">
  </picture>
</p>

<p align="center">Punto de venta web para tiendas con PrestaShop.</p>

VenPosTPV es un TPV en PHP que se instala en un subdominio del mismo servidor que la tienda online. Tiene su propia base de datos para la operativa de caja y se sincroniza con PrestaShop en catálogo, stock, clientes y pedidos.

## Instalar

Descarga los tres paquetes de la [última release](https://github.com/jorgealonsodev/VenPosTPV/releases/latest) y sigue la [guía de instalación](docs/instalacion.md):

| Paquete | Dónde va |
|---|---|
| `tpvconnect.zip` | Módulo para tu PrestaShop |
| `venpostpv-hosting.zip` | El TPV, en un subdominio de tu hosting |
| `VenPosAgent-<versión>-setup.exe` | Agente de impresión, en cada ordenador de caja |

## Qué incluye

- **Venta en caja:** cobro en efectivo, tarjeta, mixto, por [transferencia](docs/pago-transferencia.md) (queda pendiente de pago hasta que llega) y con cupón o vale, con los [métodos de pago que elija la tienda](docs/metodos-de-pago.md) en Ajustes; si un vale vale más que la compra, el saldo queda en un vale nuevo de un solo uso. El cobro con cupón dice cuánto saldo tiene el vale y, si la tienda lo rechaza, por qué. Tickets aparcados, devoluciones y cambios.
- **Artículo libre:** cobrar algo que no está en el catálogo escribiendo concepto, precio e IVA; se sube a PrestaShop con su concepto y su IVA.
- **Tickets y documentos:** ticket impreso, PDF, email o WhatsApp (enlace seguro que caduca); sin impresora, el TPV pregunta cómo entregarlo. El [ticket](docs/ticket-y-etiquetas.md) muestra cada descuento e indica quién atendió.
- **Factura completa** en A4 desde un ticket, con los datos fiscales del cliente.
- **Cupones:** crear cupones de PrestaShop desde la caja e imprimirlos, descargarlos o enviarlos; **Cupones** lista los creados en la caja con su estado en la tienda.
- **Caja:** apertura, informe X, cierre con informe Z contando por monedas y billetes o por importe total.
- **Informes de ventas:** por día, forma de pago, producto, categoría, IVA, hora y cajero, con exportación a CSV.
- **Catálogo:** búsqueda y escaneo; [modo edición](docs/modo-edicion.md) para administradores: stock, precio con IVA de cada artículo o talla, tipo de IVA del artículo, EAN y referencia, todo guardado también en la web (módulo 0.8.4). Los productos que la web vende sin IVA salen marcados «SIN IVA».
- **Etiquetas** con código de barras y un QR con el mismo código, en la impresora de tickets.
- **Venta sin conexión:** las ventas se guardan en local y se envían cuando vuelve la red.
- **Impresión en tienda:** un agente para Windows y Linux recoge tickets, aperturas de cajón y etiquetas, y los manda a las impresoras ESC/POS de cada caja.
- **Módulo TPV Connect** para PrestaShop: emparejamiento con el TPV con un código de 6 cifras (sin copiar claves), clave del webservice, inicio de sesión único (SSO), cupones y artículos libres.
- **Ventanas:** ninguna se cierra con un clic fuera o con Escape, solo con ✕, «Cancelar», «Cerrar» o «Terminar». Al cerrar Ajustes con ✕ se guarda lo cambiado; si algo no se puede guardar, Ajustes sigue abierto en esa sección con el error.
- **Instalación y actualizaciones** sin pasos manuales: las migraciones y la preparación de cada funcionalidad se aplican solas (con copia de seguridad antes) y su estado se ve en Ajustes.
- **Copias de seguridad** diarias de la base de datos.
- **VERI\*FACTU:** implementado en parte y desactivado hasta disponer del certificado.

## Estructura

| Carpeta | Contenido |
|---|---|
| `src/`, `templates/`, `public/` | Aplicación (Symfony 6.4, Doctrine DBAL, Twig) |
| `migrations/` | Migraciones de la base de datos del TPV |
| `modules/tpvconnect/` | Módulo de PrestaShop |
| `agent/` | Agente de impresión en Rust |
| `docs/` | Guías y PRD |

## Requisitos

PHP 8.4 o superior con `pdo_mysql`, MySQL o MariaDB y PrestaShop 1.7.7 a 9 (probado con 9.2).

## Documentación

- [Guía de instalación](docs/instalacion.md)
- [Instalar en un hosting](docs/despliegue-hosting.md)
- [Pasos de instalación y actualización](docs/provisioning.md) (desarrollo: toda funcionalidad que necesite preparar algo añade su paso)
- [Agente de impresión](docs/print-agent.md)
- [Informes de ventas](docs/informes-ventas.md)
- [Factura completa](docs/factura-completa.md)
- [Modo edición del catálogo](docs/modo-edicion.md)
- [Ticket y etiquetas](docs/ticket-y-etiquetas.md)
- [Pago por transferencia](docs/pago-transferencia.md)
- [Métodos de pago](docs/metodos-de-pago.md)
- [Crear y ver cupones](docs/tpv-coupon-creation.md)
- [Cupones en el cobro](docs/tpv-coupon-checkout.md)
- [Clientes y teléfonos](docs/customer-sync.md)
- [Enviar por WhatsApp](docs/enviar-por-whatsapp.md)
- [VERI\*FACTU](docs/verifactu.md)
- [Módulo TPV Connect](modules/tpvconnect/README.md)

## Autor

Creado por **Jorge Alonso** · [github.com/jorgealonsodev](https://github.com/jorgealonsodev)
