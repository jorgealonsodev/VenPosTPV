# PRD — TPV Online integrado con PrestaShop (Stack PHP, BBDD dual, subdominio)

> **Product Requirements Document**
> Sistema Punto de Venta web en PHP, desplegable en un subdominio del mismo servidor que PrestaShop, con base de datos propia para la operativa del TPV y conexión a la BBDD de PrestaShop solo para lo compartido (catálogo, stock, pedidos, clientes).

| | |
|---|---|
| **Versión** | 1.3 (stack PHP, BBDD dual, subdominio dedicado) |
| **Fecha** | Mayo 2026 |
| **Estado** | Borrador para aprobación |
| **Tipo** | Aplicación web PHP (módulo + panel propio) |
| **Plataforma destino** | PrestaShop 1.7.x / 8.x |
| **Despliegue** | Mismo servidor que PrestaShop (LAMP/LEMP), distinto vhost y subdominio |
| **URL e-commerce** | `https://tudominio.com` |
| **URL TPV** | `https://tpv.tudominio.com` |
| **Bases de datos** | Dos schemas MySQL/MariaDB en el mismo servidor: `prestashop` (existente) + `tpv` (nuevo, propio) |

---

## Tabla de contenidos

1. [Resumen ejecutivo](#1-resumen-ejecutivo)
2. [Objetivos y métricas de éxito](#2-objetivos-y-métricas-de-éxito)
3. [Alcance](#3-alcance)
4. [Personas y casos de uso](#4-personas-y-casos-de-uso)
5. [Requisitos funcionales](#5-requisitos-funcionales)
6. [Requisitos no funcionales](#6-requisitos-no-funcionales)
7. [Arquitectura técnica (PHP)](#7-arquitectura-técnica-php)
8. [Integración con PrestaShop](#8-integración-con-prestashop)
9. [Modelo de datos](#9-modelo-de-datos)
10. [Diseño de interfaz (UX/UI)](#10-diseño-de-interfaz-uxui)
11. [Hardware compatible](#11-hardware-compatible)
12. [Seguridad y cumplimiento](#12-seguridad-y-cumplimiento)
13. [Reportes e informes](#13-reportes-e-informes)
14. [Despliegue y operación](#14-despliegue-y-operación)
15. [Roadmap por fases](#15-roadmap-por-fases)
16. [Riesgos y mitigaciones](#16-riesgos-y-mitigaciones)
17. [Anexos](#17-anexos)

---

## 1. Resumen ejecutivo

### 1.1 Visión del producto

**TPV PrestaShop PHP** es un Terminal Punto de Venta web desarrollado en PHP que se aloja en el **mismo servidor físico** que la tienda PrestaShop pero en un **subdominio dedicado** (`tpv.tudominio.com`). Replica la experiencia de un TPV físico tradicional y comparte con el e-commerce solo lo que tiene sentido compartir (catálogo, stock, pedidos, clientes), manteniendo el resto de la operativa en una **base de datos propia e independiente**.

El producto se compone de **tres piezas que conviven en el mismo servidor pero quedan claramente separadas**:

1. Un **módulo nativo PrestaShop** (`tpvconnect`) instalado vía back-office, que aporta la integración profunda, los hooks y la SSO hacia el TPV.
2. Una **aplicación PHP independiente** alojada fuera del directorio web de PrestaShop (`/var/www/tpv`), servida bajo `tpv.tudominio.com`, con su propio vhost, certificado TLS, logs y reglas de seguridad.
3. Una **base de datos propia del TPV** (`tpv`), separada de la de PrestaShop pero alojada en el mismo servidor MySQL/MariaDB, donde reside todo lo que **no** debe contaminar el e-commerce: sesiones de caja, arqueos, auditoría, cola offline, configuración de terminales, informes Z firmados, usuarios y roles del TPV.

Esta arquitectura permite que un fallo, ataque o saturación en el TPV **no afecte al e-commerce**, y viceversa, manteniendo a la vez la simplicidad operativa de un único servidor.

### 1.2 Por qué PHP en el mismo servidor

- **Despliegue trivial**: copiar archivos al hosting de PrestaShop, sin servicios adicionales.
- **Mismo stack que el cliente ya tiene**: Apache/Nginx + PHP + MySQL.
- **Reutilización del núcleo de PrestaShop**: las clases `Order`, `Product`, `StockAvailable`, `CartRule` ya manejan correctamente impuestos, hooks, multi-tienda y caché.
- **Sin servicios extra que mantener**: ni Node.js, ni Redis obligatorio, ni colas externas.
- **Coste de hosting menor** y soporte por cualquier proveedor de hosting compartido o VPS estándar.

### 1.3 Por qué dos bases de datos en el mismo servidor

Separar `prestashop` y `tpv` como **schemas independientes** dentro del mismo servidor MySQL es un patrón profesional con ventajas concretas:

- **Aislamiento de la operativa propia**: arqueos, auditoría, sesiones de caja, cola offline e informes Z viven en una BBDD que tú controlas al 100%, sin depender del esquema de PrestaShop.
- **Actualizaciones de PrestaShop más seguras**: si una nueva versión renombra tablas o cambia campos, las tablas del TPV no entran en el camino de migración.
- **Backups independientes**: puedes respaldar la BBDD del TPV con frecuencia distinta y restaurar una sin tocar la otra.
- **Permisos diferenciados**: el usuario MySQL del TPV puede tener permisos limitados sobre `prestashop` (solo lo que el núcleo necesita para crear pedidos correctamente) y permisos completos sobre `tpv`.
- **Rendimiento**: queries pesadas del TPV (auditoría, reportes, cola Messenger) no compiten por locks con las del e-commerce.
- **Escalabilidad futura**: si se centralizan varias tiendas PrestaShop bajo un mismo TPV (cadenas), la BBDD `tpv` puede consolidar reportes sin ensuciar ningún PrestaShop.

**Lo que esta separación implica:**

- No hay JOINs cruzados entre `prestashop` y `tpv`. Los datos compartidos se referencian por ID lógico (`id_order`, `id_customer`, `id_product`) y se resuelven vía el núcleo de PrestaShop, no vía SQL directo.
- No hay transacciones distribuidas. Operaciones que tocan ambas BBDD (cierre de ticket = crear pedido en PrestaShop + registrar movimiento de caja en `tpv`) se diseñan como **saga de dos pasos con compensación**: si el segundo paso falla, se anota incidencia en la auditoría y se notifica al encargado para resolución manual; el pedido en PrestaShop queda creado y trazable.
- La integridad referencial cruzada la garantiza el código, no el motor: una fila en `tpv.movement` con `id_order = 12345` solo se crea **después** de que `validateOrder()` haya devuelto éxito.

### 1.4 Por qué subdominio dedicado en lugar de subdirectorio

El TPV se sirve en **`tpv.tudominio.com`** y no en `tudominio.com/pos`. Las razones son operativas y de seguridad:

- **Aislamiento de cookies y sesiones**: el subdominio tiene scope propio, sin colisiones con cookies de PrestaShop (carrito de cliente, login, módulos de tracking).
- **Headers HTTP diferenciados**: el TPV puede aplicar CSP estricta, HSTS con `preload`, `X-Frame-Options: DENY`, sin afectar al e-commerce que necesita reglas más permisivas para integraciones externas (pasarelas de pago, vídeo, redes sociales).
- **Restricción de acceso por red**: el vhost del TPV puede limitar acceso a la IP pública de la tienda física, a una VPN o a una whitelist. Imposible de hacer limpiamente en un subdirectorio del e-commerce.
- **Logs y monitorización separados**: logs de Apache/Nginx, métricas y errores quedan separados por vhost, facilitando la auditoría.
- **Certificado TLS independiente**: renovación de Let's Encrypt independiente; permite mTLS (certificado de cliente) en el TPV para máxima seguridad si se desea.
- **Rate limiting y WAF agresivos**: con Cloudflare o un WAF delante, las reglas para `tpv.*` pueden ser muy estrictas (challenge en cada petición sospechosa, bloqueo geográfico) sin afectar conversión del e-commerce.
- **SEO limpio**: el TPV nunca debe indexarse. Un `robots.txt` propio en el subdominio prohíbe todo, sin riesgo de afectar al SEO de la tienda.
- **Código fuente fuera del docroot público**: `/var/www/tpv/src/`, `/var/www/tpv/vendor/`, `/var/www/tpv/config/` quedan **físicamente fuera** del directorio web de PrestaShop. Solo `/var/www/tpv/public/` es servido como DocumentRoot del subdominio. Imposible que un fallo de configuración exponga código sensible.
- **Despliegue futuro flexible**: si un día se quiere mover el TPV a otro servidor por escalado o seguridad, solo cambia el DNS. La aplicación ya es independiente.

**Implicaciones técnicas:**

- El TPV y PrestaShop son **orígenes distintos** desde el punto de vista del navegador. CORS pasa a ser relevante si en algún momento hay peticiones AJAX cruzadas (en el flujo actual no las hay: la SSO se resuelve con redirect + token).
- El núcleo de PrestaShop se sigue cargando vía filesystem (`require_once '/var/www/prestashop/config/config.inc.php'`), que es independiente del dominio. PrestaShop seguirá conectándose a su BBDD con sus propias credenciales.
- Si en el futuro se necesita SSO bidireccional con cookies, ambos dominios comparten dominio padre (`tudominio.com`), lo que permite cookies con `domain=.tudominio.com`.

### 1.5 Trade-offs aceptados frente a un stack JS/Node

| Aspecto | Stack PHP (esta versión) | Stack Node/SPA |
|---|---|---|
| Despliegue | Trivial, junto a PrestaShop | Requiere proceso Node + proxy |
| WebSockets en tiempo real | Solución alternativa con SSE o Mercure | Nativo |
| Modo offline | Más limitado (Service Worker + IndexedDB en el front) | Más natural |
| Reutilización lógica PrestaShop | Total, directa | Indirecta vía API |
| Coste y simplicidad operativa | **Mucho menor** | Mayor |

Conclusión: para el **80% de comercios PrestaShop con tienda física**, el stack PHP es la opción correcta. El modo offline se cubre con tecnología del navegador (Service Worker + IndexedDB), no con el backend.

---

## 2. Objetivos y métricas de éxito

### 2.1 Objetivos de negocio

| Objetivo | KPI | Meta a 12 meses |
|---|---|---|
| Reducir tiempo medio de cobro | Segundos por ticket | < 25 s |
| Eliminar descuadres de stock | % diferencia inventario online vs físico | < 0,5% |
| Adopción por cajeros | Tasa de uso diario | > 95% |
| Disponibilidad | Uptime mensual | ≥ 99,5% |
| Coste de instalación | Tiempo medio de despliegue por cliente | < 30 min |

### 2.2 Objetivos de producto

- Cobrar un ticket de 5 productos en **< 30 s** desde el primer escaneo.
- Funcionar **offline** (cliente) durante al menos 4 horas con sincronización posterior.
- Soportar **al menos 10.000 productos** sin degradación perceptible.
- Búsqueda de producto **< 200 ms**.
- Instalación completa con **un solo asistente** desde el back-office de PrestaShop.

---

## 3. Alcance

### 3.1 Dentro del alcance (v1.0)

- Venta presencial con escaneo de código de barras / QR.
- Carrito en pantalla con cantidades, descuentos y notas.
- Cobro en efectivo, tarjeta, mixto y vale regalo.
- Apertura y cierre de caja con arqueo (informes X/Z).
- Reutilización directa del núcleo de PrestaShop para crear pedidos legales.
- Generación de tickets ESC/POS (58 / 80 mm) y PDF.
- Devoluciones y abonos trazables (`ps_order_slip`).
- Multi-cajero y multi-tienda heredando configuración PrestaShop.
- Búsqueda y alta rápida de clientes.
- Aplicación de cupones y reglas de catálogo de PrestaShop.
- Modo offline en cliente (Service Worker + IndexedDB) con cola de sincronización.
- Instalación íntegra como módulo PrestaShop.

### 3.2 Fuera del alcance (v1.0)

- App nativa iOS / Android (se cubre como PWA en v2.0).
- Gestión de citas o reservas.
- Módulo de fidelización propio (se usa el de PrestaShop si está activo).
- Contabilidad avanzada (se exporta a sistemas externos).
- Pedidos a proveedores.

---

## 4. Personas y casos de uso

### 4.1 Personas

**Cajero/a (María, 28 años)** — turnos de 8 horas. Prioriza velocidad, claridad y atajos. No técnica.

**Encargado/a de tienda (Carlos, 42 años)** — abre y cierra caja, gestiona devoluciones complejas, supervisa descuadres.

**Propietario/Administrador (Lucía, 50 años)** — no usa el TPV a diario; necesita reportes y la garantía de stock cuadrado.

**Administrador técnico / instalador (Pablo)** — gestiona el hosting PrestaShop. Quiere instalar el TPV **sin pelear con dependencias**.

### 4.2 Casos de uso principales

1. **Venta rápida**: escanear → descuento → cobrar efectivo → imprimir.
2. **Venta con cliente registrado**: buscar por DNI/email → asociar → factura nominal.
3. **Devolución total con ticket** y **devolución parcial sin ticket** (con autorización).
4. **Cobro mixto** (efectivo + tarjeta + vale).
5. **Cierre de caja** con arqueo guiado e informe Z firmado.
6. **Modo offline en cliente**: el internet cae → sigue vendiendo → al volver, sincroniza.
7. **Instalación**: el técnico instala el módulo desde el back-office y configura el primer terminal en menos de 30 minutos.

---

## 5. Requisitos funcionales

### 5.1 Pantalla principal de venta

- **RF-01** Tres zonas: catálogo, ticket activo, barra de acciones.
- **RF-02** Cuadrícula de productos favoritos / categorías con imagen, nombre y precio.
- **RF-03** Buscador incremental por nombre, SKU, EAN, referencia.
- **RF-04** Lectura de códigos vía:
  - Lector USB HID (modo teclado).
  - Lector Bluetooth.
  - Cámara del dispositivo (librería ZXing-js o QuaggaJS en el front).
- **RF-05** Escaneo repetido del mismo producto incrementa cantidad.
- **RF-06** Edición rápida de cantidad, precio (con permiso) y descuento por línea.
- **RF-07** Notas a nivel de línea y de ticket.
- **RF-08** Indicador visual de stock bajo / sin stock al añadir.
- **RF-09** Soporte de productos con combinaciones (`ps_product_attribute`).
- **RF-10** Soporte de productos con peso variable (báscula vía agente local).

### 5.2 Carrito y descuentos

- **RF-11** Descuento por línea (importe o %).
- **RF-12** Descuento global del ticket (importe o %).
- **RF-13** Cupones PrestaShop (`ps_cart_rule`) por código.
- **RF-14** Reglas automáticas si se cumplen condiciones.
- **RF-15** Permisos por rol: descuentos altos requieren PIN de supervisor.

### 5.3 Clientes

- **RF-16** Cliente genérico "Consumidor final" por defecto.
- **RF-17** Búsqueda por nombre, email, teléfono, DNI/NIF.
- **RF-18** Alta rápida con campos mínimos.
- **RF-19** Histórico de compras del cliente accesible desde el ticket.
- **RF-20** Asignación automática de grupo PrestaShop (impuestos y precios).

### 5.4 Cobro

- **RF-21** Métodos: efectivo, tarjeta, transferencia, vale regalo, financiación, mixto.
- **RF-22** Calculadora de cambio automática.
- **RF-23** Integración con datáfonos (Redsys, Stripe Terminal, SumUp, Worldline).
- **RF-24** Cobro mixto sin límite de métodos.
- **RF-25** Apertura automática del cajón portamonedas.
- **RF-26** Validación de cobro completo antes de cerrar.

### 5.5 Tickets, facturas y devoluciones

- **RF-27** Ticket simplificado ESC/POS con QR de verificación.
- **RF-28** Conversión a factura nominal con datos fiscales completos.
- **RF-29** Reimpresión de ticket por número, fecha o cliente.
- **RF-30** Envío por email o WhatsApp (si el módulo está activo).
- **RF-31** Devoluciones totales y parciales con `ps_order_slip` automático.
- **RF-32** Vale regalo con código único como alternativa al reembolso.

### 5.6 Caja

- **RF-33** Apertura con saldo inicial declarado.
- **RF-34** Movimientos manuales de caja con concepto.
- **RF-35** Cierre con conteo guiado por denominación.
- **RF-36** Cálculo automático de diferencia.
- **RF-37** Informe Z (cierre) y X (lectura intermedia) en PDF.

### 5.7 Administración

- **RF-38** Gestión de usuarios y roles (administrador, encargado, cajero).
  - Cada usuario puede tener un **código de identificación** (campo `code`, único, opcional) además del nombre.
  - El panel de administración muestra el estado de bloqueo de cada usuario.
  - El administrador puede **desbloquear** cuentas bloqueadas por intentos fallidos de PIN.
- **RF-39** Permisos granulares.
- **RF-40** Configuración por terminal (impresora, lector, atajos).
- **RF-41** Selector de tienda activa (multi-shop PrestaShop).
- **RF-42** Log de auditoría completo.

---

## 6. Requisitos no funcionales

| Categoría | Requisito |
|---|---|
| **Rendimiento** | Carga inicial < 3 s. Acción de añadir < 100 ms (en cliente). Búsqueda < 200 ms con 50.000 productos. |
| **Disponibilidad** | 99,5% mensual. Modo offline en cliente obligatorio. |
| **Escalabilidad** | Hasta 50 terminales concurrentes por instalación. |
| **Compatibilidad servidor** | PHP 8.1+, MySQL 5.7+/MariaDB 10.4+, Apache 2.4 / Nginx, Composer. |
| **Compatibilidad navegador** | Chrome ≥ 110, Edge ≥ 110, Firefox ≥ 115, Safari ≥ 16. |
| **Compatibilidad dispositivo** | PC, Mac, tablet Android/iPad ≥ 10", all-in-one POS. |
| **Idiomas** | Heredados de PrestaShop. UI inicial: ES, EN, FR, PT. |
| **Accesibilidad** | WCAG 2.1 AA en pantallas críticas. Modo alto contraste. Atajos completos. |

---

## 7. Arquitectura técnica (PHP)

### 7.1 Visión general

```
┌────────────────────────────────────────────────────────────────────────┐
│                           Servidor único                                │
│                                                                         │
│  ┌────────────────────────────┐    ┌────────────────────────────────┐   │
│  │  vhost: tudominio.com       │    │  vhost: tpv.tudominio.com      │   │
│  │  DocRoot: /var/www/         │    │  DocRoot: /var/www/tpv/public  │   │
│  │          prestashop         │    │                                │   │
│  │                             │    │   - CSP estricta               │   │
│  │  - PrestaShop (front+admin) │    │   - HSTS                       │   │
│  │  - /modules/tpvconnect/     │    │   - Rate limit estricto        │   │
│  │                             │    │   - robots.txt: deny all       │   │
│  └──────────────┬──────────────┘    └────────────┬───────────────────┘   │
│                 │                                │                       │
│                 ▼                                ▼                       │
│         ┌──────────────────────────────────────────┐                     │
│         │              PHP-FPM                      │                     │
│         │                                           │                     │
│         │  Pool 1 (PrestaShop)   Pool 2 (TPV)       │                     │
│         │   - usuario www-ps      - usuario www-tpv │                     │
│         │   - chroot a /presta    - chroot a /tpv   │                     │
│         │                                           │                     │
│         │  El pool del TPV carga PrestaShop core    │                     │
│         │  via require_once filesystem path:        │                     │
│         │  /var/www/prestashop/config/config.inc.php│                     │
│         └──────────────────┬─────────────────────┬──┘                    │
│                            │                     │                       │
│                ┌───────────┴────┐   ┌────────────┴────┐                   │
│                ▼                ▼   ▼                 ▼                   │
│         ┌─────────────┐  ┌──────────────┐    ┌───────────────┐            │
│         │  MySQL      │  │  MySQL       │    │ /var/www/tpv/ │            │
│         │  schema:    │  │  schema:     │    │   var/cache/  │            │
│         │ prestashop  │  │    tpv       │    │   var/log/    │            │
│         │ user:ps_usr │  │ user:tpv_usr │    │   var/reports/│            │
│         └─────────────┘  └──────────────┘    └───────────────┘            │
│                                                                         │
└────────────────────────────────────────────────────────────────────────┘
                              ▲                  ▲
                              │ HTTPS            │ HTTPS
                              │ (público)        │ (red restringida)
                  ┌───────────┴────┐    ┌────────┴────────┐
                  │   Clientes     │    │   Terminales    │
                  │   web públicos │    │   TPV (cajeros) │
                  └────────────────┘    └─────────────────┘
```

### 7.2 Stack tecnológico

**Backend (PHP)**
- **PHP 8.1+** con tipado estricto.
- **Symfony Components** (Routing, HttpFoundation, Console, Validator) — los mismos que ya usa PrestaShop 8, sin añadir versiones distintas.
- **Twig** para plantillas de panel administrativo y vistas no-SPA.
- **Doctrine DBAL** apuntando a la BBDD `tpv` para queries propias del TPV (no Doctrine ORM, para evitar fricción con el ecosistema PrestaShop).
- **Núcleo PrestaShop reutilizado** vía `require_once 'config/config.inc.php'` en el bootstrap del TPV: las clases `Order`, `OrderDetail`, `Cart`, `Product`, `StockAvailable`, `Customer`, `CartRule` operan sobre la BBDD `prestashop` con sus propias credenciales (las que ya tiene configuradas PrestaShop).
- **ConnectionManager propio**: gestor centralizado que expone dos conexiones nombradas (`prestashop` para lectura informativa puntual, `tpv` para todas las operaciones propias) con configuración separada en `pos/config/databases.yaml`.
- **Composer** para dependencias propias (PHPUnit, dompdf, mike42/escpos-php, firebase/php-jwt, ramsey/uuid).
- **Mercure** opcional para notificaciones en tiempo real (alternativa a WebSockets, ligera y compatible con PHP).

**Frontend (en el navegador)**
- **JavaScript moderno (ES2022)** sin framework pesado. Para la pantalla de venta se usa **Alpine.js** o **Vue 3** en modo standalone (un único `<script>` sin build complejo) para la reactividad del ticket. Si el equipo prefiere algo más estructurado, **Vue 3 + Vite** con build empaquetado a `/pos/assets/`.
- **Tailwind CSS** compilado a un único CSS estático.
- **Service Worker + IndexedDB (Dexie)** para modo offline.
- **ZXing-js** para lectura de códigos por cámara.
- **WebUSB / Web Serial API** para impresoras y básculas conectadas directamente al navegador (cuando aplica).

**Cola y trabajos en segundo plano**
- **Symfony Messenger** con transporte **doctrine** (tabla en MySQL): no requiere Redis ni RabbitMQ. Trabajos: envío de emails, generación de PDFs pesados, sincronización fiscal (VeriFactu).
- **Cron** del servidor ejecuta el worker cada minuto (`php bin/console messenger:consume`).

**Agente local opcional ("TPV Bridge")**
- Pequeño ejecutable (Electron o Tauri, distribuible para Windows/Linux/Mac) que se instala en cada terminal **solo si** se necesita acceso a hardware no compatible con WebUSB (algunas impresoras antiguas, cajones serie, datáfonos).
- Expone un servidor local en `127.0.0.1:9100` con JSON simple. El navegador habla con él via `fetch`.
- No es obligatorio: la mayoría de hardware moderno funciona directamente desde el navegador.

### 7.3 Justificación

- **Mismo runtime que PrestaShop** elimina conflictos de versiones, librerías y permisos.
- **Reutilización del núcleo PrestaShop** garantiza que un pedido creado desde TPV es **indistinguible** de uno creado desde la web (mismos hooks, impuestos, reglas, multi-shop).
- **Sin servicios externos obligatorios**: el cron y MySQL bastan para todo.
- **Frontend ligero** sigue siendo SPA-like en la pantalla de venta, donde se necesita reactividad. El resto (administración, reportes) puede ser server-rendered con Twig — más simple y rápido de desarrollar.

### 7.4 Servidor web y separación de procesos

**Dos vhosts independientes** (Apache o Nginx, indistintamente):

| | E-commerce | TPV |
|---|---|---|
| Hostname | `tudominio.com` | `tpv.tudominio.com` |
| DocumentRoot | `/var/www/prestashop` | `/var/www/tpv/public` |
| Certificado TLS | Propio (Let's Encrypt) | Propio (Let's Encrypt) |
| Pool PHP-FPM | `prestashop` (usuario `www-ps`) | `tpv` (usuario `www-tpv`) |
| Logs | `/var/log/.../prestashop_*.log` | `/var/log/.../tpv_*.log` |
| Acceso | Público mundial | Restringido por IP/VPN (recomendado) |
| robots.txt | El que ya tenga PrestaShop | `User-agent: *` / `Disallow: /` |

**Pools PHP-FPM separados** dan dos beneficios importantes:

1. **Aislamiento de procesos**: si el TPV consume mucha memoria por un reporte pesado, no afecta al pool de PrestaShop, que sigue sirviendo al e-commerce con normalidad.
2. **Permisos UNIX diferenciados**: el usuario `www-tpv` puede leer `/var/www/prestashop/config/config.inc.php` (necesario para el bootstrap del core), pero **no puede escribir** en `/var/www/prestashop`. El usuario `www-ps` no tiene acceso a `/var/www/tpv`. Esto reduce la superficie de ataque.

**Ejemplo de configuración Nginx para el subdominio TPV:**

```nginx
server {
    listen 443 ssl http2;
    server_name tpv.tudominio.com;

    root /var/www/tpv/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/tpv.tudominio.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tpv.tudominio.com/privkey.pem;

    # Headers de seguridad estrictos (no afectan al e-commerce)
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin" always;
    add_header Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'" always;

    # Rate limit más agresivo que el e-commerce
    limit_req zone=tpv burst=20 nodelay;

    # Restricción opcional a IPs de la tienda física / VPN
    # allow 203.0.113.0/24;
    # deny all;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.1-fpm-tpv.sock;  # Pool dedicado
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        internal;
    }

    location ~ \.php$ {
        return 404;  # Solo se ejecuta index.php
    }
}
```

---

## 8. Integración con PrestaShop

### 8.1 Estrategia: módulo en PrestaShop + aplicación en subdominio

A diferencia del enfoque "API externa", aquí el TPV vive **en el mismo servidor físico** que PrestaShop pero en un **directorio y subdominio separados**, reutilizando el núcleo de PrestaShop vía filesystem.

#### Módulo `tpvconnect` (en `/var/www/prestashop/modules/tpvconnect`)

- Estructura estándar de módulo PrestaShop: `tpvconnect.php`, `config.xml`, controladores admin, `install/sql/install.php`.
- Aporta:
  - Pestaña en el back-office "TPV" con configuración global.
  - Hooks: `actionValidateOrder`, `actionUpdateQuantity`, `actionProductUpdate` para invalidar caches del TPV.
  - Botón "Abrir TPV" que redirige a `https://tpv.tudominio.com/sso?token=...` con token firmado de un solo uso.
  - Mapeo de empleados PrestaShop ↔ usuarios TPV.
  - Definición de estados de pedido propios ("Pagado en TPV", "Devolución TPV", "Pendiente sincronización").
  - Configuración de la URL del TPV (en caso de que el cliente use otro subdominio, p.ej. `caja.tudominio.com`).

#### Aplicación TPV (en `/var/www/tpv`)

- Punto de entrada: `/var/www/tpv/public/index.php`. Único archivo accesible vía web (DocumentRoot del vhost `tpv.tudominio.com`).
- En el bootstrap:
  ```php
  // 1. Cargar el núcleo de PrestaShop por ruta absoluta de filesystem
  require_once '/var/www/prestashop/config/config.inc.php';

  // 2. Cargar las dependencias propias del TPV
  require_once __DIR__ . '/../vendor/autoload.php';

  // 3. Arrancar el Kernel propio
  $kernel = new \Tpv\Kernel($_ENV['APP_ENV'] ?? 'prod', false);
  $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
  $response = $kernel->handle($request);
  $response->send();
  ```
- A partir de aquí dispone de toda la API de PrestaShop **y** de las dependencias propias.
- Router propio (Symfony Routing) con dos grupos:
  - `/api/*` — endpoints JSON consumidos por el front del TPV.
  - `/app/*` — vistas Twig (login, panel administrativo, reportes).
  - `/sso` — endpoint que recibe el token firmado del back-office de PrestaShop.

**Nota importante sobre permisos**: el usuario UNIX bajo el que corre el pool PHP-FPM del TPV (`www-tpv`) debe tener permisos de **lectura** sobre `/var/www/prestashop/config/`, `/var/www/prestashop/classes/`, `/var/www/prestashop/vendor/` y demás directorios que carga el bootstrap del core. **No** debe tener permisos de escritura, salvo en `/var/www/prestashop/var/cache/` si se quiere que el TPV use el cache compartido (opcional, generalmente preferimos que cada uno tenga el suyo).

### 8.2 Creación de pedidos legales

El TPV **no inserta directamente** en `ps_orders`. En su lugar:

```php
$cart = new Cart();
$cart->id_customer = $customerId;
$cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
$cart->id_shop = $idShop;
// ... añadir productos vía updateQty()
$cart->save();

$paymentModule = Module::getInstanceByName('tpvconnect');
$paymentModule->validateOrder(
    (int) $cart->id,
    Configuration::get('TPV_OS_PAID'),  // estado "Pagado en TPV"
    $cart->getOrderTotal(true),
    'TPV',                               // método de pago mostrado
    null,
    ['transaction_id' => $tpvSessionId],
    null,
    false,
    $cart->secure_key
);
```

Esto garantiza que se ejecutan **todos los hooks** que cualquier otro módulo de PrestaShop pueda haber registrado (contabilidad, facturación, marketing, fidelización, etc.).

### 8.3 Tablas PrestaShop afectadas

| Tabla | Operación | Cuándo |
|---|---|---|
| `ps_orders` | INSERT (vía core) | Cierre de cada ticket |
| `ps_order_detail` | INSERT (vía core) | Por línea |
| `ps_order_payment` | INSERT (vía core) | Por método de pago |
| `ps_order_history` | INSERT (vía core) | Cambios de estado |
| `ps_stock_available` | UPDATE (vía core) | Tras venta y devolución |
| `ps_stock_mvt` | INSERT (vía core) | Trazabilidad |
| `ps_order_slip` | INSERT (vía core) | Devoluciones |
| `ps_customer` / `ps_address` | INSERT/UPDATE (vía core) | Alta cliente |
| `ps_cart` | INSERT (vía core) | Carrito asociado |

**Regla de oro**: nunca tocar tablas core con SQL directo si existe la clase de PrestaShop equivalente.

### 8.4 Estados de pedido específicos

Se crean al instalar el módulo en `ps_order_state`:

- **Pagado en TPV** (verde, marcado como pagado).
- **Devolución TPV** (gris).
- **Pendiente sincronización** (amarillo, para pedidos creados offline aún no consolidados).

---

## 9. Modelo de datos

### 9.1 Separación física de bases de datos

El TPV trabaja con **dos schemas MySQL/MariaDB** en el mismo servidor:

| Schema | Propietario | Uso desde el TPV | Credenciales |
|---|---|---|---|
| `prestashop` (existente) | PrestaShop | Solo a través de las clases del núcleo (`Order`, `Product`, `StockAvailable`, etc.). Sin SQL directo. | Las que ya tiene PrestaShop en su `parameters.php`. El TPV **no las duplica**: usa el bootstrap del core, que ya las carga. |
| `tpv` (nuevo) | TPV | A través de Doctrine DBAL desde `pos/src/Repository/`. SQL directo permitido. | Usuario MySQL propio del TPV, configurado en `pos/config/databases.yaml`. |

**Reglas de diseño:**

1. Ninguna tabla del schema `tpv` lleva prefijo `ps_`. Se usan nombres limpios (`terminal`, `session`, `movement`...).
2. Ninguna foreign key MySQL apunta cruzada entre schemas. Las referencias a `prestashop` son simples columnas `INT` con nombres explícitos (`ps_order_id`, `ps_customer_id`, `ps_product_id`).
3. El acceso a `prestashop` se hace **siempre** vía clases del núcleo, nunca con `SELECT * FROM ps_orders` desde el TPV.
4. Las únicas excepciones permitidas son lecturas de catálogo de alto rendimiento (búsqueda incremental de productos), donde se permite SQL directo de solo lectura sobre `prestashop` con queries optimizadas y cacheadas en memoria de proceso.

### 9.2 Tablas del schema `tpv`

Todas las tablas operativas del TPV viven aquí. **Nunca alteran tablas core de PrestaShop.**

```sql
-- Configuración de terminales
CREATE TABLE terminal (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ps_shop_id      INT UNSIGNED NOT NULL,           -- Referencia lógica a ps_shop
    name            VARCHAR(64) NOT NULL,
    printer_config  JSON,
    scanner_config  JSON,
    hotkeys         JSON,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Roles y permisos
CREATE TABLE role (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(32) NOT NULL UNIQUE,
    permissions  JSON NOT NULL
) ENGINE=InnoDB;

-- Usuarios del TPV (vinculados opcionalmente a empleados PrestaShop)
CREATE TABLE user (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ps_employee_id  INT UNSIGNED NULL,               -- Referencia lógica a ps_employee
    role_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(128) NOT NULL,
    pin_hash        VARCHAR(255) NOT NULL,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    FOREIGN KEY (role_id) REFERENCES role(id)
) ENGINE=InnoDB;

-- Sesiones de caja (apertura/cierre)
CREATE TABLE session (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    terminal_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    opened_at       DATETIME NOT NULL,
    closed_at       DATETIME NULL,
    opening_cash    DECIMAL(10,2) NOT NULL,
    counted_cash    DECIMAL(10,2) NULL,
    expected_cash   DECIMAL(10,2) NULL,
    difference      DECIMAL(10,2) NULL,
    FOREIGN KEY (terminal_id) REFERENCES terminal(id),
    FOREIGN KEY (user_id) REFERENCES user(id)
) ENGINE=InnoDB;

-- Movimientos de caja (ventas, devoluciones, entradas, salidas)
CREATE TABLE movement (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id    INT UNSIGNED NOT NULL,
    type          ENUM('sale','refund','cash_in','cash_out') NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    concept       VARCHAR(255),
    ps_order_id   INT UNSIGNED NULL,                 -- Referencia lógica a ps_orders
    ps_order_slip_id INT UNSIGNED NULL,              -- Referencia lógica a ps_order_slip
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES session(id),
    INDEX idx_ps_order (ps_order_id),
    INDEX idx_session_type (session_id, type)
) ENGINE=InnoDB;

-- Metadatos por pedido TPV (cobro mixto, terminal, sesión...)
CREATE TABLE order_meta (
    ps_order_id        INT UNSIGNED PRIMARY KEY,     -- Referencia lógica
    terminal_id        INT UNSIGNED NOT NULL,
    session_id         INT UNSIGNED NOT NULL,
    user_id            INT UNSIGNED NOT NULL,
    payment_breakdown  JSON NOT NULL,                -- Métodos y cantidades
    printed            TINYINT(1) NOT NULL DEFAULT 0,
    synced             TINYINT(1) NOT NULL DEFAULT 1, -- 0 si vino de cola offline
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Cola de operaciones offline pendientes de aplicar a PrestaShop
CREATE TABLE offline_queue (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    terminal_id   INT UNSIGNED NOT NULL,
    payload       JSON NOT NULL,                     -- Operación serializada
    status        ENUM('pending','processing','done','error') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    attempts      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at  DATETIME NULL,
    INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB;

-- Auditoría completa
CREATE TABLE audit_log (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    terminal_id  INT UNSIGNED NULL,
    action       VARCHAR(64) NOT NULL,
    target       VARCHAR(128) NULL,
    payload      JSON NULL,
    ip           VARCHAR(45) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_action (action)
) ENGINE=InnoDB;

-- Informes Z (cierres diarios firmados, inmutables)
CREATE TABLE z_report (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id     INT UNSIGNED NOT NULL UNIQUE,
    generated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    totals         JSON NOT NULL,
    signature_hash CHAR(64) NOT NULL,                -- SHA-256 encadenado al Z anterior
    pdf_path       VARCHAR(255),
    FOREIGN KEY (session_id) REFERENCES session(id)
) ENGINE=InnoDB;

-- Cola Symfony Messenger (transporte doctrine)
CREATE TABLE messenger_messages (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    body         LONGTEXT NOT NULL,
    headers      LONGTEXT NOT NULL,
    queue_name   VARCHAR(190) NOT NULL,
    created_at   DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX idx_queue (queue_name),
    INDEX idx_available (available_at)
) ENGINE=InnoDB;
```

### 9.3 Vinculación con `prestashop` (referencias lógicas)

Cada vez que el TPV cierra un ticket:

1. Llama a `validateOrder()` del núcleo PrestaShop → genera fila en `prestashop.ps_orders` con `id_order = X`.
2. Inserta fila en `tpv.order_meta` con `ps_order_id = X`, `payment_breakdown`, etc.
3. Inserta fila(s) en `tpv.movement` con `ps_order_id = X`.
4. Si el paso 2 o 3 falla, queda registro en `tpv.audit_log` con la incidencia y se notifica al encargado. El pedido en PrestaShop no se borra (sería una mala práctica fiscal); en su lugar se reconcilia manualmente.

**Las referencias lógicas a `prestashop` no se validan con `FOREIGN KEY`** porque cruzan schemas, pero sí se indexan para que las queries de reporte sean rápidas.

### 9.4 Configuración de credenciales

Archivo `pos/config/databases.yaml`:

```yaml
databases:
  prestashop:
    # NO se configura aquí. El TPV usa el núcleo de PrestaShop,
    # que carga sus propias credenciales desde:
    # /var/www/prestashop/app/config/parameters.php
    use_prestashop_core: true

  tpv:
    host: '127.0.0.1'
    port: 3306
    name: 'tpv'
    user: 'tpv_user'
    password: '%env(TPV_DB_PASSWORD)%'
    charset: 'utf8mb4'
    collation: 'utf8mb4_unicode_ci'
```

### 9.5 Permisos MySQL recomendados

**Usuario PrestaShop** (existente, no se toca):
```sql
-- Sus permisos actuales sobre `prestashop`. Sin cambios.
```

**Usuario TPV** (nuevo, creado por el instalador):
```sql
CREATE USER 'tpv_user'@'localhost' IDENTIFIED BY '<password seguro>';
GRANT ALL PRIVILEGES ON tpv.* TO 'tpv_user'@'localhost';
-- Sin permisos sobre `prestashop`. El TPV accede a PrestaShop
-- vía el núcleo, que usa el usuario propio de PrestaShop.
FLUSH PRIVILEGES;
```

Esta separación garantiza que un fallo de seguridad en el TPV **no compromete** la BBDD de PrestaShop, y viceversa.

---

## 10. Diseño de interfaz (UX/UI)

### 10.1 Principios

- **Una mano, una pantalla**: acciones críticas alcanzables sin desplazar la vista.
- **Botones grandes** (mínimo 56 px) para uso táctil con dedos / guantes.
- **Contraste alto** y tipografía ≥ 16 px.
- **Confirmaciones explícitas** en acciones irreversibles.
- **Atajos de teclado** mapeados a F1–F12 y teclado numérico.

### 10.2 Pantallas principales

1. **Login** — usuario + PIN numérico (4–6 dígitos).
2. **Apertura de caja** — saldo inicial, confirmación.
3. **Pantalla de venta** (la más usada):
   - **Izquierda (60%)**: catálogo en cuadrícula, pestañas por categoría, buscador en cabecera.
   - **Derecha (40%)**: ticket activo, totales, botón verde grande "COBRAR".
   - **Inferior**: barra con cliente, descuento, notas, parcar ticket, anular.
4. **Pantalla de cobro** — selección de método, teclado numérico, cálculo de cambio.
5. **Devoluciones** — buscador de tickets, selección de líneas a devolver.
6. **Cierre de caja** — conteo por denominación, resumen, impresión Z.
7. **Reportes** — gráficos, productos top, comparativa cajeros.
8. **Configuración** — terminal, hardware, métodos de pago, usuarios.

### 10.3 Tecnología del front

- **Pantalla de venta**: SPA ligera con Vue 3 standalone o Alpine.js + Tailwind.
- **Resto del panel** (administración, reportes, configuración): Twig server-rendered con interactividad puntual mediante Alpine.js. Más rápido de desarrollar y suficientemente fluido.

### 10.4 Estados visuales y modo offline

- **Verde** cobro completo / stock disponible. **Ámbar** stock bajo / descuento / parcado. **Rojo** sin stock / error / offline. **Azul** información / sincronizando.
- Banner permanente en cabecera cuando se opera sin conexión, con contador de operaciones pendientes.

---

## 11. Hardware compatible

| Tipo | Modelos / Estándares |
|---|---|
| **Lectores de código** | USB HID, Bluetooth HID, cámara web/móvil. Probados: Honeywell Voyager, Zebra DS2208, Datalogic QuickScan. |
| **Impresoras de tickets** | ESC/POS USB, red, Bluetooth. Probadas: Epson TM-T20/T88, Star TSP143, Bixolon SRP-330. Vía librería `mike42/escpos-php` en backend o vía agente local en el cliente. |
| **Cajón portamonedas** | Apertura por RJ11/RJ12 conectado a impresora ESC/POS. |
| **Visor de cliente** | Pantalla secundaria HTML5 (segunda pestaña) o display LCD compatible. |
| **TPV de cobro** | Redsys SmartPOS, Stripe Terminal, SumUp Solo, Worldline. |
| **Básculas** | Serie/USB con protocolo Dialog 06 / continuo (Dibal, Mettler). |
| **Equipos completos** | All-in-one POS con Windows 10/11 o Android, tablets ≥ 10", PCs estándar. |

**Doble vía de impresión**:
- **Servidor → impresora de red**: PHP envía ESC/POS por TCP (`mike42/escpos-php`).
- **Cliente → impresora local**: agente "TPV Bridge" o WebUSB cuando la impresora es local al cajero.

---

## 12. Seguridad y cumplimiento

### 12.1 Autenticación y autorización

- **Flujo de acceso de empleado** (dos pasos):
  1. El empleado introduce su **nombre o código** en el campo de búsqueda.
  2. El sistema busca en la tabla `user` por coincidencia exacta (case-insensitive) en `name` o `code`.
  3. Solo si se encuentra un usuario activo se muestra el **teclado numérico de PIN**.
  4. El empleado introduce su PIN (mínimo 8 caracteres; regla no visible en pantalla).
  5. En caso de PIN correcto, se inicia sesión y se redirige al panel de venta.
  6. Tras **3 intentos fallidos consecutivos**, la cuenta queda **bloqueada** (`locked_at` ≠ NULL) y se muestra el mensaje: *"Cuenta bloqueada. Contacta al administrador para desbloquearla."*
  7. Un administrador puede desbloquear la cuenta desde el panel de gestión de empleados.
- **Sesiones PHP nativas** o JWT (firebase/php-jwt) según preferencia.
- Permisos por rol con principio de mínimo privilegio.
- PIN de supervisor para descuentos altos, anulaciones, devoluciones sin ticket.
- SSO desde el back-office de PrestaShop al TPV mediante token firmado de un solo uso.
- El acceso de **administrador maestro** usa usuario + contraseña (variables de entorno `APP_ADMIN_USER` / `APP_ADMIN_PASSWORD`) sin afectar a la tabla `user`.

### 12.2 Comunicaciones

- **HTTPS obligatorio** (TLS 1.2+). El instalador valida el certificado.
- Cookies con flags `Secure`, `HttpOnly`, `SameSite=Strict`.
- CSRF tokens en todas las acciones POST/PUT/DELETE del panel.

### 12.3 Datos personales (RGPD)

- Datos de cliente almacenados solo en BBDD de PrestaShop (responsable: el comerciante).
- Logs de auditoría sin datos personales innecesarios.
- Botón de exportación y eliminación de datos por cliente.
- Política de retención configurable.

### 12.4 Cumplimiento fiscal

- **España**: preparado para **VeriFactu** y **TicketBAI** (País Vasco) — firma y envío en tiempo real cuando aplique. Implementado como servicio aislado para poder actualizar sin tocar el resto.
- **Portugal**: SAF-T y certificación AT.
- Numeración correlativa de tickets sin saltos, con serie por terminal.
- Imposibilidad de borrar tickets emitidos: las anulaciones generan abono trazable.

### 12.5 Seguridad de pagos

- El TPV **nunca almacena datos de tarjeta**.
- Los datáfonos certificados PCI-DSS hacen el cobro y devuelven solo el resultado.

### 12.6 Hardening del servidor

- **DocumentRoot apunta solo a `/var/www/tpv/public`**. El resto del código (`src/`, `vendor/`, `config/`, `var/`) queda físicamente fuera del directorio web. Imposible servir esos archivos por error de configuración.
- **Pool PHP-FPM dedicado** corriendo bajo el usuario `www-tpv`, distinto del usuario que corre PrestaShop.
- **Permisos UNIX restrictivos**: `www-tpv` puede leer pero no escribir en `/var/www/prestashop/`.
- **Acceso restringido por IP/red recomendado** en el vhost del subdominio TPV: solo IPs de tiendas físicas, VPN corporativa o rangos de confianza. Configurable en el panel administrativo durante la instalación.
- **Headers de seguridad estrictos** en el subdominio TPV: HSTS con `preload`, CSP estricta, `X-Frame-Options: DENY`. Estas reglas no afectan al e-commerce.
- **Rate limiting agresivo**: límites por IP mucho más bajos que en el e-commerce (20 req/s con burst).
- **`robots.txt` propio del subdominio** con `Disallow: /` para impedir indexación en buscadores.
- **Configuración recomendada de `open_basedir`** en el pool PHP-FPM del TPV, limitando el acceso a filesystem a `/var/www/tpv/`, `/var/www/prestashop/` (lectura) y `/tmp/`.
- **Archivos sensibles** (`/var/www/tpv/config/databases.yaml`, `/var/www/tpv/.env`) con permisos `640` y propiedad `www-tpv:www-tpv`.

---

## 13. Reportes e informes

### 13.1 Operativos (diarios)

- Informe **X** (lectura intermedia, no cierra caja).
- Informe **Z** (cierre diario, no modificable, archivado).
- Listado de tickets emitidos.
- Listado de devoluciones.
- Movimientos de caja manuales.

### 13.2 Analíticos

- Ventas por hora, día, semana, mes.
- Productos top por unidades e importe.
- Comparativa entre cajeros y entre tiendas.
- Ticket medio y unidades por ticket.
- Tasa de devolución.
- Ventas por método de pago.
- Alertas de stock cerca de rotura.

### 13.3 Exportación

- CSV, Excel (PhpSpreadsheet).
- PDF con `dompdf` para informes diarios.
- API para integraciones contables (Holded, Contasol, A3, Sage).

---

## 14. Despliegue y operación

### 14.1 Requisitos del servidor

- PHP **8.1 o superior** con extensiones: `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `curl`, `xml`, `sockets` (para impresión TCP).
- MySQL **5.7+** o MariaDB **10.4+** (la misma instancia de PrestaShop).
- **Composer** disponible en el servidor.
- Acceso a **cron** para el worker de Symfony Messenger.
- Capacidad de **crear un subdominio** con su propio vhost y certificado TLS.
- Acceso a **PHP-FPM** para crear un pool dedicado (recomendado, no obligatorio).
- Acceso al sistema de archivos fuera del directorio web de PrestaShop (típicamente `/var/www/tpv`).

**Hostings compatibles:**
- VPS y servidores dedicados: 100% compatibles.
- Hosting compartido con panel (cPanel u otros): compatible si permite crear subdominios con DocumentRoot personalizado y acceso a cron.
- Hosting administrado de PrestaShop: validar caso por caso. La capacidad de crear subdominios con DocumentRoot independiente es el requisito clave.

### 14.2 Instalación

**Pasos previos al instalador (los hace el administrador del servidor)**

1. **DNS**: añadir registro A o CNAME `tpv.tudominio.com` apuntando al servidor.
2. **Vhost**: crear el vhost en Apache/Nginx con DocumentRoot `/var/www/tpv/public`.
3. **Certificado TLS**: emitir con Let's Encrypt (`certbot --nginx -d tpv.tudominio.com`).
4. **Pool PHP-FPM dedicado** (recomendado): crear `/etc/php/8.1/fpm/pool.d/tpv.conf` con usuario `www-tpv` y socket `/run/php/php8.1-fpm-tpv.sock`.
5. **Usuario UNIX `www-tpv`** y permisos:
   ```bash
   useradd -r -s /bin/false www-tpv
   chown -R www-tpv:www-tpv /var/www/tpv
   # Permitir lectura de PrestaShop al usuario del TPV
   setfacl -R -m u:www-tpv:rX /var/www/prestashop
   ```

**Opción A — Asistente desde back-office PrestaShop (recomendado)**

1. Subir el módulo `tpvconnect` vía back-office → "Módulos".
2. Instalar; el módulo:
   - Pide al técnico la URL del subdominio TPV (ej. `https://tpv.tudominio.com`).
   - Pide los datos de la nueva BBDD `tpv` (nombre del schema, usuario, password) o los genera automáticamente.
   - Crea el schema `tpv` si tiene permisos, o muestra el SQL para que el administrador lo ejecute manualmente.
   - Crea el usuario MySQL del TPV con permisos solo sobre `tpv`.
   - Verifica conectividad con el subdominio (HTTP HEAD a `https://tpv.tudominio.com/`).
   - Si el directorio `/var/www/tpv` está vacío, descomprime allí los archivos del TPV.
   - Ejecuta las migraciones que crean todas las tablas de `tpv`.
   - Genera `/var/www/tpv/config/databases.yaml` con las credenciales.
   - Genera `/var/www/tpv/.env` con secretos (clave SSO compartida con el módulo, JWT secret).
   - Ejecuta `composer install --no-dev` desde el directorio del TPV.
   - Crea los estados de pedido nuevos en PrestaShop.
   - Genera el primer usuario administrador del TPV.
3. Configurar el primer terminal (impresora, lector, métodos de pago) desde el TPV.
4. Botón "Abrir TPV" en el back-office → redirige a `https://tpv.tudominio.com/sso?token=...` → ya operativo.

**Opción B — Manual (técnicos)**

1. Completar los pasos previos al instalador (DNS, vhost, certificado, pool PHP-FPM, usuario UNIX).
2. `git clone` del repo en `/var/www/tpv`.
3. Crear la BBDD `tpv` y el usuario:
   ```sql
   CREATE DATABASE tpv CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'tpv_user'@'localhost' IDENTIFIED BY '<password>';
   GRANT ALL PRIVILEGES ON tpv.* TO 'tpv_user'@'localhost';
   FLUSH PRIVILEGES;
   ```
4. `cd /var/www/tpv && composer install --no-dev`.
5. Editar `config/databases.yaml` con las credenciales de `tpv`.
6. Editar `.env` con `APP_ENV=prod` y secretos.
7. Ejecutar migraciones: `php bin/console doctrine:migrations:migrate`.
8. Subir el módulo a `/var/www/prestashop/modules/tpvconnect` e instalar desde back-office.
9. En el módulo, configurar la URL del TPV (`https://tpv.tudominio.com`) y la clave compartida del SSO.
10. Programar cron del worker:
    ```
    * * * * * www-tpv php /var/www/tpv/bin/console messenger:consume --time-limit=55
    ```

### 14.3 Actualizaciones

- A través del Marketplace de PrestaShop (si se distribuye comercialmente) o por upload manual del módulo.
- El proceso de update conserva configuración y datos `ps_tpv_*`.
- Sistema de migraciones de BBDD versionadas (Doctrine Migrations o propias).

### 14.4 Backup

- **Dos BBDD que respaldar**: `prestashop` (con su política habitual) y `tpv` (frecuencia recomendada: cada hora durante horario comercial, diaria fuera de él).
- El instalador genera un script `/var/www/tpv/bin/backup-tpv.sh` que hace `mysqldump` solo del schema `tpv`. Pensado para ejecutar por cron.
- **Filesystem a respaldar**:
  - `/var/www/tpv/var/reports/` (PDFs de informes Z firmados).
  - `/var/www/tpv/config/` (credenciales y configuración).
  - `/var/www/tpv/.env` (secretos).
- **Nota crítica**: dado que `tpv.order_meta.ps_order_id` referencia lógicamente a `prestashop.ps_orders`, los backups deben coordinarse: un restore parcial de solo `tpv` puede dejar referencias huérfanas. Hay un comando `php /var/www/tpv/bin/console tpv:reconcile` que detecta y reporta inconsistencias.

### 14.5 Monitorización

- Endpoint `https://tpv.tudominio.com/api/health` con estado de BBDD `tpv`, conexión al núcleo de PrestaShop, cola Messenger y espacio en disco.
- Logs en `/var/www/tpv/var/log/` rotados con `logrotate`.
- Logs de Apache/Nginx separados por vhost facilitan el análisis.
- Compatible con cualquier herramienta de monitorización estándar (UptimeRobot, Better Uptime, etc.). Se recomienda monitorizar el endpoint `/api/health` cada minuto.

---

## 15. Roadmap por fases

### Fase 1 — MVP (meses 1–3)

- Módulo `tpvconnect` con instalador, hooks y SSO.
- Aplicación `/pos` con bootstrap del núcleo PrestaShop.
- Pantalla de venta básica con escaneo y cobro efectivo/tarjeta manual.
- Generación de pedidos vía `validateOrder` del core.
- Ticket ESC/POS por TCP y vía agente local.
- Apertura/cierre de caja simple.
- Multi-cajero con roles básicos.

### Fase 2 — Producción (meses 4–6)

- Modo offline en cliente (Service Worker + IndexedDB) con cola sincronizable.
- Devoluciones y abonos (`ps_order_slip`).
- Integración con datáfonos (Redsys, Stripe Terminal).
- Reportes X/Z y analíticos básicos.
- Cupones y reglas PrestaShop aplicables en TPV.
- PDF y envío por email del ticket.

### Fase 3 — Avanzado (meses 7–9)

- Multi-tienda con selector activo.
- Cumplimiento fiscal (VeriFactu, TicketBAI).
- Visor de cliente, báscula, segunda pantalla.
- Integraciones contables.
- PWA instalable en tablets.

### Fase 4 — Escalado (meses 10–12)

- Distribución comercial en el Marketplace de PrestaShop.
- Plantillas de configuración por tipo de comercio (moda, alimentación, óptica…).
- IA para sugerencia de venta cruzada en el ticket.
- Dashboard agregado para cadenas con varias tiendas PrestaShop.

---

## 16. Riesgos y mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Conflictos de versión de Composer entre TPV y PrestaShop | Media | Alto | El TPV mantiene su propio `vendor/` aislado en `/pos/vendor`; solo `require` el `config.inc.php` de PrestaShop, sin mezclar autoloads. Tests en cada versión soportada. |
| Cambios en la BBDD entre versiones de PrestaShop | Alta | Alto | La BBDD `tpv` es independiente y queda al margen de las migraciones de PrestaShop. El acceso a `prestashop` se hace solo vía clases del core, que se adaptan a cambios. |
| Hosting compartido sin permiso para `CREATE DATABASE` | Media | Medio | El instalador detecta la falta de permisos y muestra el SQL para que el administrador del hosting cree el schema `tpv` manualmente. Listado público de hostings compatibles. |
| Hosting que no permite crear subdominios con DocumentRoot independiente | Baja | Alto | Documentación clara con listado de hostings compatibles. Modo fallback de instalación en subdirectorio (`tudominio.com/pos`) disponible pero no recomendado. |
| Configuración incorrecta del vhost que expone el código fuera de `public/` | Baja | Alto | Asistente de instalación incluye un comprobador que hace HTTP GET a rutas sensibles (`/.env`, `/config/databases.yaml`, `/vendor/`) y verifica que devuelven 403 o 404. |
| Usuario UNIX del TPV sin permisos para leer el núcleo de PrestaShop | Media | Alto | El instalador valida la lectura de `config.inc.php` antes de finalizar y aplica `setfacl` automáticamente si tiene privilegios. |
| Inconsistencia entre `prestashop` y `tpv` (p.ej. pedido creado pero `order_meta` no insertado) | Baja | Medio | Patrón saga con compensación: si el segundo paso falla, se registra incidencia en `audit_log` y se notifica al encargado. Comando `tpv:reconcile` detecta huérfanos y los reporta. |
| Restore parcial de una sola BBDD que rompa referencias lógicas | Baja | Alto | Documentación clara para administradores: las dos BBDD se backup-ean y restauran como conjunto. Comando de verificación post-restore. |
| Caída de internet prolongada | Media | Medio | Modo offline en cliente; reconciliación al volver. |
| Servidor saturado por TPV en hora punta | Media | Alto | Cache agresiva del catálogo en la sesión del cliente; queries optimizadas; **opcional** OPcache + Redis si la instalación lo requiere. |
| Impresora ESC/POS no detectada | Media | Bajo | Doble vía: TCP desde servidor o agente local; asistente de detección en la configuración del terminal. |
| Comerciante con módulos PrestaShop personalizados | Media | Alto | Sandbox de pruebas que replica un pedido completo antes del go-live. |
| Cumplimiento fiscal cambiante (VeriFactu) | Alta | Alto | Servicio fiscal aislado, actualizable independientemente. |
| Permisos de filesystem incorrectos tras instalación | Media | Medio | El instalador valida y corrige permisos de `/pos/var/` y `/pos/public/uploads/`. |

---

## 17. Anexos

### 17.1 Estructura de archivos del producto

```
/var/www/
│
├── prestashop/                          ← DocRoot de tudominio.com
│   ├── modules/
│   │   └── tpvconnect/                  ← Módulo PrestaShop (única pieza
│   │       ├── tpvconnect.php           │   del TPV que vive aquí)
│   │       ├── config.xml
│   │       ├── views/
│   │       ├── controllers/admin/
│   │       ├── install/
│   │       │   ├── install.php
│   │       │   └── sql/
│   │       └── upgrade/
│   └── ...
│
└── tpv/                                 ← DocRoot de tpv.tudominio.com
    │                                       (solo /public es servido)
    ├── bin/
    │   ├── console
    │   └── backup-tpv.sh
    ├── config/
    │   ├── routes.yaml
    │   ├── services.yaml
    │   ├── databases.yaml               ← Conexiones (tpv + ref a PS core)
    │   └── packages/
    ├── public/                          ← ÚNICO directorio expuesto vía web
    │   ├── index.php
    │   ├── robots.txt                   ← Disallow: /
    │   └── assets/                      ← compilados (Vite/Tailwind)
    ├── src/
    │   ├── Controller/
    │   │   ├── Api/                     ← endpoints JSON
    │   │   ├── App/                     ← vistas Twig
    │   │   └── SsoController.php        ← recibe token del back-office PS
    │   ├── Service/
    │   │   ├── PrestaShop/              ← envoltorios sobre el core PS
    │   │   ├── Cash/                    ← caja y arqueo (sobre BBDD tpv)
    │   │   ├── Print/                   ← ESC/POS y PDF
    │   │   ├── Fiscal/                  ← VeriFactu, TicketBAI
    │   │   └── Db/
    │   │       └── ConnectionManager.php
    │   ├── Repository/                  ← Doctrine DBAL sobre BBDD tpv
    │   ├── Migrations/                  ← Migraciones de BBDD tpv
    │   └── Kernel.php
    ├── templates/                       ← Twig
    ├── assets/                          ← fuentes JS/CSS
    ├── tests/
    ├── var/
    │   ├── cache/
    │   ├── log/
    │   └── reports/                     ← Informes Z firmados
    ├── vendor/                          ← Composer
    ├── .env                             ← Secretos (640 www-tpv:www-tpv)
    ├── composer.json
    └── package.json
```

**Permisos UNIX recomendados:**

```bash
# Propiedad
chown -R www-tpv:www-tpv /var/www/tpv

# Permisos generales
find /var/www/tpv -type d -exec chmod 750 {} \;
find /var/www/tpv -type f -exec chmod 640 {} \;

# Ejecutables
chmod 750 /var/www/tpv/bin/console
chmod 750 /var/www/tpv/bin/backup-tpv.sh

# Lectura del núcleo PrestaShop por el usuario del TPV
setfacl -R -m u:www-tpv:rX /var/www/prestashop
```

### 17.2 Glosario

- **TPV**: Terminal Punto de Venta.
- **ESC/POS**: protocolo estándar Epson para impresoras de tickets.
- **HID**: Human Interface Device (un lector como teclado).
- **Informe Z**: cierre diario fiscal, no modificable.
- **PrestaShop**: e-commerce open source PHP/MySQL.
- **VeriFactu**: sistema AEAT de facturación verificable en tiempo real.
- **TicketBAI**: equivalente del País Vasco.
- **Mercure**: protocolo de notificaciones server-sent compatible con PHP.

### 17.3 Referencias técnicas

- Documentación oficial PrestaShop: estructura BBDD, hooks, módulos.
- Symfony Components 6.x.
- ESC/POS Command Reference (Epson) y `mike42/escpos-php`.
- PCI-DSS para integraciones de pago.
- Reglamento (UE) 2016/679 (RGPD).
- Real Decreto 1007/2023 (VeriFactu).

### 17.4 Criterios de aceptación

El TPV se considera **listo para producción** cuando:

1. Se instala completo en un PrestaShop 8.x limpio en menos de 30 minutos.
2. Un cajero formado puede cobrar 100 tickets seguidos sin error.
3. El stock de PrestaShop coincide con el físico tras una jornada, con error < 0,1%.
4. Una caída de internet de 2 horas no provoca pérdida de venta (modo offline cliente).
5. El cierre Z cuadra con < 0,5 € de diferencia en pruebas controladas.
6. Una devolución completa se refleja como abono en el back-office en menos de 5 segundos.
7. Pruebas de carga con 20 terminales concurrentes sobre la misma instalación PrestaShop sin degradación perceptible (CPU < 70%, latencia < 300 ms).
8. Auditoría de seguridad sin vulnerabilidades de severidad alta o crítica.

---

*Fin del documento.*
