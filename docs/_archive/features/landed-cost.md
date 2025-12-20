# Landed Cost & Margin Feature

> Cost tracking and margin calculation for purchasing and sales.

---

## Overview

This feature provides:
- **Landed Cost**: Add freight, customs, handling to purchase cost
- **Margin Calculation**: Real-time margin based on landed cost
- **Margin Warnings**: Alert when margin below threshold

---

## Landed Cost

### Cost Types

| Type | Description |
|------|-------------|
| `freight` | Shipping/delivery cost |
| `customs` | Import duties and taxes |
| `handling` | Warehouse handling fees |
| `insurance` | Transit insurance |
| `other` | Miscellaneous costs |

### Distribution Methods

| Method | Description |
|--------|-------------|
| `by_value` | Proportional to line value |
| `by_weight` | Proportional to product weight |
| `by_quantity` | Equal per unit |
| `by_volume` | Proportional to volume |

### Calculation Example

```
Purchase Order Lines:
- Product A: 10 units × 100 = 1000 (50% of value)
- Product B: 20 units × 50  = 1000 (50% of value)

Additional Cost: 200 freight (by_value)

Distribution:
- Product A: +100 (200 × 50%)
- Product B: +100 (200 × 50%)

Landed Unit Cost:
- Product A: (1000 + 100) / 10 = 110
- Product B: (1000 + 100) / 20 = 55
```

---

## Margin Calculation

### Formula

```
Margin % = (Selling Price - Landed Cost) / Selling Price × 100
```

### Margin Thresholds

Companies can set:
- **Target Margin**: Ideal margin percentage
- **Minimum Margin**: Warning threshold
- **Allow Below Cost**: Whether below-cost sales are permitted

### Margin Indicator

```
Green:  margin >= target_margin
Yellow: minimum_margin <= margin < target_margin
Red:    margin < minimum_margin
```

---

## Services

### LandedCostService

```php
// Calculate breakdown
$breakdown = $service->calculateBreakdown($purchaseOrder, $costs);

// Distribute costs to lines
$service->distribute($purchaseOrder, $costs);

// Get product landed cost
$landedCost = $service->getProductLandedCost($productId, $companyId);
```

### MarginService

```php
// Calculate margin for price
$margin = $service->calculateMargin($sellingPrice, $productId, $companyId);

// Check if margin is acceptable
$acceptable = $service->isMarginAcceptable($margin, $company);

// Get margin indicator
$indicator = $service->getMarginIndicator($margin, $company);
// Returns: 'healthy' | 'warning' | 'critical'
```

---

## API Endpoints

```
# Landed Cost
GET  /api/products/{id}/landed-cost
POST /api/purchase-orders/{id}/additional-costs
GET  /api/purchase-orders/{id}/landed-cost-breakdown

# Margin
GET  /api/pricing/margin?product_id=X&price=Y
GET  /api/products/{id}/margin-info
```

---

## Frontend Components

### PriceInputWithMargin

Input field that shows real-time margin indicator:

```tsx
<PriceInputWithMargin
  productId={product.id}
  value={price}
  onChange={setPrice}
/>
```

### LandedCostBreakdown

Visual breakdown of landed cost components:

```tsx
<LandedCostBreakdown purchaseOrderId={order.id} />
```
