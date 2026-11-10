# Transfer payment

Objective: charge a sale by bank transfer, and refund a transfer sale by transfer or cash.
Branch: `feat/transfer-payment`. Delivery: ask-on-risk. Runners: `php vendor/bin/phpunit tests/Unit`, `node --test tests/Functional/*.test.cjs`, MySQL integration (docker venpos-test-mysql).

## Specs

- **S1.** "Hay que añadir la opcion de pago por transferencia": the payment window offers «Transferencia» next to Efectivo, Tarjeta, Mixto and Vale regalo. It charges the whole total, opens no drawer and moves no cash; the ticket, the invoice and the ticket lists say «Transferencia». Works with and without a coupon. The PrestaShop order carries `transfer` as payment method, like `cash`/`card` today.
- **S2.** The till totals keep transfer apart: X and Z reports, the cash-close screen, Informes (screen and CSV) show «Ventas transferencia» / «Devoluciones transferencia»; card totals no longer include them and expected cash is unchanged.
- **S3.** User decision (2026-11-10), refunds and exchanges of a ticket paid by transfer: "Transferencia o efectivo (Recomendado) — La devolución ofrece «Transferencia» (la hace el administrador desde el banco y el TPV solo la registra) además de efectivo. Hace falta guardar ese importe aparte en las devoluciones y que salga en el cierre de caja."
- **S5.** User decision (2026-11-10, replaces «Cobrada en el momento»): "Pendiente hasta confirmar (Recomendado) — La venta queda «Pendiente de cobro». El administrador ve una lista de transferencias pendientes y marca cada una como recibida cuando la ve en el banco. El cierre y los informes separan pendientes de recibidas." The ticket is still issued at once (VAT accrues on delivery). User (L9): "aqui debera aparecer en pendiente de pago por transferencia o algo asi no?" (screenshot of the ticket panel tabs Aparcados / Cobrados / Devueltos) → the pending list is a «Pendientes de pago» tab there, where they are marked received (admin).
- **S6.** "En ajustes habra que añadir el numero de cuenta para que aparezca en el ticket donde tiene que ingresar": Ajustes → Tienda gets the IBAN; a ticket paid by transfer prints it (with the ticket code as transfer concept). Assumption: validated as IBAN (mod 97), optional.
- **S7.** "una vez se confirme el pago se enviara el ticket, por mail o por whatsapp, tambien se puede imprimir, por impresora o en pdf": after «Marcar como recibida», the sale's detail opens with print / PDF / email / WhatsApp; the ticket then no longer says «Pendiente de pago».
- **S4.** Assumption: a checkout with an unknown payment method is refused (today any unknown method is booked as card).

## Tasks

- [x] T1 (S1, S4, S5) sale by transfer, inline, commit 276af5b. RED: LocalFirstCheckout transfer/unknown-method tests, paymentTransfer.test.cjs; GREEN.
- [x] T2 (S2) totals, reports, inline. CashReportTest transfer lines, ReportCsvTest, useSession test.
- [x] T3 (S3, commit a5f0c0c) refunds and exchanges by transfer (migration `refund.transfer_amount`), inline. RED: RefundService transfer tests, refundTransfer.test.cjs, Exchange transfer tests, CashReportByMethod transfer; GREEN.

- [x] T4 (S5) pending transfers: transfer_payment table, list «Transferencias», mark received, pending apart in totals, inline.
- [x] T5 (S6) IBAN in Ajustes → Tienda, printed on transfer tickets, inline.
- [x] T6 (S7) after marking received, open the sale detail to send or print the ticket, inline.

## Log

- L1 (2026-11-10): "Hay que añadir la opcion de pago por transferencia"
- L2 (2026-11-10): refund choice answered «Transferencia o efectivo (Recomendado)».
- L3: today a non-cash, non-mixed method is booked as card (CheckoutService tender, resolveCouponTender rejects others); PS module takes the method string as payment name.
- L4: base (main 8943ae7) already fails 19 integration tests (SalesApi 6, SyncStatus 3, LocalFirstCheckout 2 key-order, migrations, install…); this branch adds no new failure.
- L5: printed X/Z show transfer lines only when the till had transfers (older reports unchanged); «Devol. transferencia» fits 32 columns.
- L6: T3 done; full integration run has the same 19 failures as main, unit 1101 green, JS 188 green. Docs: docs/pago-transferencia.md. PDF ticket shows «Metodo: Transferencia» (other methods keep their old raw label).
- L7 (2026-11-10): user: "pero no puede aparecer como cobrado en el momento, no?" → chose «Pendiente hasta confirmar (Recomendado)». Verifier on a5f0c0c: no blockers; advisories: list labels now Spanish for all methods, coupon card_collected holds the transfer amount, CSV shows 0 transfer rows.
- L8 (2026-11-10): user: "En ajustes habra que añadir el numero de cuenta para que aparezca en el ticket donde tiene que ingresar"
- L9 (2026-11-10): user, on the ticket panel tabs screenshot: "aqui debera aparecer en pendiente de pago por transferencia o algo asi no?"
- L10 (2026-11-10): user: "una vez se confirme el pago se enviara el ticket, por mail o por whatsapp, tambien se puede imprimir, por impresora o en pdf"
- L11: T4-T6 done (Version00000052 transfer_payment + terminal.bank_account; TransferPayments; TransferController; «Pendientes de pago» tab; IBAN in Ajustes → Tienda and on transfer tickets). Unit 1110, JS 193, integration same 19 baseline failures. JS tests of T4/T6 were written after the code (no observed RED).
