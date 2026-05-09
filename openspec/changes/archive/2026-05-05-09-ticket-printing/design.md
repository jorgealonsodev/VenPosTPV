# Design: Ticket Printing (ESC/POS + PDF)

## Technical Approach

Server-side hybrid printing: after `CheckoutService::createOrder()` persists the order, dispatch a `PrintTicketMessage` via Symfony Messenger async transport. The handler resolves the terminal's `printer_config`, tries ESC/POS first (5s timeout via `NetworkPrintConnector`), and falls back to PDF generation via `dompdf`. QR verification uses short-lived JWT tokens signed with `firebase/php-jwt`. Three new API endpoints expose PDF download, reprint, and QR verification.

## Architecture Decisions

### Decision: Messenger async dispatch vs inline printing

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Inline (synchronous) | Blocks HTTP response 2-5s; simple | ❌ Rejected |
| Messenger async transport | Non-blocking; retry on failure; requires worker | ✅ Chosen |

**Rationale**: The checkout response must return immediately. Printing is best-effort — failures should not block the POS flow. Messenger provides retry, failure transport, and decoupling. The existing `SyncCatalogMessage` pattern proves this works in this codebase.

### Decision: Printer config resolution

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Env vars (global) | One printer for all terminals | ❌ Rejected |
| `terminal.printer_config` JSON column | Per-terminal config; already in schema | ✅ Chosen |
| Separate `printer` table | Normalized; overkill for MVP | ❌ Rejected |

**Rationale**: `terminal.printer_config` (JSON) already exists in the schema. Structure: `{type: "network", host: "192.168.1.100", port: 9100, paper_width: 80, enabled: true}`. TerminalService resolves the terminal from session; PrinterService reads config from the `terminal` row.

### Decision: Ticket data source for reprint

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Re-fetch from PrestaShop API | Always fresh; extra HTTP call; PS may be down | ❌ Risky |
| Store in `order_meta.ticket_data` JSON | Available locally; ticket reproduces exact original | ✅ Chosen |

**Rationale**: Reprint must work even if PrestaShop is unreachable. Store a normalized ticket payload in `order_meta.payment_breakdown` (already JSON) by extending it with line items, or add a `ticket_data` JSON column. For MVP, we extend `payment_breakdown` to include `lines` and `store_info` — no schema migration needed.

### Decision: QR token format

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Raw URL with order_id | Simple; spoofable | ❌ Insecure |
| JWT with HMAC | Verifiable; 24h expiry; `firebase/php-jwt` already installed | ✅ Chosen |
| Signed hash lookup | Requires DB lookup per verify | ❌ Extra query |

**Rationale**: JWT is self-contained — verification endpoint needs no DB call. Payload: `{order_id, reference, total, iat, exp}`. Secret from `%kernel.secret%` or dedicated `%ticket.jwt_secret%`.

### Decision: PDF rendering approach

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Raw TCPDF programmatic | Low-level; verbose | ❌ |
| HTML template + dompdf | Clean separation; Twig already available; `dompdf` installed | ✅ Chosen |

**Rationale**: `dompdf` is already in `composer.json`. Twig is configured in `services.yaml`. A Twig template for the ticket layout keeps rendering logic out of PHP and is easy to modify for branding.

## Data Flow

```
CheckoutService::createOrder()
       │
       ▼ (dispatches after order persists)
MessageBus::dispatch(PrintTicketMessage{order_id, terminal_id})
       │
       ▼ (async: Messenger worker)
PrintTicketMessageHandler::__invoke()
       │
       ├──► PrinterService::print(order_id, terminal_id)
       │         │
       │         ├──► TerminalService → load printer_config
       │         │
       │         ├──► [if enabled] EscPosTicketBuilder::build()
       │         │         │
       │         │         ▼ NetworkPrintConnector (5s timeout)
       │         │         │
       │         │         ├── Success → mark order_meta.printed=1, log
       │         │         └── Exception ─┐
       │         │                        │
       │         └──► [on failure / disabled]
       │                  │                │
       │                  ▼                │
       │              TicketPdfGenerator::generate()
       │              (Twig HTML → dompdf → PDF bytes)
       │                        │         │
       │                        ▼         │
       │                  store PDF path  │
       │                  log fallback    │
       │                                 │
       └──► audit_log on any failure ◄────┘
       
GET /api/ticket/{id}/pdf   → TicketPdfGenerator → StreamedResponse
POST /api/ticket/reprint   → PrinterService (rebuild from order_meta + catalog data)
GET /api/ticket/verify     → TicketVerificationService::verify(token) → JSON
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/Service/Print/EscPosTicketBuilder.php` | Create | Builds ESC/POS byte stream using `mike42/escpos-php` |
| `src/Service/Print/TicketPdfGenerator.php` | Create | Renders ticket HTML via Twig, converts to PDF with `dompdf` |
| `src/Service/Print/PrinterService.php` | Create | Orchestrator: resolve config → try ESC/POS → fallback PDF |
| `src/Service/Print/TicketVerificationService.php` | Create | JWT sign/verify for QR tokens using `firebase/php-jwt` |
| `src/Service/Print/TicketDataResolver.php` | Create | Assembles ticket data from `order_meta` + `catalog_*` tables |
| `src/Message/PrintTicketMessage.php` | Create | Messenger DTO: `{orderId, terminalId}` |
| `src/MessageHandler/PrintTicketMessageHandler.php` | Create | Async handler: calls `PrinterService::print()` |
| `src/Controller/Api/TicketController.php` | Create | `/api/ticket/{id}/pdf`, `/api/ticket/reprint`, `/api/ticket/verify` |
| `templates/ticket/print.html.twig` | Create | Twig template for PDF ticket layout |
| `src/Service/Checkout/CheckoutService.php` | Modify | Inject `MessageBusInterface`, dispatch `PrintTicketMessage` after order creation |
| `src/Controller/Api/CheckoutController.php` | Modify | Return `print_status` in checkout response |
| `public/assets/js/api.js` | Modify | Add `ticketPdf()`, `ticketReprint()`, `ticketVerify()` endpoints |
| `public/assets/js/components/PaymentModal.js` | Modify | Replace placeholder "Imprimiendo ticket…" with real status |
| `config/packages/messenger.yaml` | Modify | Add `print_ticket` transport and route `PrintTicketMessage` |
| `config/services.yaml` | Modify | Register new services with constructor args |

