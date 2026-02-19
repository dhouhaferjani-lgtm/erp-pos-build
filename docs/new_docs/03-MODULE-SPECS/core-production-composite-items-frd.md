# Production & Composite Items - Core Module FRD

**ERP Core Module**

| Field | Value |
|-------|-------|
| Version | 1.0 |
| Date | January 2026 |
| Status | Draft for Review |
| Module Type | Core (applies to F&B, Manufacturing, Sewing, Assembly) |
| Dependencies | UOM Module, Products Module, Inventory Module |

---

## 1. Executive Summary

### 1.1 Purpose

This document defines the functional requirements for the Production & Composite Items module, which provides the foundation for any business that **creates sellable items from component parts**. This includes:

| Vertical | Use Case |
|----------|----------|
| **F&B (Café/Restaurant)** | Menu items made from ingredients (recipes) |
| **Manufacturing** | Products assembled from raw materials (BOM) |
| **Sewing/Tailoring** | Garments made from fabric, thread, buttons |
| **Bakery/Pastry** | Baked goods from flour, sugar, butter |
| **Furniture** | Assembled from wood, screws, fabric |
| **Electronics Assembly** | PCBs from components |
| **Central Kitchen** | Prepared items for distribution to shops |

### 1.2 Core Problem

Currently, the ERP only supports simple products (buy → stock → sell). It cannot model:

1. **Composite items** - Things made from other things
2. **Recipe/BOM** - The formula defining what goes into making something
3. **Production consumption** - Automatic ingredient deduction when producing/selling
4. **Yield tracking** - Expected output vs actual output
5. **Cost rollup** - Calculating cost from component costs

### 1.3 Solution

Introduce a **Composite Item** entity that:
- Is distinct from inventory Products
- Has a **Bill of Materials (BOM)** or **Recipe** defining its components
- Implements `SellableContract` so it can be sold via POS and Sales Orders
- Triggers **recipe-based stock deduction** when sold or produced
- Supports **variants** (sizes, options) that affect the recipe

---

## 2. Conceptual Model

### 2.1 Entity Relationships

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              SELLABLE ITEMS                                  │
│                                                                             │
│   ┌─────────────┐      ┌──────────────────┐      ┌─────────────┐           │
│   │   Product   │      │  CompositeItem   │      │   Service   │           │
│   │  (Atomic)   │      │  (Has Recipe)    │      │ (No Stock)  │           │
│   └──────┬──────┘      └────────┬─────────┘      └─────────────┘           │
│          │                      │                                           │
│          │ implements           │ implements                                │
│          ▼                      ▼                                           │
│   ┌─────────────────────────────────────────────────────────────┐          │
│   │                   SellableContract                          │          │
│   │  + getSellableId()                                          │          │
│   │  + getSellableType()                                        │          │
│   │  + getSellablePrice(context)                                │          │
│   │  + getStockDeductionStrategy()                              │          │
│   └─────────────────────────────────────────────────────────────┘          │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                           COMPOSITION STRUCTURE                              │
│                                                                             │
│   ┌──────────────────┐           ┌──────────────────┐                      │
│   │  CompositeItem   │           │      Recipe      │                      │
│   │                  │ ─────────►│  (Bill of Mat.)  │                      │
│   │  - Cappuccino    │    has    │                  │                      │
│   │  - Chocolate Cake│           │  - version       │                      │
│   │  - T-Shirt       │           │  - yield_qty     │                      │
│   └──────────────────┘           └────────┬─────────┘                      │
│                                           │                                 │
│                                           │ has many                        │
│                                           ▼                                 │
│                                  ┌──────────────────┐                      │
│                                  │   RecipeLine     │                      │
│                                  │                  │                      │
│                                  │  - component_id  │───► Product          │
│                                  │  - quantity      │     (Ingredient)     │
│                                  │  - unit_id       │                      │
│                                  │  - is_optional   │                      │
│                                  └──────────────────┘                      │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Key Distinctions

| Aspect | Product | CompositeItem |
|--------|---------|---------------|
| **Nature** | Atomic, indivisible | Made from components |
| **Stock** | Direct inventory | No direct stock (made-to-order) OR tracked separately |
| **Cost** | Purchase cost | Calculated from recipe components |
| **Deduction** | Direct stock -= qty | Recipe-based: each component -= (qty × recipe_qty) |
| **Examples** | Coca-Cola, Flour, Fabric | Cappuccino, Chocolate Donut, Custom Dress |
| **Variants** | Color, Size (same stock) | Size affects recipe (Small vs Large coffee) |

