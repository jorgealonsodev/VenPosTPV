# Design: Tickets List and Gift Receipt

## Technical Approach

Toggle the right panel between cart (TicketPanel) and a new TicketListPanel via `showTickets` state in SaleScreen. Three tabs feed from localStorage (Parcados) and `GET /api/orders/search` (Cobrados/Devueltos). Gift receipt mode injects a `giftMode` flag through the entire print pipeline — TicketController → PrinterService → EscPosTicketBuilder/TicketPdfGenerator → Twig template — conditionally hiding monetary fields.

## Architecture Decisions

### Decision: Right-panel toggle vs full-screen overlay

**Choice**: Toggle right panel content (like RefundScreen uses full screen, but tickets stay in the 38% sidebar)
**Alternatives**: Full-screen tickets view (like RefundScreen), separate route
**Rationale**: RefundScreen is full-screen because it's a multi-step workflow. Tickets list is a quick-lookup — toggling the right panel keeps the catalog visible and feels lighter. Matches the existing cart paradigm.

### Decision: Separate TicketListPanel component vs inline in SaleScreen

**Choice**: New `TicketListPanel.js` component, registered in `app.js`
**Alternatives**: Expand TicketPanel.js with conditional rendering
**Rationale**: TicketPanel is the active-cart view (lines, totals, payment button). Mixing tabs/history with cart editing violates SRP. A dedicated component is cleaner and matches how RefundScreen isolates its concern.

### Decision: Gift mode parameter on existing `build()`/`generate()` methods vs separate builder

**Choice**: Add `bool $giftMode = false` parameter to `EscPosTicketBuilder::build()` and `TicketPdfGenerator::generate()`
**Alternatives**: Separate GiftEscPosBuilder class, decorator pattern
**Rationale**: Gift mode only suppresses output fields — it's the same ticket structure with conditional rendering. A parameter is the simplest change. Default `false` preserves backward compatibility.

### Decision: Search endpoint filter for refunded orders

**Choice**: Add `refunded` query param to existing `GET /api/orders/search` endpoint in RefundController
**Alternatives**: Separate `/api/orders/refunded` endpoint, client-side filtering
**Rationale**: Reuses existing OrderSearchService with one extra WHERE clause. Client-side filtering wastes bandwidth. No new controller needed.

## Data Flow

```
SaleScreen (showTickets state)
  ├─ false → TicketPanel (cart, current behavior)
  └─ true  → TicketListPanel
        ├─ Tab: Parcados → localStorage('venpostpv_parked') → Restore → useCart.add()
        ├─ Tab: Cobrados → GET /api/orders/search → list → tap → OrderDetailView
        └─ Tab: Devueltos → GET /api/orders/search?refunded=1 → list → tap → OrderDetailView

Gift Receipt:
  User taps "Gift Receipt" → POST /api/ticket/reprint {ps_order_id, gift_mode:true}
    → TicketController → PrinterService::reprintTicket($id, $terminalId, giftMode:true)
      → EscPosTicketBuilder::build($data, true)   // skip prices
      → TicketPdfGenerator::generate($data, true)  // pass giftMode to Twig
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `public/assets/js/components/TicketListPanel.js` | Create | Tabbed panel (Parcados/Cobrados/Devueltos) with list + detail views |
| `public/assets/js/components/OrderDetailView.js` | Create | Read-only order detail (adapted from RefundScreen detail layout) |
| `public/assets/js/SaleScreen.js` | Modify | Add `showTickets` ref, "Tickets" button in header, conditional rendering |
| `public/assets/js/app.js` | Modify | Register `ticket-list-panel` and `order-detail-view` components |
| `public/assets/js/api.js` | Modify | Add `ticketReprintGift()` method, ensure `searchOrders` works without query |
| `src/Service/Print/EscPosTicketBuilder.php` | Modify | Add `bool $giftMode = false` param to `build()`; skip price/total/payment lines when true |
| `src/Service/Print/TicketPdfGenerator.php` | Modify | Add `bool $giftMode = false` param to `generate()`; pass to Twig context |
| `templates/ticket/print.html.twig` | Modify | Add `{% if not giftMode %}` blocks around prices, totals, payment section |
| `src/Service/Print/PrinterService.php` | Modify | Pass `giftMode` through `printTicket()` and `reprintTicket()` signatures |
| `src/Controller/Api/TicketController.php` | Modify | Accept `gift_mode` from reprint body, pass to PrinterService |
| `src/Service/Refund/OrderSearchService.php` | Modify | Add `has_refund` filter (WHERE refunded_amount > 0) |
| `src/Controller/Api/RefundController.php` | Modify | Add `refunded` query param to `searchOrders()` route |

## Interfaces / Contracts

### Backend: `EscPosTicketBuilder::build()`

```php
public function build(TicketData $ticketData, bool $giftMode = false): string
```

When `$giftMode === true`: skip unit price, line total, subtotal, IVA, TOTAL, payment method, tendered, change. Keep: store name/address, date, reference, cashier, item names + quantities, QR, footer.

### Backend: `TicketPdfGenerator::generate()`

```php
public function generate(TicketData $ticketData, bool $giftMode = false): string
```

Passes `['ticket' => $ticketData, 'giftMode' => $giftMode]` to Twig render.

### Backend: `PrinterService` methods

```php
public function printTicket(int $orderId, int $terminalId, bool $giftMode = false): void
public function reprintTicket(int $orderId, int $terminalId, bool $giftMode = false): array
```

### Backend: Reprint endpoint body

```json
{ "ps_order_id": 123, "gift_mode": true }
```

### Backend: Search endpoint

`GET /api/orders/search?refunded=1` returns only orders where `refunded_amount > 0`.

### Frontend: TicketListPanel props/emits

```js
props: ['cashierName']
emits: ['back', 'notify', 'restore']
```

### Frontend: ApiClient additions

```js
ticketReprintGift: (orderId) => ApiClient.post('/api/ticket/reprint', { ps_order_id: orderId, gift_mode: true })
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | EscPosTicketBuilder `giftMode=true` output misses price lines | PHPUnit: assert output contains item names but not `€` in totals section |
| Unit | TicketPdfGenerator `giftMode=true` passes flag to Twig | PHPUnit: mock Twig, assert render called with `giftMode => true` |
| Unit | OrderSearchService `has_refund` filter | PHPUnit: assert SQL includes `refunded_amount > 0` |
| Integration | `POST /api/ticket/reprint` with `gift_mode` | Test client: assert PrinterService receives `giftMode=true` |
| Integration | `GET /api/orders/search?refunded=1` | Test client: assert only refunded orders returned |
| E2E (manual) | Tickets button toggles panel, tabs switch, restore parked | Manual verification in browser |

## Migration / Rollback

No database migration required. `order_meta` already has `refunded_amount`. Rollback per proposal: remove TicketListPanel/OrderDetailView, revert SaleScreen toggle, revert `giftMode` parameters, revert search filter. localStorage key `venpostpv_parked` is untouched.

## Open Questions

- [ ] Should the Devueltos tab show only fully-refunded orders or any with partial refunds? (Proposal says `refunded_amount > 0`, which includes partial.)