# Ticket Management Specification

## Purpose

Tabbed panel for browsing parked, paid, and refunded tickets from the sale screen, with restore and read-only detail actions.

## Requirements

### R1: Tickets Button

The system MUST provide a "Tickets" button in the sale screen action bar. Pressing it MUST toggle the right panel between cart view and ticket list view. The active panel state MUST persist until the user toggles back or completes a sale.

| Scenario | Given | When | Then |
|---|---|---|---|
| Open panel | User on sale screen | Presses "Tickets" button | Right panel switches to ticket list with Parcados/Cobrados/Devueltos tabs; button shows active state |
| Close panel | Ticket panel open | Presses "Tickets" button again | Right panel returns to cart view; button returns to default state |

### R2: Parked Tab

The system MUST show a "Parcados" tab listing all parked tickets from `localStorage` key `venpostpv_parked`. Each entry MUST display ticket reference, total, and parked timestamp. Selecting an entry MUST offer a "Restore" action that replaces the active cart with the parked items after user confirmation. Restored tickets MUST be removed from localStorage.

| Scenario | Given | When | Then |
|---|---|---|---|
| List parked | localStorage has 3 parked tickets under `venpostpv_parked` | User opens Parcados tab | 3 entries shown with reference, total, timestamp |
| Restore parked | Parked ticket with 5 items displayed | User selects ticket and confirms restore | Cart replaced with 5 items; entry removed from localStorage |
| Restore with cart | Non-empty cart, user restores parked ticket | User confirms "Replace current cart?" | Current cart cleared; parked items loaded; parked entry removed |
| Empty parked | No parked tickets in localStorage | User opens Parcados tab | Empty state: "No hay tickets aparcados" |

### R3: Paid Tab

The system MUST show a "Cobrados" tab fetching paid orders via `GET /api/orders/search?q=` (empty query, limited to 50). Each entry MUST display ticket reference, total, and date. Selecting an entry MUST open a read-only detail view (R4).

| Scenario | Given | When | Then |
|---|---|---|---|
| List paid orders | Backend has paid orders | User opens Cobrados tab | Last 50 paid orders listed with reference, total, date |
| No paid orders | No paid orders exist | User opens Cobrados tab | Empty state: "No hay tickets cobrados" |

### R4: Ticket Detail

The system MUST provide a read-only detail view showing item names, quantities, subtotal, IVA, total, and payment method. No edit or modification actions MUST be available. A "Back" action MUST return to the previously active tab.

| Scenario | Given | When | Then |
|---|---|---|---|
| View paid detail | User selected a paid order | Detail view opens | Items, quantities, subtotal, IVA, total, payment method displayed; no edit actions |
| Return to list | Detail view open | User presses "Back" | Ticket list returns to previously active tab |