### 2.3 Vertical Terminology Mapping

| Core Term | F&B | Manufacturing | Sewing | Bakery |
|-----------|-----|---------------|--------|--------|
| CompositeItem | MenuItem | ManufacturedProduct | Garment | BakedGood |
| Recipe | Recipe | BillOfMaterials | CutSheet | Recipe |
| RecipeLine | Ingredient | Component | Material | Ingredient |
| Component (Product) | Ingredient | RawMaterial | Fabric/Thread | Ingredient |

The core module uses **neutral terminology** (CompositeItem, Recipe, Component). Vertical modules can extend with domain-specific naming.

---

## 3. Data Model

### 3.1 Composite Items

```sql
CREATE TABLE composite_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    company_id UUID NOT NULL REFERENCES companies(id),
    
    -- Identity
    code VARCHAR(50) NOT NULL,              -- 'CAPP-001', 'DRESS-BLK-M'
    name VARCHAR(255) NOT NULL,             -- 'Cappuccino', 'Evening Dress'
    description TEXT,
    
    -- Classification
    category_id UUID REFERENCES categories(id),
    vertical_type VARCHAR(50) NOT NULL,     -- 'fnb', 'manufacturing', 'sewing'
    item_subtype VARCHAR(50),               -- 'hot_beverage', 'dress', 'furniture'
    
    -- Pricing (base price, variants may override)
    base_price DECIMAL(15,4) NOT NULL,
    currency_code VARCHAR(3) NOT NULL,
    tax_category_id UUID REFERENCES tax_categories(id),
    
    -- Production settings
    default_recipe_id UUID,                 -- Current active recipe version
    production_type VARCHAR(50) DEFAULT 'made_to_order',  -- 'made_to_order', 'batch', 'stock'
    
    -- If production_type = 'stock', track inventory
    track_inventory BOOLEAN DEFAULT FALSE,
    stock_unit_id UUID REFERENCES units_of_measure(id),
    
    -- Display
    image_url VARCHAR(500),
    display_order INT DEFAULT 0,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_available BOOLEAN DEFAULT TRUE,      -- Can be sold right now?
    
    -- Audit
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    created_by UUID REFERENCES users(id),
    
    UNIQUE(tenant_id, company_id, code)
);

-- Indexes
CREATE INDEX idx_composite_tenant_company ON composite_items(tenant_id, company_id);
CREATE INDEX idx_composite_category ON composite_items(category_id);
CREATE INDEX idx_composite_vertical ON composite_items(vertical_type);
CREATE INDEX idx_composite_active ON composite_items(is_active, is_available);
```

### 3.2 Recipes (Bill of Materials)

```sql
CREATE TABLE recipes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    company_id UUID NOT NULL REFERENCES companies(id),
    composite_item_id UUID NOT NULL REFERENCES composite_items(id),
    
    -- Versioning
    version INT NOT NULL DEFAULT 1,
    version_name VARCHAR(100),              -- 'Original', 'New Formula 2026'
    is_active BOOLEAN DEFAULT TRUE,         -- Only one active version per item
    
    -- Yield (how much does this recipe produce?)
    yield_quantity DECIMAL(15,4) NOT NULL DEFAULT 1,
    yield_unit_id UUID REFERENCES units_of_measure(id),
    
    -- Costing
    calculated_cost DECIMAL(15,4),          -- Sum of component costs
    cost_calculated_at TIMESTAMP,
    
    -- Production info
    prep_time_minutes INT,                  -- Time to prepare
    cook_time_minutes INT,                  -- Time to cook/assemble
    total_time_minutes INT GENERATED ALWAYS AS (COALESCE(prep_time_minutes, 0) + COALESCE(cook_time_minutes, 0)) STORED,
    
    -- Instructions (for F&B/production staff)
    instructions TEXT,
    
    -- Audit
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    created_by UUID REFERENCES users(id),
    
    UNIQUE(composite_item_id, version)
);

-- Only one active recipe per composite item
CREATE UNIQUE INDEX idx_recipe_active ON recipes(composite_item_id) WHERE is_active = TRUE;
```

### 3.3 Recipe Lines (Components/Ingredients)

