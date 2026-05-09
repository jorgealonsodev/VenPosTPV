# Apply Progress: 09-ticket-printing

**Status**: 28/28 tasks complete. Ready for verify.
**Mode**: Standard (Strict TDD not active)

## Completed Tasks

### Phase 1: Foundation / Infrastructure
- [x] 1.1 Create Doctrine migration adding `print_attempts INT UNSIGNED DEFAULT 0` and `printed_at DATETIME NULL` to `order_meta` table
- [x] 1.2 Create `src/Message/PrintTicketMessage.php` with `{orderId: int, terminalId: int}`
- [x] 1.3 Create `src/Service/Print/PrinterConfig.php` value object with `fromJson(?string): self` factory
- [x] 1.4 Create `src/Service/Print/TicketData.php` and `TicketLine.php` value objects matching the spec interface
- [x] 1.5 Modify `config/packages/messenger.yaml` to add `print_ticket` transport and route `PrintTicketMessage`

### Phase 2: Core Implementation
- [x] 2.1 Create `src/Service/Print/TicketDataResolver.php` — assembles `TicketData` from `order_meta` + `catalog_*` tables via `Connection`
- [x] 2.2 Create `src/Service/Print/EscPosTicketBuilder.php` — builds 80mm ESC/POS byte stream with CP1252 Spanish chars, QR via mike42, paper cut
- [x] 2.3 Create `src/Service/Print/TicketPdfGenerator.php` — renders `ticket/print.html.twig` via Twig, converts to PDF bytes with dompdf
- [x] 2.4 Create `src/Service/Print/TicketVerificationService.php` — JWT sign/verify with HS256, 24h expiry using `firebase/php-jwt`
- [x] 2.5 Create `src/Service/Print/PrinterService.php` — orchestrator: resolve config → try ESC/POS (5s timeout) → fallback PDF; update `order_meta.printed` on success
- [x] 2.6 Create `templates/ticket/print.html.twig` — ticket layout matching ESC/POS content (store name, CIF, date, items, totals, QR)

### Phase 3: Integration / Wiring
- [x] 3.1 Create `src/MessageHandler/PrintTicketMessageHandler.php` — calls `PrinterService::print()`, catches exceptions, logs to `audit_log`
- [x] 3.2 Modify `src/Service/Checkout/CheckoutService.php` — inject `MessageBusInterface`, dispatch `PrintTicketMessage` after `insertOrderMeta()` in `createOrder()`
- [x] 3.3 Modify `src/Controller/Api/CheckoutController.php` — return `print_status` in checkout response (polling info)
- [x] 3.4 Create `src/Controller/Api/TicketController.php` — `GET /api/ticket/{id}/pdf`, `POST /api/ticket/reprint`, `GET /api/ticket/verify`

### Phase 4: Frontend Integration
- [x] 4.1 Modify `public/assets/js/api.js` — add `ticketPdf(orderId)`, `ticketReprint(orderId)`, `ticketVerify(token)` endpoints
- [x] 4.2 Modify `public/assets/js/components/PaymentModal.js` — replace static "Imprimiendo ticket…" with "Ticket enviado a impresora" and "Descargar ticket PDF" button
- [x] 4.3 Add "Reimprimir" button in post-checkout view (SaleScreen.js header) — calls `ticketReprint()`, shows toast or PDF download fallback

### Phase 5: Testing
- [x] 5.1 Write unit tests: `PrinterConfig::fromJson()` — valid JSON, null → disabled, malformed JSON → default
- [x] 5.2 Write unit tests: `EscPosTicketBuilder::build()` — byte format, Spanish chars (ñ, á, €), QR encoding
- [x] 5.3 Write unit tests: `TicketPdfGenerator::generate()` — Twig render, PDF magic bytes
- [x] 5.4 Write unit tests: `TicketVerificationService` — JWT sign/verify, expired token rejection, tampered token rejection
- [x] 5.5 Write unit tests: `PrinterService::print()` — ESC/POS success, fallback-to-PDF, disabled path via mocked dependencies
- [x] 5.6 Write unit tests: `TicketDataResolver` — assembles `TicketData` from mock DB rows, field mapping
- [x] 5.7 Write integration tests: `PrintTicketMessageHandler` — dispatch via `sync://` transport, verify service calls
- [x] 5.8 Write integration tests: `TicketController` — PDF download (200/404), reprint (200/404), verify (200/401/400) endpoints via `WebTestCase`

### Phase 6: Configuration
- [x] 6.1 Modify `config/services.yaml` — register `PrinterService`, `EscPosTicketBuilder`, `TicketPdfGenerator`, `TicketVerificationService`, `TicketDataResolver`, `PrintTicketMessageHandler` with constructor dependencies

## Files Changed

