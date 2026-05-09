# Proposal: Tickets List and Gift Receipt

## Intent

Cashiers need visibility into recent transactions without leaving the sales screen. A "Tickets" button will open a tabbed panel showing parked, paid, and refunded orders with restore/view actions. Additionally, gift receipts (without prices) are required for customer privacy — both ESC/POS and PDF formats must support a gift mode.

## Scope

### In Scope
- Action bar "Tickets" button opening tabbed panel in right area
- Tabs: Parcados (localStorage), Cobrados (backend), Devueltos (backend)
- Restore parked ticket into active cart
- View paid order detail (read-only, from OrderSearchService)
- Gift mode flag on `EscPosTicketBuilder` and `TicketPdfGenerator`
- Gift receipt: hide prices, totals, payment info; keep items, qty, store info, QR
- `POST /api/ticket/reprint` accepts optional `gift_mode: true`

### Out of Scope
- Backend parked table persistence (remains localStorage)
- Cross-caja restore of parked tickets
- QR code price hiding (QR still links to full order)
- Pagination / infinite scroll (first 50 orders via existing LIMIT)

## Capabilities

### New Capabilities
- `ticket-history`: Tabbed panel for parked/paid/refunded list with restore and view actions
- `gift-receipt`: Print/PDF ticket variant omitting monetary fields

### Modified Capabilities
- `ticket-printing`: Add `giftMode` support to builders and reprint endpoint
- `sales-screen`: Add Tickets button, integrate tabbed panel into right zone layout

## Approach

Reuse `RefundScreen` search/detail UI patterns for the paid/refunded tabs. The right panel (currently cart area) toggles between cart view and tickets view via local state. Parked tab reads from `localStorage` key `venpostpv_parked`. Paid/refunded tabs call `GET /api/orders/search?q=` (empty query returns all, limited to 50).

Gift mode: add `giftMode: bool = false` parameter to `EscPosTicketBuilder::build()` and `TicketPdfGenerator::generate()`. When true, skip price columns, subtotal/IVA/TOTAL lines, payment method, and tendered/change. Keep item names and quantities, store header, date/reference, QR, and footer. Update `print.html.twig` with conditional blocks.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `public/assets/js/SaleScreen.js` | Modified | Add Tickets button, toggle right panel state |
| `public/assets/js/TicketPanel.js` | New | Tabbed panel component (parked/paid/refunded) |
| `src/Service/Ticket/EscPosTicketBuilder.php` | Modified | Add `giftMode` flag, skip price fields |
| `src/Service/Ticket/TicketPdfGenerator.php` | Modified | Add `giftMode` flag, skip price fields |
| `templates/pos/print.html.twig` | Modified | Conditional gift mode rendering |
| `src/Controller/Api/TicketController.php` | Modified | Accept `gift_mode` on reprint endpoint |
| `public/assets/js/api.js` | Modified | Add `giftMode` param support |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Gift mode leaks prices in QR-linked order page | Med | QR still links to full order — document this as known limitation |
| Panel state conflicts with active cart editing | Low | Toggle panel; restore parked clears current cart with confirmation |
| LocalStorage parked data lost on browser clear | Low | Out of scope; future backend persistence will address |

## Rollback Plan

1. Revert `SaleScreen.js` button addition and panel toggle
2. Revert `EscPosTicketBuilder` and `TicketPdfGenerator` to remove `giftMode` param
3. Revert `TicketController.php` reprint signature
4. Remove `TicketPanel.js` and any new API methods
5. Frontend cache clear (localStorage key `venpostpv_parked` untouched)

## Dependencies

- `OrderSearchService` — existing backend search with empty-query list support
- `RefundScreen` patterns — search UI and detail view to adapt
- `EscPosTicketBuilder` / `TicketPdfGenerator` — both accept `TicketData`, clean injection point for `giftMode`

## Success Criteria

- [ ] Tickets button visible in action bar; opens/closes tabbed panel
- [ ] Parcados tab shows localStorage parked tickets; restore loads into cart
- [ ] Cobrados tab shows last 50 paid orders; tap shows read-only detail
- [ ] Devueltos tab shows refunded orders (detected via `refunded_amount > 0`)
- [ ] Gift receipt hides all prices, subtotal, IVA, total, payment, change
- [ ] Gift receipt reprint works for both ESC/POS and PDF paths
- [ ] All existing ticket-printing scenarios remain unchanged when `gift_mode=false`
