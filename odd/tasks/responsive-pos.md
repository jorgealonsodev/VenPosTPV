# Responsive POS (tablet and phone)

Objective: the POS is fully usable by touch on tablets (768-1024px, both orientations) and phones (360-430px).
Branch: `feat/responsive-pos`. Delivery: feature branch merged to `main` per slice, then FTPS upload (repository convention, no PRs).
Test runner: `node --test tests/Functional/*.test.cjs`; layout checked in the browser against the online POS with local assets served into the agent's own tab.

## Specs

- S1. User request, verbatim: "el tpv tiene que funcionar en tablet y en movil, hay que asegurarse que la usabilidad es optima".
- S2. Header: every header action stays reachable at any width. Below the width where the row fits without wrapping its labels (measured: about 1435px for an admin, 1545px with «Reimprimir»), the actions move into a menu opened from one header button. Nothing is clipped.
- S3. Sale screen on a phone (<768px): catalog and ticket are shown one at a time at full width; a bottom bar on the catalog shows the item count and total and opens the ticket; the ticket has a way back to the catalog.
- S4. Sale screen on a tablet: catalog and ticket stay side by side; product cards stay readable (fewer columns instead of narrower cards).
- S5. Touch targets used during a sale are at least 44px (cart quantity and delete buttons, ticket actions, catalog toolbar, category arrows).
- S6. Payment modal and small modals (customer, discount, park note, cash open) fit a phone: no fixed width wider than the screen, content scrolls, the confirm action stays reachable.
- S7. Secondary screens (refunds, labels, cash close, reports, settings) are usable on tablet and phone: two-column layouts stack, wide tables scroll inside their own container.
- S8. Viewport: heights follow the dynamic viewport (`dvh`) so the on-screen keyboard and browser bars do not hide the bottom of the screen.
- S9. Desktop (>=1600px) layout is unchanged.
- S10. Search box, user verbatim: "cuando ponemos en buscar en modo movil el campo de busqueda debe ampliarse de golpe por que no deja ver el texto". On phone and tablet the box takes the whole row on focus and while it has text, with no animation; the tool buttons drop to a second row. Follow-up, user verbatim: "si tal en vesion movil pon un boton de lupa y al calcarlo abre la busqueda": on a phone the idle search is a 44px magnifier button; tapping it opens the full-width box.

Assumptions (mine, not stated by the user): breakpoints 1599px (header menu), 1279px (4 product columns), 1023px (tablet, 3 columns), 767px (phone); the phone pattern is "catalog + bottom bar that opens the ticket".

## Tasks

- [x] T1 (S2, S3, S4, S8, S9, S10) header menu and sale layout. Route: inline. Commit: 9c266be. Review: medium, under_budget (not due). Live since 2026-11-11.
- [x] T2 (S5, S10) touch targets in ticket and catalog; phone search opens from a magnifier button. Route: inline. Commit: see git log (feat(pos): touch-sized sale controls). Live since 2026-11-11.
- [x] T3 (S6) payment modal and small modals. Route: inline. Commit: see git log (feat(pos): payment screen and small modals fit a phone). Live since 2026-11-11. Not browser-checked: cash open card, payment result card, park note (CSS and structure tests only).
- [x] T4 (S7) secondary screens. Route: delegated writer (context backstop), parent checked in the browser and added two fixes. Commit: see git log (feat(pos): secondary screens usable on tablet and phone). Live since 2026-11-11.

Route evidence: no writer trigger holds (units depend on the shared CSS in `app.css`, so they are not disjoint).
Forecast: about 900 authored changed lines, delivered as one merge per task.

## Log

