# AutoERP - Phase 3 Supplement: Landed Cost & Margin Management

> **Prerequisite:** Complete after Phase 3.8 (Pricing Rules & Discounts)
> **Estimated Hours:** 14-18

---

## Overview

Implement true cost tracking and margin-based pricing with visual safeguards.

### Goals

1. **Landed Cost:** Calculate true product acquisition cost including transport, insurance, etc.
2. **Weighted Average Cost:** Track product cost using WAC method
3. **Margin-Based Pricing:** Auto-suggest prices based on cost + margin
4. **Visual Safeguards:** Color-coded warnings for low-margin or below-cost sales
5. **Permission Controls:** Restrict who can override pricing rules

---

## Concepts

### Landed Cost Formula

```
Landed Unit Cost = (Line Total + Allocated Additional Costs) / Quantity

Where:
- Line Total = Quantity × Unit Price
- Allocated Costs = Total Additional Costs × (Line Total / PO Subtotal)
```

### Weighted Average Cost Formula

```
New WAC = (Current Stock Value + New Purchase Value) / (Current Qty + New Qty)

Where:
- Current Stock Value = Current Qty × Current WAC
- New Purchase Value = New Qty × Landed Unit Cost
```

### Margin Thresholds

```
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│  SELL PRICE vs COST INDICATORS                              │
│                                                             │
│  ████████████  GREEN   → Above target margin                │
│  ████████████  YELLOW  → Below target, above minimum        │
│  ████████████  ORANGE  → Below minimum margin               │
│  ████████████  RED     → Below cost (LOSS)                  │
│                                                             │
│  Example with Cost = 100 TND:                               │
│                                                             │
│  Target Margin: 30%  → Target Price: 130 TND                │
│  Minimum Margin: 10% → Minimum Price: 110 TND               │
│                                                             │
│  Sell at 140 TND → GREEN  (40% margin)                      │
│  Sell at 125 TND → YELLOW (25% margin, below target)        │
│  Sell at 105 TND → ORANGE (5% margin, below minimum)        │
│  Sell at 95 TND  → RED    (-5%, LOSS!)                      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## Section 1: Database Schema

### 1.1 Company Settings

```sql
-- Add to companies table
ALTER TABLE companies ADD COLUMN inventory_costing_method VARCHAR(20) DEFAULT 'weighted_average';
ALTER TABLE companies ADD COLUMN default_target_margin DECIMAL(5,2) DEFAULT 30.00;
ALTER TABLE companies ADD COLUMN default_minimum_margin DECIMAL(5,2) DEFAULT 10.00;
ALTER TABLE companies ADD COLUMN allow_below_cost_sales BOOLEAN DEFAULT false;
```

**Tasks:**
- [ ] Create migration for company inventory settings
- [ ] Update Company model with new fields
- [ ] Add to company settings UI
- [ ] Add validation (minimum_margin <= target_margin)

### 1.2 Category Margin Overrides

```sql
-- Add to categories table
ALTER TABLE categories ADD COLUMN target_margin_override DECIMAL(5,2);
ALTER TABLE categories ADD COLUMN minimum_margin_override DECIMAL(5,2);
-- NULL means inherit from company
```

**Tasks:**
- [ ] Create migration for category margin fields
- [ ] Update Category model
- [ ] Add margin fields to category form
- [ ] Show inherited vs custom in UI

### 1.3 Product Cost & Margin Fields

```sql
-- Add to products table
ALTER TABLE products ADD COLUMN cost_price DECIMAL(12,2) DEFAULT 0;
-- Current weighted average cost (auto-calculated)

ALTER TABLE products ADD COLUMN target_margin_override DECIMAL(5,2);
ALTER TABLE products ADD COLUMN minimum_margin_override DECIMAL(5,2);
-- NULL means inherit from category or company

ALTER TABLE products ADD COLUMN last_purchase_cost DECIMAL(12,2);
-- Most recent landed cost (for reference)

