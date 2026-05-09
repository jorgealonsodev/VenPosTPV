# Exploration: 09-ticket-printing

## Current State

The checkout flow is fully functional but has **no printing implementation**:

1. **Backend**: `CheckoutService::createOrder()` creates the PrestaShop order, persists `order_meta` (with `printed = 0`), decrements stock, and clears the cart. Returns `{ps_order_id, reference, total, change_due, payment_method, saga_error}`.

2. **Frontend**: `PaymentModal.js` sends the checkout payload, shows a "done" state with text "Imprimiendo ticket..." (line 134), then emits `complete` after 1.4s. `SaleScreen.js::handlePaymentComplete()` clears the cart and shows a notification — no print call exists.

3. **Dependencies available** (in `composer.json`):
   - `mike42/escpos-php ^4.0` — ESC/POS thermal printer driver (installed, with QR code, barcode, image, and network/USB connectors)
   - `dompdf/dompdf ^2.0` — HTML-to-PDF generator
   - `firebase/php-jwt ^6.10` — JWT token generation (usable for QR verification tokens)

4. **Database**: `order_meta.printed` column exists (TINYINT(1), default 0) — ready to track print status.

5. **PRD requirements** (RF-27 to RF-30):
   - **RF-27**: Ticket simplificado ESC/POS con QR de verificación
   - **RF-28**: Conversión a factura nominal con datos fiscales completos
   - **RF-29**: Reimpresión de ticket por número, fecha o cliente
   - **RF-30**: Envío por email o WhatsApp

6. **PRD Section 11 (Hardware)**: Specifies dual printing path — server-side ESC/POS via TCP (`mike42/escpos-php`) for network printers, and client-side via "TPV Bridge" agent or WebUSB for local printers.

## Affected Areas

- `src/Service/Checkout/CheckoutService.php` — Hook point for post-order printing (after `createOrder` succeeds)
- `src/Controller/Api/CheckoutController.php` — May need to return print-ready data or trigger async print
- `src/Service/Checkout/PsOrderClient.php` — Source of order data; may need to fetch line details for ticket
- `public/assets/js/components/PaymentModal.js` — "Imprimiendo ticket..." placeholder; needs real print trigger
- `public/assets/js/components/SaleScreen.js` — `handlePaymentComplete()` needs print status handling
- `public/assets/js/api.js` — Needs new endpoints: `reprint()`, `printTicket()`
- `config/services.yaml` — New service registrations (TicketPrinter, etc.)
- `order_meta` table — `printed` column already exists; may need `print_attempts`, `printed_at`

## Approaches

### 1. Server-side ESC/POS only (recommended for MVP)

PHP generates ESC/POS raw bytes and sends directly to a network printer via TCP (port 9100) using `mike42/escpos-php`.

- **Pros**: Simple, uses existing dependency, no client-side complexity, works with Epson/Star/Bixolon network printers, QR codes natively supported
- **Cons**: Requires printer to be on the same network as the server; USB printers need CUPS or shared access; no offline printing
- **Effort**: Low

### 2. Server-side PDF + browser print

PHP generates a PDF via `dompdf`, returns it to the browser, and the browser's native print dialog handles the rest.

- **Pros**: Works with any printer (USB, network, Bluetooth); no printer configuration on server; familiar UX
- **Cons**: Requires user interaction (print dialog); slower (PDF generation + download); ESC/POS cash drawer pulse not possible; QR code in PDF is just an image
- **Effort**: Low-Medium

### 3. Hybrid: ESC/POS server-side + PDF fallback

Primary path uses ESC/POS for network printers; if no printer is configured or print fails, falls back to PDF download for browser printing.

- **Pros**: Covers both scenarios; graceful degradation
- **Cons**: More code to maintain; two rendering paths to test
- **Effort**: Medium

### 4. Client-side WebUSB / TPV Bridge agent

Browser talks directly to USB printer via WebUSB API, or to a local agent ("TPV Bridge") on `127.0.0.1:9100`.

- **Pros**: No server-side printer config needed; works with USB printers directly attached to the POS terminal
- **Cons**: WebUSB has limited browser support (Chrome only); TPV Bridge requires installing/maintaining a separate executable; complex error handling
- **Effort**: High

