# Delta for Sales Screen

## ADDED Requirements

### R9: Cash Session State Wiring

The sales shell MUST use backend cash APIs as the source of truth: `GET /api/cash/current` for hydration, `POST /api/cash/open` for opening, `POST /api/cash/close` for closing, and `GET /api/cash/report-x` for close totals.

#### Scenario: Existing session hydrates the shell

- GIVEN the cashier enters the POS shell with an already open cash session
- WHEN the frontend loads
- THEN it calls `GET /api/cash/current` and renders the real session ids and totals instead of local placeholders

#### Scenario: Cash open uses backend API

- GIVEN no active cash session exists
- WHEN the cashier confirms opening cash
- THEN the frontend calls `POST /api/cash/open` and only enters the sales screen after the backend returns the real session data

#### Scenario: Cash close uses backend totals

- GIVEN an active cash session exists
- WHEN the cashier closes cash
- THEN the frontend loads current/report totals from backend responses and submits `counted_cash` through `POST /api/cash/close`