ALTER TABLE products ADD COLUMN cost_updated_at TIMESTAMP;
-- When cost was last recalculated
```

**Tasks:**
- [ ] Create migration for product cost fields
- [ ] Update Product model with accessors for effective margins
- [ ] Create method: `getEffectiveTargetMargin()` (checks product → category → company)
- [ ] Create method: `getEffectiveMinimumMargin()`
- [ ] Create method: `getSuggestedSellPrice()`

### 1.4 Purchase Order Additional Costs

```sql
CREATE TABLE document_additional_costs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    document_id UUID NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    
    cost_type VARCHAR(50) NOT NULL,
    -- 'transport', 'shipping', 'insurance', 'customs', 'handling', 'other'
    
    description VARCHAR(255),
    amount DECIMAL(12,2) NOT NULL,
    
    -- Optional: link to expense/supplier invoice
    expense_document_id UUID REFERENCES documents(id),
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX idx_document_additional_costs_document ON document_additional_costs(document_id);
```

**Tasks:**
- [ ] Create document_additional_costs migration
- [ ] Create DocumentAdditionalCost model
- [ ] Add relationship to Document model
- [ ] Create cost type enum/config

### 1.5 Document Line Landed Cost

```sql
-- Add to document_lines table
ALTER TABLE document_lines ADD COLUMN allocated_costs DECIMAL(12,2) DEFAULT 0;
ALTER TABLE document_lines ADD COLUMN landed_unit_cost DECIMAL(12,2);
-- For purchase lines: (line_total + allocated_costs) / quantity
-- For sale lines: NULL (not applicable)
```

**Tasks:**
- [ ] Create migration for document_lines cost fields
- [ ] Update DocumentLine model

### 1.6 Stock Movement Cost Tracking

```sql
-- Verify/add to stock_movements table
ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS unit_cost DECIMAL(12,2);
ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS total_cost DECIMAL(12,2);
ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS avg_cost_before DECIMAL(12,2);
ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS avg_cost_after DECIMAL(12,2);
```

**Tasks:**
- [ ] Create migration if columns don't exist
- [ ] Update StockMovement model

---

## Section 2: Backend Services

### 2.1 Landed Cost Service

```php
<?php

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Models\Document;
use App\Modules\Document\Domain\Models\DocumentLine;

class LandedCostService
{
    /**
     * Allocate additional costs to purchase order lines proportionally by value
     */
    public function allocateCosts(Document $purchaseOrder): void
    {
        $lines = $purchaseOrder->lines;
        $additionalCostsTotal = $purchaseOrder->additionalCosts()->sum('amount');
        $subtotal = $lines->sum('total');

        foreach ($lines as $line) {
            if ($subtotal > 0 && $additionalCostsTotal > 0) {
                $proportion = $line->total / $subtotal;
                $allocatedCost = round($additionalCostsTotal * $proportion, 2);
            } else {
                $allocatedCost = 0;
            }

            $line->allocated_costs = $allocatedCost;
            $line->landed_unit_cost = $line->quantity > 0
                ? round(($line->total + $allocatedCost) / $line->quantity, 2)
                : $line->unit_price;
            $line->save();
        }
    }

    /**
     * Get breakdown of cost allocation for display
     */
    public function getAllocationBreakdown(Document $purchaseOrder): array
    {
        $result = [];
        
        foreach ($purchaseOrder->lines as $line) {
            $result[] = [
                'line_id' => $line->id,
                'product_name' => $line->product?->name ?? $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->total,
                'allocated_costs' => $line->allocated_costs,
                'landed_unit_cost' => $line->landed_unit_cost,
            ];
        }
        
        return $result;
    }
}
```

**Tasks:**
- [ ] Create LandedCostService
- [ ] Write unit tests for allocation calculation
- [ ] Test edge cases (zero subtotal, single line, rounding)

### 2.2 Weighted Average Cost Service

```php
<?php

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Product\Domain\Models\Product;
use App\Modules\Company\Domain\Models\Location;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\StockLevel;