```sql
CREATE TABLE recipe_lines (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    recipe_id UUID NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    
    -- Component reference (the ingredient/material)
    component_type VARCHAR(50) NOT NULL,    -- 'product', 'composite_item' (for sub-assemblies)
    component_id UUID NOT NULL,             -- References products.id or composite_items.id
    
    -- Quantity required
    quantity DECIMAL(15,6) NOT NULL,
    unit_id UUID NOT NULL REFERENCES units_of_measure(id),
    
    -- Behavior
    is_optional BOOLEAN DEFAULT FALSE,      -- Optional ingredient?
    is_scalable BOOLEAN DEFAULT TRUE,       -- Scale with size multiplier?
    
    -- Wastage/shrinkage allowance
    wastage_percent DECIMAL(5,2) DEFAULT 0, -- e.g., 5% for trimming
    
    -- Cost snapshot (for costing)
    unit_cost DECIMAL(15,4),                -- Cost per unit at time of calculation
    line_cost DECIMAL(15,4),                -- quantity × unit_cost
    
    -- Display
    display_order INT DEFAULT 0,
    notes TEXT,                             -- 'Dice finely', 'Pre-heat'
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Indexes
CREATE INDEX idx_recipe_line_recipe ON recipe_lines(recipe_id);
CREATE INDEX idx_recipe_line_component ON recipe_lines(component_type, component_id);
```

### 3.4 Composite Item Variants (Sizes/Options)

```sql
CREATE TABLE composite_item_variants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    composite_item_id UUID NOT NULL REFERENCES composite_items(id) ON DELETE CASCADE,
    
    -- Variant identity
    code VARCHAR(50) NOT NULL,              -- 'SM', 'MD', 'LG', 'XL'
    name VARCHAR(100) NOT NULL,             -- 'Small', 'Medium', 'Large'
    
    -- Pricing
    price_adjustment_type VARCHAR(20) DEFAULT 'absolute',  -- 'absolute', 'percentage', 'override'
    price_adjustment DECIMAL(15,4) DEFAULT 0,
    -- If absolute: base_price + adjustment
    -- If percentage: base_price × (1 + adjustment/100)
    -- If override: use adjustment as final price
    
    -- Recipe scaling
    recipe_multiplier DECIMAL(8,4) DEFAULT 1.0,  -- Small=0.75, Medium=1.0, Large=1.5
    
    -- Status
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(composite_item_id, code)
);

-- Only one default variant per item
CREATE UNIQUE INDEX idx_variant_default ON composite_item_variants(composite_item_id) WHERE is_default = TRUE;
```

### 3.5 Modifiers (Add-ons/Customizations)

```sql
CREATE TABLE modifier_groups (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    company_id UUID NOT NULL REFERENCES companies(id),
    
    name VARCHAR(100) NOT NULL,             -- 'Milk Options', 'Extra Toppings'
    code VARCHAR(50) NOT NULL,
    
    -- Selection rules
    selection_type VARCHAR(20) DEFAULT 'single',  -- 'single', 'multiple'
    min_selections INT DEFAULT 0,
    max_selections INT,                     -- NULL = unlimited
    is_required BOOLEAN DEFAULT FALSE,
    
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(tenant_id, company_id, code)
);

CREATE TABLE modifiers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    modifier_group_id UUID NOT NULL REFERENCES modifier_groups(id) ON DELETE CASCADE,
    
    name VARCHAR(100) NOT NULL,             -- 'Oat Milk', 'Extra Shot'
    code VARCHAR(50) NOT NULL,
    
    -- Pricing
    price_adjustment DECIMAL(15,4) DEFAULT 0,
    
    -- Inventory impact (optional - if modifier uses ingredients)
    component_type VARCHAR(50),             -- 'product' if this modifier uses inventory
    component_id UUID,                      -- The product used
    component_quantity DECIMAL(15,6),       -- How much to deduct
    component_unit_id UUID REFERENCES units_of_measure(id),
    
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(modifier_group_id, code)
);

-- Link modifier groups to composite items
CREATE TABLE composite_item_modifier_groups (
    composite_item_id UUID REFERENCES composite_items(id) ON DELETE CASCADE,
    modifier_group_id UUID REFERENCES modifier_groups(id) ON DELETE CASCADE,
    display_order INT DEFAULT 0,
    
    PRIMARY KEY (composite_item_id, modifier_group_id)
);
```

---

## 4. Core Abstractions

### 4.1 SellableContract

The unified interface for anything that can be sold:

