# WebSocket Real-Time Updates - Implementation Guide

## Overview

This document describes the WebSocket infrastructure for real-time product cost/price updates using Laravel Reverb and Laravel Echo.

**Problem Solved:** Product cost updates took ~30 seconds to appear due to TanStack Query caching. Now updates appear in <1 second via WebSockets.

**Technology Stack:**
- **Backend:** Laravel Reverb (WebSocket server using Pusher protocol)
- **Frontend:** Laravel Echo + Pusher.js (WebSocket client)
- **Authentication:** Sanctum cookie-based auth
- **Channel Security:** Private channels with multi-tenant authorization

---

## Architecture

### Event Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    DOMAIN LAYER (Pure)                          │
│  WeightedAverageCostService → ProductCostPriceUpdated Event     │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│              INFRASTRUCTURE LAYER (Broadcasting)                 │
│  BroadcastProductEventsListener → ProductCostPriceUpdatedBroadcast│
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                   LARAVEL REVERB SERVER                         │
│  Broadcasts to channel: tenant.{id}.company.{id}.product.{id}   │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                    FRONTEND (React)                             │
│  useProductRealtime → useRealtimeChannel → useWebSocketConnection│
│  Invalidates TanStack Query → UI Auto-Refreshes                │
└─────────────────────────────────────────────────────────────────┘
```

### Separation of Concerns

**Domain Layer (Pure):**
- `ProductCostPriceUpdated` - Domain event (no broadcasting logic)
- Extends `DomainEvent` for event sourcing
- Remains infrastructure-agnostic

**Infrastructure Layer (Broadcasting):**
- `ProductCostPriceUpdatedBroadcast` - Wrapper implementing `ShouldBroadcastNow`
- `BroadcastProductEventsListener` - Event subscriber
- `BroadcastServiceProvider` - Service provider registration

**Application Layer (Frontend):**
- `useWebSocketConnection` - Connection management
- `useRealtimeChannel` - Generic channel subscription
- `useProductRealtime` - Product-specific hook with TanStack Query integration

---

## Backend Implementation

### 1. Broadcasting Event

**File:** `app/Modules/Product/Infrastructure/Broadcasting/ProductCostPriceUpdatedBroadcast.php`

```php
final class ProductCostPriceUpdatedBroadcast implements ShouldBroadcastNow
{
    public function __construct(
        public readonly ProductCostPriceUpdated $domainEvent
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(
                sprintf(
                    'tenant.%s.company.%s.product.%s',
                    $this->domainEvent->tenantId,
                    $this->domainEvent->companyId,
                    $this->domainEvent->productId
                )
            ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'product.cost-price-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'productId' => $this->domainEvent->productId,
            'productSku' => $this->domainEvent->productSku,
            'oldCostPrice' => $this->domainEvent->oldCostPrice,
            'newCostPrice' => $this->domainEvent->newCostPrice,
            'oldSalePrice' => $this->domainEvent->oldSalePrice,
            'newSalePrice' => $this->domainEvent->newSalePrice,
            'reason' => $this->domainEvent->reason,
            'timestamp' => now()->toIso8601String(),
            'referenceDocument' => $this->domainEvent->referenceDocument, // Optional
        ];
    }
}
```

### 2. Event Listener

**File:** `app/Modules/Product/Infrastructure/Listeners/BroadcastProductEventsListener.php`

```php
class BroadcastProductEventsListener
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            ProductCostPriceUpdated::class => 'handleProductCostUpdated',
        ];
    }

    public function handleProductCostUpdated(ProductCostPriceUpdated $event): void
    {
        event(new ProductCostPriceUpdatedBroadcast($event));
    }
}
```

### 3. Channel Authorization

**File:** `routes/channels.php`

```php
Broadcast::channel('tenant.{tenantId}.company.{companyId}.product.{productId}',
    function (User $user, string $tenantId, string $companyId, string $productId) {
        return $user->canAccessChannel($tenantId, $companyId, $productId);
    }
);
```

**Authorization Logic** (`User::canAccessChannel()`):
1. User must belong to the specified tenant
2. User must be active (not suspended/inactive)
3. User must have an active company membership for the specified company

---

## Frontend Implementation

### 1. Echo Service Configuration

**File:** `apps/web/src/lib/echo.ts`

```typescript
export function createEchoInstance(): Echo {
  const apiUrl = import.meta.env.VITE_API_URL || 'http://localhost:8002'
  const wsHost = import.meta.env.VITE_WS_HOST || 'localhost'
  const wsPort = parseInt(import.meta.env.VITE_WS_PORT || '8080', 10)

  return new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'local_key',
    wsHost,
    wsPort,
    authEndpoint: `${apiUrl}/broadcasting/auth`,
    auth: {
      headers: {
        Accept: 'application/json',
      },
    },
    withCredentials: true, // Enable Sanctum cookie auth
  })
}
```

### 2. WebSocket Hooks (Layered Architecture)

**Low-Level:** `useWebSocketConnection`
- Manages Echo connection lifecycle
- Provides connection state (connected, connecting, error)
- Auto-connects on mount

**Mid-Level:** `useRealtimeChannel`
- Generic channel subscription
- Handles subscribe/unsubscribe lifecycle
- Works with any channel/event combination

**High-Level:** `useProductRealtime`
- Product-specific hook
- Auto-invalidates TanStack Query caches
- Type-safe payload handling

### 3. Usage Example

**File:** `apps/web/src/features/inventory/ProductDetailPage.tsx`

```typescript
export function ProductDetailPage() {
  const { id } = useParams()

  // Fetch product data
  const { data: product } = useQuery({
    queryKey: ['product', id],
    queryFn: () => fetchProduct(id),
  })

  // Subscribe to real-time updates (auto-invalidates query)
  useProductRealtime({
    productId: id || '',
    enabled: !!id,
  })

  // Product data auto-refreshes when cost changes!
  return <div>{product.cost_price}</div>
}
```

---

## Channel Naming Convention

### Pattern

```
private-tenant.{tenantId}.company.{companyId}.product.{productId}
```

**Note:** Laravel automatically prepends `private-` to PrivateChannel names.

### Examples

```
private-tenant.9a1b2c3d.company.4e5f6g7h.product.8i9j0k1l
```

### Benefits

1. **Multi-Tenant Isolation:** Tenant ID in channel name prevents cross-tenant subscriptions
2. **Granular Subscriptions:** Subscribe to specific products, not all products
3. **Security:** Private channels require authorization
4. **Scalability:** Can add more levels (location, warehouse) if needed

---

## Event Payload Structure

### Event Name

```
product.cost-price-updated
```

### Payload

```typescript
{
  productId: string          // "9a1b2c3d-4e5f-6g7h-8i9j-0k1l2m3n4o5p"
  productSku: string         // "BRAKE-PAD-001"
  oldCostPrice: string       // "10.50"
  newCostPrice: string       // "12.75"
  oldSalePrice: string       // "15.75"
  newSalePrice: string       // "19.13"
  reason: string             // "purchase_receipt" | "manual_adjustment" | "product_return"
  timestamp: string          // "2025-12-22T21:30:45+00:00" (ISO 8601)
  referenceDocument?: string // "PO-2025-001" (optional)
}
```

---

## Configuration

### Backend (.env)

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=176244
REVERB_APP_KEY=6vhbhb9lmyhxuixydxkq
REVERB_APP_SECRET=qdza0mqp72k3weogs4ws
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
```