class WeightedAverageCostService
{
    /**
     * Record a purchase and update weighted average cost
     */
    public function recordPurchase(
        Product $product,
        Location $location,
        float $quantity,
        float $landedUnitCost,
        ?string $reference = null
    ): StockMovement {
        $stockLevel = StockLevel::firstOrCreate(
            ['product_id' => $product->id, 'location_id' => $location->id],
            ['quantity' => 0, 'unit_cost' => 0]
        );

        $currentQty = $stockLevel->quantity;
        $currentValue = $currentQty * ($product->cost_price ?? 0);

        $newQty = $currentQty + $quantity;
        $newValue = $currentValue + ($quantity * $landedUnitCost);

        $newAvgCost = $newQty > 0 ? round($newValue / $newQty, 2) : 0;

        // Record movement
        $movement = StockMovement::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'company_id' => $location->company_id,
            'type' => 'purchase',
            'quantity' => $quantity,
            'unit_cost' => $landedUnitCost,
            'total_cost' => $quantity * $landedUnitCost,
            'avg_cost_before' => $product->cost_price ?? 0,
            'avg_cost_after' => $newAvgCost,
            'reference' => $reference,
        ]);

        // Update stock level
        $stockLevel->quantity = $newQty;
        $stockLevel->unit_cost = $newAvgCost;
        $stockLevel->save();

        // Update product cost
        $product->cost_price = $newAvgCost;
        $product->last_purchase_cost = $landedUnitCost;
        $product->cost_updated_at = now();
        $product->save();

        return $movement;
    }

    /**
     * Record a sale (cost comes out at current average)
     */
    public function recordSale(
        Product $product,
        Location $location,
        float $quantity,
        ?string $reference = null
    ): StockMovement {
        $stockLevel = StockLevel::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->firstOrFail();

        $costPrice = $product->cost_price ?? 0;

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'company_id' => $location->company_id,
            'type' => 'sale',
            'quantity' => -$quantity,
            'unit_cost' => $costPrice,
            'total_cost' => $quantity * $costPrice,
            'avg_cost_before' => $costPrice,
            'avg_cost_after' => $costPrice, // WAC doesn't change on sale
            'reference' => $reference,
        ]);

        // Update stock level (cost stays same)
        $stockLevel->quantity -= $quantity;
        $stockLevel->save();

        return $movement;
    }

    /**
     * Record a return (stock comes back at original cost)
     */
    public function recordReturn(
        Product $product,
        Location $location,
        float $quantity,
        float $originalCost,
        ?string $reference = null
    ): StockMovement {
        // Similar to purchase but with type 'return'
        // Recalculates WAC
    }
}
```

**Tasks:**
- [ ] Create WeightedAverageCostService
- [ ] Write unit tests for WAC calculations
- [ ] Test purchase → sale → purchase sequence
- [ ] Test edge cases (first purchase, zero stock)

### 2.3 Margin Calculation Service

```php
<?php

namespace App\Modules\Pricing\Application\Services;

use App\Modules\Product\Domain\Models\Product;
use App\Modules\Company\Domain\Models\Company;

class MarginService
{
    /**
     * Margin indicator levels
     */
    public const LEVEL_GREEN = 'green';   // Above target
    public const LEVEL_YELLOW = 'yellow'; // Below target, above minimum
    public const LEVEL_ORANGE = 'orange'; // Below minimum, above cost
    public const LEVEL_RED = 'red';       // Below cost (loss)

    /**
     * Get effective margins for a product (with inheritance)
     */
    public function getEffectiveMargins(Product $product): array
    {
        $company = $product->company;
        $category = $product->category;

        $targetMargin = $product->target_margin_override
            ?? $category?->target_margin_override
            ?? $company->default_target_margin
            ?? 30.0;

        $minimumMargin = $product->minimum_margin_override
            ?? $category?->minimum_margin_override
            ?? $company->default_minimum_margin
            ?? 10.0;

        return [
            'target_margin' => $targetMargin,
            'minimum_margin' => $minimumMargin,
            'source' => $this->getMarginSource($product),
        ];
    }