```php
<?php

namespace App\Modules\Sales\Contracts;

use App\Modules\Common\ValueObjects\Money;
use App\Modules\Inventory\Contracts\StockDeductionStrategy;
use App\Modules\Tax\Domain\TaxCategory;
use App\Modules\Uom\Domain\UnitOfMeasure;

interface SellableContract
{
    /**
     * Unique identifier
     */
    public function getSellableId(): string;
    
    /**
     * Type discriminator for polymorphic references
     * Returns: 'product', 'composite_item', 'service'
     */
    public function getSellableType(): string;
    
    /**
     * Display name
     */
    public function getSellableName(): string;
    
    /**
     * Get price with context (variant, customer, quantity, etc.)
     */
    public function getSellablePrice(SellableContext $context): Money;
    
    /**
     * Tax category for tax calculation
     */
    public function getTaxCategory(): ?TaxCategory;
    
    /**
     * Unit of measure for quantity
     */
    public function getSellableUnit(): UnitOfMeasure;
    
    /**
     * Does this item affect inventory?
     */
    public function isStockTracked(): bool;
    
    /**
     * How should stock be deducted when sold?
     */
    public function getStockDeductionStrategy(): StockDeductionStrategy;
    
    /**
     * Is this item currently available for sale?
     */
    public function isAvailable(): bool;
}
```

### 4.2 SellableContext

Context passed when calculating prices or deducting stock:

```php
<?php

namespace App\Modules\Sales\Contracts;

class SellableContext
{
    public function __construct(
        public readonly ?string $variantId = null,
        public readonly array $modifierIds = [],
        public readonly ?string $customerId = null,
        public readonly ?string $priceListId = null,
        public readonly float $quantity = 1.0,
        public readonly ?\DateTimeInterface $date = null,
        public readonly array $metadata = [],
    ) {}
}
```

### 4.3 Stock Deduction Strategies

```php
<?php

namespace App\Modules\Inventory\Contracts;

interface StockDeductionStrategy
{
    /**
     * Deduct inventory based on sale
     */
    public function deduct(
        SellableContract $sellable,
        float $quantity,
        SellableContext $context,
        string $companyId,
        string $referenceType,  // 'sale', 'pos_transaction', 'production'
        string $referenceId,
    ): StockDeductionResult;
    
    /**
     * Preview what would be deducted (for availability check)
     */
    public function preview(
        SellableContract $sellable,
        float $quantity,
        SellableContext $context,
        string $companyId,
    ): array; // Returns component requirements
}
```

**Implementations:**

```php
// For Products - direct stock deduction
class DirectStockDeduction implements StockDeductionStrategy
{
    public function deduct(...): StockDeductionResult
    {
        // Simply: product.stock -= quantity
        // Creates inventory movement record
    }
}

// For CompositeItems - recipe-based deduction
class RecipeBasedDeduction implements StockDeductionStrategy
{
    public function deduct(...): StockDeductionResult
    {
        // 1. Get active recipe
        // 2. Get variant multiplier (if applicable)
        // 3. For each recipe line:
        //    component.stock -= (quantity × recipe_qty × variant_multiplier × (1 + wastage%))
        // 4. Handle modifiers (additional deductions)
        // 5. Create inventory movements for each component
    }
}

// For Services - no deduction
class NoStockDeduction implements StockDeductionStrategy
{
    public function deduct(...): StockDeductionResult
    {
        // Return empty result, no inventory impact
    }
}
```

---

## 5. Functional Requirements

### 5.1 Composite Item Management

| ID | Requirement |
|----|-------------|
| FR-CI-001 | Users can create composite items with name, code, category, and base price |
| FR-CI-002 | Composite items must specify a vertical type (fnb, manufacturing, sewing) |
| FR-CI-003 | Each composite item can have multiple recipe versions |
| FR-CI-004 | Only one recipe version can be active at a time |
| FR-CI-005 | Composite items can be marked as unavailable without deletion |
| FR-CI-006 | Composite items support image upload for POS/catalog display |
| FR-CI-007 | Composite items can be assigned to categories for organization |

### 5.2 Recipe Management

| ID | Requirement |
|----|-------------|
| FR-REC-001 | Each recipe defines a list of components (products or sub-assemblies) |
| FR-REC-002 | Recipe lines specify quantity and unit of measure |
| FR-REC-003 | System auto-converts units if recipe unit differs from component's stock unit |
| FR-REC-004 | Recipes can specify wastage percentage per component |
| FR-REC-005 | Recipe cost is calculated as sum of (component_qty × component_cost × (1 + wastage)) |
| FR-REC-006 | Recipe cost can be recalculated on demand or scheduled |
| FR-REC-007 | Recipes support optional components (not deducted by default) |
| FR-REC-008 | Recipes can include preparation instructions for staff |
| FR-REC-009 | Recipe versioning allows keeping history of formula changes |
| FR-REC-010 | New recipe version can be created by copying existing version |

