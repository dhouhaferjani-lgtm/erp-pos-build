# Batch and Expiry Date Tracking

> **Status**: Post-Fork Feature Specification
> **Priority**: High for Pharmacy/Auto Parts verticals
> **Estimated Effort**: 3-4 weeks

---

## Overview

Batch and expiry date tracking enables inventory management for products that require lot number tracking, expiration date management, and FEFO (First Expired First Out) allocation. This is critical for:

- **Pharmacies**: Drug inventory requiring expiry tracking per regulatory requirements
- **Auto Parts**: Brake fluids, oils, batteries, and other perishable components
- **Food Service**: Consumables with shelf life constraints

---

## Business Requirements

### Core Functionality

1. **Batch/Lot Tracking**
   - Assign unique batch/lot numbers on goods receipt
   - Track batch origin (supplier, PO, date received)
   - Support supplier-provided batch numbers
   - Auto-generate batch numbers if not provided

2. **Expiry Date Management**
   - Record expiry date per batch
   - Configurable alert thresholds (e.g., 30/60/90 days before expiry)
   - Dashboard widget for expiring inventory
   - Block sale of expired products

3. **FEFO Allocation**
   - Automatically allocate oldest-expiring batches first during sales
   - Manual override capability for specific situations
   - Reservation system respects FEFO ordering

4. **Traceability**
   - Full audit trail: batch received from supplier, sold to which customers
   - Recall support: identify all customers who received a specific batch
   - Certificate of Analysis (CoA) document attachment per batch

---

## Database Schema

### New Tables

```sql
-- Batch master table
CREATE TABLE stock_batches (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    company_id UUID NOT NULL REFERENCES companies(id),
    product_id UUID NOT NULL REFERENCES products(id),
    location_id UUID NOT NULL REFERENCES locations(id),

    -- Batch identification
    batch_number VARCHAR(100) NOT NULL,
    supplier_batch_number VARCHAR(100),

    -- Dates
    expiry_date DATE,
    manufacture_date DATE,
    received_date DATE NOT NULL DEFAULT CURRENT_DATE,

    -- Quantities
    initial_quantity DECIMAL(15,4) NOT NULL,
    current_quantity DECIMAL(15,4) NOT NULL,
    reserved_quantity DECIMAL(15,4) NOT NULL DEFAULT 0,

    -- Costing (per batch WAC)
    unit_cost DECIMAL(15,4) NOT NULL,

    -- Source tracking
    goods_receipt_id UUID REFERENCES documents(id),
    supplier_id UUID REFERENCES partners(id),

    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    -- active, depleted, expired, quarantine, recalled

    -- Metadata
    notes TEXT,
    certificate_url VARCHAR(500),
    payload JSONB,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    UNIQUE (company_id, product_id, location_id, batch_number)
);

-- Allocation table: links sales to specific batches
CREATE TABLE batch_allocations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    batch_id UUID NOT NULL REFERENCES stock_batches(id),
    document_line_id UUID NOT NULL REFERENCES document_lines(id),

    quantity DECIMAL(15,4) NOT NULL,
    allocated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    -- For reversals
    reversed_at TIMESTAMPTZ,
    reversal_reason VARCHAR(255),

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Indexes for performance
CREATE INDEX idx_stock_batches_product_location
    ON stock_batches(product_id, location_id)
    WHERE status = 'active';

CREATE INDEX idx_stock_batches_expiry
    ON stock_batches(company_id, expiry_date)
    WHERE status = 'active' AND expiry_date IS NOT NULL;

CREATE INDEX idx_batch_allocations_batch
    ON batch_allocations(batch_id);

CREATE INDEX idx_batch_allocations_document_line
    ON batch_allocations(document_line_id);
```

### Products Table Extension

```sql
-- Add batch tracking flag to products
ALTER TABLE products ADD COLUMN requires_batch_tracking BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE products ADD COLUMN default_shelf_life_days INTEGER; -- For auto-calculating expiry
```

### Company Settings Extension

```sql
-- Add company-level batch settings
ALTER TABLE companies ADD COLUMN batch_settings JSONB DEFAULT '{
    "auto_generate_batch_numbers": true,
    "batch_number_prefix": "LOT",
    "expiry_alert_days": [30, 60, 90],
    "block_expired_sales": true,
    "require_expiry_date": false
}'::jsonb;
```

---

## API Endpoints

### Batch Management

```
GET    /api/v1/batches                      # List batches with filters
GET    /api/v1/batches/{id}                 # Get batch details
POST   /api/v1/batches                      # Create batch (manual)
PATCH  /api/v1/batches/{id}                 # Update batch (notes, status)
DELETE /api/v1/batches/{id}                 # Soft delete (set status)

GET    /api/v1/batches/expiring             # Get batches expiring soon
GET    /api/v1/batches/expired              # Get expired batches
POST   /api/v1/batches/{id}/quarantine      # Put batch in quarantine
POST   /api/v1/batches/{id}/recall          # Initiate recall
```

### Batch Query Parameters

```
GET /api/v1/batches?product_id=xxx&status=active&expiring_before=2024-03-01
GET /api/v1/batches?location_id=xxx&include_depleted=false
```

