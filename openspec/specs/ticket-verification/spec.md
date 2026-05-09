# Ticket Verification Specification

## Purpose

QR code generation and JWT-signed verification endpoint for ticket authenticity.

## Requirements

### R1: QR Token Generation

The system MUST generate a JWT token containing `{order_id, reference, iat, exp}` with a 24-hour expiry, encoded as a QR code on every printed ticket. The token MUST be signed with `JWT_SECRET` using HS256.

| Scenario | Given | When | Then |
|---|---|---|---|
| QR on ticket | Order #42 created with reference "XKABCD" | Ticket rendered | QR code encodes JWT with order_id=42, reference="XKABCD", exp=now+24h |
| Token expiry | 25 hours after generation | Verify token | Token MUST be rejected as expired |

### R2: Verification Endpoint

`GET /api/ticket/verify?token={jwt}` MUST decode and validate the JWT, then return order details: `{ps_order_id, reference, total, payment_method, date, items[]}`. Invalid or expired tokens MUST return 401.

| Scenario | Given | When | Then |
|---|---|---|---|
| Valid token | Valid JWT from a recent ticket | GET /api/ticket/verify?token=... | Returns 200 with order details |
| Expired token | JWT older than 24 hours | GET /api/ticket/verify?token=... | Returns 401 with error "Token expired" |
| Tampered token | JWT with modified payload | GET /api/ticket/verify?token=... | Returns 401 with error "Invalid token" |
| Missing token | No token parameter | GET /api/ticket/verify | Returns 400 with error "Token required" |

### R3: QR Code Rendering

The QR code MUST encode a verification URL: `{APP_URL}/api/ticket/verify?token={jwt}`. ESC/POS rendering MUST use `mike42/escpos-php` QR support. PDF fallback MUST render QR as an image.

| Scenario | Given | When | Then |
|---|---|---|---|
| ESC/POS QR | Printer configured, ticket printing | Render QR | QR encoded as ESC/POS QR command with verification URL |
| PDF QR | PDF fallback path | Render QR | QR rendered as image in PDF linking to verification URL |