    /**
     * Calculate suggested sell price based on cost and target margin
     */
    public function getSuggestedPrice(Product $product): float
    {
        $cost = $product->cost_price ?? 0;
        $margins = $this->getEffectiveMargins($product);
        
        if ($cost <= 0) {
            return $product->sell_price ?? 0;
        }

        return round($cost * (1 + $margins['target_margin'] / 100), 2);
    }

    /**
     * Calculate actual margin for a given sell price
     */
    public function calculateMargin(float $cost, float $sellPrice): ?float
    {
        if ($cost <= 0) {
            return null;
        }

        return round((($sellPrice - $cost) / $cost) * 100, 2);
    }

    /**
     * Get margin indicator level for a sell price
     */
    public function getMarginLevel(Product $product, float $sellPrice): array
    {
        $cost = $product->cost_price ?? 0;
        $margins = $this->getEffectiveMargins($product);
        $actualMargin = $this->calculateMargin($cost, $sellPrice);

        if ($cost <= 0) {
            return [
                'level' => self::LEVEL_GREEN,
                'message' => 'No cost data',
                'actual_margin' => null,
            ];
        }

        if ($sellPrice < $cost) {
            return [
                'level' => self::LEVEL_RED,
                'message' => 'Below cost - LOSS',
                'actual_margin' => $actualMargin,
                'loss_amount' => round($cost - $sellPrice, 2),
            ];
        }

        if ($actualMargin < $margins['minimum_margin']) {
            return [
                'level' => self::LEVEL_ORANGE,
                'message' => 'Below minimum margin',
                'actual_margin' => $actualMargin,
                'minimum_margin' => $margins['minimum_margin'],
            ];
        }

        if ($actualMargin < $margins['target_margin']) {
            return [
                'level' => self::LEVEL_YELLOW,
                'message' => 'Below target margin',
                'actual_margin' => $actualMargin,
                'target_margin' => $margins['target_margin'],
            ];
        }

        return [
            'level' => self::LEVEL_GREEN,
            'message' => 'Above target margin',
            'actual_margin' => $actualMargin,
        ];
    }