- L1 (2026-11-11) User request: "el tpv tiene que funcionar en tablet y en movil, hay que asegurarse que la usabilidad es optima".
- L2 (2026-11-11) User: "puedes usar playwrite", "puedes verlo online", "es que no estabamos usando la version local, no la uses", "trabaja con la version online y ftp".
- L3 (2026-11-11) Measured on the online POS: header needs 1386px (admin); at 768px seven header buttons are clipped and cards are 83px wide; at 390px twelve header buttons are off-screen, cards are 36px wide, Aparcar/Anular are off-screen.
- L4 (2026-11-11) Cause: layout lives in inline `style=` inside the Vue templates, so media queries cannot reach it; `.layout-catalog`/`.layout-ticket` exist in `app.css` but are unused. Fix direction: move layout to classes.
- L5 (2026-11-11) Verification method: Playwright tab on the online POS with `/assets/*.js|css` fulfilled from the working copy (only that tab sees the change). Look-only: no charging, cash close or settings saves.
- L6 (2026-11-11) Browser check of T1: the first breakpoint (1399px) was too low; with labels unwrapped the admin row needs about 1435px and «Reimprimir» adds about 110px, so the menu starts below 1600px. Chrome emulation reports fractional widths, so a boundary value such as 1399 may not match its own media query.
- L7 (2026-11-11) User: "cuando ponemos en buscar en modo movil el campo de busqueda debe ampliarse de golpe por que no deja ver el texto". Added S10 to T1; applied to tablet as well because at 768px the box was 147px wide and cut the placeholder (my extension).
- L8 (2026-11-11) T1 evidence: RED 6/6 then GREEN; full functional suite 215/215; browser (online POS, local assets): no clipped header element at 360-1440, 2/3/4/5 product columns at 390/768/1024/1366+, phone bar opens the ticket and the back button returns, search box 126->366px on focus at 390 and 165->405px at 768, desktop 1920 unchanged (62% catalog, no menu). Uploaded 5 files by FTPS (server matched main before, sha256 checked after), cache rotated to prod-old-185017, live check without overrides passed. Not merged to main nor pushed yet.
- L9 (2026-11-11) User: "si tal en vesion movil pon un boton de lupa y al calcarlo abre la busqueda". S10 rewritten for the phone; tablet keeps the visible box that expands.
- L10 (2026-11-11) T2 evidence: RED 4/4 then GREEN; suite 219/219; browser at 390px: magnifier 44x44 -> 366x44 on tap with focus in the input; every ticket control >= 44px (qty, remove, customer, Descuento/Aparcar/Anular, Cobrar 62px), nothing off-screen; category arrows 44px; size picker fits. A test line was added to the online cart and removed again (cart left empty).
- L11 (2026-11-11) User, about the payment screen ("al lo que se abre cuando se le da a cobraar", "me refiero a cobrar"): "intenta que el boton verde entre en la pantalla", "reduce un poco el tamaño de los botones en version movil". S6 for the payment modal on a phone: compact keys and methods (46px), «Cobrar» pinned to the bottom of the screen. Measured before: at 360x640 the modal was 816px tall and «Cobrar» sat at 632-696px.
- L12 (2026-11-11) Defect of T1 found while checking T3 and fixed: `.pos-header` had z-index 900 and covered the modals (payment head hidden). Now 80, below `.modal-backdrop` (200); test added.
- L13 (2026-11-11) T3 evidence: RED then GREEN; suite 224/224; browser (online POS, local assets, test line added and removed, nothing charged): payment at 360x640 is 664px tall (was 816) with «Cobrar» at 574-630 in view and content scrolling clear of it; at 390x700 it fits without scrolling; total and close button visible; tablet 768 unchanged (60px keys, button in flow); discount card 320x273 and customer card 336x410 fit at 360px.
- L14 (2026-11-11) User, with a phone screenshot of the payment screen: "estos botones hay que hacelos mas pequeños para que entre el boton verde", "sobre todo los metodos de pago, no es necesario tan grandes", "tienen que entrar en una linea". S6: on a phone the payment methods sit on one line (small icon over an 11px label, 44px tall, width by content); the selected mark is hidden there and the selected method gets a green background.
- L15 (2026-11-11) User, on the refund screen: "el boton buscar igual que en la pagina principal". On a phone the refund search button is an icon-only magnifier (48px), the box keeps the row (272px at 360px) and «Sin ticket» drops to its own row.
- L16 (2026-11-11) T4 evidence: writer RED 8/9 then GREEN; suite 235/235. Browser (online POS, local assets, look-only): reports at 360/768 do not pan and tables scroll in their wrapper (VAT 336/861), 1440 unchanged; settings at 360 is a full-screen sheet with a scrolling tab row, 44px close and no resize handles, 768 fits, 1440 unchanged (20px close, 4 handles); labels stack at 360 and keep two columns at 768; refunds with ticket X26000001 loaded stack at 360 with «CAMBIAR»/«DEVOLVER» in view (nothing refunded); cash close is one column at 360 and two at 768 with numeric inputs. Defect found by the parent check and fixed: the cash close screen could not scroll (640px viewport, 1280px content, close button at 1214px) because its wrapper has no height; `.cash-close-screen` now takes 100dvh at every width (1440x900 measured unchanged, 900/900).
- L17 (2026-11-11) Not browser-checked: settings tabs other than «Empleados», settings with a saved size at 800px, cash open card, payment result card, park note, label list with items, refund «DEVOLVER 0.00 €» label wraps to three lines at 360px (cosmetic, open).