### Goods Receipt Integration

```
POST /api/v1/documents/{goods_receipt_id}/receive
{
  "lines": [
    {
      "document_line_id": "xxx",
      "received_quantity": 100,
      "batches": [
        {
          "batch_number": "LOT2024001",
          "quantity": 60,
          "expiry_date": "2025-06-30",
          "unit_cost": "12.50"
        },
        {
          "batch_number": "LOT2024002",
          "quantity": 40,
          "expiry_date": "2025-09-30",
          "unit_cost": "12.75"
        }
      ]
    }
  ]
}
```

### Allocation Endpoints

```
GET    /api/v1/allocations?document_id=xxx  # Get allocations for document
POST   /api/v1/allocations/preview          # Preview FEFO allocation
POST   /api/v1/allocations/manual           # Override allocation
DELETE /api/v1/allocations/{id}             # Reverse allocation
```

---

## Domain Services

### BatchService

```php
class BatchService
{
    /**
     * Create a new batch from goods receipt.
     */
    public function createBatch(
        string $productId,
        string $locationId,
        string $quantity,
        string $unitCost,
        ?string $batchNumber = null,
        ?\DateTimeInterface $expiryDate = null,
        ?string $goodsReceiptId = null,
        ?string $supplierId = null,
    ): StockBatch;

    /**
     * Allocate stock using FEFO algorithm.
     * Returns array of batch allocations that sum to requested quantity.
     */
    public function allocateFEFO(
        string $productId,
        string $locationId,
        string $quantity,
        ?string $excludeBatchId = null,
    ): array;

    /**
     * Reserve quantity from specific batches.
     */
    public function reserveBatches(
        array $batchAllocations,
        string $documentLineId,
    ): void;

    /**
     * Release reservation (order cancelled, line removed).
     */
    public function releaseReservation(string $documentLineId): void;

    /**
     * Confirm allocation (convert reservation to actual stock movement).
     */
    public function confirmAllocation(string $documentLineId): void;

    /**
     * Get batches expiring within N days.
     */
    public function getExpiringBatches(
        string $companyId,
        int $days = 30,
    ): Collection;

    /**
     * Get trace report: all customers who received a batch.
     */
    public function getTraceReport(string $batchId): array;
}
```

### FEFO Algorithm

```php
/**
 * FEFO (First Expired First Out) allocation algorithm.
 *
 * 1. Get all active batches for product/location
 * 2. Order by expiry_date ASC (nulls last = never expires)
 * 3. Allocate from earliest expiring first
 * 4. Skip batches in quarantine/recalled status
 * 5. Skip expired batches if company setting blocks expired sales
 */
public function allocateFEFO(
    string $productId,
    string $locationId,
    string $requestedQty,
): array {
    $batches = StockBatch::query()
        ->where('product_id', $productId)
        ->where('location_id', $locationId)
        ->where('status', 'active')
        ->whereRaw('current_quantity - reserved_quantity > 0')
        ->orderByRaw('COALESCE(expiry_date, DATE \'9999-12-31\') ASC')
        ->lockForUpdate()
        ->get();

    $allocations = [];
    $remaining = $requestedQty;

    foreach ($batches as $batch) {
        if (bccomp($remaining, '0', 4) <= 0) break;

        $available = bcsub($batch->current_quantity, $batch->reserved_quantity, 4);
        $toAllocate = bccomp($available, $remaining, 4) >= 0
            ? $remaining
            : $available;

        $allocations[] = [
            'batch_id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'expiry_date' => $batch->expiry_date,
            'quantity' => $toAllocate,
        ];

        $remaining = bcsub($remaining, $toAllocate, 4);
    }

    if (bccomp($remaining, '0', 4) > 0) {
        throw new InsufficientStockException(
            "Cannot allocate {$requestedQty}, only " .
            bcsub($requestedQty, $remaining, 4) . " available"
        );
    }

    return $allocations;
}
```

---

## Frontend Components

### BatchSelector Component

For goods receipt, allow splitting a line into multiple batches:

```tsx
interface BatchEntry {
  batchNumber: string;
  quantity: string;
  expiryDate: string | null;
  manufactureDate: string | null;
  supplierBatchNumber: string | null;
  unitCost: string;
}

function BatchSelector({
  productId,
  totalQuantity,
  onBatchesChange,
}: {
  productId: string;
  totalQuantity: string;
  onBatchesChange: (batches: BatchEntry[]) => void;
}) {
  // Render form to add/edit batch entries
  // Validate total matches line quantity
  // Auto-generate batch numbers if setting enabled
}
```

### ExpiringBatchesWidget

Dashboard widget showing batches expiring soon:

```tsx
function ExpiringBatchesWidget() {
  const { data: batches } = useExpiringBatches({ days: 30 });

  return (
    <Card>
      <CardHeader>
        <CardTitle>Expiring Soon</CardTitle>
      </CardHeader>
      <CardContent>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Product</TableHead>
              <TableHead>Batch</TableHead>
              <TableHead>Qty</TableHead>
              <TableHead>Expires</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {batches.map(batch => (
              <TableRow key={batch.id}>
                <TableCell>{batch.product.name}</TableCell>
                <TableCell>{batch.batch_number}</TableCell>
                <TableCell>{batch.current_quantity}</TableCell>
                <TableCell>
                  <Badge variant={getDaysUntilExpiry(batch.expiry_date) < 7 ? 'destructive' : 'warning'}>
                    {formatDate(batch.expiry_date)}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  );
}
```

