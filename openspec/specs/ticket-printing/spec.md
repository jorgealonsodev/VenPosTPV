# Ticket Printing Specification

## Purpose

ESC/POS and PDF ticket rendering, printer config resolution, reprint, and async dispatch via Messenger.

## Requirements

### R1: Ticket Format

The system MUST render tickets in 80mm ESC/POS format with: store name (centered, bold), address + CIF/NIF, date/time + ticket reference, cashier name, item lines (name, qty × unit, line total), separator, subtotal, IVA, TOTAL (bold, large), payment method, tendered/change, QR code, "Gracias por su compra", and paper cut. Spanish characters (ñ, á, €) MUST use proper ESC/POS CodePage.

| Scenario | Given | When | Then |
|---|---|---|---|
| Standard ticket | Order with 3 items, cash payment €50, total €42.50 | Print ticket | ESC/POS bytes contain header, 3 lines, totals, change €7.50, QR, cut |
| Spanish chars | Product named "Café Ñoño €1.50" | Print ticket | ESC/POS output uses CodePage 858, renders correctly |

### R2: Printer Config Resolution

The system MUST resolve printer config from `terminal.printer_config` (JSON: `{type, host, port, width}`). If config is missing or `type` is not `network`, the system MUST fall back to PDF generation. Connection MUST use a 5-second timeout.

| Scenario | Given | When | Then |
|---|---|---|---|
| Network printer configured | terminal.printer_config has host=192.168.1.100, port=9100 | Print request | TCP connection to printer, ESC/POS bytes sent |
| No printer configured | terminal.printer_config is null or empty | Print request | Skips ESC/POS, generates PDF fallback |
| Printer unreachable | Configured printer host not responding | Print request (5s timeout) | Exception caught, PDF fallback generated, audit_log entry created |

### R3: PDF Fallback

When ESC/POS printing fails or is unavailable, the system MUST generate a PDF ticket via `dompdf` with identical content. `GET /api/ticket/{ps_order_id}/pdf` MUST return the PDF as a downloadable response.

| Scenario | Given | When | Then |
|---|---|---|
| Printer failure fallback | ESC/POS throws exception | Fallback triggers | PDF generated, audit_log records failure, order_meta.printed remains 0 |
| Explicit PDF request | No printer issues | GET /api/ticket/42/pdf | Returns 200 with PDF content-type |
| Order not found | Non-existent order ID | GET /api/ticket/99999/pdf | Returns 404 |

### R4: Async Print Dispatch

The system MUST dispatch ticket printing via Symfony Messenger async after order persistence. Printing MUST NOT block the HTTP checkout response. On success, `order_meta.printed` MUST be set to 1 with `printed_at` timestamp.

| Scenario | Given | When | Then |
|---|---|---|
| Successful async print | Order created, printer reachable | Messenger handler processes | ESC/POS bytes sent, printed=1, printed_at set |
| Handler failure | Order created, printer timeout | Messenger handler processes | PDF fallback generated, audit_log entry, printed=0 |
| Messenger transport down | MESSENGER_TRANSPORT_DSN misconfigured | Checkout dispatches message | Message queued in sync fallback, checkout still returns 200 |

### R5: Reprint

`POST /api/ticket/reprint` MUST accept `{ps_order_id}` and an optional `{gift_mode: bool}` (default `false`). When `gift_mode=true`, the reprinted ticket MUST use gift receipt format (R6 for ESC/POS, R7 for PDF). When `gift_mode=false` or absent, behavior MUST be identical to current reprint. Reprint MUST log to `audit_log` with action `ticket_reprint` and include `gift_mode` value in the log entry. If no printer is configured, MUST return PDF (gift or normal per `gift_mode`).

| Scenario | Given | When | Then |
|---|---|---|---|
| Successful reprint | Order #42 exists, printer reachable | POST /api/ticket/reprint {ps_order_id: 42} | Ticket reprinted normally; audit_log with ticket_reprint, gift_mode=false |
| Gift reprint ESC/POS | Order #42 exists, printer reachable | POST /api/ticket/reprint {ps_order_id: 42, gift_mode: true} | Gift receipt printed via ESC/POS (R6); audit_log with ticket_reprint, gift_mode=true |
| Gift reprint PDF fallback | Order #42 exists, no printer | POST /api/ticket/reprint {ps_order_id: 42, gift_mode: true} | Returns gift-format PDF (R7) |
| Reprint non-existent | Order ID 99999 not found | POST /api/ticket/reprint {ps_order_id: 99999} | Returns 404 |

### R6: Gift Receipt ESC/POS

When `giftMode=true`, `EscPosTicketBuilder::build()` MUST omit all monetary fields: unit prices, line totals, subtotal, IVA, total, payment method, tendered, and change. It MUST retain: store header (name, address, CIF/NIF), date/time + ticket reference, item names and quantities, QR code, and footer. Output MUST still use proper ESC/POS CodePage for Spanish characters. When `giftMode=false` (default), output MUST be identical to current R1 standard ticket format.

| Scenario | Given | When | Then |
|---|---|---|---|
| Gift receipt | Order with 3 items, cash €50, total €42.50 | Print with giftMode=true | ESC/POS output has item names + quantities, NO prices/totals/payment/change; store header, date, reference, QR, footer present |
| Normal receipt unchanged | Same order | Print with giftMode=false | Output identical to R1 standard ticket |

### R7: Gift Receipt PDF

`TicketPdfGenerator::generate()` MUST accept a `giftMode` parameter. When `giftMode=true`, the PDF MUST omit all monetary fields (same rules as R6). `GET /api/ticket/{ps_order_id}/pdf` MUST accept an optional `gift_mode` query parameter. When `gift_mode=true`, response MUST be a gift-format PDF. When absent or `false`, behavior MUST be identical to current R3 PDF output.

| Scenario | Given | When | Then |
|---|---|---|---|
| Gift receipt PDF | Paid order exists | GET /api/ticket/42/pdf?gift_mode=true | PDF contains item names + quantities, NO prices/totals/payment/change |
| Normal PDF unchanged | Same order | GET /api/ticket/42/pdf | PDF identical to current R3 format |