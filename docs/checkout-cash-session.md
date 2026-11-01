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

Este manejo de errores no hace atómicos el registro de movimientos y el cierre Z. Existe un riesgo previo, inferido del código y no reproducido dinámicamente, si dos sesiones del mismo cajero registran un movimiento y cierran la caja a la vez; requiere una revisión de concurrencia separada.

La interfaz confirma el estado con `GET /api/cash/current`; una respuesta `open: false` muestra la apertura de caja y elimina el estado `sale`. El estado local no conserva la pantalla de venta: mientras el estado de caja es desconocido, un usuario autenticado ve la apertura de caja. Las respuestas antiguas no pueden sobrescribir una apertura, un cierre o un cierre de sesión más recientes. La plantilla versiona `useSession.js` para que una respuesta HTML nueva no ejecute una copia almacenada del control de caja. Un `409` al abrir caja solo pasa a venta si esa comprobación confirma una sesión abierta del usuario actual; una sesión ajena no se adopta, cierra ni transfiere.
