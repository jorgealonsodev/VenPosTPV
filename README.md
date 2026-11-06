<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/brand/venpostpv-logo-dark.svg">
    <img src="docs/brand/venpostpv-logo.svg" alt="VenPosTPV" width="340">
  </picture>
</p>

<p align="center">Punto de venta web para tiendas con PrestaShop.</p>

VenPosTPV es un TPV en PHP que se instala en un subdominio del mismo servidor que la tienda online. Tiene su propia base de datos para la operativa de caja y se sincroniza con PrestaShop en catálogo, stock, clientes y pedidos.

## Qué incluye

- **Venta en caja:** cobro en efectivo y tarjeta, cupones, vales, devoluciones, apertura y cierre de caja (informe Z).
- **Venta sin conexión:** las ventas se guardan en local y se envían cuando vuelve la red.
- **Impresión en tienda:** un agente para Windows y Linux recoge tickets, aperturas de cajón y etiquetas, y los manda a las impresoras ESC/POS de cada caja.
- **Módulo TPV Connect** para PrestaShop: clave del webservice, inicio de sesión único (SSO) y cupones.
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

PHP 8.4 o superior con `pdo_mysql`, MySQL o MariaDB y PrestaShop 1.7 u 8.

## Documentación

- [Instalar en un hosting](docs/despliegue-hosting.md)
- [Agente de impresión](docs/print-agent.md)
- [VERI\*FACTU](docs/verifactu.md)
