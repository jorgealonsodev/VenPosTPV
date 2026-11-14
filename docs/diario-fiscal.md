# Diario fiscal

Guía para quien lleva la tienda o instala el TPV. Explica qué es el diario fiscal, dónde se guarda, qué hace la caja cuando se bloquea y cómo restaurar una copia de seguridad sin perder tickets ni repetir números.

## Qué es

Con VERI*FACTU no se puede perder ningún ticket ni repetir un número. La base de datos guarda los tickets, pero si algún día hay que restaurar una copia de seguridad, todo lo vendido después de esa copia desaparecería de la base de datos y la caja volvería a usar números ya emitidos.

Para que eso no pase, el TPV anota cada documento que emite (ticket, factura rectificativa por devolución y factura completa) en el **diario fiscal**: un archivo aparte de la base de datos donde solo se añaden líneas, nunca se cambian ni se borran. Cada línea guarda el documento con su número, sus importes y su registro fiscal tal cual, encadenada con la anterior, así que se nota si alguien la cambia.

El diario no hay que activarlo ni mantenerlo: lo crea la instalación (o la actualización) y se anota solo.

## Dónde se guarda

Hay tres copias:

| Dónde | Qué es |
|---|---|
| `backups/fiscal-journal/` | La copia principal, junto a las copias de la base de datos. |
| `var/fiscal-journal/` | La segunda copia: recibe las líneas mientras la principal no se puede escribir y las devuelve a la principal en cuanto se puede. |
| La tienda PrestaShop | Cada línea se envía también a la tienda (módulo TPV Connect 0.9.0 o posterior) con las subidas de cada minuto. Así, aunque se pierda el servidor, la tienda sabe cuál fue el último número de cada serie. |

Las dos carpetas del servidor no se pueden abrir desde la web: están fuera de `public/`, solo las lee el usuario de la suscripción (permisos 0700, archivos 0600) y llevan un `.htaccess` que lo deniega todo, igual que `backups/`. El paquete de instalación no las incluye y no las sustituye al actualizar. La limpieza diaria de copias de seguridad (las 7 más recientes) nunca toca el diario.

- **No borres** `backups/fiscal-journal/` ni `var/fiscal-journal/`, tampoco al actualizar.
- **Descarga de vez en cuando** la carpeta `backups/fiscal-journal/` junto con la copia de la base de datos y el archivo `.env`, y guárdala fuera del servidor.
- El diario ocupa unos 2 KB por documento. El TPV no borra nunca nada de él. Si algún día quieres liberar espacio a mano, no borres nunca un mes más reciente que la copia de seguridad más antigua que guardas, ni ninguno de los últimos cuatro años completos (obligación de conservar los registros). La copia en la tienda no la borra nunca el TPV.

## Instalación y actualización

No hay ningún paso a mano. El paso de instalación **«Diario fiscal preparado»** (en la última página del instalador y en **Ajustes → Acerca de → Estado de la instalación**):

- crea las dos carpetas con sus protecciones (y las vuelve a cerrar si alguien cambió los permisos);
- la primera vez, empieza el diario y anota en él los tickets y facturas que la tienda ya había emitido antes de esta versión, marcados como anteriores al diario y con sus números de siempre. Si hay muchos, la actualización anota los primeros 500 y la tarea de cada minuto termina el resto sin parar la venta.

Antes de empezar el diario, el paso pregunta a la tienda por **todos** los diarios que guarda. Si la tienda tiene un documento que esta base de datos no tiene (bajo cualquier diario), no empieza ninguno, porque el siguiente ticket repetiría su número, y la caja queda bloqueada. Pasa al restaurar una base de datos anterior al diario, y también, **a propósito**, al instalar desde cero con la base de datos vacía contra una tienda que ya tiene el diario de una instalación anterior: el TPV no puede distinguir una cosa de la otra. Si de verdad se empieza de cero (una reinstalación, nunca después de restaurar una copia), el administrador empieza el diario con:

