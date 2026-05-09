# PRD — Sistema TPV para Tienda de Ropa (integrado con PrestaShop)

**Versión:** 2.0
**Fecha:** Mayo 2026
**Estado:** Versión preliminar funcionando, en evolución
**Arquitectura:** TPV cliente — PrestaShop como sistema maestro

---

## 1. Resumen ejecutivo

TPV para tienda física de ropa que opera como **cliente de un PrestaShop maestro**. PrestaShop es la fuente única de verdad para catálogo, variantes (talla/color), precios y stock; el TPV consume y registra ventas, devoluciones y movimientos de caja, sincronizándolos de vuelta. Este modelo evita el clásico problema del doble inventario entre tienda física y online, pero exige sincronización fiable y observable.

### 1.1. Estado actual de la versión preliminar

| Módulo | Estado |
|---|---|
| Venta básica y cobro | ✅ Implementado |
| Stock y variantes (talla/color) | ✅ Implementado |
| Devoluciones y cambios | ✅ Implementado |
| Cierre de caja | ✅ Implementado |
| Clientes / fidelización | ✅ Implementado |
| Modo offline | ✅ Implementado |
| Descuentos | ✅ Implementado |
| Aparcar tickets / tickets en espera | ✅ Implementado |
| Gestión de tickets (consulta, reimpresión, anulación) | ✅ Implementado |
| Conexión con PrestaShop | ✅ Operativa (PrestaShop = maestro) |
| **Indicador visible de estado de sincronización** | ⚠️ Pendiente |
| **Cumplimiento Veri\*Factu / Ley Antifraude** | ⚠️ Pendiente |
| Apartados / reservas con señal | ⚠️ Por definir |
| Promociones avanzadas (2x1, 3x2, segunda al X%) | ⚠️ Por definir |
| Multi-tienda | ⚠️ Por definir |
| Informes y analítica avanzada | ⚠️ Por definir |
| Tarjeta regalo / vale con saldo | ⚠️ Por definir |

### 1.2. Objetivos de esta iteración

- **Hacer visible y auditable la sincronización** TPV ↔ PrestaShop (saber en todo momento si está al día).
- Cerrar el cumplimiento fiscal (Veri\*Factu).
- Endurecer la resolución de conflictos cuando el TPV trabaja offline y PrestaShop sigue vendiendo.
- Definir las funcionalidades aún pendientes con criterios de aceptación claros.

---

## 2. Arquitectura de integración con PrestaShop

### 2.1. Modelo maestro–cliente

PrestaShop es el **sistema maestro**. El TPV:

- **Lee** de PrestaShop: catálogo, atributos (combinaciones talla/color), precios, stock, clientes, promociones, impuestos.
- **Escribe** en PrestaShop: ventas (pedidos), devoluciones, movimientos de stock derivados, altas/actualizaciones de cliente generadas en tienda.
- **No mantiene** un catálogo local divergente: cualquier cambio de producto se hace en PrestaShop y se propaga.

### 2.2. Reglas de oro

1. Si un dato existe en PrestaShop, manda PrestaShop.
2. Si el TPV crea un dato nuevo (ej. cliente nuevo en caja), se sube a PrestaShop y a partir de ahí PrestaShop manda.
3. El stock se descuenta en PrestaShop **en el momento de cerrar el ticket**, no antes.
4. En modo offline, el TPV trabaja contra una caché local y encola operaciones; al recuperar conexión, se reconcilia.

### 2.3. Mecanismo técnico (recomendado)

- API REST de PrestaShop (Webservice) o módulo intermedio dedicado.
- Webhooks o polling para cambios entrantes (precios, stock, nuevos productos).
- Cola de salida en el TPV con reintentos exponenciales y dead-letter para fallos persistentes.
- Identificadores cruzados: el TPV guarda `id_prestashop` para producto, combinación, cliente y pedido.

---

## 3. Estado de sincronización (módulo nuevo, prioritario)

Esta es la pieza que hoy falta y la que más tranquilidad da al usuario de tienda. La sincronización debe ser **visible, accionable y auditable**.

### 3.1. Indicador en la barra superior del TPV

Siempre visible, en todas las pantallas:

| Estado | Color | Significado |
|---|---|---|
| 🟢 **Al día** | Verde | Última sync OK hace < umbral (p. ej. 60 s) y cola vacía |
| 🟡 **Sincronizando** | Ámbar | Hay operaciones en cola subiendo o bajando |
| 🟠 **Retrasado** | Naranja | Última sync OK hace > umbral pero hay conexión |
| 🔴 **Sin conexión / Offline** | Rojo | No hay conexión con PrestaShop; modo offline activo |
| ⚫ **Error de sincronización** | Gris/rojo | Operación rechazada por PrestaShop, requiere atención |

Al pulsar el indicador se abre el **panel de sincronización**.

### 3.2. Panel de sincronización (detalle)

Información mostrada:

