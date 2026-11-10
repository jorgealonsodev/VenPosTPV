# Edit mode: price and VAT

Objective: «Modo edición» changes a product's or combination's price (and the product's VAT rate) in PrestaShop and in the TPV catalog at once.
Branch: `feat/edit-mode-price`. Delivery: ask-on-risk. Runners: phpunit tests/Unit, module phpunit (modules/tpvconnect/tests), node --test tests/Functional, MySQL integration.

## Specs

- **S1.** User (on the Modo edición screenshot): "podemos editar tambien el precio?"
- **S2.** User decision: "Tienda y web (Recomendado) — Como el stock: se cambia también en PrestaShop, así la web, el TPV y los pedidos siempre tienen el mismo precio. Puedo añadir también el tipo de IVA (21/10/4/0 %) para arreglar desde aquí productos como los 12 bañadores."
- **S3.** User: "el precio, por articulo y por combinacion" — the product's price, and each combination's own price.
- **S4.** Assumption: the price typed is the price with VAT (what the till shows); the VAT rate is per product (PrestaShop's tax rule is per product), chosen from 21/10/4/0 %. Admin only, like the rest of Modo edición; audited.

## Tasks

- [x] T1 (S2-S4) module endpoint `tpvconnect/price` (HMAC, idempotent): set Product price + tax rules group, or Combination price impact; version 0.8.3 with upgrade script.
- [x] T2 (S1-S4) TPV: CatalogEditService::setPrice → module, then refresh local catalog prices; API route; ProductEditModal fields «Precio (tienda y web)» and «IVA».

## Log

- L1 (2026-11-10): "en el modo edicion: [screenshot] podemos editar tambien el precio?"
- L2: user chose «Tienda y web (Recomendado)».
- L3: "el precio, por articulo y por combinacion"
- L4: "los precios cambiados desde el tpv ya son con iva incluido" — confirms S4: the typed price includes VAT; PrestaShop gets price / (1 + rate).
- L5: T1 2fcccde (module 0.8.3, 193 module tests). T2: PsPriceClient, CatalogEditService::setPrice/price(), PUT /api/catalog/edit/{p}/{a}/price, ProductEditModal price + IVA select. Unit 1120, JS 199. Integration: ExchangeService testPositiveBalanceByCardOrMixed / ByTransfer and ExchangeCreditRefund also fail on main 986fb46 in the full run (state-dependent, pass alone) — not from this feature; to investigate apart.
