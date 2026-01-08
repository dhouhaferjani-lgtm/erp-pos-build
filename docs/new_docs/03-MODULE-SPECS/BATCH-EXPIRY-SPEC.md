# Batch & Expiry Tracking Specification

**Module:** Extends Product + Inventory  
**Product:** IziPOS (primarily para-pharmacy)  
**Status:** Phase 1C - Planned

---

## 1. Overview

Track products by manufacturing batch with expiry dates. Critical for:
- Para-pharmacy (medications, supplements)
- Food retail (perishables)
- Cosmetics (shelf life)

Implements **FEFO** (First-Expired-First-Out) logic for inventory selection.

---

## 2. Key Concepts

### Batch vs Lot
Used interchangeably in this spec. A batch/lot represents:
- A specific manufacturing run
- With a specific expiry date
- Tracked separately in inventory

### FEFO (First-Expired-First-Out)
Unlike FIFO (first in, first out), FEFO prioritizes:
- Products expiring soonest sell first
- Regardless of when they were received
- Reduces waste and ensures compliance

### Expiry Alert Thresholds
Configurable warnings at:
- 90 days before expiry (Yellow)
- 30 days before expiry (Orange)
- 7 days before expiry (Red)
- Expired (Block sale)

---

## 3. Database Schema

### product_batches
```sql
CREATE TABLE product_batches (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL UNIQUE,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    
    -- Product reference
    product_id BIGINT NOT NULL REFERENCES products(id),
    product_variant_id BIGINT REFERENCES product_variants(id),
    
    -- Batch identity
    batch_number VARCHAR(100) NOT NULL,
    
    -- Dates
    manufacturing_date DATE,              -- Optional
    expiry_date DATE NOT NULL,
    
    -- Status
    is_active BOOLEAN DEFAULT true,
    is_expired BOOLEAN DEFAULT false,     -- Computed, updated by job
    
    -- Recall support
    is_recalled BOOLEAN DEFAULT false,
    recall_reason VARCHAR(255),
    recalled_at TIMESTAMP,
    
    -- Metadata
    notes TEXT,
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(company_id, product_id, batch_number)
);

CREATE INDEX idx_batches_expiry ON product_batches(company_id, expiry_date);
CREATE INDEX idx_batches_product ON product_batches(product_id);
```

### inventory_batch_stock
Tracks stock quantity per batch per location.

```sql
CREATE TABLE inventory_batch_stock (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    
    batch_id BIGINT NOT NULL REFERENCES product_batches(id),
    warehouse_id BIGINT NOT NULL REFERENCES warehouses(id),
    location_id BIGINT REFERENCES warehouse_locations(id),
    
    quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
    reserved_quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
    
    -- Computed available
    available_quantity DECIMAL(15,4) GENERATED ALWAYS AS 
        (quantity - reserved_quantity) STORED,
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(batch_id, warehouse_id, COALESCE(location_id, 0))
);

CREATE INDEX idx_batch_stock_warehouse ON inventory_batch_stock(warehouse_id);
CREATE INDEX idx_batch_stock_available ON inventory_batch_stock(available_quantity) 
    WHERE available_quantity > 0;
```

### inventory_batch_movements
Tracks all movements by batch.

```sql
CREATE TABLE inventory_batch_movements (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    
    batch_id BIGINT NOT NULL REFERENCES product_batches(id),
    
    -- Link to main movement
    movement_id BIGINT NOT NULL REFERENCES inventory_movements(id),
    
    quantity DECIMAL(15,4) NOT NULL,      -- Positive or negative
    
    created_at TIMESTAMP DEFAULT NOW()
);
```

### Modify products table
Add flag for batch tracking requirement:

```sql
ALTER TABLE products ADD COLUMN 
    requires_batch_tracking BOOLEAN DEFAULT false;

ALTER TABLE products ADD COLUMN 
    default_shelf_life_days INT;          -- For auto-calculating expiry
```

---

## 4. Domain Models

### Batch Entity
```php
class Batch
{
    private int $id;
    private Uuid $uuid;
    private int $productId;
    private ?int $variantId;
    private string $batchNumber;
    private ?Carbon $manufacturingDate;
    private Carbon $expiryDate;
    private bool $isActive;
    private bool $isExpired;
    private bool $isRecalled;
    private ?string $recallReason;
    
    public function isExpired(): bool
    {
        return $this->expiryDate->isPast();
    }
    
    public function daysUntilExpiry(): int
    {
        return now()->diffInDays($this->expiryDate, false);
    }
    
    public function expiryStatus(): ExpiryStatus
    {
        $days = $this->daysUntilExpiry();
        
        if ($days < 0) return ExpiryStatus::EXPIRED;
        if ($days <= 7) return ExpiryStatus::CRITICAL;
        if ($days <= 30) return ExpiryStatus::WARNING;
        if ($days <= 90) return ExpiryStatus::APPROACHING;
        
        return ExpiryStatus::OK;
    }
}
```