## Interfaces / Contracts

### PrintTicketMessage

```php
final class PrintTicketMessage
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $terminalId,
    ) {}
}
```

### PrinterConfig (value object from `terminal.printer_config` JSON)

```php
final class PrinterConfig
{
    public function __construct(
        public readonly string $type,     // 'network' | 'disabled'
        public readonly string $host,
        public readonly int $port,
        public readonly int $paperWidth,  // 80 (default) or 58
        public readonly bool $enabled,
    ) {}
    
    public static function fromJson(?string $json): self // null → disabled
}
```

### TicketData (assembled by TicketDataResolver)

```php
final class TicketData
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $reference,
        public readonly string $storeName,
        public readonly string $storeAddress,
        public readonly \DateTimeImmutable $date,
        public readonly string $cashierName,
        /** @var TicketLine[] */
        public readonly array $lines,
        public readonly float $subtotal,
        public readonly float $tax,
        public readonly float $total,
        public readonly string $paymentMethod,
        public readonly float $amountTendered,
        public readonly float $changeDue,
        public readonly string $verificationUrl,
    ) {}
}

final class TicketLine
{
    public function __construct(
        public readonly string $name,
        public readonly int $quantity,
        public readonly float $unitPrice,
        public readonly float $lineTotal,
    ) {}
}
```

### TicketController endpoints

| Method | Path | Auth | Response |
|--------|------|------|----------|
| GET | `/api/ticket/{id}/pdf` | Session | `200` PDF stream, `404`if order not found |
| POST | `/api/ticket/reprint` | Session | `200` `{printed: true}` or `{printed: false, fallback: "pdf", message: "..."}` |
| GET | `/api/ticket/verify` | Public | `200` `{order_id, reference, total, date}` or `401` expired/invalid |

### API additions in `api.js`

```javascript
ticketPdf: (orderId) => request(`/api/ticket/${orderId}/pdf`, { method: 'GET' }),
ticketReprint: (orderId) => request('/api/ticket/reprint', { method: 'POST', body: JSON.stringify({ ps_order_id: orderId }) }),
ticketVerify: (token) => request(`/api/ticket/verify?token=${encodeURIComponent(token)}`),
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `EscPosTicketBuilder::build()` — ticket byte format, Spanish chars, QR encoding | PHPUnit with mock `PrintConnector`; assert byte sequences |
| Unit | `TicketPdfGenerator::generate()` — HTML→PDF produces valid PDF | PHPUnit with real `dompdf`; assert PDF magic bytes |
| Unit | `TicketVerificationService` — JWT sign/verify, expiry rejection | PHPUnit; assert token decode, expired token → exception |
| Unit | `PrinterService::print()` — ESC/POS success path, fallback-to-PDF path, disabled path | PHPUnit with mocked `EscPosTicketBuilder` + `TicketPdfGenerator`; assert fallback triggers |
| Unit | `PrinterConfig::fromJson()` — valid config, null config → disabled, malformed JSON | PHPUnit; assert defaults |
| Unit | `TicketDataResolver` — assembles `TicketData` from DB rows | PHPUnit with mock `Connection`; assert field mapping |
| Integration | `PrintTicketMessageHandler` — dispatch → handler → service calls | KernelTestCase with Messenger transport `sync://` |
| Integration | `TicketController` — PDF download, reprint, verify endpoints | `WebTestCase` with test DB |
| E2E | Checkout → print dispatch → worker processes → audit_log entry | Manual testing with test printer or mock connector |

## Migration / Rollout

A new Doctrine migration adds the `print_attempts` and `printed_at` columns to `order_meta`:

```sql
ALTER TABLE order_meta ADD COLUMN print_attempts INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE order_meta ADD COLUMN printed_at DATETIME NULL;
```

The `terminal.printer_config` column already exists (JSON, nullable). No migration needed for that.

Rollback per the proposal:
1. Remove `print_ticket` transport from `messenger.yaml`.
2. Comment out dispatch in `CheckoutService`.
3. `order_meta.printed`, `print_attempts`, `printed_at` remain harmless.

## Open Questions

- [ ] Should `ticket_data` be stored in a separate JSON column on `order_meta` or folded into `payment_breakdown`? (Leaning: separate column for clarity, but extends `payment_breakdown` works for MVP without migration)
- [ ] Store name/address for ticket header — should this come from a config parameter (`%tpv.store_name%`) or from PrestaShop's shop data? (Leaning: config parameter for MVP)
- [ ] PDF file storage: store on disk for later download, or regenerate on demand from `order_meta`? (Leaning: regenerate on demand — no file management overhead)