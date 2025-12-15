# Stock Reservation Requirements

> **Created:** 2025-12-13
> **Purpose:** Document stock reservation system requirements for preventing overselling

---

## 1. Current State

### 1.1 Existing Infrastructure

The `stock_levels` table already has reservation support:

```sql
CREATE TABLE stock_levels (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    product_id UUID NOT NULL,
    location_id UUID NOT NULL,
    quantity DECIMAL(15,2) DEFAULT 0,   -- Physical on-hand
    reserved DECIMAL(15,2) DEFAULT 0,   -- Committed to orders
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**Available Quantity Formula:**
```
available = quantity - reserved
```

### 1.2 StockAdjustmentService Has Reserve Methods

```php
// Already exists in StockAdjustmentService.php

public function reserve(...): StockMovement
{
    return DB::transaction(function () use (...): StockMovement {
        $stockLevel = $this->lockStockLevel($productId, $locationId);
        $available = $stockLevel->getAvailableQuantity();

        if (bccomp($quantity, $available, self::SCALE) > 0) {
            throw new InsufficientStockException(...);
        }

        $movement = StockMovement::create([
            'type' => StockMovementType::Reserve,
            // ...
        ]);

        $stockLevel->reserved = bcadd($stockLevel->reserved, $quantity, self::SCALE);
        $stockLevel->save();

        return $movement;
    });
}