| File | Action | What Was Done |
|------|--------|---------------|
| `migrations/Version00000004AddPrintColumnsToOrderMeta.php` | Created | Adds `print_attempts` and `printed_at` to `order_meta` |
| `src/Message/PrintTicketMessage.php` | Created | Messenger DTO with `orderId`, `terminalId` |
| `src/MessageHandler/PrintTicketMessageHandler.php` | Created | Async handler calling `PrinterService::printTicket()` |
| `src/Service/Print/PrinterConfig.php` | Created | Value object with `fromJson()` factory |
| `src/Service/Print/TicketData.php` | Created | Value object for ticket payload |
| `src/Service/Print/TicketLine.php` | Created | Value object for ticket line items |
| `src/Service/Print/TicketDataResolver.php` | Created | Assembles `TicketData` from DB rows |
| `src/Service/Print/EscPosTicketBuilder.php` | Created | Builds ESC/POS bytes using mike42 library |
| `src/Service/Print/TicketPdfGenerator.php` | Created | Renders Twig template to PDF via dompdf |
| `src/Service/Print/TicketVerificationService.php` | Created | JWT sign/verify for QR tokens |
| `src/Service/Print/PrinterService.php` | Created | Orchestrator: ESC/POS → PDF fallback |
| `src/Controller/Api/TicketController.php` | Created | `/api/ticket/{id}/pdf`, `/api/ticket/reprint`, `/api/ticket/verify` |
| `templates/ticket/print.html.twig` | Created | PDF ticket layout |
| `config/packages/messenger.yaml` | Modified | Added `print_ticket` transport and routing |
| `config/services.yaml` | Modified | Registered new services with env-based args |
| `src/Service/Checkout/CheckoutService.php` | Modified | Injected `MessageBusInterface`, dispatches `PrintTicketMessage`, stores cart lines in `payment_breakdown` |
| `src/Controller/Api/CheckoutController.php` | Modified | Returns `print_status: 'dispatched'` in response |
| `public/assets/js/api.js` | Modified | Added `ticketPdf`, `ticketReprint`, `ticketVerify` |
| `public/assets/js/components/PaymentModal.js` | Modified | Shows "Descargar ticket PDF" button on success |
| `public/assets/js/components/SaleScreen.js` | Modified | Added "Reimprimir" button in header |
| `.env.example` | Modified | Added `APP_URL`, `TPV_STORE_NAME`, `TPV_STORE_ADDRESS` |
| `tests/Unit/Service/Print/PrinterConfigTest.php` | Created | Unit tests for PrinterConfig |
| `tests/Unit/Service/Print/EscPosTicketBuilderTest.php` | Created | Unit tests for ESC/POS builder |
| `tests/Unit/Service/Print/TicketPdfGeneratorTest.php` | Created | Unit tests for PDF generator |
| `tests/Unit/Service/Print/TicketVerificationServiceTest.php` | Created | Unit tests for JWT verification |
| `tests/Unit/Service/Print/PrinterServiceTest.php` | Created | Unit tests for PrinterService orchestrator |
| `tests/Unit/Service/Print/TicketDataResolverTest.php` | Created | Unit tests for TicketDataResolver |
| `tests/Unit/MessageHandler/PrintTicketMessageHandlerTest.php` | Created | Unit tests for message handler |
| `tests/Integration/Controller/Api/TicketControllerTest.php` | Created | Integration tests for ticket endpoints |
| `tests/Unit/Service/Checkout/CheckoutServiceTest.php` | Modified | Added `MessageBusInterface` mock to factory method |

## Deviations from Design

1. **CodePage selection**: Used CP1252 (table 16) instead of CP858 (table 19) because mike42/escpos-php's `EscposPrintBuffer` does not have encoding data for CP858, causing PHP warnings. CP1252 supports all required Spanish characters including €.
2. **JWT expiry**: Implemented 24h expiry per spec/design. The orchestrator mentioned 1 year, but spec and design both say 24h.
3. **EscPosTicketBuilder constructor**: Changed from `PrinterConfig` object to `array $config = []` to allow auto-wiring in Symfony, since `PrinterConfig` is a dynamic value object created per-terminal.
4. **Ticket data source**: Stored line items in `order_meta.payment_breakdown` JSON (extended) rather than querying `catalog_*` tables at print time, following the design decision for MVP.

## Issues Found

1. **CheckoutServiceTest required update**: Existing tests needed `MessageBusInterface` mock after constructor change.
2. **mike42/escpos-php codepage limitation**: CP858 encoding data not available in the library's default `EscposPrintBuffer`; switched to CP1252.
3. **Anonymous PrintConnector class**: Had to match interface signatures exactly (no PHP type hints on parameters) to avoid fatal errors.

## Test Results

- **PHPUnit**: 177 tests, 389 assertions, 0 failures, 13 skipped (existing DB-dependent integration tests)
- **PHPStan**: Level 5, 0 errors
- **CS Fixer**: Applied 2 minor fixes (spacing in anonymous class, import ordering)

## Status

28/28 tasks complete. Ready for verify phase.