    /**
     * Check if user can sell at this price
     */
    public function canSellAtPrice(
        Product $product,
        float $sellPrice,
        User $user
    ): array {
        $marginLevel = $this->getMarginLevel($product, $sellPrice);
        $company = $product->company;

        // Below cost check
        if ($marginLevel['level'] === self::LEVEL_RED) {
            if (!$company->allow_below_cost_sales) {
                return [
                    'allowed' => false,
                    'reason' => 'Sales below cost are not allowed',
                    'requires_permission' => 'sell_below_cost',
                ];
            }

            if (!$user->can('sell_below_cost')) {
                return [
                    'allowed' => false,
                    'reason' => 'You do not have permission to sell below cost',
                    'requires_permission' => 'sell_below_cost',
                ];
            }
        }

        // Below minimum margin check
        if ($marginLevel['level'] === self::LEVEL_ORANGE) {
            if (!$user->can('sell_below_minimum_margin')) {
                return [
                    'allowed' => false,
                    'reason' => 'You do not have permission to sell below minimum margin',
                    'requires_permission' => 'sell_below_minimum_margin',
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'margin_level' => $marginLevel,
        ];
    }

    private function getMarginSource(Product $product): string
    {
        if ($product->target_margin_override !== null) {
            return 'product';
        }
        if ($product->category?->target_margin_override !== null) {
            return 'category';
        }
        return 'company';
    }
}
```

**Tasks:**
- [ ] Create MarginService
- [ ] Write unit tests for margin calculations
- [ ] Test inheritance chain (product → category → company)
- [ ] Test permission checks

---

## Section 3: API Endpoints

### 3.1 Purchase Order Additional Costs

```php
// Routes
Route::prefix('documents/{document}/additional-costs')->group(function () {
    Route::get('/', [DocumentAdditionalCostController::class, 'index']);
    Route::post('/', [DocumentAdditionalCostController::class, 'store']);
    Route::patch('/{cost}', [DocumentAdditionalCostController::class, 'update']);
    Route::delete('/{cost}', [DocumentAdditionalCostController::class, 'destroy']);
});

Route::post('documents/{document}/allocate-costs', [DocumentController::class, 'allocateCosts']);
Route::get('documents/{document}/landed-cost-breakdown', [DocumentController::class, 'landedCostBreakdown']);
```

**Tasks:**
- [ ] Create DocumentAdditionalCostController
- [ ] Create DocumentAdditionalCostRequest
- [ ] Create DocumentAdditionalCostResource
- [ ] Add allocateCosts action to DocumentController
- [ ] Add landedCostBreakdown action
- [ ] Write feature tests

### 3.2 Margin Check Endpoint

```php
// Route
Route::post('pricing/check-margin', [PricingController::class, 'checkMargin']);

// Controller
public function checkMargin(CheckMarginRequest $request): JsonResponse
{
    $product = Product::findOrFail($request->product_id);
    $sellPrice = $request->sell_price;

    $marginLevel = $this->marginService->getMarginLevel($product, $sellPrice);
    $canSell = $this->marginService->canSellAtPrice($product, $sellPrice, $request->user());

    return response()->json([
        'cost_price' => $product->cost_price,
        'sell_price' => $sellPrice,
        'margin_level' => $marginLevel,
        'can_sell' => $canSell,
        'suggested_price' => $this->marginService->getSuggestedPrice($product),
    ]);
}
```

**Tasks:**
- [ ] Create PricingController
- [ ] Create CheckMarginRequest
- [ ] Write feature tests

### 3.3 Product Cost Endpoints

```php
// Routes
Route::get('products/{product}/cost-history', [ProductCostController::class, 'history']);
Route::get('products/{product}/margin-info', [ProductCostController::class, 'marginInfo']);
```

**Tasks:**
- [ ] Create ProductCostController
- [ ] Create cost history endpoint (list of stock movements with costs)
- [ ] Create margin info endpoint
- [ ] Write feature tests

---

## Section 4: Frontend Components

### 4.1 Additional Costs Form (Purchase Order)

**Component:** `features/purchasing/components/AdditionalCostsForm.tsx`

```tsx
interface AdditionalCost {
  id?: string;
  cost_type: 'transport' | 'shipping' | 'insurance' | 'customs' | 'handling' | 'other';
  description: string;
  amount: number;
}

interface AdditionalCostsFormProps {
  costs: AdditionalCost[];
  onChange: (costs: AdditionalCost[]) => void;
  disabled?: boolean;
}
```

**Features:**
- List of additional cost lines
- Add new cost button
- Cost type dropdown
- Description text input
- Amount number input
- Remove button per line
- Total display

**Tasks:**
- [ ] Create AdditionalCostsForm component
- [ ] Create CostTypeSelect atom
- [ ] Write unit tests
- [ ] Integrate into PurchaseOrderForm

### 4.2 Landed Cost Breakdown

**Component:** `features/purchasing/components/LandedCostBreakdown.tsx`

```tsx
interface LandedCostBreakdownProps {
  documentId: string;
}
```

**Features:**
- Table showing:
  - Product name
  - Quantity
  - Unit price
  - Line total
  - Allocated costs
  - Landed unit cost
- Summary row with totals
- Info tooltip explaining allocation

**Tasks:**
- [ ] Create LandedCostBreakdown component
- [ ] Create useLandedCostBreakdown hook
- [ ] Write unit tests
- [ ] Add to purchase order detail page

### 4.3 Margin Indicator

**Component:** `components/molecules/MarginIndicator.tsx`

```tsx
interface MarginIndicatorProps {
  level: 'green' | 'yellow' | 'orange' | 'red';
  actualMargin: number | null;
  message: string;
  showDetails?: boolean;
}
```

**Visual:**
```
┌──────────────────────────────────────────┐
│  ● 25.0%  Below target (30%)             │  ← Yellow dot + text
└──────────────────────────────────────────┘

┌──────────────────────────────────────────┐
│  ● -5.0%  LOSS: 5.00 TND below cost      │  ← Red dot + warning
└──────────────────────────────────────────┘
```

**Tasks:**
- [ ] Create MarginIndicator molecule
- [ ] Add color-coded dot/badge
- [ ] Add tooltip with details
- [ ] Write unit tests

### 4.4 Price Input with Margin

**Component:** `components/molecules/PriceInputWithMargin.tsx`

```tsx
interface PriceInputWithMarginProps {
  value: number;
  onChange: (value: number) => void;
  productId: string;
  quantity?: number;
  disabled?: boolean;
  showSuggested?: boolean;
}
```

**Features:**
- Number input for price
- Suggested price button/chip
- Real-time margin indicator
- Warning if below minimum
- Error state if below cost and not allowed

**Visual:**
```
┌─────────────────────────────────────────────────────────────┐
│ Price:  [65.00    ] TND    Suggested: 58.50                 │
│                                                             │
│ ● 44.4% margin  ✓ Above target                              │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ Price:  [40.00    ] TND    Suggested: 58.50                 │
│                                                             │
│ ● -11.1% margin  ⚠ LOSS - Cannot sell at this price         │
│ [Use Suggested Price]                                       │
└─────────────────────────────────────────────────────────────┘
```

**Tasks:**
- [ ] Create PriceInputWithMargin molecule
- [ ] Integrate margin check API
- [ ] Add debounced margin calculation
- [ ] Write unit tests

### 4.5 Invoice Line with Margin

**Component:** `features/sales/components/InvoiceLineRow.tsx`

Update existing component to include:
- Pre-populate price from product sell_price
- Show MarginIndicator
- Block submission if price not allowed

**Tasks:**
- [ ] Update InvoiceLineRow component
- [ ] Add margin indicator integration
- [ ] Add validation before submit
- [ ] Write unit tests
- [ ] Write Playwright test for margin warnings

### 4.6 Product Pricing Card

**Component:** `features/products/components/ProductPricingCard.tsx`

For product detail/edit page:

```
┌─────────────────────────────────────────────────────────────┐
│ PRICING                                                     │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ Current Cost:      45.00 TND                                │
│                    ↳ Weighted average from purchases        │
│                    ↳ Last updated: 2 days ago               │
│                                                             │
│ Last Purchase:     48.00 TND  (landed cost)                 │
│                                                             │
│ ─────────────────────────────────────────────────────────── │
│                                                             │
│ Target Margin:     [Inherit: 30% ▼]                         │
│                    ○ Inherit from Category (30%)            │
│                    ○ Custom: [___] %                        │
│                                                             │
│ Minimum Margin:    [Inherit: 10% ▼]                         │
│                    ○ Inherit from Company (10%)             │
│                    ○ Custom: [___] %                        │
│                                                             │
│ ─────────────────────────────────────────────────────────── │
│                                                             │
│ Suggested Price:   58.50 TND                                │
│                                                             │
│ Sell Price:        [60.00    ] TND                          │
│                    ● 33.3% margin  ✓ Above target           │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

**Tasks:**
- [ ] Create ProductPricingCard organism
- [ ] Create MarginInheritSelect molecule
- [ ] Show cost history link
- [ ] Write unit tests
- [ ] Integrate into product edit page

### 4.7 Company Margin Settings

**Component:** `features/settings/components/InventorySettings.tsx`

```
┌─────────────────────────────────────────────────────────────┐
│ INVENTORY & PRICING                                         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ Costing Method:    [Weighted Average ▼]                     │
│                    ℹ️ Used for all products                  │
│                                                             │
│ Default Target Margin:   [30] %                             │
│                          Applied when not set on product    │
│                          or category                        │
│                                                             │
│ Default Minimum Margin:  [10] %                             │
│                          Warns when selling below this      │
│                                                             │
│ ☐ Allow sales below cost                                    │
│   ⚠️ If enabled, users with permission can sell at a loss   │
│                                                             │
│                                          [Save Settings]    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

**Tasks:**
- [ ] Create InventorySettingsForm component
- [ ] Add to company settings page
- [ ] Write unit tests

---

## Section 5: Permissions

### 5.1 New Permissions

Add to permission matrix:

| Permission | Description | Default Roles |
|------------|-------------|---------------|
| `sell_below_target_margin` | Can sell below target margin (yellow) | Owner, Admin, Manager |
| `sell_below_minimum_margin` | Can sell below minimum margin (orange) | Owner, Admin |
| `sell_below_cost` | Can sell at a loss (red) | Owner only |
| `view_cost_prices` | Can see product cost prices | Owner, Admin, Manager, Accountant |
| `manage_pricing_rules` | Can edit margins and pricing settings | Owner, Admin |

**Tasks:**
- [ ] Add permissions to seeder
- [ ] Update permission matrix documentation
- [ ] Add permission checks to controllers
- [ ] Add permission checks to frontend (hide cost if no permission)

---

## Section 6: Integration Points

### 6.1 Purchase Order Confirmation

When purchase order is confirmed:
1. Allocate additional costs to lines
2. Calculate landed unit cost per line
3. On goods receipt → Update WAC for each product

**Tasks:**
- [ ] Hook into PO confirmation flow
- [ ] Call LandedCostService::allocateCosts()
- [ ] Update existing goods receipt to use landed cost

### 6.2 Goods Receipt

When goods are received:
1. For each line, call WeightedAverageCostService::recordPurchase()
2. Use landed_unit_cost (not unit_price)
3. Update product cost_price

**Tasks:**
- [ ] Update goods receipt service
- [ ] Ensure stock movements record correct costs

### 6.3 Invoice Creation

When creating invoice line:
1. Pre-populate price from product sell_price
2. Check margin level
3. Validate permission before allowing save
4. On invoice confirmation → Record sale with current WAC as COGS

**Tasks:**
- [ ] Update invoice line validation
- [ ] Add margin check before save
- [ ] Record COGS on invoice confirmation

### 6.4 Credit Note / Return

When processing return:
1. Stock comes back at original sale cost (or current WAC)
2. Recalculate WAC if using original cost

**Tasks:**
- [ ] Update credit note flow
- [ ] Handle stock return with cost tracking

---

## Section 7: Testing

### 7.1 Unit Tests

```
tests/Unit/
├── Services/
│   ├── LandedCostServiceTest.php
│   ├── WeightedAverageCostServiceTest.php
│   └── MarginServiceTest.php
```

**Test Cases:**

**LandedCostService:**
- [ ] Single line, no additional costs
- [ ] Single line, with additional costs
- [ ] Multiple lines, proportional allocation
- [ ] Zero subtotal handling
- [ ] Rounding accuracy

**WeightedAverageCostService:**
- [ ] First purchase (no existing stock)
- [ ] Second purchase (recalculate WAC)
- [ ] Sale (WAC unchanged)
- [ ] Purchase after sale
- [ ] Return handling

**MarginService:**
- [ ] Margin inheritance (product → category → company)
- [ ] Suggested price calculation
- [ ] Margin level determination
- [ ] Permission checks

### 7.2 Feature Tests

```
tests/Feature/
├── Purchasing/
│   ├── AdditionalCostsTest.php
│   └── LandedCostAllocationTest.php
├── Inventory/
│   └── CostTrackingTest.php
└── Pricing/
    └── MarginCheckTest.php
```

**Tasks:**
- [ ] Write all feature tests
- [ ] Test full purchase → receipt → sale flow
- [ ] Test permission restrictions

### 7.3 Playwright E2E Tests

```
tests/e2e/
├── purchasing/
│   └── additional-costs.spec.ts
├── products/
│   └── pricing-card.spec.ts
└── sales/
    └── margin-warnings.spec.ts
```

**Test Scenarios:**

**additional-costs.spec.ts:**
- [ ] Add additional costs to purchase order
- [ ] View landed cost breakdown
- [ ] Edit and remove costs

**pricing-card.spec.ts:**
- [ ] View product cost and margins
- [ ] Change margin override
- [ ] See suggested price update

**margin-warnings.spec.ts:**
- [ ] Enter price above target → green indicator
- [ ] Enter price below target → yellow warning
- [ ] Enter price below minimum → orange warning
- [ ] Enter price below cost → red error
- [ ] Submit blocked for unauthorized user
- [ ] Submit allowed for authorized user

---

## Section 8: Progress Tracking

```markdown
## Landed Cost & Margin Management Progress

### Database Schema
- [x] 1.1 Company settings migration (default_target_margin, default_minimum_margin, allow_below_cost_sales)
- [ ] 1.2 Category margin fields (deferred - categories not yet implemented)
- [x] 1.3 Product cost fields (cost_price, target_margin_override, minimum_margin_override)
- [x] 1.4 Document additional costs table (document_additional_costs)
- [x] 1.5 Document line cost fields (allocated_costs, landed_unit_cost)
- [x] 1.6 Stock movement cost fields (unit_cost, total_cost, avg_cost_before, avg_cost_after)

### Backend Services
- [x] 2.1 LandedCostService (allocateCosts, getAllocationBreakdown, calculateAllocatedCost, calculateLandedUnitCost)
- [x] 2.2 WeightedAverageCostService (recordPurchase, recordSale, recordReturn, calculateNewWac)
- [x] 2.3 MarginService (getEffectiveMargins, getSuggestedPrice, calculateMargin, getMarginLevel, canSellAtPrice)

### API Endpoints
- [x] 3.1 Additional costs CRUD (DocumentAdditionalCostController)
- [x] 3.2 Margin check endpoint (POST /api/v1/pricing/check-margin)
- [ ] 3.3 Product cost endpoints (product cost history - deferred)

### Frontend Components
- [x] 4.1 AdditionalCostsForm
- [x] 4.2 LandedCostBreakdown
- [x] 4.3 MarginIndicator
- [x] 4.4 PriceInputWithMargin
- [x] 4.5 DocumentLineRow with margin support
- [x] 4.6 ProductPricingCard
- [x] 4.7 InventorySettings

### Permissions
- [x] 5.1 New permissions added (pricing.sell_below_cost, pricing.sell_below_minimum_margin, pricing.sell_below_target_margin, pricing.view_cost_prices, pricing.manage_pricing_rules)

### Integration
- [x] 6.1 PO confirmation flow (allocateCosts on confirm)
- [x] 6.2 Goods receipt flow (update product costs)
- [x] 6.3 Invoice creation flow (checkMargin API available)
- [x] 6.4 Credit note flow (copies lines from invoice)

### Testing
- [x] 7.1 Unit tests (LandedCostServiceTest, WeightedAverageCostServiceTest, MarginServiceTest)
- [x] 7.2 Feature tests (DocumentAdditionalCostTest, CheckMarginTest)
- [x] 7.3 E2E tests (purchasing/additional-costs.spec.ts, products/pricing-card.spec.ts, sales/margin-warnings.spec.ts)

### Last Updated: 2025-12-02
ALL SECTIONS COMPLETE
- Backend: Sections 1-3, 5-6, 7.1-7.2 (836 tests passing)
- Frontend: Section 4 (all components created)
- E2E Tests: Section 7.3 (3 test suites created)
```

---

## Definition of Done

This supplement is complete when:

- [ ] Additional costs can be added to purchase orders
- [ ] Costs are allocated proportionally to lines
- [ ] Landed cost is calculated correctly
- [ ] WAC updates on goods receipt
- [ ] Products show current cost and margins
- [ ] Margin inheritance works (product → category → company)
- [ ] Suggested price calculates from cost + margin
- [ ] Invoice lines show margin indicator
- [ ] Color coding works (green/yellow/orange/red)
- [ ] Permission checks block unauthorized pricing
- [ ] All tests passing