- Hora de la última sincronización completa con éxito (entrante y saliente).
- Versión/última actualización del catálogo descargado.
- **Cola de salida**: número de tickets, devoluciones y movimientos pendientes de subir, con desglose y antigüedad.
- **Cola de entrada**: cambios de PrestaShop pendientes de aplicar (productos nuevos, cambios de precio, ajustes de stock).
- **Conflictos abiertos**: operaciones rechazadas con motivo y acción sugerida.
- Latencia media de las últimas N llamadas.
- Botones: "Sincronizar ahora", "Reintentar fallidos", "Ver log".

### 3.3. Reglas de frescura por dato

| Dato | Frecuencia objetivo | Acción si está obsoleto |
|---|---|---|
| Stock por variante | ≤ 30 s | Aviso al añadir línea: "stock no confirmado" |
| Precios | ≤ 5 min | Aviso visible si > 1 h sin actualizar |
| Catálogo (alta/baja) | ≤ 15 min | Permite venta de lo cacheado, marca como "posiblemente desactualizado" |
| Clientes | Bajo demanda + push | Búsqueda fuerza llamada en línea si hay conexión |
| Pedidos creados en TPV | Inmediato (al cerrar ticket) | Si falla, queda en cola y el ticket se marca "pendiente de subir" |

### 3.4. Resolución de conflictos

Casos típicos y política por defecto:

| Conflicto | Política |
|---|---|
| TPV vende offline una unidad que online ya se había vendido | Marcar incidencia, notificar a encargado, sugerir contacto con cliente online |
| PrestaShop cambia el precio mientras el TPV está cobrando | Respetar el precio del ticket en curso, aplicar el nuevo a partir del siguiente |
| Producto borrado en PrestaShop pero presente en caché | Bloquear nuevas ventas; permitir devoluciones |
| Cliente duplicado (creado offline y online a la vez) | Merge automático por email/teléfono; si no coincide, marcar para revisión |
| Pedido no aceptado por PrestaShop (error de validación) | Quedar en cola de errores con detalle; encargado puede reintentar o marcar como "venta solo en TPV" |

### 3.5. Criterios de aceptación

- El estado de sincronización es visible en < 1 segundo desde cualquier pantalla.
- Tras un corte de internet de hasta 8 horas, todas las operaciones offline se reconcilian sin pérdida.
- Cualquier conflicto queda registrado con: timestamp, usuario, datos antes/después y acción tomada.
- El log de sincronización es exportable (CSV) por rango de fechas.

---

## 4. Alcance funcional

### 4.1. Módulos ya implementados (consolidación)

Aunque ya funcionan, conviene fijar los **criterios de aceptación** para evitar regresiones cuando se evolucione la integración con PrestaShop:

#### Venta y cobro
- Lectura por código de barras de la combinación (no solo del producto padre).
- Pago mixto (efectivo + tarjeta + Bizum + vale).
- Cálculo de cambio.
- Impresión de ticket simplificado y factura completa a petición.
- Apertura de cajón en pagos en efectivo.

#### Stock y variantes (talla/color)
- Lectura de combinaciones desde PrestaShop.
- Reserva lógica al añadir línea al ticket; descuento real al cerrar.
- Consulta rápida "¿hay esta talla?" con el stock vivo de PrestaShop.

#### Devoluciones y cambios
- Búsqueda de ticket original.
- Devolución total o parcial.
- Devolución de dinero, cambio por otro artículo o vale.
- Reincorporación de stock notificada a PrestaShop.

#### Cierre de caja
- Apertura con saldo declarado.
- Arqueo intermedio.
- Cierre Z con cuadre por método de pago, descuadre y desglose por vendedor.
- Bloqueo de modificación tras cierre.

#### Clientes y fidelización
- Ficha sincronizada con PrestaShop.
- Histórico de compras (online + tienda).
- Programa de puntos / vales.
- Cumplimiento RGPD (consentimientos registrados).

#### Modo offline
- Catálogo y precios cacheados localmente.
- Cobro funcional sin conexión.
- Cola de operaciones para subir.
- Aviso visible de modo offline.

#### Descuentos
- Por línea (% o importe).
- Sobre el total.
- Por código promocional traído de PrestaShop.
- Descuentos de empleado con autorización.

#### Aparcar y gestionar tickets
- Aparcar ticket en curso, recuperarlo después o desde otra caja.
- Listado de tickets aparcados con caducidad.
- Consulta histórica de tickets, reimpresión con marca "duplicado", anulación con motivo.

### 4.2. Módulos pendientes (este PRD los incorpora)

#### 4.2.1. Cumplimiento Veri\*Factu / Ley Antifraude — **CRÍTICO**

- Registro inalterable de cada factura/ticket con hash encadenado.
- Firma digital de los registros.
- Código QR en factura/ticket conforme a especificación AEAT.
- Envío automático a la AEAT (modo Veri\*Factu) o conservación firmada (modo no-Veri\*Factu).
- Imposibilidad de borrar registros; correcciones siempre vía rectificativa.
- Tique BAI si la tienda está en País Vasco.

