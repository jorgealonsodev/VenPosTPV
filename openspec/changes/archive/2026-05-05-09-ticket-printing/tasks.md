# Tasks: Ticket Printing (09-ticket-printing)

## Phase 1: Foundation / Infrastructure

- [x] 1.1 Create Doctrine migration adding `print_attempts INT UNSIGNED DEFAULT 0` and `printed_at DATETIME NULL` to `order_meta` table
- [x] 1.2 Create `src/Message/PrintTicketMessage.php` with `{orderId: int, terminalId: int}`
- [x] 1.3 Create `src/Service/Print/PrinterConfig.php` value object with `fromJson(?string): self` factory
- [x] 1.4 Create `src/Service/Print/TicketData.php` and `TicketLine.php` value objects matching the spec interface
- [x] 1.5 Modify `config/packages/messenger.yaml` to add `print_ticket` transport and route `PrintTicketMessage`

## Phase 2: Core Implementation

- [x] 2.1 Create `src/Service/Print/TicketDataResolver.php` — assembles `TicketData` from `order_meta` + `catalog_*` tables via `Connection`
- [x] 2.2 Create `src/Service/Print/EscPosTicketBuilder.php` — builds 80mm ESC/POS byte stream with CodePage 858 Spanish chars, QR via mike42, paper cut
- [x] 2.3 Create `src/Service/Print/TicketPdfGenerator.php` — renders `ticket/print.html.twig` via Twig, converts to PDF bytes with dompdf
- [x] 2.4 Create `src/Service/Print/TicketVerificationService.php` — JWT sign/verify with HS256, 24h expiry using `firebase/php-jwt`
- [x] 2.5 Create `src/Service/Print/PrinterService.php` — orchestrator: resolve config → try ESC/POS (5s timeout) → fallback PDF; update `order_meta.printed` on success
- [x] 2.6 Create `templates/ticket/print.html.twig` — ticket layout matching ESC/POS content (store name, CIF, date, items, totals, QR)

## Phase 3: Integration / Wiring

- [x] 3.1 Create `src/MessageHandler/PrintTicketMessageHandler.php` — calls `PrinterService::print()`, catches exceptions, logs to `audit_log`
- [x] 3.2 Modify `src/Service/Checkout/CheckoutService.php` — inject `MessageBusInterface`, dispatch `PrintTicketMessage` after `insertOrderMeta()` in `createOrder()`
- [x] 3.3 Modify `src/Controller/Api/CheckoutController.php` — return `print_status` in checkout response (polling info)
- [x] 3.4 Create `src/Controller/Api/TicketController.php` — `GET /api/ticket/{id}/pdf`, `POST /api/ticket/reprint`, `GET /api/ticket/verify`

## Phase 4: Frontend Integration

- [x] 4.1 Modify `public/assets/js/api.js` — add `ticketPdf(orderId)`, `ticketReprint(orderId)`, `ticketVerify(token)` endpoints
- [x] 4.2 Modify `public/assets/js/components/PaymentModal.js` — replace static 1.4s "Imprimiendo ticket…" with real status from `printed` field; show warning banner on failure without blocking completion
- [x] 4.3 Add "Reimprimir" button in post-checkout view (likely in `SaleScreen.js` or `TicketPanel.js`) — calls `ticketReprint()`, shows toast or PDF download fallback

## Phase 5: Testing

- [x] 5.1 Write unit tests: `PrinterConfig::fromJson()` — valid JSON, null → disabled, malformed JSON → default
- [x] 5.2 Write unit tests: `EscPosTicketBuilder::build()` — byte format, Spanish chars (ñ, á, €), QR encoding
- [x] 5.3 Write unit tests: `TicketPdfGenerator::generate()` — Twig render, PDF magic bytes
- [x] 5.4 Write unit tests: `TicketVerificationService` — JWT sign/verify, expired token rejection, tampered token rejection
- [x] 5.5 Write unit tests: `PrinterService::print()` — ESC/POS success, fallback-to-PDF, disabled path via mocked dependencies
- [x] 5.6 Write unit tests: `TicketDataResolver` — assembles `TicketData` from mock DB rows, field mapping
- [x] 5.7 Write integration tests: `PrintTicketMessageHandler` — dispatch via `sync://` transport, verify service calls
- [x] 5.8 Write integration tests: `TicketController` — PDF download (200/404), reprint (200/404), verify (200/401/400) endpoints via `WebTestCase`

## Phase 6: Configuration

- [x] 6.1 Modify `config/services.yaml` — register `PrinterService`, `EscPosTicketBuilder`, `TicketPdfGenerator`, `TicketVerificationService`, `TicketDataResolver`, `PrintTicketMessageHandler` with constructor dependencies