### 5.3 Variants

| ID | Requirement |
|----|-------------|
| FR-VAR-001 | Composite items can have multiple variants (sizes, options) |
| FR-VAR-002 | Each variant can adjust price (absolute, percentage, or override) |
| FR-VAR-003 | Each variant has a recipe multiplier affecting component quantities |
| FR-VAR-004 | One variant can be marked as default (pre-selected in POS) |
| FR-VAR-005 | Variants can be deactivated without deletion |

### 5.4 Modifiers

| ID | Requirement |
|----|-------------|
| FR-MOD-001 | Modifier groups define sets of add-ons/customizations |
| FR-MOD-002 | Modifier groups specify selection rules (single/multiple, min/max) |
| FR-MOD-003 | Modifiers can have price adjustments |
| FR-MOD-004 | Modifiers can trigger inventory deductions (e.g., "Extra Shot" uses espresso) |
| FR-MOD-005 | Modifier groups can be shared across multiple composite items |
| FR-MOD-006 | Required modifier groups must have at least min_selections chosen |

### 5.5 Stock Deduction

| ID | Requirement |
|----|-------------|
| FR-STK-001 | When a composite item is sold, components are deducted per recipe |
| FR-STK-002 | Deduction respects variant's recipe multiplier |
| FR-STK-003 | Deduction includes modifier-triggered components |
| FR-STK-004 | Wastage percentage is included in deduction calculation |
| FR-STK-005 | Insufficient stock generates warning but can proceed (configurable) |
| FR-STK-006 | All deductions create inventory movement records for traceability |
| FR-STK-007 | Batch deduction for multiple items in single transaction |

### 5.6 Costing

| ID | Requirement |
|----|-------------|
| FR-CST-001 | Recipe cost auto-calculated from component costs |
| FR-CST-002 | Cost updates when component purchase prices change (on demand) |
| FR-CST-003 | Gross margin displayed as (sell_price - recipe_cost) / sell_price |
| FR-CST-004 | Cost breakdown shows each component's contribution |
| FR-CST-005 | Historical cost tracking for profitability analysis |

### 5.7 Availability

| ID | Requirement |
|----|-------------|
| FR-AVL-001 | System can check if composite item is producible (all components in stock) |
| FR-AVL-002 | "Available quantity" = min(component_stock / recipe_qty) across all components |
| FR-AVL-003 | Items with insufficient components can be marked "86'd" (unavailable) |
| FR-AVL-004 | POS shows availability status for composite items |

---

## 6. Sales Integration

### 6.1 Polymorphic Order Lines

Sales documents (Orders, Invoices, POS Transactions) use polymorphic lines:

```sql
-- Example: sales_order_lines
CREATE TABLE sales_order_lines (
    id UUID PRIMARY KEY,
    sales_order_id UUID NOT NULL REFERENCES sales_orders(id),
    
    -- Polymorphic sellable reference
    sellable_type VARCHAR(50) NOT NULL,     -- 'product', 'composite_item', 'service'
    sellable_id UUID NOT NULL,
    
    -- Snapshot for immutability
    description VARCHAR(255) NOT NULL,
    
    -- Variant/Modifiers (for composite items)
    variant_id UUID,
    modifiers JSONB,                        -- [{id, name, price_adj}, ...]
    
    -- Quantities and pricing
    quantity DECIMAL(15,4) NOT NULL,
    unit_id UUID REFERENCES units_of_measure(id),
    unit_price DECIMAL(15,4) NOT NULL,
    discount_amount DECIMAL(15,4) DEFAULT 0,
    tax_amount DECIMAL(15,4) DEFAULT 0,
    line_total DECIMAL(15,4) NOT NULL,
    
    -- Metadata
    notes TEXT,
    
    created_at TIMESTAMP DEFAULT NOW()
);
```