public function releaseReservation(...): StockMovement
{
    // Decrements reserved, creates release movement
}
```

### 1.3 What's NOT Connected

The reservation methods exist but are **never called** from:
- Sales Order confirmation
- Delivery Note creation
- Any document workflow

---

## 2. Phase 1: Basic Reservation System

### 2.1 When Reservations Should Happen

| Event | Action | Purpose |
|-------|--------|---------|
| Sales Order confirmed | Create reservation | Prevent overselling |
| Delivery Note confirmed | Release reservation + Issue stock | Stock physically left |
| Sales Order cancelled | Release reservation | Return to available pool |
| Delivery Note cancelled | Release reservation (if not already issued) | Return to available pool |

### 2.2 Reservation Data Model

Each reservation should track:

```php
// stock_movements table already has this
StockMovement {
    id: UUID
    product_id: UUID
    location_id: UUID
    movement_type: 'reserve' | 'reserve_release' | 'issue' | 'receive'
    quantity: decimal
    reference: string  // e.g., "SO-2024-001"
    document_id: UUID  // Link to source document
}
```

### 2.3 Integration Points

**SalesOrderService::confirm()** (new service)
```php
public function confirm(Document $salesOrder): Document
{
    return DB::transaction(function () use ($salesOrder) {
        foreach ($salesOrder->lines as $line) {
            if ($line->product?->isPhysical()) {
                $this->stockAdjustmentService->reserve(
                    productId: $line->product_id,
                    locationId: $salesOrder->location_id,
                    quantity: (string) $line->quantity,
                    reference: $salesOrder->document_number,
                    userId: auth()->id()
                );
            }
        }

        $salesOrder->update(['status' => DocumentStatus::Confirmed]);
        return $salesOrder;
    });
}
```

**DeliveryNoteService::confirm()** (modify existing)
```php
public function confirm(Document $deliveryNote): Document
{
    return DB::transaction(function () use ($deliveryNote) {
        foreach ($deliveryNote->lines as $line) {
            if ($line->product?->isPhysical()) {
                // First release reservation (if exists)
                $this->stockAdjustmentService->releaseReservation(
                    productId: $line->product_id,
                    locationId: $deliveryNote->location_id,
                    quantity: (string) $line->quantity,
                    reference: $deliveryNote->source_document?->document_number ?? $deliveryNote->document_number,
                    userId: auth()->id()
                );

                // Then issue stock
                $this->stockAdjustmentService->issue(
                    productId: $line->product_id,
                    locationId: $deliveryNote->location_id,
                    quantity: (string) $line->quantity,
                    reference: $deliveryNote->document_number,
                    userId: auth()->id()
                );
            }
        }

        $this->confirmWithFiscalChain($deliveryNote);
        return $deliveryNote;
    });
}
```

---

## 3. Phase 2: Time-Based Reservations (Long-Term)

### 3.1 Concept

The user mentioned reservations with dynamic durations based on:
- Stock level (how much is available)
- Sales frequency (how fast the item sells)

**Example:**
- Item A: 100 units in stock, sells 10/day → Reservation valid for 7 days
- Item B: 5 units in stock, sells 3/day → Reservation valid for 1 day

### 3.2 Reservation Expiry Schema

```sql
CREATE TABLE stock_reservations (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    product_id UUID NOT NULL,
    location_id UUID NOT NULL,
    document_id UUID NOT NULL,          -- The SO or DN holding the reservation
    document_line_id UUID NOT NULL,     -- Specific line
    quantity DECIMAL(15,4) NOT NULL,
    reserved_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NULL,          -- NULL = never expires
    released_at TIMESTAMP NULL,         -- When released (cancelled, fulfilled)
    release_reason VARCHAR(50),         -- 'fulfilled', 'cancelled', 'expired'

    FOREIGN KEY (document_id) REFERENCES documents(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (location_id) REFERENCES locations(id)
);
```

### 3.3 Expiry Duration Calculation

```php
class ReservationDurationCalculator
{
    /**
     * Calculate how long a reservation should last.
     *
     * Factors:
     * - Available stock (more stock = longer duration)
     * - Average daily sales (faster sales = shorter duration)
     * - Days of stock remaining (safety buffer)
     */
    public function calculateDuration(
        Product $product,
        Location $location,
        string $quantityToReserve
    ): int {
        $stockLevel = StockLevel::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->first();

        $available = $stockLevel?->getAvailableQuantity() ?? '0';
        $dailySales = $this->getAverageDailySales($product, $location, days: 30);

        if ($dailySales <= 0) {
            // No sales history - use default
            return self::DEFAULT_RESERVATION_DAYS;
        }

        // Calculate days of stock remaining after this reservation
        $remainingAfter = bcsub($available, $quantityToReserve, 4);
        $daysOfStock = (float) $remainingAfter / $dailySales;

        // Map to reservation duration
        return match (true) {
            $daysOfStock >= 30 => 14,  // Plenty of stock: 2 weeks
            $daysOfStock >= 14 => 7,   // Good stock: 1 week
            $daysOfStock >= 7  => 3,   // Limited stock: 3 days
            $daysOfStock >= 3  => 1,   // Low stock: 1 day
            default            => 0,   // Very low: no hold, immediate only
        };
    }

    private function getAverageDailySales(Product $product, Location $location, int $days): float
    {
        $totalSold = StockMovement::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->where('movement_type', 'issue')
            ->where('created_at', '>=', now()->subDays($days))
            ->sum(DB::raw('ABS(quantity)'));

        return $totalSold / $days;
    }

    private const DEFAULT_RESERVATION_DAYS = 7;
}
```

### 3.4 Reservation Expiry Worker

```php
// Scheduled job to run every hour

class ExpireStaleReservations
{
    public function handle(): void
    {
        $expired = StockReservation::query()
            ->whereNull('released_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $reservation) {
            DB::transaction(function () use ($reservation) {
                // Release the reservation
                $stockLevel = StockLevel::where('product_id', $reservation->product_id)
                    ->where('location_id', $reservation->location_id)
                    ->lockForUpdate()
                    ->first();

                $stockLevel->reserved = bcsub(
                    (string) $stockLevel->reserved,
                    (string) $reservation->quantity,
                    4
                );
                $stockLevel->save();

                // Mark reservation as expired
                $reservation->update([
                    'released_at' => now(),
                    'release_reason' => 'expired',
                ]);

                // Notify customer? Send event for UI update
                event(new ReservationExpired($reservation));
            });
        }
    }
}
```

---

## 4. Real-Time UI Updates

### 4.1 WebSocket Events

When stock changes, broadcast to relevant clients:

```php
// On any stock level change
event(new StockLevelChanged(
    productId: $productId,
    locationId: $locationId,
    newQuantity: $newQty,
    newAvailable: $newAvailable,
    reason: 'reservation_created' | 'reservation_released' | 'issue' | 'receive'
));
```

### 4.2 Frontend Subscription

```typescript
// In product listing or cart components
useEffect(() => {
    const channel = window.Echo.private(`stock.${companyId}`);

    channel.listen('StockLevelChanged', (event: StockLevelChangedEvent) => {
        queryClient.setQueryData(
            ['stock-levels', event.productId],
            (old: StockLevel) => ({
                ...old,
                quantity: event.newQuantity,
                available: event.newAvailable,
            })
        );

        // Show visual indicator
        if (event.newAvailable < threshold) {
            showLowStockWarning(event.productId);
        }
    });

    return () => channel.stopListening('StockLevelChanged');
}, [companyId]);
```

### 4.3 UI Display Requirements

| Context | Display | Behavior |
|---------|---------|----------|
| Product list | Available qty + status badge | Update in real-time |
| Cart/Quote form | Available when line added | Warn if insufficient |
| Sales Order form | Check availability on save | Block if insufficient |
| Sales Order confirm | Reserve + show expiry | Show countdown if timed |
| Stock list | On-hand, Reserved, Available columns | Real-time updates |

---

## 5. Edge Cases

### 5.1 Overselling Protection

**Scenario:** Two salespeople add same product to orders simultaneously.

**Solution:**
```php
// In SalesOrderService::confirm()
public function confirm(Document $salesOrder): Document
{
    return DB::transaction(function () use ($salesOrder) {
        foreach ($salesOrder->lines as $line) {
            if ($line->product?->isPhysical()) {
                try {
                    $this->stockAdjustmentService->reserve(...);
                } catch (InsufficientStockException $e) {
                    throw new \DomainException(
                        "Cannot confirm order: insufficient stock for {$line->product->name}. " .
                        "Available: {$e->available}, Requested: {$e->requested}"
                    );
                }
            }
        }

        $salesOrder->update(['status' => DocumentStatus::Confirmed]);
        return $salesOrder;
    });
}
```

### 5.2 Partial Delivery

**Scenario:** Order has 10 units, only 5 available for immediate delivery.

**Options:**
1. **All-or-nothing:** Block confirmation until full stock available
2. **Partial reservation:** Reserve 5, backorder 5
3. **Split order:** Create two orders (immediate + backorder)

**Recommended for Phase 1:** All-or-nothing, with clear error message showing shortfall.

### 5.3 Reservation Transfer

**Scenario:** Order is partially delivered (5 of 10 units).

**Flow:**
1. Original reservation: 10 units
2. DN created for 5 units
3. DN confirmed: Release 5, Issue 5
4. Remaining reservation: 5 units (still held for next DN)

```php
// On partial delivery note confirmation
$this->stockAdjustmentService->releaseReservation(
    quantity: $deliveredQty  // Only release what's being delivered
);

$this->stockAdjustmentService->issue(
    quantity: $deliveredQty
);

// Remaining reservation stays on the Sales Order
```

---

## 6. Schema Changes Required

### 6.1 Phase 1 (Basic Reservations)

No schema changes needed - use existing `stock_levels.reserved` column and `stock_movements` table.

### 6.2 Phase 2 (Time-Based)

```sql
-- New table for explicit reservation tracking
CREATE TABLE stock_reservations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    product_id UUID NOT NULL REFERENCES products(id),
    location_id UUID NOT NULL REFERENCES locations(id),
    document_id UUID NOT NULL REFERENCES documents(id),
    document_line_id UUID REFERENCES document_lines(id),
    quantity DECIMAL(15,4) NOT NULL,
    reserved_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP WITH TIME ZONE,
    released_at TIMESTAMP WITH TIME ZONE,
    release_reason VARCHAR(50),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),

    CONSTRAINT positive_quantity CHECK (quantity > 0)
);

