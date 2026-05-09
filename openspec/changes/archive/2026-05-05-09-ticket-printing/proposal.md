# Proposal: Ticket Printing (ESC/POS + PDF)

## Intent

Enable sales ticket printing after checkout. Primary: ESC/POS for Epson network printers. Fallback: PDF when printing fails or no printer is configured.

## Scope

### In Scope
- `TicketPrinter`: ESC/POS rendering via `mike42/escpos-php` (TCP port 9100)
- `TicketPdfGenerator`: PDF rendering via `dompdf/dompdf`
- Ticket format: store header, items, totals, payment method, QR code, date/time
- QR verification: short-lived JWT (`GET /api/ticket/verify?token=...`)
- Hook: fire-and-forget after `CheckoutService::createOrder()` via Messenger async
- Printer config: per-terminal JSON in `terminal.printer_config`
- Reprint: `POST /api/ticket/reprint`
- PDF: `GET /api/ticket/{id}/pdf`
- Fallback: on ESC/POS failure (timeout 5s), auto-PDF + audit_log

### Out of Scope
- Full invoice / fiscal receipt (RF-28)
- Email / WhatsApp sending (RF-30)
- WebUSB / TPV Bridge client-side printing
- Cash drawer pulse control

## Capabilities

### New
- `ticket-printing`: ESC/POS and PDF rendering, reprint, printer config resolution
- `ticket-verification`: QR generation and JWT-signed verification endpoint

### Modified
- `checkout-integration`: non-blocking post-order print dispatch; `order_meta.printed` tracking
- `sales-screen`: `PaymentModal` real print status; `SaleScreen` print error handling

## Approach

Server-side hybrid. ESC/POS raw bytes via `NetworkPrintConnector` (5s timeout). On failure or missing config, PDF fallback. Checkout dispatches a Messenger async message after order persistence so printing never blocks the HTTP response. QR JWT contains `order_id`, `reference`, 24h expiry.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Service/Ticket/` | New | `TicketPrinter`, `TicketPdfGenerator` |
| `src/Service/Checkout/CheckoutService.php` | Modified | Dispatch print message after order creation |
| `src/MessageHandler/PrintTicketHandler.php` | New | Async handler for fire-and-forget printing |
| `src/Controller/Api/TicketController.php` | New | Reprint, PDF, verification endpoints |
| `public/assets/js/components/PaymentModal.js` | Modified | Real print status instead of placeholder |
| `config/services.yaml` | Modified | Service registration and Messenger routing |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Printer timeout/unreachable | Med | 5s timeout, catch exception, PDF fallback, audit_log |
| Spanish char encoding (ñ, é, €) | Med | Test `CodePage` config against target printer |
| Messenger transport missing | Low | Verify `MESSENGER_TRANSPORT_DSN` in env |

## Rollback Plan

1. Remove Messenger routing for print messages.
2. Comment out dispatch call in `CheckoutService`.
3. `order_meta.printed` remains unused but harmless.

## Dependencies

`mike42/escpos-php`, `dompdf/dompdf`, `firebase/php-jwt` (all installed). Symfony Messenger async transport configured.

## Success Criteria

- [ ] ESC/POS ticket prints on checkout when printer configured
- [ ] PDF generates when printer unreachable or unconfigured
- [ ] QR token verifies order details at `GET /api/ticket/verify`
- [ ] Reprint endpoint reproduces identical ticket
- [ ] Print failures audit-logged without blocking checkout