### 6.2 POS Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                         POS INTERFACE                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  MENU / PRODUCTS                         CURRENT ORDER          │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐    ┌───────────────────┐  │
│  │Cappuccino│ │  Latte  │ │Croissant│    │ 1× Cappuccino (L) │  │
│  │  €3.50   │ │  €3.50  │ │  €2.50  │    │    + Oat Milk     │  │
│  │CompositeItem│ │CompositeItem│ │Product│    │    €4.20          │  │
│  └─────────┘ └─────────┘ └─────────┘    │                   │  │
│                                          │ 1× Croissant      │  │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐    │    €2.50          │  │
│  │  Cake   │ │ Sandwich │ │  Soda   │    │                   │  │
│  │  €4.00  │ │  €5.50  │ │  €2.00  │    │ ──────────────    │  │
│  │CompositeItem│ │CompositeItem│ │Product│    │ Subtotal: €6.70   │  │
│  └─────────┘ └─────────┘ └─────────┘    │ Tax: €1.34        │  │
│                                          │ TOTAL: €8.04      │  │
│         [Products] [Menu Items]          │                   │  │
│                                          │ [PAY]             │  │
└─────────────────────────────────────────────────────────────────┘
           │
           │ On selecting Cappuccino:
           ▼
┌─────────────────────────────────────────────────────────────────┐
│                    VARIANT & MODIFIER SELECTION                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Cappuccino                                                     │
│                                                                 │
│  SIZE (required)                                                │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐                           │
│  │  Small  │ │ Medium  │ │  Large  │                           │
│  │  €3.00  │ │  €3.50  │ │  €4.00  │                           │
│  │  0.75×  │ │  1.0×   │ │  1.5×   │  ← Recipe multiplier      │
│  └─────────┘ └────✓────┘ └─────────┘                           │
│                                                                 │
│  MILK OPTIONS (optional)                                        │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐              │
│  │ Regular │ │   Oat   │ │ Almond  │ │  Soy    │              │
│  │  +€0.00 │ │  +€0.50 │ │  +€0.50 │ │  +€0.50 │              │
│  └────✓────┘ └─────────┘ └─────────┘ └─────────┘              │
│                                                                 │
│  EXTRAS (multiple)                                              │
│  ┌─────────┐ ┌─────────┐                                       │
│  │Extra Shot│ │ Syrup   │                                       │
│  │  +€0.80 │ │  +€0.50 │                                       │
│  └─────────┘ └─────────┘                                       │
│                                                                 │
│                              [Cancel]  [Add to Order - €3.50]   │
└─────────────────────────────────────────────────────────────────┘
```

### 6.3 B2B Sales Orders

Composite items can also appear in B2B sales orders:

```
┌─────────────────────────────────────────────────────────────────┐
│ Sales Order #SO-2026-0042                                       │
│ Customer: Franchise Shop Downtown                               │
├─────────────────────────────────────────────────────────────────┤
│ # │ Item                    │ Type       │ Qty │ Price │ Total │
├───┼─────────────────────────┼────────────┼─────┼───────┼───────┤
│ 1 │ Chocolate Donut         │ Composite  │  50 │  0.80 │ 40.00 │
│ 2 │ Glazed Donut            │ Composite  │  30 │  0.70 │ 21.00 │
│ 3 │ Coca-Cola 330ml         │ Product    │  24 │  0.60 │ 14.40 │
│ 4 │ Delivery Service        │ Service    │   1 │  5.00 │  5.00 │
├───┴─────────────────────────┴────────────┴─────┴───────┴───────┤
│                                              Subtotal:   80.40 │
│                                              Tax (19%):  15.28 │
│                                              TOTAL:      95.68 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 7. Production Workflow (Batch Production)

For businesses that produce in batches (not made-to-order):

### 7.1 Production Orders

```sql
CREATE TABLE production_orders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    
    order_number VARCHAR(50) NOT NULL,
    
    composite_item_id UUID NOT NULL REFERENCES composite_items(id),
    recipe_id UUID NOT NULL REFERENCES recipes(id),
    variant_id UUID REFERENCES composite_item_variants(id),
    
    -- Planned vs Actual
    planned_quantity DECIMAL(15,4) NOT NULL,
    actual_quantity DECIMAL(15,4),           -- Filled after production
    
    -- Scheduling
    scheduled_date DATE,
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    
    -- Status
    status VARCHAR(20) DEFAULT 'draft',      -- 'draft', 'confirmed', 'in_progress', 'completed', 'cancelled'
    
    -- Costing
    planned_cost DECIMAL(15,4),
    actual_cost DECIMAL(15,4),
    
    notes TEXT,
    
    created_at TIMESTAMP DEFAULT NOW(),
    created_by UUID REFERENCES users(id),
    
    UNIQUE(tenant_id, company_id, order_number)
);

CREATE TABLE production_order_lines (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    production_order_id UUID NOT NULL REFERENCES production_orders(id),
    
    -- Component
    component_type VARCHAR(50) NOT NULL,
    component_id UUID NOT NULL,
    
    -- Quantities
    planned_quantity DECIMAL(15,6) NOT NULL,
    actual_quantity DECIMAL(15,6),           -- May differ (wastage, substitution)
    unit_id UUID NOT NULL REFERENCES units_of_measure(id),
    
    -- Cost
    unit_cost DECIMAL(15,4),
    line_cost DECIMAL(15,4),
    
    -- Lot/batch tracking (optional)
    lot_number VARCHAR(50),
    
    notes TEXT
);
```

