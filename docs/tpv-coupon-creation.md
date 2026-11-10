# Creación de cupones TPV

`POST /api/coupons` crea una regla de carrito nativa de PrestaShop. Solo una sesión autenticada con rol `admin` o `cashier` puede usarlo; tienda, moneda y rol se obtienen del servidor, nunca del JSON.

Entrada:

```json
{"discount_type":"amount","value":10.5,"expires_at":"2030-06-30","quantity":10,"quantity_per_user":1}
```

`discount_type` puede ser `amount` o `percentage`. Los importes son positivos y tienen como máximo dos decimales; los porcentajes son mayores que cero y como máximo 100. La fecha debe ser futura en `PS_SHOP_TIMEZONE` (por defecto documentado: `Europe/Madrid`) y vence a las 23:59:59 locales. Los usos son enteros positivos y el límite por cliente no puede superar el total.

La respuesta correcta es `201` con `data.id`, un código automático de 8 caracteres sin prefijo (alfabeto sin `0/O` ni `1/I`), tipo, valor, fecha y límites. La creación consulta primero el esquema en blanco de `cart_rules` para obtener el idioma real del nombre y hace un único `POST` XML nativo, limitado al `id_shop` de la sesión. El código no puede ser aportado por el cliente.

Errores: `401` sin sesión, `403` con otro rol, `422` para entrada o contexto inválido, `503` si el esquema no está disponible y `409` si un POST no puede confirmarse. Un `409` incluye el código generado para reconciliarlo en PrestaShop; no se reintenta automáticamente para evitar duplicados.

La moneda de los descuentos en euros procede de `PS_DEFAULT_CURRENCY`; debe ser un ID positivo configurado para la tienda. No se crean asociaciones de tienda no soportadas ni se afirma aislamiento global: el alcance multitienda se pide explícitamente mediante `id_shop` en las solicitudes Webservice.

## Ver los cupones creados

El botón **Cupones**, junto a **Crear cupón** en la cabecera de la pantalla de venta (administradores y cajeros), lista los cupones creados desde el TPV, también los vales con el saldo que sobra de otro vale: del más reciente al más antiguo, 25 por página con **Ver más**, y con búsqueda por parte del código. Cada uno muestra el código (**Copiar**), el valor, la caducidad, cuándo, quién y en qué caja se creó, su estado en PrestaShop (Disponible, Usado, Caducado, Desactivado, Borrado en la tienda, o Sin comprobar si la tienda no responde) y **Imprimir** y **PDF** para su vale. La lista sale aunque la tienda no responda. API y estados: [Cupones en el cobro](tpv-coupon-checkout.md#cupones-the-tills-coupons-and-their-state) (`GET /api/coupons?q=&page=`).
