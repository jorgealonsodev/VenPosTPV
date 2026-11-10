# Modo edición del catálogo

Los administradores pueden cambiar desde la pantalla de venta el stock, el precio, el IVA, el EAN y la referencia de un artículo. Todo se guarda primero en PrestaShop y después en el TPV, así que la web y la caja quedan iguales.

## Cómo se usa

1. En el catálogo, pulsa el botón **Modo edición** (solo lo ven los administradores). Sale el aviso «Modo edición · pulsa un artículo para cambiar su stock, precio, IVA, EAN o referencia. No se añade nada al ticket.»
2. Pulsa un artículo (o escanéalo con el modo de escaneo activado). Si tiene tallas o colores, elige una (también las que no tienen stock), o pulsa **Editar producto** en esa ventana para cambiar el EAN y la referencia del propio producto.
3. Cambia lo que necesites y pulsa **Guardar**. **Cancelar** o ✕ cierran sin guardar.
4. Vuelve a pulsar **Modo edición** para seguir vendiendo.

| Campo | Qué guarda |
|---|---|
| Stock (tienda y web) | La cantidad del artículo o de esa talla. En un producto con tallas o colores se cambia en cada una. Si el artículo aún no tiene stock en PrestaShop, hay que sincronizar el catálogo primero. |
| Precio con IVA (tienda y web) | El precio final, de 0 a 99.999,99 € con hasta 2 decimales, de un producto o de una talla. |
| IVA | 21, 10, 4 o 0 %. Es del producto: se aplica a todas sus tallas y colores. La tienda tiene que tener una regla de impuestos para ese tipo. |
| EAN (tienda y web) | 8 o 13 cifras con el dígito de control correcto. Un EAN que ya venía de la web se respeta aunque su dígito de control no cuadre. |
| Referencia (tienda y web) | Hasta 64 caracteres. |

Vaciar el EAN o la referencia los borra también en la web.

El stock que escribes es el que verá la caja. Si hay ventas de ese artículo que aún no se han subido a PrestaShop, el TPV le suma lo que esas ventas descontarán, para no restarlas dos veces.

Las reducciones de PrestaShop (precios específicos) no se tocan: el TPV las recoge en la siguiente sincronización.

## Si algo falla

Si PrestaShop no acepta el cambio o no responde, no se cambia nada en el TPV y la ventana muestra el motivo, por ejemplo «PrestaShop no ha aceptado el cambio de precio; no se ha cambiado nada» o «La tienda no tiene configurado ese tipo de IVA». Si ya se había guardado una parte (por ejemplo, el EAN antes de un stock rechazado), la ventana la muestra.

Con un módulo antiguo, el TPV dice «El módulo de PrestaShop no admite cambiar precios: actualízalo a la versión 0.8.3» o «… no admite cambiar el EAN y la referencia: actualízalo a la versión 0.8.4».

## Valores que estaban solo en el TPV

Antes del módulo 0.8.4, el EAN y la referencia cambiados en el TPV se quedaban solo en la caja. La ventana los señala con «Ahora solo en el TPV; en la web: …». Al pulsar **Guardar** pasan a la web y dejan de estar solo en el TPV. **Restablecer** los descarta y vuelven los de la web.

## Productos «SIN IVA»

El catálogo marca con «SIN IVA» los productos cuyo precio con IVA y sin IVA son iguales en PrestaShop, es decir, que la web vende sin regla de impuestos. El ticket de esos productos diría «IVA 0 %».

Para corregirlo: en **Modo edición**, abre el producto, elige **IVA 21 %** (o el tipo que corresponda), revisa el precio con IVA y pulsa **Guardar**. El cambio se guarda en la web y el aviso desaparece.

## Requisitos

- Módulo TPV Connect **0.8.3** para el precio y el IVA, y **0.8.4** para el EAN y la referencia. Se actualiza subiendo `tpvconnect.zip`; no necesita ningún paso más.
- La clave del webservice necesita `PUT` en `stock_availables` (el emparejamiento ya la da).

## Detalles técnicos

- API del TPV, solo administradores (`403` para el resto): `GET /api/catalog/edit/{productId}/{attributeId}`, `PUT …/stock`, `PUT …/price` (`{price, vat_rate}`), `PUT …/override` (`{ean13, reference}`) y `DELETE …/override` (Restablecer). `attributeId` 0 es el producto.
- El stock va a PrestaShop con `PUT stock_availables`. El precio y los códigos van a los endpoints del módulo `POST /module/tpvconnect/price` (0.8.3) y `POST /module/tpvconnect/codes` (0.8.4), firmados con HMAC. El módulo escribe solo esas columnas (`product`, `product_shop`, `product_attribute`, `product_attribute_shop`): guardar el `Product` entero obliga a PrestaShop a validar todos sus campos, y un producto con, por ejemplo, la descripción corta demasiado larga no podría cambiar de precio.
- Cada cambio queda en `audit_log` con el valor anterior y el nuevo: `catalog_stock_set`, `catalog_price_set`, `catalog_codes_set`, `catalog_override_save` y `catalog_override_reset`.
- Los valores solo del TPV de antes están en `catalog_override` (migración `Version00000050CatalogOverride`).