### 7.2 Production Flow

```
┌──────────────────────────────────────────────────────────────────┐
│                      PRODUCTION WORKFLOW                          │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. CREATE PRODUCTION ORDER                                      │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ Item: Chocolate Donut                                      │ │
│  │ Quantity: 100 units                                        │ │
│  │ Recipe: v2 (Current)                                       │ │
│  │ Scheduled: 2026-01-10 06:00                               │ │
│  └────────────────────────────────────────────────────────────┘ │
│                              │                                   │
│                              ▼                                   │
│  2. CONFIRM ORDER (reserves/checks components)                   │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ Component Check:                                           │ │
│  │ ✓ Flour: Need 5kg, Have 20kg                              │ │
│  │ ✓ Sugar: Need 1kg, Have 8kg                               │ │
│  │ ✓ Chocolate: Need 2kg, Have 3kg                           │ │
│  │ ✓ Oil: Need 0.5L, Have 5L                                 │ │
│  │ Status: All components available                           │ │
│  └────────────────────────────────────────────────────────────┘ │
│                              │                                   │
│                              ▼                                   │
│  3. START PRODUCTION                                             │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ Status: In Progress                                        │ │
│  │ Started: 2026-01-10 06:15                                 │ │
│  │ (Components locked/reserved)                               │ │
│  └────────────────────────────────────────────────────────────┘ │
│                              │                                   │
│                              ▼                                   │
│  4. COMPLETE PRODUCTION                                          │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ Actual Output: 98 units (2 rejected)                       │ │
│  │ Actual Consumption:                                        │ │
│  │   Flour: 5.1kg (slightly more)                            │ │
│  │   Sugar: 1kg                                               │ │
│  │   Chocolate: 2.1kg                                         │ │
│  │   Oil: 0.5L                                                │ │
│  │                                                            │ │
│  │ [Complete Production]                                      │ │
│  └────────────────────────────────────────────────────────────┘ │
│                              │                                   │
│                              ▼                                   │
│  5. INVENTORY UPDATED                                            │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ Component Deductions:                                      │ │
│  │   Flour: 20kg → 14.9kg                                    │ │
│  │   Sugar: 8kg → 7kg                                        │ │
│  │   Chocolate: 3kg → 0.9kg                                  │ │
│  │   Oil: 5L → 4.5L                                          │ │
│  │                                                            │ │
│  │ Output Added (if track_inventory=true):                    │ │
│  │   Chocolate Donut: 0 → 98 units                           │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

---

## 8. Vertical Extensions

The core module provides the foundation. Vertical modules extend with domain-specific features:

### 8.1 F&B Extension (fnb_composite_items)

```sql
CREATE TABLE fnb_composite_item_details (
    composite_item_id UUID PRIMARY KEY REFERENCES composite_items(id),
    
    -- F&B specific
    course_type VARCHAR(50),                -- 'appetizer', 'main', 'dessert', 'beverage'
    dietary_tags VARCHAR[],                 -- ['vegetarian', 'vegan', 'gluten-free']
    allergens VARCHAR[],                    -- ['nuts', 'dairy', 'gluten']
    calories INT,
    spice_level INT,                        -- 0-5 scale
    
    -- Display
    preparation_display TEXT,               -- "Freshly brewed"
    
    -- Service
    avg_prep_time_seconds INT,
    requires_kitchen_printer BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT NOW()
);
```

### 8.2 Manufacturing Extension (manufacturing_composite_items)

```sql
CREATE TABLE manufacturing_composite_item_details (
    composite_item_id UUID PRIMARY KEY REFERENCES composite_items(id),
    
    -- Manufacturing specific
    part_number VARCHAR(100),
    revision VARCHAR(20),
    engineering_change_order VARCHAR(50),
    
    -- Quality
    inspection_required BOOLEAN DEFAULT FALSE,
    quality_specifications JSONB,
    
    -- Routing
    work_center_id UUID,
    routing_steps JSONB,                    -- [{step, operation, time}, ...]
    
    -- Costing
    labor_cost_per_unit DECIMAL(15,4),
    overhead_rate DECIMAL(8,4),
    
    created_at TIMESTAMP DEFAULT NOW()
);
```

### 8.3 Sewing Extension (sewing_composite_items)

```sql
CREATE TABLE sewing_composite_item_details (
    composite_item_id UUID PRIMARY KEY REFERENCES composite_items(id),
    
    -- Garment specific
    garment_type VARCHAR(50),               -- 'dress', 'shirt', 'pants', 'jacket'
    size_chart_id UUID,
    
    -- Materials
    primary_fabric_type VARCHAR(100),
    lining_required BOOLEAN DEFAULT FALSE,
    
    -- Construction
    pattern_id UUID,                        -- Link to pattern library
    construction_notes TEXT,
    
    -- Customization
    allows_custom_measurements BOOLEAN DEFAULT FALSE,
    allows_custom_fabric BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT NOW()
);
```

---

## 9. API Endpoints

### 9.1 Composite Items

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/composite-items` | List items (filterable by vertical, category) |
| GET | `/api/composite-items/{id}` | Get item with active recipe |
| POST | `/api/composite-items` | Create new item |
| PUT | `/api/composite-items/{id}` | Update item |
| DELETE | `/api/composite-items/{id}` | Deactivate item |
| POST | `/api/composite-items/{id}/duplicate` | Duplicate with recipes |