```bash
php bin/console tpv:fiscal:journal:reset --confirm "Reinstalación desde cero" --accept-shop-ahead --env=prod
```

Una tienda caída, sin el módulo o con un error no frena nunca la instalación ni la venta; una tienda que todavía no tiene ninguna línea del diario (lo normal al actualizar una instalación en uso) tampoco.

Si el paso queda «Pendiente», el motivo dice qué hacer (normalmente, los permisos de la carpeta del TPV).

## Qué ves en Ajustes

En **Ajustes → VERI*FACTU → Diario fiscal** (solo administradores):

- «Diario fiscal al día: último documento A26000124 (14/11/2026 18:02).» Todo bien.
- «Diario fiscal con 3 documentos pendientes de anotar; se anotan solos cada minuto.» Normal justo después de vender.
- «El diario fiscal lleva más de 5 minutos sin poder anotarse. Revisa los permisos de la carpeta backups/fiscal-journal.» Se puede seguir vendiendo: los documentos esperan en la base de datos y se anotan solos cuando la carpeta vuelve a funcionar. Llega también un correo a la dirección de «Avisar de copias de seguridad fallidas a:» (Ajustes → Email).
- «La base de datos está por detrás del diario fiscal…» o «…por detrás de la copia en la tienda…»: se ha restaurado una copia antigua. Ver [Restaurar una copia de seguridad](#restaurar-una-copia-de-seguridad).
- «El diario fiscal tiene una línea dañada…» o «Faltan líneas o archivos del diario fiscal…»: ver [Si el diario se pierde o se daña](#si-el-diario-se-pierde-o-se-daña).
- «Copia en la tienda al día…» o «Copia en la tienda no disponible: actualiza el módulo TPV Connect a la versión 0.9.0. Mientras tanto el diario se guarda solo en el servidor.» Con un módulo antiguo se sigue vendiendo; los documentos se envían solos al actualizarlo.

## Cuando la caja se bloquea

Si la base de datos tiene menos documentos que el diario o que la copia en la tienda (se ha restaurado una copia de seguridad anterior a los últimos tickets), la caja **no deja vender, devolver, facturar ni hacer otros cambios de caja**, y muestra:

> Caja bloqueada: la base de datos se ha restaurado desde una copia anterior a los últimos documentos. Avisa al administrador (Ajustes → VERI*FACTU → Diario fiscal).

Es a propósito: si siguiera vendiendo, el siguiente ticket tendría un número ya emitido. Se pueden seguir abriendo Ajustes, el catálogo y los informes. El aviso sale en cuanto se abre o se recarga la página de la caja. La caja se desbloquea en cuanto se recuperan los documentos.

Mientras la caja está bloqueada, la tarea de cada minuto (`tpv:cron`) deja esperando la sincronización de clientes y la **copia de seguridad nocturna**: una copia de la base de datos restaurada, que va por detrás, no sirve, y varias noches así acabarían borrando por rotación las copias buenas anteriores. En Ajustes aparecen como «Esperando: la base de datos está por detrás del diario fiscal…» y se ejecutan solas en cuanto se recuperan los documentos.

## Restaurar una copia de seguridad

1. Restaura la copia de la base de datos como siempre (cómo hacerlo: «Copias de seguridad y restauración» en [despliegue-plesk.md](despliegue-plesk.md#copias-de-seguridad-y-restauración)). Usa la copia más reciente que tengas.
2. Abre el TPV con un usuario administrador y ve a **Ajustes → VERI*FACTU → Diario fiscal**.
3. Si dice que la base de datos está por detrás del diario fiscal, pulsa **Recuperar documentos**. Primero ves la lista de los documentos que faltan (número, tipo y fecha) y no se escribe nada.
4. Pulsa **Recuperar N documentos**. Vuelven a la base de datos en orden, con sus números, importes y registros fiscales tal cual, y los contadores siguen después del último. Si algo falla, la base de datos se queda como estaba y puedes repetirlo.
5. El mensaje «Recuperados N documentos… La caja puede volver a emitir documentos.» confirma que se puede vender otra vez.
6. Revisa lo que no se recupera (abajo).

Con acceso por consola es lo mismo:

```bash
php bin/console tpv:fiscal:replay --env=prod          # lista lo que falta, no escribe nada
php bin/console tpv:fiscal:replay --apply --env=prod  # lo recupera
```

Si la copia restaurada es más antigua que el diario que se conserva, no se puede recuperar: aparece «La copia restaurada es anterior al diario conservado (desde {fecha}). Restaura una copia más reciente.».

### Qué no se recupera

El diario guarda los documentos fiscales y lo que los acompaña (las líneas de venta, el cobro, la devolución, la factura). No guarda todo lo demás, así que después de recuperar revisa:

- **Los movimientos de caja manuales** (entradas y salidas de dinero hechas a mano) posteriores a la copia: vuelve a apuntarlos.
- **Los clientes creados después de la copia**: vuelve a crearlos o espera a que se sincronicen desde la tienda.
- **El stock**: no se toca; la sincronización con la tienda lo corrige sola.
- Los cierres Z y el cierre de las sesiones de caja posteriores a la copia: las sesiones vuelven abiertas, ciérralas de nuevo.
- El estado de impresión, los tickets aparcados, los cambios de ajustes, usuarios, cajas y numeración, y los cupones creados con «Crear cupón».

Las subidas a la tienda de lo recuperado vuelven a la cola; la tienda no duplica pedidos ni devoluciones.

### Copias de seguridad de versiones anteriores

Las copias de la base de datos hechas con versiones anteriores a esta no guardaban algunas fechas: al restaurarlas, la fecha de creación de ventas, movimientos, devoluciones y subidas a la tienda queda con la hora de la restauración. Las fechas de los tickets y de los registros fiscales no cambian. Las copias hechas con esta versión conservan todas las fechas.

## Si el diario se pierde o se daña

**Diario dañado.** Si una línea del diario se ha modificado o el disco la ha estropeado, Ajustes lo dice («El diario fiscal tiene una línea dañada en {archivo} (línea {n})») y llega un correo. El TPV no lo reescribe nunca: se sigue vendiendo y las líneas nuevas se siguen añadiendo. Para ver el detalle: `php bin/console tpv:fiscal:journal --verify-all --env=prod`. Una última línea que quedó a medio escribir (un corte de luz justo al anotarla) no es daño: el TPV la aparta a `quarantine/` y la vuelve a escribir sola.

**Diario perdido** (un servidor nuevo al que no se copiaron las carpetas, una carpeta borrada): la base de datos sabe que tenía un diario y la caja se bloquea hasta que la copia en la tienda confirme que la base de datos no va por detrás. Si la tienda tiene documentos que la base de datos no tiene, restaura una copia más reciente de la base de datos. Cuando la tienda lo confirma **se puede volver a vender, pero el diario no se está anotando** (ni se copia en la tienda) hasta que se ejecute `tpv:fiscal:journal:reset`: Ajustes lo dice («Falta el diario fiscal de este servidor: se puede vender, pero los documentos no se anotan…»), llega un correo y la tarea `tpv:fiscal:journal` sale con 3, una vez por incidencia y otra cada 24 horas mientras dure.

En los dos casos, cuando ya está claro que la base de datos tiene todos los documentos, el administrador empieza un **diario nuevo** desde la base de datos:

```bash
php bin/console tpv:fiscal:journal:reset --confirm "Servidor nuevo, el diario no se copió" --env=prod
```

- Antes de cambiar nada **pregunta a la tienda** por todos los diarios que guarda (también los de reinicios anteriores o de un diario que la base de datos no conoce). Si la tienda tiene, en cualquiera de ellos, documentos que la base de datos no tiene, se niega: recupéralos con `tpv:fiscal:replay --apply` si el diario los tiene, o restaura una copia más reciente de la base de datos.
- Solo para **empezar de cero a propósito** (una reinstalación con la base de datos vacía contra una tienda que tiene el diario de la instalación anterior), `--accept-shop-ahead` deja empezar aunque la tienda tenga esos documentos: **sus números se pueden volver a emitir**, así que nunca se usa después de restaurar una copia de seguridad. Los diarios que la tienda tiene en ese momento quedan **retirados** (no vuelven a bloquear la caja) y se anotan en `audit_log`.
- Si no se puede preguntar a la tienda (caída, un módulo TPV Connect que no puede contestar por todos sus diarios, un error), también se niega. Solo si sabes que la tienda no puede tener la copia, repite la orden con `--shop-unavailable`. **Cuidado**: si la base de datos se restauró de una copia anterior, así se pueden repetir números.
- El motivo es obligatorio y queda en el registro de auditoría (`audit_log`, acción `fiscal_journal_reset`), con el diario anterior, lo archivado, si la tienda lo confirmó o se usó `--shop-unavailable`, y los diarios retirados con `--accept-shop-ahead`.
- **No se borra nada**: los archivos del diario anterior se mueven tal cual a `archive/<id del diario>-<fecha>/` dentro de `backups/fiscal-journal/` y de `var/fiscal-journal/`.
- El diario nuevo anota todos los documentos que tiene la base de datos (si son muchos, la tarea de cada minuto anota el resto) y se envía de nuevo a la tienda.
- **No se ejecuta** mientras la base de datos está por detrás del diario (recupera antes los documentos con **Recuperar documentos**), ni cuando lo que aún se puede leer de un diario dañado (las líneas sanas, `head.json`, los documentos a medio anotar) nombra documentos que la base de datos no tiene, ni mientras la tienda los tiene: con un diario nuevo se repetirían números. Sale con 3 y no escribe nada (tampoco aparta a `quarantine/` una última línea a medio escribir).

**Lo que no se puede detectar.** Si se restauran a la vez la base de datos y la tienda desde copias anteriores, y además se pierden las dos carpetas del diario, ya no queda ninguna copia de los últimos documentos: ni el servidor ni la tienda saben que existieron, y el TPV volvería a emitir sus números. Guarda siempre las carpetas del diario con las copias de seguridad y no restaures la tienda sin avisar al administrador del TPV.

Si se han perdido a la vez la base de datos, el diario y la copia en la tienda, no hay forma de saber el último número: ajusta la numeración a mano como explica «Continuar la numeración a mano» en [despliegue-plesk.md](despliegue-plesk.md#copias-de-seguridad-y-restauración).

## Órdenes de consola

| Orden | Para qué |
|---|---|
| `tpv:fiscal:journal` | La lanza `tpv:cron` cada minuto: anota lo pendiente y comprueba el diario. `--verify-all` lo comprueba entero ahora. |
| `tpv:fiscal:replay [--apply]` | Recupera los documentos tras restaurar una copia (lo mismo que el botón). |
| `tpv:fiscal:journal:reset --confirm "<motivo>" [--shop-unavailable] [--accept-shop-ahead]` | Empieza un diario nuevo cuando el anterior se ha perdido o está dañado, o desde cero a propósito (`--accept-shop-ahead`). Códigos de salida: 0 hecho, 1 error, 2 falta el motivo, 3 rechazado porque la base de datos va por detrás o no se ha podido preguntar a la tienda. |
| `tpv:provision` | Ejecuta ahora los pasos pendientes de la instalación, entre ellos «Diario fiscal preparado». |

Todas se ejecutan con `php bin/console … --env=prod` desde la carpeta del TPV (en Plesk, una tarea «Ejecutar un script PHP» con esos argumentos y **Ejecutar ahora**).
