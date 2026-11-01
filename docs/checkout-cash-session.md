# Cobros y sesión de caja

`POST /api/checkout` requiere una sesión de caja abierta del usuario autenticado antes de crear el pedido en PrestaShop. Si no existe, responde:

```json
{
  "error": "No hay caja abierta. Abre la caja antes de cobrar.",
  "code": "cash_session_closed",
  "retry": false
}
```

con HTTP `409`. El pedido no se crea, PrestaShop no recibe la solicitud y el carrito se conserva. Los errores de contexto o infraestructura siguen respondiendo como errores internos (`500`).

Si PrestaShop ya creó el pedido y se detecta un fallo al guardar metadatos, registrar el movimiento o actualizar el stock, el pedido se devuelve como éxito con `saga_error: true`. La aplicación conserva la identidad del pedido, intenta completar los pasos locales restantes, registra la incidencia en auditoría y vacía el carrito para no inducir un reintento duplicado.

El registro de movimientos y el cierre Z adquieren el mismo bloqueo de fila de sesión dentro de sus propias transacciones. El cierre utiliza lecturas actuales de los movimientos para incluir un movimiento aceptado antes del cierre; un movimiento posterior a un cierre completado se rechaza sin insertarse. La carrera anterior se reprodujo con dos procesos en MariaDB desechable (100 € frente a 125 € esperados), y la prueba concurrente pasa con la corrección.

`addMovement()` y `closeSession()` deben iniciar su propia transacción. Si el llamador ya tiene una activa, lanzan `RuntimeException` con el mensaje `Cash session mutations cannot run inside an existing transaction` antes de consultar o modificar la caja o su caché; no confirman, revierten ni reintentan la transacción del llamador.

Para ejecutar las pruebas de concurrencia sin acceder a la base de datos real:

```bash
bash tests/Integration/Service/Cash/run-cash-close-atomicity.sh
```

El runner requiere la imagen local `mariadb:11`, usa un puerto efímero de loopback y datos sintéticos, y elimina su propio contenedor al terminar. La comparación temporal con la versión anterior comprobó que los flujos secuenciales habituales de venta, devolución, entrada/salida de efectivo, rechazo de otro usuario y cierre conservan las mismas salidas, errores y códigos de salida. Se ejecutó contra otra MariaDB desechable, sin acceder a datos reales.

La interfaz confirma el estado con `GET /api/cash/current`; una respuesta `open: false` muestra la apertura de caja y elimina el estado `sale`. El estado local no conserva la pantalla de venta: mientras el estado de caja es desconocido, un usuario autenticado ve la apertura de caja. Las respuestas antiguas no pueden sobrescribir una apertura, un cierre o un cierre de sesión más recientes. La plantilla versiona `useSession.js` para que una respuesta HTML nueva no ejecute una copia almacenada del control de caja. Un `409` al abrir caja solo pasa a venta si esa comprobación confirma una sesión abierta del usuario actual; una sesión ajena no se adopta, cierra ni transfiere.
