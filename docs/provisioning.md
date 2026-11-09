# Pasos de instalación y actualización (provisioning)

**Regla:** toda funcionalidad nueva que necesite preparar algo para funcionar (una migración, datos en PrestaShop, un ajuste inicial) añade un **paso de provisioning idempotente**. Así la instalación y cada actualización lo hacen solas; nadie tiene que acordarse de ejecutar una orden a mano.

## Cómo funciona

Todos los pasos están en un único registro: cada clase que implementa `VenPosTPV\Service\Provisioning\ProvisioningStep` (en `src/`) se registra sola por autoconfiguración y `ProvisioningRunner` los ejecuta en orden. Se ejecutan:

| Cuándo | Cómo | Espera tras un fallo |
|---|---|---|
| Al instalar | `public/install.php`, justo después de las migraciones y de escribir `.env` | no |
| Cada minuto | `tpv:sync:outbox`, antes de subir las ventas | 15 minutos por paso |
| A mano | `php bin/console tpv:provision --env=prod` | no |

`php bin/console tpv:provision --status --env=prod` solo informa. Las dos terminan con 0 si todo está hecho y con 1 si queda algo pendiente.

En cada ejecución, para cada paso: si `isDone()` es verdadero no se hace nada; si no, se llama a `run()`. Un fallo se guarda con su motivo en `app_setting` (`provisioning.<id>`: motivo y hora) y **no detiene los pasos siguientes**. La tarea de cada minuto no vuelve a intentar un paso que falló hasta 15 minutos después (`ProvisioningRunner::RETRY_AFTER_SECONDS`), para no llamar a PrestaShop cada minuto si la tienda está caída o el módulo no está instalado. Un paso hecho borra su fila.

Los pasos pendientes y su motivo se ven en **Ajustes → Acerca de → Estado de la instalación** (`GET /api/settings/provisioning`, solo administradores) y en la última página del instalador. En la instalación es normal que los pasos que necesitan PrestaShop queden pendientes: el módulo TPV Connect se configura después. La instalación no falla por ellos.

## Pasos actuales

| Orden | `id` | Qué hace | Hecho cuando |
|---|---|---|---|
| 1 (prioridad 1000) | `migrations` | Aplica las migraciones pendientes de `migrations/` (el mismo `MigrationRunner` que el instalador) | no queda ninguna migración pendiente |
| 2 (prioridad 100) | `free-item-products` | Crea o comprueba en PrestaShop los productos genéricos del artículo libre (`FreeItemSetupService`, lo mismo que `tpv:free-item:setup`) | `free_item.ps_products` tiene el producto y los 20 huecos de cada IVA de `free_item.vat_rates` |

### Migraciones desde la tarea de cada minuto

Aplicar migraciones en producción sin que nadie lo lance es una decisión delicada, así que el paso `migrations` tiene dos protecciones:

- **Copia de seguridad antes.** En una base de datos ya instalada (con migraciones ejecutadas) primero se hace una copia con el mismo servicio que `tpv:backup:database` (`backups/venpostpv-…sql.gz`). Si la copia falla, o su comprobación de la base de datos (`CHECK TABLE`, cadena fiscal) encuentra problemas, **no se migra**: el motivo queda en Ajustes y se reintenta a los 15 minutos. Corrige el problema o aplica las migraciones a mano. Una base de datos vacía (instalación nueva) no tiene nada que copiar.
- **Una sola a la vez.** Un bloqueo de MySQL (`GET_LOCK('venpostpv_migrations', 0)`) impide que dos ejecuciones migren a la vez. La que no lo consigue no espera ni lo cuenta como fallo: lo vuelve a mirar el minuto siguiente. MySQL libera el bloqueo si el proceso muere.

Si una migración falla, el motivo dice en qué copia está la base de datos de antes.

## Añadir un paso

1. Crea una clase en `src/Service/Provisioning/` (o junto a su funcionalidad) que implemente `ProvisioningStep`:
   - `id()`: identificador estable en minúsculas con guiones; da nombre a la fila `provisioning.<id>`.
   - `description()`: qué hace, en español, para Ajustes y el instalador.
   - `isDone()`: barato y sin efectos; se llama cada minuto.
   - `run()`: idempotente (repetirlo no cambia nada); si no puede terminar, lanza `ProvisioningException` con el motivo en español y qué hacer. `ProvisioningBusyException` significa «otro proceso lo está haciendo» y no cuenta como fallo.
2. Ponle `#[AsTaggedItem(priority: N)]` para su orden (más alto, antes). Si necesita el esquema nuevo, que vaya después de `migrations` (prioridad menor de 1000).
3. Añade su prueba unitaria (hecho, pendiente, motivo del fallo) y, si cambia algo que ve el administrador, actualiza esta tabla.

No hace falta tocar el instalador, la tarea de cada minuto ni Ajustes: los tres usan el mismo registro.