CREATE INDEX idx_stock_reservations_product ON stock_reservations(product_id);
CREATE INDEX idx_stock_reservations_document ON stock_reservations(document_id);
CREATE INDEX idx_stock_reservations_expires ON stock_reservations(expires_at) WHERE released_at IS NULL;
```

---

## 7. Implementation Priority

| Phase | Feature | Complexity | Impact |
|-------|---------|------------|--------|
| **1a** | Reserve on SO confirm | Low | Prevents overselling |
| **1b** | Release + issue on DN confirm | Low | Completes reservation lifecycle |
| **1c** | Release on cancel | Low | Returns stock to pool |
| **1d** | Real-time UI updates | Medium | Better UX |
| **2a** | Time-based expiry schema | Medium | Enables advanced features |
| **2b** | Expiry calculation | Medium | Dynamic reservation periods |
| **2c** | Expiry worker + notifications | Medium | Automatic cleanup |
| **2d** | Customer-facing countdown | Low | Transparency |

---

## 8. Files Reference

| File | Action |
|------|--------|
| `StockAdjustmentService.php` | ✅ Already has reserve/release methods |
| `SalesOrderService.php` | 🔴 CREATE - Call reserve on confirm |
| `DeliveryNoteService.php` | 🔴 MODIFY - Call release + issue |
| `stock_levels` migration | ✅ Already has `reserved` column |
| `stock_reservations` migration | 🟡 CREATE for Phase 2 |
| `ReservationDurationCalculator.php` | 🟡 CREATE for Phase 2 |
| `ExpireStaleReservations.php` | 🟡 CREATE for Phase 2 |
| `StockLevelChanged` event | 🟡 CREATE for real-time UI |