### 9.2 Recipes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/composite-items/{id}/recipes` | List recipe versions |
| GET | `/api/recipes/{id}` | Get recipe with lines |
| POST | `/api/composite-items/{id}/recipes` | Create recipe version |
| PUT | `/api/recipes/{id}` | Update recipe |
| POST | `/api/recipes/{id}/activate` | Set as active recipe |
| POST | `/api/recipes/{id}/duplicate` | Copy to new version |
| POST | `/api/recipes/{id}/calculate-cost` | Recalculate cost |

### 9.3 Variants & Modifiers

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/composite-items/{id}/variants` | List variants |
| POST | `/api/composite-items/{id}/variants` | Add variant |
| PUT | `/api/variants/{id}` | Update variant |
| DELETE | `/api/variants/{id}` | Remove variant |
| GET | `/api/modifier-groups` | List modifier groups |
| POST | `/api/modifier-groups` | Create group |
| POST | `/api/composite-items/{id}/modifier-groups` | Assign group to item |

### 9.4 Production

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/production-orders` | List orders |
| POST | `/api/production-orders` | Create order |
| POST | `/api/production-orders/{id}/confirm` | Confirm & check components |
| POST | `/api/production-orders/{id}/start` | Start production |
| POST | `/api/production-orders/{id}/complete` | Complete with actuals |
| POST | `/api/production-orders/{id}/cancel` | Cancel order |

---

## 10. Acceptance Criteria

### 10.1 Must Have (MVP)

- [ ] CompositeItem entity with CRUD operations
- [ ] Recipe/BOM with component lines
- [ ] Recipe-based stock deduction on sale
- [ ] SellableContract implementation
- [ ] Integration with POS (show composite items alongside products)
- [ ] Integration with Sales Orders (polymorphic lines)
- [ ] Basic recipe cost calculation
- [ ] Unit conversion in recipes (recipe ml → component L)

### 10.2 Should Have

- [ ] Variants with price adjustments and recipe multipliers
- [ ] Modifier groups with inventory impact
- [ ] Recipe versioning
- [ ] Availability check (component stock)
- [ ] Production orders for batch production

### 10.3 Nice to Have

- [ ] Sub-assembly support (composite item as component)
- [ ] Recipe scaling calculator
- [ ] Yield variance tracking
- [ ] Cost history and trends
- [ ] Recipe comparison across versions

---

## 11. Open Questions

| # | Question | Recommendation |
|---|----------|----------------|
| 1 | Should sub-assemblies (composite item as component) be MVP? | Defer to v2 |
| 2 | How to handle negative stock (sell more than available)? | Config: warn vs block |
| 3 | Should recipes support alternative components? | Yes, add later |
| 4 | Track actual vs theoretical yield? | Yes, in production orders |
| 5 | Kitchen display system integration? | Separate module |

---

## 12. Related Documents

| Document | Description |
|----------|-------------|
| Core UOM Module FRD | Units of measure (dependency) |
| Core Loyalty Module FRD | LoyaltyableContract for composite items |
| F&B Boss FRD | F&B-specific extension requirements |
| POS Module FRD | Point of sale integration |

---

*— End of Document —*
