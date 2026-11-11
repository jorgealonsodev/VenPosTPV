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
- S10. Search box, user verbatim: "cuando ponemos en buscar en modo movil el campo de busqueda debe ampliarse de golpe por que no deja ver el texto". On phone and tablet the box takes the whole row on focus and while it has text, with no animation; the tool buttons drop to a second row.

Assumptions (mine, not stated by the user): breakpoints 1599px (header menu), 1279px (4 product columns), 1023px (tablet, 3 columns), 767px (phone); the phone pattern is "catalog + bottom bar that opens the ticket".

## Tasks

- [ ] T1 (S2, S3, S4, S8, S9, S10) header menu and sale layout. Route: inline. Commit: pending.
- [ ] T2 (S5) touch targets in ticket and catalog. Route: inline. Commit: pending.
- [ ] T3 (S6) payment modal and small modals. Route: inline. Commit: pending.
- [ ] T4 (S7) secondary screens. Route: inline, re-evaluate per screen. Commit: pending.

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
