# Ticket y etiquetas

Qué imprime el TPV en el ticket de venta y en las etiquetas. El ticket impreso (ESC/POS) y el PDF llevan lo mismo, también al reimprimir.

## Descuentos en el ticket

Cada línea con descuento lleva, debajo, su porcentaje y su importe:

```
2 x 12,10 € = 24,20 €
Dto. 10 %: -2,42 €
```

Entre el subtotal y el IVA van los totales de los descuentos, solo los que hay:

| Línea | Cuándo |
|---|---|
| «Dto. artículos: -X €» | La suma de los descuentos de las líneas. |
| «Dto. ticket (5 %): -X €» | Un descuento de todo el ticket en porcentaje. |
| «Dto. ticket: -X €» | Un descuento de todo el ticket en importe. |
| «Cupón: -X €» | Un cupón de PrestaShop aplicado en el cobro. |

Se imprimen con cualquier forma de pago. Las ventas anteriores a este cambio no guardaron si el descuento del ticket era en porcentaje, así que al reimprimirlas sale «Dto. ticket» solo con el importe. Un ticket sin descuentos no cambia. El ticket regalo no lleva importes. En la impresora térmica, «artículos» sale sin tilde: su juego de caracteres no la tiene.

## Quién atendió

«Le ha atendido …» va al final del ticket: después del código de barras y del QR (y de «Sustituido por la factura …», si se canjeó), justo antes del pie (el pie del ticket de la caja o, si no tiene, «Gracias por su compra»).

## Productos sin IVA

Si el catálogo marca un producto con «SIN IVA», la web lo vende sin regla de impuestos y su ticket diría «IVA 0 %». Se corrige en el [modo edición](modo-edicion.md#productos-sin-iva) eligiendo el IVA del producto.

## Etiquetas

Cada etiqueta lleva el código de barras del artículo (su EAN-13, EAN-8 o UPC-A; sin uno válido, la referencia en Code128), su texto debajo y, al lado, un QR pequeño con el mismo código. El QR mide como mucho 15 mm y nunca es más alto que las barras con su texto, así que el alto de la etiqueta no cambia. Si un Code128 largo no deja sitio para el QR, las barras se estrechan hasta el mínimo; si aun así no cabe, la etiqueta sale sin QR, con las barras de siempre. La vista previa muestra exactamente lo que se imprime.

Las etiquetas se imprimen en la impresora de etiquetas de la caja, a través del [agente de impresión](print-agent.md).
