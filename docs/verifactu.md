# VERI*FACTU

**Estado (5 de noviembre de 2026): implementado en parte y sin usar.** El club no dispone todavía del certificado electrónico necesario para enviar los registros a la AEAT, así que VERI*FACTU queda desactivado y bloqueado. Se configurará más adelante, cuando haya certificado y se haya completado el envío (ver [Qué falta para activarlo](#qué-falta-para-activarlo)).

El seguimiento técnico completo (requisitos, tareas, pruebas y decisiones) está en `odd/tasks/verifactu.md`.

## Qué funciona ya, siempre

Aunque VERI*FACTU esté desactivado, el TPV hace esto en cada venta y cada devolución:

- **Registro fiscal encadenado.** Cada ticket (tipo `F2`, factura simplificada) y cada abono (tipo `R5`, rectificativa de simplificada, con importes negativos) genera un registro en la tabla `fiscal_record`, en la misma transacción que le da el número. Si la venta falla, no queda registro.
- **Facturas completas por canje** (tipo `F3`, «factura emitida en sustitución de facturas simplificadas»; ver `docs/factura-completa.md`). Al canjear un ticket por factura se graba un registro `F3` encadenado con los demás, con su número `F26000001`, en la misma transacción que le da el número. Se enlaza con la factura por `invoice_id` (no por `order_id`): el ticket conserva su registro `F2`, que no se anula ni se modifica. La huella usa los mismos campos que el resto. Los datos que el envío a la AEAT necesitará (destinatario, desglose por tipo, ticket sustituido y fecha de operación) quedan guardados en la tabla `invoice`.
- **Huella (hash) según la AEAT.** Cada registro lleva la huella SHA-256 calculada como indica la especificación oficial de la AEAT (v0.1.2), encadenada con la del registro anterior. Se comprueba con los tres ejemplos oficiales del documento.
- **Registros inalterables.** El TPV nunca modifica ni borra registros. Si el servidor lo permite, la base de datos también rechaza cualquier modificación o borrado (triggers); en hostings que no permiten crear triggers, la comprobación de la cadena detecta cualquier cambio.
- **Fechas en hora española** (`Europe/Madrid`), sea cual sea la zona horaria del servidor. El año del número (el `26` de `A26001307`, `R2026-000001` o `F26000001`) y el contador anual que usa también son los de la hora española en el momento de la emisión: un ticket emitido a las 00:30 del 1 de enero en España lleva el año nuevo aunque el servidor esté en UTC y allí aún sea 31 de diciembre. Así el número, la fecha del registro fiscal y la línea del diario de un mismo documento siempre tienen el mismo año. **Ajustes → Cajas** muestra y cambia el próximo número de ese mismo año.
- **Tipo de IVA en el ticket** (art. 7.1.f del Reglamento de facturación). Cada línea de la venta guarda su tipo de IVA (tabla `sale_line`, deducido de los precios con y sin IVA del catálogo y ajustado a 21, 10, 4 o 0 %). El ticket imprime una línea por tipo, «IVA 21 %: 18,40 € (base 87,60 €)», redondeando una vez por tipo; la cuota del registro fiscal (`tax_total`) es la suma de esas cuotas, así que ticket y registro coinciden al céntimo. Las ventas anteriores (sin líneas, o con líneas rellenadas después con `tpv:sales:backfill-lines`, que son aproximadas) siguen imprimiendo «IVA: …» con el IVA que se guardó al venderlas: reimprimir un ticket nunca cambia sus importes.

### Comprobar la cadena

En **Ajustes → VERI*FACTU → Comprobar registros** (solo administradores). Recalcula todas las huellas e indica:

- si la cadena es correcta y cuántos registros tiene;
- el primer registro alterado o el hueco, si lo hay;
- si la base de datos protege los registros (triggers) o solo lo hace la aplicación.

También está disponible como `GET /api/fiscal/chain`.

### Después de restaurar una copia: recuperar los documentos

Cada documento emitido se anota también en el diario fiscal, fuera de la base de datos (`backups/fiscal-journal/`). Si se restaura una copia de seguridad anterior a los últimos documentos, la caja se bloquea para no repetir números. Para volver a vender, el administrador recupera esos documentos desde el diario, con el botón **Ajustes → VERI*FACTU → Diario fiscal → «Recuperar documentos»** (solo administradores; aparece mientras la caja está bloqueada): primero lista los documentos que faltan sin escribir nada y, al confirmar, los recupera. El botón ejecuta lo mismo que la orden de consola:

```bash
php bin/console tpv:fiscal:replay          # lista lo que falta, no escribe nada (sale con 2)
php bin/console tpv:fiscal:replay --apply  # lo recupera (sale con 0)
```

La recuperación vuelve a poner en la base de datos cada ticket, rectificativa y factura posterior a la copia, en orden y con sus números, ids, importes y registros fiscales tal cual (misma huella), de modo que la cadena sigue siendo correcta y los contadores continúan después del último número. Se hace en una sola transacción: si falla o se interrumpe, la base de datos queda como estaba, y repetirla no cambia nada. Solo se ejecuta mientras la caja está bloqueada. Una fila que la copia ya tenía en un estado anterior (una devolución pendiente que se completó después, un cambio reintentado después) se deja como la guarda el diario; si el id de un movimiento de caja, de una subida a la tienda o de una sesión de caja lo ocupa otra fila creada después de restaurar (por ejemplo una entrada de caja manual), la fila del diario se guarda con un id nuevo y la orden lo lista como «Reasignado». Los ids de registros fiscales, ventas, devoluciones y facturas no cambian nunca. Si la base de datos solo va por delante del diario (documentos aún sin anotar) no hay nada que recuperar: lo anota el siguiente `tpv:cron`. Si la última línea del diario quedó a medio escribir, el siguiente `tpv:cron` la repara y después se puede recuperar. No se ejecuta si el diario está dañado; si la copia es anterior al diario conservado sale con 3 y si una fila ya existe con otro contenido, con 4. No recupera los cierres Z ni el cierre de las sesiones de caja posteriores a la copia (vuelven abiertas: hay que cerrarlas de nuevo), el stock, el estado de impresión, los clientes nuevos, los tickets aparcados ni los movimientos de caja manuales; las subidas a la tienda vuelven a la cola y la tienda no las duplica.

Mientras la caja está bloqueada no se registra nada: el servidor responde 423 (`fiscal_journal_behind`) a cualquier petición que cambie datos (ventas, devoluciones, facturas, movimientos de caja, aperturas y cierres, cupones, clientes, tickets aparcados, ajustes, y también a cualquier función que se añada más adelante), para que nada ocupe los ids que necesita la recuperación. Siguen funcionando la consulta (catálogo, informes, Ajustes, PDF), entrar y salir, asignar este equipo a una caja que ya existe y la recuperación misma. La caja muestra en todas las pantallas el aviso «Caja bloqueada: la base de datos está por detrás del diario fiscal», con dónde recuperar los documentos. El bloqueo se comprueba en cada petición; el «no bloqueado» se reutiliza como mucho 5 segundos y deja de valer en cuanto cambia el último registro fiscal de la base de datos (lo que hace una restauración). Las tareas de `tpv:cron` siguen, salvo la sincronización de clientes, que espera («Esperando: la base de datos está por detrás del diario fiscal…») y se ejecuta en el minuto siguiente a la recuperación.

La guía para la tienda (dónde se guarda el diario, qué muestra la caja cuando se bloquea, cómo restaurar paso a paso y qué no se recupera) está en [diario-fiscal.md](diario-fiscal.md). El diario lo crea el paso de instalación «Diario fiscal preparado» (`fiscal-journal`, [provisioning.md](provisioning.md)), que en una instalación con documentos anteriores los anota marcados como `backfill`. Si el diario se pierde o se daña, `tpv:fiscal:journal:reset --confirm "<motivo>"` empieza uno nuevo desde la base de datos: pregunta antes a la tienda por todos los diarios que guarda (si no puede, se niega salvo con `--shop-unavailable`; si la tienda tiene documentos que la base de datos no tiene, se niega salvo con `--accept-shop-ahead`, solo para empezar de cero a propósito), archiva el anterior en `archive/<id>-<fecha UTC>/` (nunca lo borra), lo anota en `audit_log` y se niega, sin escribir nada, mientras la base de datos vaya por detrás del diario, de lo que aún se lee de un diario dañado o de la tienda.

### Copia del diario en la tienda

Cada línea del diario se envía también a la tienda PrestaShop (módulo TPV Connect 0.9.0 o posterior) con las subidas de cada minuto, en orden y una sola vez; la tienda no la guarda dos veces. Cada minuto el TPV pregunta a la tienda por el último documento de cada serie de todos los diarios que tiene, sea cual sea el diario que conoce la base de datos. Si la tienda tiene un documento que la base de datos no tiene, la caja se bloquea igual que con el diario del servidor («La base de datos está por detrás de la copia en la tienda»). Si el servidor ha perdido su diario (carpeta borrada, servidor nuevo) y la base de datos dice que tenía uno, la caja se bloquea hasta que la tienda confirme que la base de datos no va por detrás; desde entonces se puede vender, pero nada se anota en el diario hasta iniciar uno nuevo con `tpv:fiscal:journal:reset` (Ajustes y un correo lo avisan). Una tienda caída, lenta o con un módulo anterior nunca bloquea ni frena una venta: Ajustes → VERI*FACTU → Diario fiscal dice «Copia en la tienda no disponible: actualiza el módulo TPV Connect a la versión 0.9.0. Mientras tanto el diario se guarda solo en el servidor.» y los documentos se suben solos cuando se actualiza el módulo. Un documento cuyo número no cabe en la copia de la tienda (un pedido con cupón con una referencia de pedido con espacios u otros signos) queda como incidencia y solo en el diario del servidor.

## Qué está desactivado

- **QR tributario y leyenda «VERI*FACTU» en los tickets.** El código existe (ESC/POS y PDF, arriba del ticket, con «QR tributario:» encima y «VERI*FACTU» debajo), pero no se imprime.
- **El interruptor de Ajustes está bloqueado.** No se puede activar mientras el TPV no envíe los registros a la AEAT: un ticket con la leyenda «VERI*FACTU» cuyo registro no llega a Hacienda incumple el artículo 16.1 del Reglamento y el cotejo del QR respondería «Factura no encontrada». El servidor rechaza la activación con el mensaje «VERI*FACTU no se puede activar todavía: esta versión del TPV aún no envía los registros a la AEAT».

## Qué falta para activarlo

1. **Certificado electrónico** del club (o de un representante autorizado) para el servicio web de la AEAT.
2. **Envío de los registros a la AEAT**, con reintentos y control de incidencias. Al terminarlo, se cambia `VerifactuSettings::SUBMISSION_AVAILABLE` a `true` y el interruptor de Ajustes queda disponible.
3. **Contenido completo de cada registro** según el anexo de la Orden HAC/1177/2024: bloque `SistemaInformatico` (nombre, NIF, identificador, versión, número de instalación…), desglose de IVA, datos del registro anterior, y en los abonos `TipoRectificativa` y `FacturasRectificadas`.
4. **Registros de anulación.**
5. **Declaración responsable** del sistema (artículo 13 del Reglamento y artículo 15 de la Orden), visible dentro del TPV y entregada al cliente.
6. **Respuestas del asesor fiscal** (ver más abajo).

### Producción

El servidor solo aplica migraciones desde el instalador (`install.php`). Antes de desplegar esta versión en una instalación existente hay que aplicar la migración `Version00000042FiscalRecord`. Mientras no se aplique, el TPV sigue vendiendo con normalidad y deja constancia en el log de cada ticket emitido sin registro fiscal.

En la primera instalación en producción la migración se aplicó el 5 de noviembre de 2026 (se aplicaron las migraciones 40, 41 y 42 pendientes). El servidor permite triggers, así que la base de datos también impide modificar o borrar registros. Desde ese día cada ticket y cada abono genera su registro encadenado.

## Contexto legal (resumen)

Revisado en los textos consolidados del BOE el 5 de noviembre de 2026; conviene confirmarlo con el asesor fiscal.

- **Fechas en vigor** (Real Decreto-ley 15/2025): antes del **1 de enero de 2027** para quienes tributan por el Impuesto sobre Sociedades y antes del **1 de julio de 2027** para el resto. Hacienda anunció el 5 de octubre de 2026 un aplazamiento a octubre de 2028 que aún no está publicado en el BOE.
- **Modo NO VERI*FACTU.** Un sistema que no envía los registros a la AEAT debe cumplir requisitos adicionales: firma electrónica XAdES de los registros, registro de eventos, exportación y consulta de la cadena. **El TPV actual no los cumple**, así que a partir de la fecha obligatoria no debe usarse en ese modo.
- **Modo VERI*FACTU.** Si el sistema envía todos los registros, el artículo 3 de la Orden HAC/1177/2024 le exime de la firma, el registro de eventos y la exportación. Es la vía prevista para VenPosTPV.
- **Sanciones** (artículo 201 bis de la Ley General Tributaria): 150.000 € por ejercicio y tipo de sistema para el productor del software, 1.000 € por cada sistema sin certificar comercializado y 50.000 € por ejercicio para quien lo usa.

### Preguntas para el asesor fiscal

1. ¿El club está totalmente exento del Impuesto sobre Sociedades o parcialmente exento? ¿Las ventas del TPV son rentas sujetas y no exentas?
2. ¿El club está en el SII (que excluye de VERI*FACTU) o tiene alguna resolución de no aplicación?
3. Al desarrollar el TPV para uso propio, ¿el club es el productor y debe firmar la declaración responsable?
4. ¿Se planifica para el 1 de enero de 2027 o se espera al aplazamiento anunciado?
5. En los abonos, ¿rectificativa por diferencias (`I`) o por sustitución (`S`)?
6. ¿Hay operaciones no sujetas o exentas de IVA (cuotas, servicios deportivos) que deban codificarse aparte?
7. ¿El certificado será del club o se enviará a través de un representante (colaboración social)?

## Referencias

- [Orden HAC/1177/2024, texto consolidado (BOE)](https://boe.es/buscar/pdf/2024/BOE-A-2024-22138-consolidado.pdf)
- [Real Decreto 1007/2023 (BOE)](https://www.boe.es/buscar/act.php?id=BOE-A-2023-24840)
- [AEAT: especificaciones de la huella](https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/IVA/VERI-FACTU/Veri-Factu_especificaciones_huella_hash_registros.pdf)
- [AEAT: especificaciones del código QR](https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/IVA/VERI-FACTU/DetalleEspecificacTecnCodigoQRfactura.pdf)
- [AEAT: preguntas frecuentes VERI*FACTU](https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/cuestiones-generales.html)

## Código

- `src/Service/Fiscal/FiscalHash.php`: huella según la AEAT.
- `src/Service/Fiscal/FiscalRecorder.php`: grabación encadenada dentro de la transacción de la venta o del abono.
- `src/Service/Fiscal/FiscalChainVerifier.php`: comprobación de la cadena.
- `src/Service/Fiscal/FiscalRecordTriggers.php`: triggers que impiden modificar o borrar.
- `src/Service/Fiscal/Journal/FiscalReplay.php` y `src/Command/FiscalReplayCommand.php`: recuperación de documentos desde el diario fiscal tras restaurar una copia (`tpv:fiscal:replay`).
- `src/Service/Fiscal/FiscalQr.php`: URL de cotejo del QR tributario.
- `src/Service/Fiscal/VerifactuSettings.php`: interruptor y bloqueo (`SUBMISSION_AVAILABLE`).
- `src/Controller/Api/FiscalController.php`: `GET /api/fiscal/chain`, `GET/PUT /api/fiscal/settings`.
- `migrations/Version00000042FiscalRecord.php`: tablas `fiscal_record` y `fiscal_chain`.
- `migrations/Version00000047FullInvoice.php`: columna `fiscal_record.invoice_id` (única) para los registros `F3`.
