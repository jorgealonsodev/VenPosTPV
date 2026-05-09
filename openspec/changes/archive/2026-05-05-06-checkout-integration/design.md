# Design: Checkout Integration

## Technical Approach

Thin controller delegates to `CheckoutService`, which orchestrates: format cart → HMAC-sign payload → POST to PS module via `PsOrderClient` → persist `order_meta` → decrement `catalog_stock` → clear cart. Saga: if `order_meta` or stock update fails after PS order created, log to `audit_log` with action `checkout_orphan`. Frontend `PaymentModal` becomes async: on confirm, calls `POST /api/checkout`, waits for response, then shows success/error.

## Architecture Decisions

| Decision | Choice | Rejected | Rationale |
|----------|--------|----------|-----------|
| Anonymous customer | Create "Consumidor final" in PS on module install | Modify PS module to accept id_customer=0 | No PS code divergence; stable PS-side ID stored in `TPVCONNECT_ANONYMOUS_CUSTOMER_ID` config |
| Cart source | Frontend sends cart_lines in request body | Rely on PHP session cart | Frontend is source of truth (localStorage); session can diverge |
| HTTP client | Dedicated `PsOrderClient` wrapper | Use existing `PrestaShopApiClient` | Different auth (HMAC vs Basic), different endpoint, different base URL |
| Error handling | Saga with audit_log on partial failure | Distributed transaction / rollback | PS `validateOrder()` is irreversible; audit log + manual reconciliation is the POS standard |
| Exception hierarchy | `CheckoutException` with named constructors | Generic RuntimeException | Matches existing pattern (`PsApiException`, `CatalogSyncException`) |

## Data Flow

```
PaymentModal (JS)
  │  POST /api/checkout {payment_method, amount_tendered, cart_lines}
  ▼
CheckoutController
  │  validates auth + input
  ▼
CheckoutService::createOrder()
  │  1. fetch cart (session fallback)
  │  2. build PS payload
  │  3. PsOrderClient::createOrder()
  │     │  json_encode payload
  │     │  HMAC-SHA256 sign → Authorization header
  │     │  POST {PS_SHOP_URL}/module/tpvconnect/orders
  │     ▼
  │  4. INSERT order_meta
  │  5. UPDATE catalog_stock (decrement per line)
  │  6. CLEAR cart (session + response)
  │  7. On step 4-5 failure: INSERT audit_log {action: checkout_orphan}
  ▼
Response: {ps_order_id, reference, total, change_due}
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/Service/Checkout/CheckoutService.php` | Create | Orchestrates order creation: cart → payload → PS call → persist → stock → clear |
| `src/Service/Checkout/PsOrderClient.php` | Create | HTTP client: HMAC-sign + POST to PS `/module/tpvconnect/orders` |
| `src/Service/Checkout/Exception/CheckoutException.php` | Create | Domain exception with named constructors for PS failure, stock failure, orphan |
| `src/Controller/Api/CheckoutController.php` | Modify | Replace placeholder with CheckoutService delegation |
| `config/services.yaml` | Modify | Wire CheckoutService, PsOrderClient with env params |
| `public/assets/js/api.js` | Modify | Add `ApiClient.checkout(payload)` convenience method |
| `public/assets/js/components/PaymentModal.js` | Modify | `handleConfirm()` calls API, shows success/error, emits event with response data |
| `public/assets/js/components/SaleScreen.js` | Modify | `handlePaymentComplete()` receives order data, increments ticket, shows reference |
| `modules/tpvconnect/controllers/front/orders.php` | Modify | Accept anonymous customer: use `TPVCONNECT_ANONYMOUS_CUSTOMER_ID` when `id_customer` maps to anonymous |
| `modules/tpvconnect/install/install.php` | Modify | Create "Consumidor final" customer in PS on install, store ID in config |
| `.env` | Modify | Add `PS_DEFAULT_CURRENCY=1`, `PS_DEFAULT_SHOP=1` |

## Interfaces / Contracts

### PsOrderClient

```php
namespace VenPosTPV\Service\Checkout;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PsOrderClient
{
    public function __construct(
        private readonly string $shopUrl,
        private readonly string $sharedSecret,
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * @param array $payload PS order payload
     * @return array{order_id: int, reference: string, total_paid: float}
     * @throws CheckoutException on network/validation/PS errors
     */
    public function createOrder(array $payload): array;
}
```

HMAC signing: `hash_hmac('sha256', $jsonBody, $this->sharedSecret)` → header `Authorization: HMAC-SHA256 {hex}`. Client timeout: 10s connect, 30s total.

### CheckoutService

```php
namespace VenPosTPV\Service\Checkout;

use Doctrine\DBAL\Connection;
use VenPosTPV\Service\Cart\CartService;

class CheckoutService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly PsOrderClient $client,
        private readonly Connection $conn,
        private readonly string $sharedSecret,
    ) {}

    /**
     * @return array{ps_order_id: int, reference: string, total: float, change_due: float}
     * @throws CheckoutException
     */
    public function createOrder(int $userId, string $paymentMethod, float $amountTendered): array;
}
```

Steps: get cart → resolve customer (anonymous if default) → build payload → call `PsOrderClient` → insert `order_meta` → decrement `catalog_stock` → clear cart. On `order_meta`/stock failure: log `audit_log` with action `checkout_orphan`.

### CheckoutException

```php
namespace VenPosTPV\Service\Checkout\Exception;

final class CheckoutException extends \RuntimeException
{
    public static function psUnavailable(string $message, ?\Throwable $previous = null): self;
    public static function psValidationFailed(string $message): self;
    public static function stockUpdateFailed(int $psOrderId, \Throwable $previous): self;
    public static function orderMetaFailed(int $psOrderId, string $reference, \Throwable $previous): self;
}
```

### Frontend API Contract

```
POST /api/checkout
Request:  {payment_method: string, amount_tendered: float, cart_lines: array}
Response: {data: {ps_order_id, reference, total, change_due, payment_method}}
Error:     {error: string, retry: bool}
```

### PS Module: Anonymous Customer

- Install creates Customer with firstname="Consumidor", lastname="final", email="tpv@localhost"
- Stores ID in `Configuration::get('TPVCONNECT_ANONYMOUS_CUSTOMER_ID')`
- `orders.php` `validateInput()` uses anonymous ID when frontend sends `"id_customer": 0` or omits it
- Sets a default address on the anonymous customer for `validateOrder()`

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | `PsOrderClient::createOrder()` HMAC signing | Mock HttpClient; assert Authorization header, JSON body |
| Unit | `CheckoutService::createOrder()` orchestration | Mock PsOrderClient, CartService, Connection; verify step order |
| Unit | `PsOrderClient` error handling | Mock 401/422/500 responses; assert throws CheckoutException |
| Integration | Full checkout flow | HTTP test to PS module with test secret; verify order created |
| E2E | Frontend → /api/checkout → PS | Manual terminal test; verify PaymentModal success animation |

## Migration / Rollout

- **No schema migration needed**: `order_meta`, `audit_log`, `catalog_stock` tables already exist.
- **PS module install**: Adds "Consumidor final" customer creation to `TpvConnectInstaller::run()`. Existing installs need manual config or module reinstall.
- **Rollback**: Revert CheckoutController to placeholder; revert PaymentModal to sync emit; remove new service files.

## Open Questions

- [ ] Should `PS_DEFAULT_CURRENCY` and `PS_DEFAULT_SHOP` be env vars or config table entries? (Proposed: env vars for consistency with existing `.env` pattern)
- [ ] Anonymous customer email: use `tpv@localhost` or configurable? (Proposed: hardcoded for MVP, configurable later)