#### 4.2.2. Apartados / reservas

- Reserva de combinación con plazo configurable (48–72 h por defecto).
- Señal opcional cobrada como pago a cuenta.
- Bloqueo del stock en PrestaShop durante el apartado.
- Aviso al cliente y liberación automática al vencer.
- Conversión a venta o cancelación con devolución de la señal.

#### 4.2.3. Promociones avanzadas

- 2x1, 3x2, segunda unidad al X%.
- Promociones por categoría, marca o etiqueta.
- Vigencia con fecha/hora de inicio y fin.
- Aplicación automática al detectar condiciones en el ticket.
- Sincronización con las reglas de promoción ya definidas en PrestaShop.

#### 4.2.4. Tarjeta regalo / vale con saldo

- Emisión con código y saldo asignado.
- Canje parcial (saldo restante queda disponible).
- Caducidad configurable.
- Consulta de saldo desde el TPV y desde PrestaShop.
- Idealmente, canjeable también en la tienda online.

#### 4.2.5. Multi-tienda (si aplica a futuro)

- Stock por tienda visible al cobrar ("hay esta talla en otra tienda").
- Traspasos entre tiendas con albarán.
- Venta en una tienda con recogida en otra o envío a domicilio (usando logística de PrestaShop).
- Informes consolidados y por tienda.

#### 4.2.6. Informes y analítica

- Ventas por día, semana, mes y año, con comparativa interanual.
- Top y bottom de productos vendidos en tienda física vs. online.
- Margen bruto por producto, categoría y tienda.
- Productividad por vendedor (tickets, importe, ticket medio, unidades por ticket).
- Tasa de devolución y motivos.
- Indicadores de salud de la sincronización: nº de conflictos, latencia media, % operaciones reintentadas.

---

## 5. Requisitos no funcionales

### 5.1. Rendimiento

- Lectura de código a línea ≤ 500 ms (incluyendo consulta a caché de stock).
- Cierre de ticket y subida a PrestaShop ≤ 2 s en condiciones normales.
- Reconciliación de hasta 500 tickets offline en ≤ 5 min al volver la conexión.

### 5.2. Disponibilidad

- 99,5% en horario comercial.
- Modo offline indefinido para venta básica.
- Caída de PrestaShop no debe impedir cobrar en tienda.

### 5.3. Seguridad

- Comunicación TPV ↔ PrestaShop por HTTPS con token rotable.
- Permisos del usuario API de PrestaShop limitados a lo estrictamente necesario.
- Log de auditoría de operaciones sensibles (anulaciones, descuentos extraordinarios, cambios de precio en línea).
- Backup cifrado de la base local del TPV.

### 5.4. Cumplimiento legal

- RGPD (datos de clientes y empleados; consentimientos; derecho a baja).
- RD 1007/2023 (sistemas SIF / Veri\*Factu).
- Reglamento UE 1007/2011 (etiquetado textil).
- Conservación de registros fiscales ≥ 4 años.

---

## 6. Roadmap propuesto

| Fase | Plazo | Alcance |
|---|---|---|
| **Sprint actual** | 0–4 semanas | Indicador y panel de estado de sincronización; log y exportación |
| **Sprint +1** | 4–8 semanas | Veri\*Factu, firma y QR en tickets/facturas |
| **Sprint +2** | 8–12 semanas | Tarjeta regalo, apartados, promociones avanzadas |
| **Sprint +3** | 12–20 semanas | Informes y analítica, multi-tienda (si aplica) |

---

## 7. Riesgos y mitigaciones

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Sobreventa entre tienda física y online | Alto | Reserva lógica al añadir línea + sync de stock < 30 s + protocolo de conflicto |
| Caída prolongada de PrestaShop | Alto | Modo offline robusto + alerta automática a encargado |
| Cambios en la API de PrestaShop al actualizar | Medio | Capa de abstracción del cliente API + tests de regresión |
| Conflictos de stock tras offline largo | Medio | Reconciliador con UI clara y bitácora de incidencias |
| Incumplimiento Veri\*Factu | Crítico (sanción) | Priorizar en roadmap; auditoría externa antes de salir a producción |
| Doble alta de cliente (tienda + online) | Bajo | Merge por email/teléfono y revisión manual ante ambigüedad |

---

## 8. Glosario

- **Maestro**: sistema que tiene la versión autoritativa de un dato. En este caso, PrestaShop.
- **Combinación**: en PrestaShop, una variante concreta talla/color de un producto.
- **Cola de salida / entrada**: operaciones pendientes de propagarse del TPV a PrestaShop o viceversa.
- **Veri\*Factu**: sistema español de facturación verificable (RD 1007/2023).
- **Cierre Z**: informe oficial de cierre diario.
- **Reconciliación**: proceso de poner en orden datos divergentes tras un periodo offline.
