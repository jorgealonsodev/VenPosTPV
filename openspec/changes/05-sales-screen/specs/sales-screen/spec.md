# Sales Screen Specification

## Purpose

Primary cashier interface: product search, ticket management, payment, session control. Vue 3 SPA on Twig shell at `/app/pos`. Dark theme (oklch), IBM Plex Sans+Mono, 56px+ touch targets.

## Requirements

### R1: Login & Cash Open

Login: user selector + PIN numpad (4–6 digits). Auto-login on 4th digit; shake on <4 digits. Cash Open: balance input with presets (0,50,100,150,200,300 €).

| Scenario | Given | When | Then |
|---|---|---|---|
| Auto-login | User selected, 3 digits | 4th digit pressed | Auth → Cash Open |
| PIN shake | <4 digits | Confirm pressed | Shake, no submit |
| Preset | Cash Open shown | Tap "€100" | Amount=100, confirm enabled |
| Zero-fund | Cash Open shown | Tap "Sin fondos" | Opens balance=0 → Sales Screen |

### R2: Layout

3-zone CSS Grid: catalog (60%), ticket (40%), action bar (full). Header: cashier, terminal, clock, offline icon.

| Scenario | Given | When | Then |
|---|---|---|---|
| Grid render | ≥1024px viewport | Screen loads | 60/40 split + bottom bar |
| Offline | Server unreachable | Header renders | Offline icon, APIs queued |

### R3: Product Catalog

Category tabs, search bar, 5-col grid. Cards: name, price, stock. Search: name/SKU/EAN13.

| Scenario | Given | When | Then |
|---|---|---|---|
| Category filter | 9 categories synced | Tap tab | Filtered products shown |
| SKU search | Search focused | Type "CAM-001" | Matching products appear |
| No-stock | stock=0 | Card renders | Red badge, tap blocked |
| Low-stock | stock 1–5 | Card renders | Amber indicator |

### R4: Active Ticket

Line items: name, price, qty ±, remove. Subtotal, discount, IVA, total. Duplicate → increment.

| Scenario | Given | When | Then |
|---|---|---|---|
| Add | Empty ticket | Tap product | Line qty=1 |
| Increment | Product in ticket | Tap same | Qty+1 |
| Remove | Items present | Tap trash | Line removed |
| Qty floor | qty=1 | Tap minus | Line removed |

### R5: Payment Flow

Modal: cash (numpad, change, presets, "Exacto"), card (placeholder), mixed (split), voucher (code). Change in amber when ≥ total.

| Scenario | Given | When | Then |
|---|---|---|---|
| Cash change | Total €19.95 | Enter €50 | "Cambio: €30.05" |
| Exact | Total €19.95 | Tap "Exacto" | Fills 19.95, change €0 |
| Mixed | Total covered | Confirm | Processes payment |
| Success | Any method | Done | Green check, ticket cleared |

### R6: Barcode Input

USB HID (≤50ms debounce + Enter) + camera (ZXing-js, F6). No match → notification.

| Scenario | Given | When | Then |
|---|---|---|---|
| USB scan | Scan on | Rapid chars + Enter | Product found, added |
| Camera | F6 pressed | Barcode detected | Product added, camera closes |

### R7: API Endpoints

Session-protected. No session → 401. Endpoints: `GET /api/catalog/search` (q+category), `GET /api/catalog/categories`, `GET /api/catalog/product/{id}`, `GET /api/cart`, `POST /api/cart/items`, `DELETE /api/cart/items/{lineId}`, `POST /api/cart/clear`, `POST /api/cart/apply-discount`, `POST /api/cart/checkout`.

| Scenario | Given | When | Then |
|---|---|---|---|
| Search | Auth session | GET search?q=cam&category=3 | Matching products returned |
| Unauthorized | No session | GET any /api/ endpoint | HTTP 401 |

### R8: Quick Actions

Park (F4), void (F10), discount (F2), customer (F3).

| Scenario | Given | When | Then |
|---|---|---|---|
| Park | Items in ticket | F4 | Save localStorage, clear |
| Void | Items in ticket | F10 | Confirm → clear, red notification |
| Discount | Items present | F2 | Modal (% or €) |
| Customer | Any | F3 | Search name/email/phone, assign |