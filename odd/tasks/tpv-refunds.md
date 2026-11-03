# TPV refunds and exchanges

Objective: implement docs/prd/devoluciones.md (R1-R22, A1-A10). Branch: feat/tpv-refunds. Delivery: ask-on-risk, one reviewable commit per task.
Runners: `.runtime/php-8.5.12/bin/php vendor/bin/phpunit --no-coverage --do-not-cache-result`; Node `tests/Functional/*.test.cjs`; module tests under modules/tpvconnect/tests.

## Specs

The authoritative requirement text is docs/prd/devoluciones.md (commit 1f7345d). User answers, verbatim:

- S1 (scope, R4/R13-R15): "Con ticket, total", "Con ticket, parcial", "Cambio de producto", "Sin ticket"; partial refunds "Sí, hasta agotar"; exchanges "Solo con ticket", online exchange "Sí".
- S2 (money, R9-R12): "Mismo medio que pagó"; mixed "Proporcional"; card "Manual y confirmar"; without ticket "Cajero elige medio"; online "Reembolso en web" with "Estado + abono en PS"; voucher "1 año, uso parcial".
- S3 (permissions and window, R1-R3): "Cualquier cajero"; "Configurable, 30 días"; outside it "Bloquear"; online "Desde entrega".
- S4 (amounts and reasons, R5-R6): coupon orders "Precio pagado real"; reasons "Lista obligatoria" with "Talla o color, Defectuoso, No le gusta, Error de cobro" plus "No coincide con la web, Regalo / duplicado, Llegó tarde, Otro (texto obligatorio)".
- S5 (stock, R7-R8): "El cajero decide"; Defectuoso "Desmarcada por defecto". Restock adds to the live PrestaShop quantity of the exact combination.
- S6 (documents, R16-R18, R20): numbering "Serie R propia"; abono ticket "Siempre"; scanning "No sé / ambos" (Code128 + QR); without-ticket refunds recorded "Solo en el TPV".
- S7 (reports, R19): "Línea separada".
- S8 (failure, R21-R22): PrestaShop down "Bloquear".

## Tasks

T1a | S2,S4 | inline | done | Sale order_meta keeps product_id, id_product_attribute, paid_total per line (line discount + cart discount/coupon prorated in cents) and tender {cash,card}. RED 2 failures -> GREEN 56/173; full 355/1050 exit0. Commit: see log L5.
T1b | S1,S4,S5,S8 | inline | pending | tpvconnect refunds.php: fix OrderSlip::create call (product_list with id_order_detail/unit_price/quantity, explicit amount), cap qty by product_quantity - refunded - return, restock per combination via StockAvailable::updateQuantity when requested; module tests with stubs.
T1c | S2,S4,S5,S8 | inline | pending | TPV RefundService: amounts from order_meta paid_total (never browser), per-line refunded qty, positive refund movement (fix expected_cash sign), no 0 movement, mixed split from tender, card confirmation, mandatory reason list, local stock per combination; legacy orders without product ids rejected clearly.
T1d | S2,S4,S5 | inline | pending | RefundScreen: reasons, restock checkbox (Defectuoso unchecked), available qty, card confirmation.
T2 | S6 | inline | pending | R-series credit notes (migration, atomic numbering) and abono ticket print.
T3 | S3 | inline | pending | Configurable refund window (terminal setting), block outside window.
T4 | S1,S2,S3 | inline | pending | Online orders: search, delivered-date window, state "Devuelto en tienda – reembolso pendiente", no cash movement.
T5 | S6 | inline | pending | Code128 on sale ticket + scan QR/Code128 in refund search.
T6 | S1,S2 | inline | pending | Refund without ticket (current price, cashier-chosen method incl. voucher via coupon service, partial_use).
T7 | S1,S2 | inline | pending | Exchanges with balance (refund + new sale in one flow).
T8 | S7 | inline | pending | X/Z reports: refunds line by method and net.

## Log

L1. 2026-11-03 User: "Nos falta la parte de gestionar una devolucion, como lo hariamos?" then "vamos a generar un pequeño prd de la funcionalidad, hazme preguntas muchas hasta definir exactamente como hacerlo." and "si el ticket lleva codigo de barras se podria escanear". After the PRD: "haz lo que consideres mas optimo".
L2. Commits before feature work: 91643e8 (checkout no longer pushes stock to PS; sync/now guarded), 1f7345d (PRD). Verified fact: PS 8.1.7 OrderDetail::updateProductQuantityInStock lowers stock on order creation; OrderSlip::create does not restock.
L3. Routes are inline per task (no writer trigger: units are sequential and depend on T1 contract). High-risk items: 1 (money/stock in PrestaShop, new migration), 3 (tpvconnect refund contract). Independent verifier at feature end and for T1/T2.
L4. Explorer map (2026-11-03): current refund cannot work end to end. order_meta lines lacked product ids (module 422); refunds.php calls OrderSlip::create($order,$products,$quantities,$shipping) but PS 8.1.7 signature is create(Order,$product_list,$shipping_cost=false,$amount=0,$amount_choosen=false,$add_tax=true) reading unit_price/quantity per entry (OrderSlip.php:264,308); OrderSlip::create bumps product_quantity_refunded only on paid orders and never restocks; refund movement stored negative while aggregateTotals subtracts refunds, so a cash refund raises expected_cash; CouponCreationService hard-codes partial_use=0; QR holds a JWT verify URL, not the reference; useBarcode composable exists but is unused. Out of scope, reported to user: manual TPV discounts never reach PrestaShop, so PS order totals differ from what was charged.
L5. T1a delivered (see Tasks).
