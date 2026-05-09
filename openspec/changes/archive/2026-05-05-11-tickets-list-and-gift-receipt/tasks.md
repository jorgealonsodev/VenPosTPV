# Tasks: 11-tickets-list-and-gift-receipt

## Phase 1: Foundation — New Components and API Support

- [ ] 1.1 Create `public/assets/js/components/TicketListPanel.js` — tabbed panel with Parcados/Cobrados/Devueltos, emits `back`, `notify`, `restore`
- [ ] 1.2 Create `public/assets/js/components/OrderDetailView.js` — read-only order detail (item names, qty, subtotal, IVA, total, payment method)
- [ ] 1.3 Register `ticket-list-panel` and `order-detail-view` in `public/assets/js/app.js`
- [ ] 1.4 Add `ticketReprintGift(orderId)` method to `public/assets/js/api.js` — `POST /api/ticket/reprint {ps_order_id, gift_mode: true}`
- [ ] 1.5 Add `refunded` query param to `GET /api/orders/search` in `src/Controller/Api/RefundController.php` — filters `refunded_amount > 0`
- [ ] 1.6 Add `has_refund` filter to `src/Service/Refund/OrderSearchService.php` — WHERE `refunded_amount > 0`

## Phase 2: Gift Mode — Backend Print Pipeline

- [ ] 2.1 Add `bool $giftMode = false` param to `EscPosTicketBuilder::build()` — skip unit prices, line totals, subtotal, IVA, TOTAL, payment, tendered, change when true
- [ ] 2.2 Add `{% if not giftMode %}` blocks around monetary fields in `templates/ticket/print.html.twig` — keep item names, qty, store header, date, reference, QR, footer
- [ ] 2.3 Add `bool $giftMode = false` param to `TicketPdfGenerator::generate()` — pass `giftMode` to Twig context
- [ ] 2.4 Pass `giftMode` through `PrinterService::printTicket()` and `PrinterService::reprintTicket()` signatures
- [ ] 2.5 Update `TicketController.php` — accept `gift_mode` from reprint body, pass to PrinterService

## Phase 3: Frontend Integration — SaleScreen Toggle

- [ ] 3.1 Add `showTickets` state to `SaleScreen.js` — toggles right panel between cart and ticket list
- [ ] 3.2 Add "Tickets" button in SaleScreen action bar — active state when panel open, toggles on click
- [ ] 3.3 Render `TicketListPanel` when `showTickets=true`, `TicketPanel` when `false`

## Phase 4: Testing

- [ ] 4.1 Unit test `EscPosTicketBuilder` with `giftMode=true` — assert output contains item names but NOT `€` in totals
- [ ] 4.2 Unit test `TicketPdfGenerator` with `giftMode=true` — mock Twig, assert render called with `giftMode => true`
- [ ] 4.3 Unit test `OrderSearchService` with `has_refund` filter — assert SQL includes `refunded_amount > 0`
- [ ] 4.4 Integration test `POST /api/ticket/reprint` with `gift_mode: true` — assert PrinterService receives `giftMode=true`
- [ ] 4.5 Integration test `GET /api/orders/search?refunded=1` — assert only refunded orders returned

## Implementation Order

Foundation first (1.1–1.6) so components exist before wiring. Gift mode backend (2.1–2.5) is independent of frontend — can be done in parallel. Frontend integration (3.1–3.3) last, depends on both. Testing (4.1–4.5) runs after implementation completes.