### ExpiryStatus Enum
```php
enum ExpiryStatus: string
{
    case OK = 'ok';
    case APPROACHING = 'approaching';   // 90 days
    case WARNING = 'warning';           // 30 days
    case CRITICAL = 'critical';         // 7 days
    case EXPIRED = 'expired';
    
    public function color(): string
    {
        return match($this) {
            self::OK => 'green',
            self::APPROACHING => 'yellow',
            self::WARNING => 'orange',
            self::CRITICAL => 'red',
            self::EXPIRED => 'gray',
        };
    }
    
    public function canSell(): bool
    {
        return $this !== self::EXPIRED;
    }
}
```

---

## 5. FEFO Service

```php
class FEFOInventoryService
{
    /**
     * Get batches to fulfill a quantity, ordered by expiry (soonest first)
     */
    public function suggestBatchesForSale(
        int $productId,
        int $warehouseId,
        float $quantity,
        bool $includeExpired = false
    ): BatchSuggestionResult {
        $query = InventoryBatchStock::query()
            ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
            ->where('product_batches.product_id', $productId)
            ->where('inventory_batch_stock.warehouse_id', $warehouseId)
            ->where('inventory_batch_stock.available_quantity', '>', 0)
            ->where('product_batches.is_active', true)
            ->where('product_batches.is_recalled', false)
            ->orderBy('product_batches.expiry_date', 'asc');  // FEFO: earliest expiry first
        
        if (!$includeExpired) {
            $query->where('product_batches.expiry_date', '>=', now()->startOfDay());
        }
        
        $batches = $query->get();
        
        $suggestions = [];
        $remaining = $quantity;
        
        foreach ($batches as $stock) {
            if ($remaining <= 0) break;
            
            $takeQuantity = min($remaining, $stock->available_quantity);
            
            $suggestions[] = new BatchSuggestion(
                batch: $stock->batch,
                quantity: $takeQuantity,
                expiryDate: $stock->batch->expiry_date,
                expiryStatus: $stock->batch->expiryStatus()
            );
            
            $remaining -= $takeQuantity;
        }
        
        return new BatchSuggestionResult(
            suggestions: $suggestions,
            fullyFulfilled: $remaining <= 0,
            shortfall: max(0, $remaining)
        );
    }
    
    /**
     * Get products expiring within threshold
     */
    public function getExpiringProducts(
        int $companyId,
        int $daysThreshold = 30,
        ?int $warehouseId = null
    ): Collection {
        $query = ProductBatch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_recalled', false)
            ->whereBetween('expiry_date', [
                now()->startOfDay(),
                now()->addDays($daysThreshold)->endOfDay()
            ])
            ->whereHas('batchStock', function ($q) use ($warehouseId) {
                $q->where('available_quantity', '>', 0);
                if ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                }
            })
            ->with(['product', 'batchStock'])
            ->orderBy('expiry_date', 'asc');
        
        return $query->get();
    }
}
```

---

## 6. API Endpoints

### Batches
```
POST   /api/batches                     # Create batch
GET    /api/batches                     # List batches (filterable)
GET    /api/batches/{id}                # Get batch details
PATCH  /api/batches/{id}                # Update batch
DELETE /api/batches/{id}                # Deactivate batch

POST   /api/batches/{id}/recall         # Initiate recall
GET    /api/batches/expiring            # Get expiring products
```

### Batch Stock
```
GET    /api/products/{id}/batch-stock   # Stock by batch for product
GET    /api/batches/{id}/stock          # Stock levels for batch
GET    /api/batches/{id}/movements      # Movement history
```

### POS Integration
```
GET    /api/pos/products/{id}/batches   # Available batches for sale (FEFO ordered)
```

---

## 7. POS Integration

### Checkout Flow with Batches

```
1. Scan product
   │
   ├─► Product doesn't require batch tracking
   │   └─► Add to cart normally
   │
   └─► Product requires batch tracking
       │
       ├─► Single batch available
       │   └─► Auto-select, show expiry on line
       │
       └─► Multiple batches available
           │
           ├─► Auto-suggest FEFO (earliest expiry)
           │
           └─► Allow override with reason
               (e.g., customer requests specific batch)
```

### Cart Item with Batch
```typescript
interface CartItem {
  productId: number;
  variantId?: number;
  quantity: number;
  unitPrice: number;
  
  // Batch tracking
  batchId?: number;
  batchNumber?: string;
  expiryDate?: string;
  expiryStatus?: 'ok' | 'approaching' | 'warning' | 'critical';
}
```

