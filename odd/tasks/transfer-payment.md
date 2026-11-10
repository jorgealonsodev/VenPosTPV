# Transfer payment

Objective: charge a sale by bank transfer, and refund a transfer sale by transfer or cash.
Branch: `feat/transfer-payment`. Delivery: ask-on-risk. Runners: `php vendor/bin/phpunit tests/Unit`, `node --test tests/Functional/*.test.cjs`, MySQL integration (docker venpos-test-mysql).

## Specs

- **S1.** "Hay que añadir la opcion de pago por transferencia": the payment window offers «Transferencia» next to Efectivo, Tarjeta, Mixto and Vale regalo. It charges the whole total, opens no drawer and moves no cash; the ticket, the invoice and the ticket lists say «Transferencia». Works with and without a coupon. The PrestaShop order carries `transfer` as payment method, like `cash`/`card` today.
- **S2.** The till totals keep transfer apart: X and Z reports, the cash-close screen, Informes (screen and CSV) show «Ventas transferencia» / «Devoluciones transferencia»; card totals no longer include them and expected cash is unchanged.
- **S3.** User decision (2026-11-10), refunds and exchanges of a ticket paid by transfer: "Transferencia o efectivo (Recomendado) — La devolución ofrece «Transferencia» (la hace el administrador desde el banco y el TPV solo la registra) además de efectivo. Hace falta guardar ese importe aparte en las devoluciones y que salga en el cierre de caja."
- **S5.** User decision (2026-11-10): "Cobrada en el momento (Recomendado) — El cajero confirma que ha visto la transferencia (justificante en el móvil del cliente) y se emite el ticket como una venta normal. Sin cajón, fuera del efectivo, con su propia línea en el ticket, el cierre X/Z y los informes."
- **S4.** Assumption: a checkout with an unknown payment method is refused (today any unknown method is booked as card).

## Tasks

- [x] T1 (S1, S4, S5) sale by transfer, inline. RED: LocalFirstCheckout transfer/unknown-method tests, paymentTransfer.test.cjs; GREEN.
- [x] T2 (S2) totals, reports, inline. CashReportTest transfer lines, ReportCsvTest, useSession test.
- [ ] T3 (S3) refunds and exchanges by transfer (migration `refund.transfer_amount`), inline.

## Log

- L1 (2026-11-10): "Hay que añadir la opcion de pago por transferencia"
- L2 (2026-11-10): refund choice answered «Transferencia o efectivo (Recomendado)».
- L3: today a non-cash, non-mixed method is booked as card (CheckoutService tender, resolveCouponTender rejects others); PS module takes the method string as payment name.
- L4: base (main 8943ae7) already fails 19 integration tests (SalesApi 6, SyncStatus 3, LocalFirstCheckout 2 key-order, migrations, install…); this branch adds no new failure.
- L5: printed X/Z show transfer lines only when the till had transfers (older reports unchanged); «Devol. transferencia» fits 32 columns.
