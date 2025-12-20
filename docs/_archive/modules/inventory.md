# Inventory Module

> Stock management, movements, and costing.

---

## Purpose

The Inventory module manages physical stock across locations with:
- Real-time stock levels
- Movement tracking
- Weighted average costing
- Landed cost calculation
- Physical inventory counting

---

## Stock Levels

```php
StockLevel {
    id: UUID
    product_id: UUID
    location_id: UUID
    quantity: decimal
    reserved: decimal      // Reserved for orders
    available: decimal     // quantity - reserved
    unit_cost: decimal     // Weighted average cost
}
```

---

## Stock Movements

Every stock change creates a movement record:

| Type | Effect | Trigger |
|------|--------|---------|
| `purchase` | + quantity | Purchase invoice posted |
| `sale` | - quantity | Sales invoice posted |
| `transfer_in` | + quantity | Internal transfer |
| `transfer_out` | - quantity | Internal transfer |
| `adjustment` | +/- | Manual adjustment |
| `return` | + quantity | Customer return |
| `scrap` | - quantity | Write-off |

---

## Costing Methods

### Weighted Average Cost (WAC)

Default method. Cost recalculated on each purchase:

```
New WAC = (Existing Value + Purchase Value) / (Existing Qty + Purchase Qty)
```

### Landed Cost

Additional costs (freight, customs, handling) added to purchase cost:

```php
LandedCostService::distribute($purchaseOrder, [
    ['type' => 'freight', 'amount' => 500, 'method' => 'by_value'],
    ['type' => 'customs', 'amount' => 200, 'method' => 'by_weight'],
]);
```

Distribution methods:
- `by_value` - Proportional to line value
- `by_weight` - Proportional to weight
- `by_quantity` - Equal per unit
- `by_volume` - Proportional to volume

---

## Services

### WeightedAverageCostService

```php
// Update cost after purchase
$service->updateCost($product, $location, $quantity, $unitCost);
```

### LandedCostService

```php
// Calculate landed cost breakdown
$breakdown = $service->calculateBreakdown($purchaseOrder, $costs);

// Distribute costs to lines
$service->distribute($purchaseOrder, $costs);
```

---

## API Endpoints

```
GET    /api/stock-levels                # Current stock levels
GET    /api/stock-levels/{product}      # Stock by product
GET    /api/stock-movements             # Movement history
POST   /api/stock-adjustments           # Manual adjustment
GET    /api/products/{id}/landed-cost   # Landed cost breakdown
```

---

## Related Features

- [Landed Cost & Margin](../features/landed-cost.md)
- [Inventory Counting](../features/inventory-counting.md)