### Expiry Warning Modal
When selecting product with batch nearing expiry:

```
┌─────────────────────────────────────────┐
│  ⚠️  Expiry Warning                      │
├─────────────────────────────────────────┤
│                                         │
│  Paracetamol 500mg                      │
│  Batch: BATCH-2024-001                  │
│  Expires: 15 Jan 2025 (17 days)         │
│                                         │
│  This product is nearing expiry.        │
│  Proceed with sale?                     │
│                                         │
│  ┌──────────┐  ┌───────────────────┐    │
│  │  Cancel  │  │  Proceed with Sale│    │
│  └──────────┘  └───────────────────┘    │
│                                         │
│  □ Don't warn again for this batch      │
│                                         │
└─────────────────────────────────────────┘
```

### Receipt with Batch Info
```
--------------------------------
QTY  ITEM                  PRICE
--------------------------------
 2   Paracetamol 500mg     4.800
     Lot: BATCH-2024-001
     Exp: 15/01/2025
--------------------------------
```

---

## 8. Reports

### Expiry Report
```
Expiring Products Report
Company: [Company Name]
Generated: 29/12/2025

Products Expiring in Next 30 Days:
─────────────────────────────────────────────────────────
Product          Batch           Qty    Expiry     Status
─────────────────────────────────────────────────────────
Paracetamol     BATCH-2024-001   50    15/01/25   Warning
Vitamin C       VC-2024-Q4       25    22/01/25   Warning
Ibuprofen       IBU-2024-12      100   05/01/25   Critical
─────────────────────────────────────────────────────────

Summary:
- Critical (≤7 days): 1 product, 100 units
- Warning (≤30 days): 2 products, 75 units
- Total value at risk: 450.000 TND
```

### Batch Traceability Report
For recalls - shows all sales containing a batch:

```
Batch Traceability Report
Batch: BATCH-2024-001
Product: Paracetamol 500mg

Sales History:
──────────────────────────────────────────────────
Date        Receipt       Customer     Qty
──────────────────────────────────────────────────
25/12/2025  POS001-1042   Walk-in       2
24/12/2025  POS001-1035   Ahmed K.      5
23/12/2025  POS002-0892   Walk-in       1
──────────────────────────────────────────────────
Total Sold: 8 units
Remaining Stock: 50 units
```

---

## 9. Background Jobs

### DailyExpiryCheck
Run daily to update expired status and send alerts:

```php
class DailyExpiryCheck implements ShouldQueue
{
    public function handle(): void
    {
        // Mark expired batches
        ProductBatch::where('expiry_date', '<', now()->startOfDay())
            ->where('is_expired', false)
            ->update(['is_expired' => true]);
        
        // Get batches expiring soon
        $expiringBatches = ProductBatch::query()
            ->where('is_expired', false)
            ->where('expiry_date', '<=', now()->addDays(30))
            ->whereHas('batchStock', fn($q) => $q->where('available_quantity', '>', 0))
            ->get();
        
        // Group by company and send notifications
        foreach ($expiringBatches->groupBy('company_id') as $companyId => $batches) {
            ExpiryAlertNotification::dispatch($companyId, $batches);
        }
    }
}
```

---

## 10. Testing Requirements

```php
// FEFO tests
test_fefo_suggests_earliest_expiry_first()
test_fefo_skips_expired_batches()
test_fefo_skips_recalled_batches()
test_fefo_handles_partial_fulfillment()
test_fefo_returns_shortfall_when_insufficient_stock()

// Batch stock tests
test_batch_stock_decrements_on_sale()
test_batch_stock_increments_on_refund()
test_batch_stock_respects_reservations()

// Expiry tests
test_expired_product_blocked_from_sale()
test_expiry_warning_shown_for_approaching_expiry()
test_daily_job_marks_expired_batches()
test_expiry_notification_sent_to_company()

// Recall tests
test_recalled_batch_excluded_from_suggestions()
test_recall_generates_traceability_report()

// Receipt tests
test_receipt_includes_batch_number()
test_receipt_includes_expiry_date()
```

---

## 11. Implementation Order

1. **Database migrations** - Create tables
2. **Enums** - ExpiryStatus
3. **Domain models** - Batch, BatchStock
4. **FEFOInventoryService** - Core FEFO logic
5. **Batch CRUD** - Create, update, list batches
6. **Stock integration** - Connect to existing inventory
7. **POS integration** - Batch selection in checkout
8. **Reports** - Expiry and traceability
9. **Background jobs** - Daily expiry check
10. **Frontend** - Batch management UI
11. **Frontend** - POS batch selection