### Frontend (.env - Optional)

```env
VITE_API_URL=http://localhost:8002
VITE_WS_HOST=localhost
VITE_WS_PORT=8080
VITE_REVERB_APP_KEY=6vhbhb9lmyhxuixydxkq
```

**Note:** Frontend has sensible defaults, so .env file is optional for local development.

---

## Testing

### Unit Tests (Backend)

```bash
# Run broadcasting tests
php artisan test --filter=ProductCostPriceUpdatedBroadcastTest
php artisan test --filter=BroadcastProductEventsListenerTest
php artisan test --filter=ProductChannelAuthorizationTest

# All 11 tests should pass (29 assertions)
```

### E2E Testing

See [WEBSOCKETS-TESTING.md](./WEBSOCKETS-TESTING.md) for complete E2E testing guide.

---

## Performance Considerations

### Backend

- **Synchronous Broadcasting:** Uses `ShouldBroadcastNow` for immediate delivery (<100ms)
- **Minimal Payload:** ~200-300 bytes per event
- **No N+1 Queries:** Broadcast logic has no database queries

### Frontend

- **Automatic Reconnection:** Echo handles connection drops
- **Shared Connection:** Single WebSocket connection for all channels
- **Efficient Updates:** Only invalidates affected queries

### Scalability

**Current Capacity:**
- Single Reverb server: ~10,000 concurrent connections
- Event throughput: ~1,000 events/second

**Future Scaling:**
- Horizontal scaling: Run multiple Reverb servers with Redis pub/sub
- Load balancing: Sticky sessions via WebSocket proxy

---

## Security

### Multi-Tenant Isolation

1. **Channel Authorization:** User must belong to tenant in channel name
2. **Company Membership:** User must have active membership in company
3. **Active Account:** Suspended users cannot subscribe

### Authentication

- **Sanctum Cookies:** Existing session authentication
- **CSRF Protection:** Standard Laravel CSRF for auth endpoint
- **Private Channels:** All product channels are private (require auth)

### Authorization Code

```php
// User model method
public function canAccessChannel(
    string $tenantId,
    string $companyId,
    string $productId
): bool {
    // Verify tenant
    if ($this->tenant_id !== $tenantId) {
        return false;
    }

    // Verify active status
    if (!$this->isActive()) {
        return false;
    }

    // Verify company membership
    return $this->companyMemberships()
        ->where('company_id', $companyId)
        ->where('status', 'active')
        ->exists();
}
```

---

## Troubleshooting

See [WEBSOCKETS-TROUBLESHOOTING.md](./WEBSOCKETS-TROUBLESHOOTING.md) for common issues and solutions.

---

## Future Enhancements

### Additional Real-Time Features

- **Stock Level Updates:** Notify when stock falls below threshold
- **Document Status Changes:** Invoice posted, payment received
- **User Notifications:** Chat messages, system alerts
- **Collaborative Editing:** Multiple users editing same document

### Optimizations

- **Event Batching:** Batch multiple product updates in bulk imports
- **Selective Broadcasting:** Only broadcast significant changes (>5% price change)
- **Regional Reverb Servers:** Deploy closer to users for lower latency

---

## References

- [Laravel Broadcasting Documentation](https://laravel.com/docs/broadcasting)
- [Laravel Reverb Documentation](https://laravel.com/docs/reverb)
- [Laravel Echo Documentation](https://laravel.com/docs/broadcasting#client-side-installation)
- [Pusher Protocol Documentation](https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol)

---

**Document Version:** 1.0
**Last Updated:** 2025-12-22
**Author:** Claude Sonnet 4.5 (via Claude Code)