## Recommendation

**Approach 3 (Hybrid: ESC/POS server-side + PDF fallback)** for the following reasons:

### MVP Scope (Phase 1)
1. **ESC/POS server-side** as the primary print path — this is what the PRD specifies (RF-27) and `mike42/escpos-php` is already installed
2. **Printer configured per terminal** in `terminal.printer_config` (JSON: `{type: "network", host: "192.168.1.100", port: 9100, width: 80}`)
3. **Ticket format** (80mm ESC/POS):
   ```
   [Store name/logo - centered, bold]
   [Address, CIF/NIF]
   [Date/time | Ticket #reference]
   [Cashier name]
   ──────────────────────────────
   [Item name]              [price]
     [qty] x [unit price]   [line total]
   [Item 2]                 [price]
   ──────────────────────────────
   Subtotal:                [amount]
   IVA:                     [amount]
   TOTAL:              [bold, large amount]
   Payment: [method]
   Tendered: [amount]  Change: [amount]
   ──────────────────────────────
   [QR code: verification URL or JWT token]
   "Gracias por su compra"
   [Cut paper]
   ```
4. **QR verification**: Generate a short-lived JWT (`firebase/php-jwt`) containing `{order_id, reference, timestamp}` encoded in the QR. Scanning the QR opens a verification endpoint (`GET /api/ticket/verify?token=...`) that returns the order details.

### Hook point in checkout flow
- **Synchronous hook**: After `CheckoutService::createOrder()` returns successfully, call `TicketPrinter::printTicket(orderData, cartData, terminalConfig)`. If printing fails, log to `audit_log` and return success to frontend (printing is best-effort, not blocking).
- Set `order_meta.printed = 1` on success.
- Frontend shows "Imprimiendo ticket..." → "Cobro completado" regardless of print result (printing is async/fire-and-forget for UX).

### Reprint endpoint (Phase 1)
- `POST /api/ticket/reprint` with `{ps_order_id}` → fetches order data from `order_meta` + PrestaShop, re-generates ESC/POS, sends to printer.
- Updates `order_meta.printed` (stays 1, but could track `reprint_count`).

### PDF fallback (Phase 1)
- `GET /api/ticket/{ps_order_id}/pdf` → returns PDF via `dompdf` for browser download/print.
- Used when: no network printer configured, print fails, or user explicitly requests PDF.

### Phase 2 (out of scope for MVP)
- RF-28: Full invoice generation (requires fiscal data, customer tax info)
- RF-30: Email/WhatsApp sending (can use Symfony Messenger async)
- Client-side WebUSB / TPV Bridge for USB printers

## Risks

1. **Printer connectivity**: Network printers may be unreachable, timeout, or reject connections. Must handle gracefully with timeout (5s) and audit logging.
2. **Character encoding**: Spanish characters (ñ, á, é, í, ó, ú, €) need proper ESC/POS code page configuration. `mike42/escpos-php` supports this via `CodePage` but requires testing with the target printer model.
3. **Order data availability**: The checkout response only returns minimal data (`ps_order_id`, `reference`, `total`). For reprinting, we need to fetch full order details from PrestaShop or store them in `order_meta`. Storing a summary in `order_meta.payment_breakdown` is already done but may need expansion.
4. **Async checkout conflict**: The checkout flow is synchronous. If printing takes 2-3 seconds, it blocks the response. Solution: print after returning the response (use `register_shutdown_function` or Symfony Messenger async).
5. **Multiple terminals**: Each terminal may have different printer configs. Must resolve the correct printer from the active session's `terminal_id`.
6. **No printer in dev/testing**: Need a "raw output" mode that returns ESC/POS bytes as base64 for testing without a physical printer.

## Ready for Proposal

**Yes.** The exploration is complete with:
- Clear MVP scope: ESC/POS server-side with PDF fallback
- Defined ticket format and data requirements
- Identified hook point in the existing checkout flow
- QR verification approach using JWT
- Reprint endpoint design
- Known risks with mitigations

The orchestrator should proceed to the **proposal phase** where the user can confirm/reject the approach before design and implementation.
