# Delta for Ticket Printing

## ADDED Requirements

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

## MODIFIED Requirements

### R5: Reprint

`POST /api/ticket/reprint` MUST accept `{ps_order_id}` and an optional `{gift_mode: bool}` (default `false`). When `gift_mode=true`, the reprinted ticket MUST use gift receipt format (R6 for ESC/POS, R7 for PDF). When `gift_mode=false` or absent, behavior MUST be identical to current reprint. Reprint MUST log to `audit_log` with action `ticket_reprint` and include `gift_mode` value in the log entry. If no printer is configured, MUST return PDF (gift or normal per `gift_mode`).

(Previously: Reprint accepted only `ps_order_id`; now also accepts optional `gift_mode` boolean.)

| Scenario | Given | When | Then |
|---|---|---|---|
| Successful reprint | Order #42 exists, printer reachable | POST /api/ticket/reprint {ps_order_id: 42} | Ticket reprinted normally; audit_log with ticket_reprint, gift_mode=false |
| Gift reprint ESC/POS | Order #42 exists, printer reachable | POST /api/ticket/reprint {ps_order_id: 42, gift_mode: true} | Gift receipt printed via ESC/POS (R6); audit_log with ticket_reprint, gift_mode=true |
| Gift reprint PDF fallback | Order #42 exists, no printer | POST /api/ticket/reprint {ps_order_id: 42, gift_mode: true} | Returns gift-format PDF (R7) |
| Reprint non-existent | Order ID 99999 not found | POST /api/ticket/reprint {ps_order_id: 99999} | Returns 404 |