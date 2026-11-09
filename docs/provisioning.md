# Pasos de instalación y actualización (provisioning)

**Regla:** toda funcionalidad nueva que necesite preparar algo para funcionar (una migración, datos en PrestaShop, un ajuste inicial) añade un **paso de provisioning idempotente**. Así la instalación y cada actualización lo hacen solas; nadie tiene que acordarse de ejecutar una orden a mano.

## Cómo funciona

Todos los pasos están en un único registro: cada clase que implementa `VenPosTPV\Service\Provisioning\ProvisioningStep` (en `src/`) se registra sola por autoconfiguración y `ProvisioningRunner` los ejecuta en orden. Se ejecutan:

| Cuándo | Cómo | Espera tras un fallo |
|---|---|---|
| Al instalar | `public/install.php`, justo después de las migraciones y de escribir `.env` | no |
| Cada minuto | `tpv:sync:outbox`, antes de subir las ventas | 15 minutos por paso; un paso **bloqueado**, nunca |
| A mano | `php bin/console tpv:provision --env=prod` | no |

`php bin/console tpv:provision --status --env=prod` solo informa. Las dos terminan con 0 si todo está hecho y con 1 si queda algo pendiente.

En cada ejecución, para cada paso: si `isDone()` es verdadero no se hace nada; si no, se llama a `run()`. Un fallo se guarda con su motivo en `app_setting` (`provisioning.<id>`: motivo y hora) y **no detiene los pasos siguientes**. La tarea de cada minuto no vuelve a intentar un paso que falló hasta 15 minutos después (`ProvisioningRunner::RETRY_AFTER_SECONDS`), para no llamar a PrestaShop cada minuto si la tienda está caída o el módulo no está instalado. Un paso hecho borra su fila.

### Pasos bloqueados

Hay fallos que reintentar no arregla y que reintentar puede empeorar (una migración que falló a medias). Un paso los señala lanzando `ProvisioningBlockedException`: la fila guarda además `blocked`, `block_key` y `reported_at`, y la tarea de cada minuto **deja de ejecutarlo**. Cada 15 minutos, cuando le habría tocado, solo escribe `Provisioning: <id> blocked: <motivo>`, sin ejecutar nada. Ajustes lo muestra como «Bloqueado» (`status: "blocked"` en `GET /api/settings/provisioning`) y `tpv:provision --status` como `blocked`, los dos con el motivo.

Solo vuelve a intentarse:

- con `php bin/console tpv:provision --env=prod` a mano (una vez por ejecución; si falla, queda bloqueado otra vez con el nuevo motivo), o
- desde la tarea de cada minuto, si el paso implementa `BlockableProvisioningStep` y su `blockKey()` ya no es el del bloqueo (en `migrations`: otro conjunto de migraciones pendientes, es decir, una versión nueva).

Los pasos pendientes y su motivo se ven en **Ajustes → Acerca de → Estado de la instalación** (`GET /api/settings/provisioning`, solo administradores) y en la última página del instalador. En la instalación es normal que los pasos que necesitan PrestaShop queden pendientes: el módulo TPV Connect se configura después. La instalación no falla por ellos.

## Pasos actuales

| Orden | `id` | Qué hace | Hecho cuando |
|---|---|---|---|
| 1 (prioridad 1000) | `migrations` | Aplica las migraciones pendientes de `migrations/` (el mismo `MigrationRunner` que el instalador) | no queda ninguna migración pendiente |
| 2 (prioridad 100) | `free-item-products` | Crea o comprueba en PrestaShop los productos genéricos del artículo libre (`FreeItemSetupService`, lo mismo que `tpv:free-item:setup`) | el artículo libre está desactivado (`free_item.enabled` = 0: no llama a PrestaShop), o `free_item.ps_products` tiene el producto y los 20 huecos de cada IVA de `free_item.vat_rates` |

### Migraciones desde la tarea de cada minuto

Aplicar migraciones en producción sin que nadie lo lance es una decisión delicada, así que el paso `migrations` tiene dos protecciones:

- **Copia de seguridad antes.** En una base de datos ya instalada (con migraciones ejecutadas) primero se hace una copia con el mismo servicio que `tpv:backup:database`, pero con su propio nombre: `backups/venpostpv-premigrate-<fecha>-<hora>.sql.gz`. La retención diaria (7 copias) no la toca nunca; de estas se guardan las 3 últimas (`DatabaseBackup::PREMIGRATE_KEEP`) y, aparte, las 3 últimas marcadas `-REVISAR`, así que tampoco se acumulan. Una copia nunca sustituye a otra del mismo segundo. Si la copia no se puede escribir, **no se migra** y se reintenta a los 15 minutos. Si su comprobación de la base de datos (`CHECK TABLE`, cadena fiscal) encuentra problemas, **no se migra** y el paso queda **bloqueado**. Una base de datos vacía (instalación nueva) no tiene nada que copiar.
- **Una sola a la vez.** Un bloqueo de MySQL (`GET_LOCK('venpostpv_migrations', 0)`) impide que dos ejecuciones migren a la vez. La que no lo consigue no espera ni lo cuenta como fallo: lo vuelve a mirar el minuto siguiente. MySQL libera el bloqueo si el proceso muere.

Si una migración falla, el paso queda **bloqueado** y el motivo dice en qué copia está la base de datos de antes. MySQL confirma cada cambio de estructura por separado, así que una migración que falla a medias deja aplicados sus primeros `ALTER` sin registrar la versión, y repetirla vuelve a fallar (por ejemplo, «1060 Duplicate column name»). Por eso la tarea de cada minuto no lo reintenta: si lo hiciera, haría una copia nueva de la base de datos a medio migrar cada 15 minutos. Para recuperarte: restaura la copia que nombra el motivo o corrige la base de datos a mano, y ejecuta `php bin/console tpv:provision --env=prod` (ver «Actualizar el TPV» en `docs/despliegue-plesk.md` o `docs/despliegue-hosting.md`).

## Añadir un paso

1. Crea una clase en `src/Service/Provisioning/` (o junto a su funcionalidad) que implemente `ProvisioningStep`:
   - `id()`: identificador estable en minúsculas con guiones; da nombre a la fila `provisioning.<id>`.
   - `description()`: qué hace, en español, para Ajustes y el instalador.
   - `isDone()`: barato y sin efectos; se llama cada minuto.
   - `run()`: idempotente (repetirlo no cambia nada); si no puede terminar, lanza `ProvisioningException` con el motivo en español y qué hacer. `ProvisioningBusyException` significa «otro proceso lo está haciendo» y no cuenta como fallo. `ProvisioningBlockedException` bloquea el paso (ver «Pasos bloqueados»); úsala solo cuando reintentarlo solo no pueda arreglarlo.
2. Ponle `#[AsTaggedItem(priority: N)]` para su orden (más alto, antes). Si necesita el esquema nuevo, que vaya después de `migrations` (prioridad menor de 1000).
3. Añade su prueba unitaria (hecho, pendiente, motivo del fallo) y, si cambia algo que ve el administrador, actualiza esta tabla.

No hace falta tocar el instalador, la tarea de cada minuto ni Ajustes: los tres usan el mismo registro.