### BatchTraceReport

For recalls and traceability:

```tsx
function BatchTraceReport({ batchId }: { batchId: string }) {
  const { data } = useBatchTrace(batchId);

  // Show:
  // - Batch details (product, received date, supplier)
  // - All sales documents that included this batch
  // - Customer names and contact info
  // - Quantities sold to each
  // - Export to CSV for recall notifications
}
```

---

## Integration Points

### Goods Receipt

When receiving goods for a batch-tracked product:
1. Present BatchSelector UI
2. Allow single or multiple batches per line
3. Validate total quantity matches PO line
4. Create `stock_batches` records
5. Update `stock_levels` as usual

### Sales Order / Invoice

When creating a sales document for batch-tracked products:
1. Auto-allocate using FEFO (on confirm)
2. Store allocations in `batch_allocations`
3. Reserve quantities on batches
4. Show batch info on delivery note (for warehouse picking)
5. On invoice post: confirm allocations, reduce batch quantities

### Inventory Adjustments

When adjusting stock:
1. If increasing: create new batch or add to existing
2. If decreasing: require batch selection (similar to FEFO but manual)
3. Record adjustment reason per batch

### Stock Transfer

When transferring between locations:
1. Select batches to transfer (or use FEFO)
2. Create new batch records at destination (same batch number)
3. Reduce source batch quantities

---

## Reporting

### Batch Inventory Report

```sql
SELECT
    p.sku,
    p.name as product_name,
    l.name as location_name,
    b.batch_number,
    b.expiry_date,
    b.current_quantity,
    b.reserved_quantity,
    (b.current_quantity - b.reserved_quantity) as available,
    b.unit_cost,
    (b.current_quantity * b.unit_cost) as value
FROM stock_batches b
JOIN products p ON b.product_id = p.id
JOIN locations l ON b.location_id = l.id
WHERE b.company_id = :company_id
AND b.status = 'active'
ORDER BY p.name, b.expiry_date;
```

### Expiry Analysis Report

```sql
SELECT
    CASE
        WHEN expiry_date < CURRENT_DATE THEN 'Expired'
        WHEN expiry_date < CURRENT_DATE + INTERVAL '30 days' THEN '0-30 days'
        WHEN expiry_date < CURRENT_DATE + INTERVAL '60 days' THEN '31-60 days'
        WHEN expiry_date < CURRENT_DATE + INTERVAL '90 days' THEN '61-90 days'
        ELSE '90+ days'
    END as expiry_bucket,
    COUNT(*) as batch_count,
    SUM(current_quantity * unit_cost) as total_value
FROM stock_batches
WHERE company_id = :company_id
AND status = 'active'
AND expiry_date IS NOT NULL
GROUP BY 1
ORDER BY 1;
```

---

## Configuration Options

### Company Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `auto_generate_batch_numbers` | boolean | true | Auto-generate if not provided |
| `batch_number_prefix` | string | "LOT" | Prefix for auto-generated numbers |
| `batch_number_format` | string | "{prefix}{YYYYMM}{seq}" | Format pattern |
| `expiry_alert_days` | array | [30, 60, 90] | Alert thresholds |
| `block_expired_sales` | boolean | true | Prevent selling expired stock |
| `require_expiry_date` | boolean | false | Force expiry on batch creation |
| `fefo_enabled` | boolean | true | Use FEFO for allocation |

### Product Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `requires_batch_tracking` | boolean | false | Enable batch tracking |
| `default_shelf_life_days` | integer | null | Auto-calculate expiry from manufacture date |

---

## Implementation Phases

### Phase 1: Core Infrastructure
- Database migrations
- StockBatch model and repository
- BatchService with FEFO algorithm
- Basic API endpoints

### Phase 2: Goods Receipt Integration
- Modify goods receipt workflow
- BatchSelector frontend component
- Batch creation on receipt

### Phase 3: Sales Integration
- FEFO allocation on SO confirm
- Reservation system
- Allocation confirmation on invoice post
- Batch info on delivery notes

### Phase 4: Reporting & Dashboard
- Expiring batches widget
- Batch inventory report
- Expiry analysis report
- Trace report for recalls

---

## Testing Scenarios

1. **Basic FEFO**: Product with 3 batches expiring in different months, verify oldest allocated first
2. **Split Allocation**: Request qty that spans multiple batches
3. **Expired Blocking**: Attempt to sell from expired batch when blocking enabled
4. **Reservation + Release**: Create SO, then cancel, verify quantities released
5. **Transfer Between Locations**: Move batch, verify new record at destination
6. **Trace Report**: Sell batch to multiple customers, verify trace shows all
7. **Concurrent Allocation**: Two users allocating from same batch simultaneously

---

*Created: 2025-12-14*
*Last Updated: 2025-12-14*
