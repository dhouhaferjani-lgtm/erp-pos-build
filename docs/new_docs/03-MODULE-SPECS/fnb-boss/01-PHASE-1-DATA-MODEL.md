# F&B Boss - Phase 1: Data Model & Backend

**Phase:** 1 of 4
**Duration:** 2 weeks (80 hours)
**Status:** Planning
**Dependencies:** None (foundational phase)

---

## Phase Overview

### Goals

1. Design and implement complete F&B data model
2. Create domain entities with business logic
3. Build repository layer with Eloquent
4. Implement core services (RecipeService, ModifierService)
5. Create API controllers for all CRUD operations
6. Achieve 90%+ test coverage on domain logic

### Deliverables

- [ ] 8 database migrations (recipes, modifiers, menu items)
- [ ] 12 domain entities with full business logic
- [ ] 6 repository implementations
- [ ] 4 application services
- [ ] 5 API controllers with 40+ endpoints
- [ ] 80+ unit tests
- [ ] 20+ integration tests
- [ ] API documentation (OpenAPI/Swagger)

---

## Database Design

### Design Decision: Shared vs Separate Tables

**DECISION:** Use shared `products` table with type discriminator

**Rationale:**
1. **Inventory Unification:** Both ingredients and menu items need stock tracking, locations, movements
2. **Pricing Consistency:** Same pricing rules, discounts, tax application
3. **Code Reuse:** Existing inventory services work without modification
4. **Reporting:** Unified product analytics and reporting
5. **Performance:** Single table join for cross-type queries

**Trade-off:** Some columns unused for certain types (acceptable with clear documentation)

---

## Table Specifications

### 1. Extend `products` Table

Add discriminator column to support multiple product types:

```sql
-- Migration: 2026_01_10_100000_add_product_type_to_products.php

ALTER TABLE products
ADD COLUMN product_type VARCHAR(20) NOT NULL DEFAULT 'standard';

-- Create enum constraint
ALTER TABLE products
ADD CONSTRAINT products_type_check
CHECK (product_type IN ('standard', 'ingredient', 'menu_item', 'service'));

-- Add index for type filtering
CREATE INDEX idx_products_type ON products(product_type);

-- Add F&B specific columns
ALTER TABLE products
ADD COLUMN unit_of_measure VARCHAR(20),           -- g, ml, pc, kg, l
ADD COLUMN is_sellable BOOLEAN DEFAULT true,      -- Ingredients may not be sellable
ADD COLUMN cost_price DECIMAL(12,2),              -- For COGS calculation
ADD COLUMN is_perishable BOOLEAN DEFAULT false,   -- For expiry tracking
ADD COLUMN reorder_point INTEGER;                 -- Low stock alert
```

**Product Type Mapping:**
| Type | Use Case | Required Fields |
|------|----------|----------------|
| `standard` | Regular retail products | All standard product fields |
| `ingredient` | Raw materials for recipes | `unit_of_measure`, `cost_price` |
| `menu_item` | Sellable F&B items | Standard + link to `recipes` |
| `service` | Automotive services | Standard fields |

---

### 2. New Table: `recipes`

Links menu items to their ingredient composition.

```sql
-- Migration: 2026_01_10_100001_create_recipes_table.php

CREATE TABLE recipes (
    -- Primary Key
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Multi-tenancy
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    company_id UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,

    -- Menu Item Reference
    menu_item_id UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,

    -- Recipe Metadata
    name VARCHAR(100) NOT NULL,                    -- "Standard Cappuccino Recipe"
    description TEXT,
    version INTEGER NOT NULL DEFAULT 1,            -- For recipe change tracking

    -- Yield Information
    base_yield DECIMAL(10,3) NOT NULL DEFAULT 1.0, -- Base recipe makes X servings
    yield_unit VARCHAR(20),                         -- servings, portions, pieces

    -- Costing
    theoretical_cost DECIMAL(12,2),                 -- Calculated from ingredient costs
    target_cost_percentage DECIMAL(5,2),            -- Ideal COGS %

    -- Lifecycle
    is_active BOOLEAN NOT NULL DEFAULT true,
    effective_from DATE,                            -- When this recipe version starts
    effective_until DATE,                           -- When superseded by new version

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    created_by UUID REFERENCES users(id),

    -- Constraints
    CONSTRAINT recipes_unique_menu_item_version UNIQUE(menu_item_id, version),
    CONSTRAINT recipes_yield_positive CHECK (base_yield > 0)
);

-- Indexes
CREATE INDEX idx_recipes_tenant ON recipes(tenant_id);
CREATE INDEX idx_recipes_menu_item ON recipes(menu_item_id);
CREATE INDEX idx_recipes_active ON recipes(is_active) WHERE is_active = true;

-- Comments
COMMENT ON TABLE recipes IS 'Recipe definitions linking menu items to ingredients';
COMMENT ON COLUMN recipes.theoretical_cost IS 'Sum of (ingredient_cost × quantity) for all lines';
```

---

### 3. New Table: `recipe_lines`

Individual ingredient quantities in a recipe.

```sql
-- Migration: 2026_01_10_100002_create_recipe_lines_table.php

CREATE TABLE recipe_lines (
    -- Primary Key
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    recipe_id UUID NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,

    -- Ingredient Reference
    ingredient_id UUID NOT NULL REFERENCES products(id) ON DELETE RESTRICT,

    -- Quantity
    quantity DECIMAL(10,3) NOT NULL,               -- 14.5
    unit VARCHAR(20) NOT NULL,                     -- g, ml, pc

    -- Sorting
    sort_order INTEGER NOT NULL DEFAULT 0,

    -- Optional Ingredient (tied to modifier)
    is_optional BOOLEAN NOT NULL DEFAULT false,
    linked_modifier_option_id UUID REFERENCES modifier_options(id) ON DELETE SET NULL,

    -- Cost Snapshot (for historical accuracy)
    unit_cost DECIMAL(12,2),                       -- Cost per unit at recipe creation
    line_cost DECIMAL(12,2),                       -- quantity × unit_cost

    -- Notes
    preparation_note TEXT,                         -- "Finely ground", "Steamed to 65°C"

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),

    -- Constraints
    CONSTRAINT recipe_lines_quantity_positive CHECK (quantity > 0),
    CONSTRAINT recipe_lines_unique_ingredient UNIQUE(recipe_id, ingredient_id)
);

-- Indexes
CREATE INDEX idx_recipe_lines_recipe ON recipe_lines(recipe_id);
CREATE INDEX idx_recipe_lines_ingredient ON recipe_lines(ingredient_id);

-- Comments
COMMENT ON TABLE recipe_lines IS 'Ingredient quantities for each recipe';
COMMENT ON COLUMN recipe_lines.is_optional IS 'True if ingredient added by modifier selection';
```

---

### 4. New Table: `modifier_groups`

Customization categories (e.g., "Milk Type", "Extras").

```sql
-- Migration: 2026_01_10_100003_create_modifier_groups_table.php

CREATE TABLE modifier_groups (
    -- Primary Key
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Multi-tenancy
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    company_id UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,

    -- Group Identity
    name VARCHAR(100) NOT NULL,                    -- "Milk Type", "Sugar Level"
    description TEXT,

    -- Selection Rules
    is_required BOOLEAN NOT NULL DEFAULT false,    -- Must select at least one?
    min_selections INTEGER NOT NULL DEFAULT 0,     -- Minimum options to select
    max_selections INTEGER NOT NULL DEFAULT 1,     -- 1 = single choice, >1 = multiple
    selection_type VARCHAR(20) NOT NULL,           -- SINGLE, MULTIPLE

    -- Display
    sort_order INTEGER NOT NULL DEFAULT 0,
    display_style VARCHAR(20),                     -- RADIO, CHECKBOX, DROPDOWN, GRID

    -- Lifecycle
    is_active BOOLEAN NOT NULL DEFAULT true,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

    -- Constraints
    CONSTRAINT modifier_groups_selection_type_check
        CHECK (selection_type IN ('SINGLE', 'MULTIPLE')),
    CONSTRAINT modifier_groups_selection_logic
        CHECK (min_selections <= max_selections),
    CONSTRAINT modifier_groups_unique_name
        UNIQUE(tenant_id, company_id, name)
);

-- Indexes
CREATE INDEX idx_modifier_groups_tenant ON modifier_groups(tenant_id);
CREATE INDEX idx_modifier_groups_company ON modifier_groups(company_id);
CREATE INDEX idx_modifier_groups_active ON modifier_groups(is_active)
    WHERE is_active = true;

-- Comments
COMMENT ON TABLE modifier_groups IS 'Customization categories for menu items';
COMMENT ON COLUMN modifier_groups.selection_type IS 'SINGLE (radio) or MULTIPLE (checkbox)';
```

---

### 5. New Table: `modifier_options`

Individual modifier choices within a group.

```sql
-- Migration: 2026_01_10_100004_create_modifier_options_table.php

CREATE TABLE modifier_options (
    -- Primary Key
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    modifier_group_id UUID NOT NULL REFERENCES modifier_groups(id) ON DELETE CASCADE,

    -- Option Identity
    name VARCHAR(100) NOT NULL,                    -- "Oat Milk", "Extra Shot"
    description TEXT,

    -- Pricing
    price_adjustment DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- +0.50, -0.20

    -- Ingredient Effects
    ingredient_effect_type VARCHAR(20),            -- ADD, REMOVE, SUBSTITUTE
    ingredient_id UUID REFERENCES products(id),    -- Affected ingredient
    ingredient_quantity DECIMAL(10,3),             -- Quantity to add/remove
    substitute_ingredient_id UUID REFERENCES products(id),  -- For SUBSTITUTE type

    -- Display
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_default BOOLEAN NOT NULL DEFAULT false,     -- Pre-selected option

    -- Availability
    is_available BOOLEAN NOT NULL DEFAULT true,
    unavailable_reason TEXT,                       -- "Out of oat milk"

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

    -- Constraints
    CONSTRAINT modifier_options_effect_type_check
        CHECK (ingredient_effect_type IN ('ADD', 'REMOVE', 'SUBSTITUTE', NULL)),
    CONSTRAINT modifier_options_substitute_logic
        CHECK (
            (ingredient_effect_type != 'SUBSTITUTE') OR
            (substitute_ingredient_id IS NOT NULL)
        ),
    CONSTRAINT modifier_options_unique_name
        UNIQUE(modifier_group_id, name)
);

-- Indexes
CREATE INDEX idx_modifier_options_group ON modifier_options(modifier_group_id);
CREATE INDEX idx_modifier_options_ingredient ON modifier_options(ingredient_id)
    WHERE ingredient_id IS NOT NULL;

-- Comments
COMMENT ON TABLE modifier_options IS 'Individual modifier choices';
COMMENT ON COLUMN modifier_options.ingredient_effect_type IS 'How this modifier affects recipe: ADD, REMOVE, SUBSTITUTE';
```

---

### 6. New Table: `menu_item_sizes`

Size variants for menu items (Small, Medium, Large).

```sql
-- Migration: 2026_01_10_100005_create_menu_item_sizes_table.php

CREATE TABLE menu_item_sizes (
    -- Primary Key
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Menu Item Reference
    menu_item_id UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,

    -- Size Identity
    name VARCHAR(50) NOT NULL,                     -- "Small", "Medium", "Large"
    code VARCHAR(10),                              -- S, M, L

    -- Pricing
    price_adjustment DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- +1.50

    -- Recipe Scaling
    recipe_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1.0,   -- 1.0, 1.25, 1.5

    -- Display
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_default BOOLEAN NOT NULL DEFAULT false,

    -- Availability
    is_available BOOLEAN NOT NULL DEFAULT true,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

    -- Constraints
    CONSTRAINT menu_item_sizes_multiplier_positive
        CHECK (recipe_multiplier > 0),
    CONSTRAINT menu_item_sizes_unique_name
        UNIQUE(menu_item_id, name)
);

-- Indexes
CREATE INDEX idx_menu_item_sizes_menu_item ON menu_item_sizes(menu_item_id);

-- Comments
COMMENT ON TABLE menu_item_sizes IS 'Size variants for menu items';
COMMENT ON COLUMN menu_item_sizes.recipe_multiplier IS 'Multiplier applied to recipe quantities';
```

---

### 7. New Table: `menu_item_modifier_groups` (Pivot)

Many-to-many relationship between menu items and modifier groups.

```sql
-- Migration: 2026_01_10_100006_create_menu_item_modifier_groups_table.php

CREATE TABLE menu_item_modifier_groups (
    -- Composite Primary Key
    menu_item_id UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    modifier_group_id UUID NOT NULL REFERENCES modifier_groups(id) ON DELETE CASCADE,

    -- Ordering (which group shows first in POS)
    sort_order INTEGER NOT NULL DEFAULT 0,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),

    -- Primary Key
    PRIMARY KEY (menu_item_id, modifier_group_id)
);

-- Indexes
CREATE INDEX idx_menu_item_modifiers_menu_item
    ON menu_item_modifier_groups(menu_item_id);
CREATE INDEX idx_menu_item_modifiers_modifier_group
    ON menu_item_modifier_groups(modifier_group_id);

-- Comments
COMMENT ON TABLE menu_item_modifier_groups IS 'Links menu items to their available modifier groups';
```

---

### 8. Extend `pos_receipt_lines` Table

Add support for storing selected modifiers on receipt lines.

```sql
-- Migration: 2026_01_10_100007_add_modifiers_to_pos_receipt_lines.php

ALTER TABLE pos_receipt_lines
ADD COLUMN selected_size_id UUID REFERENCES menu_item_sizes(id) ON DELETE SET NULL,
ADD COLUMN selected_modifiers JSONB;

-- Index for JSONB queries
CREATE INDEX idx_pos_receipt_lines_modifiers
    ON pos_receipt_lines USING gin(selected_modifiers);

-- Comments
COMMENT ON COLUMN pos_receipt_lines.selected_modifiers IS
'JSON array of selected modifiers: [{"group_id": "...", "option_id": "...", "name": "Oat Milk", "price": 0.50}]';
```

**Example JSONB structure:**
```json
[
  {
    "modifier_group_id": "uuid-123",
    "modifier_group_name": "Milk Type",
    "modifier_option_id": "uuid-456",
    "modifier_option_name": "Oat Milk",
    "price_adjustment": 0.50
  },
  {
    "modifier_group_id": "uuid-789",
    "modifier_group_name": "Extras",
    "modifier_option_id": "uuid-012",
    "modifier_option_name": "Extra Shot",
    "price_adjustment": 0.80
  }
]
```

---

## Domain Entities

### Entity Structure

All domain entities follow this pattern:

```
app/Modules/{Module}/Domain/Entities/
├── Ingredient.php               (extends Product with F&B semantics)
├── MenuItem.php                 (extends Product)
├── Recipe.php
├── RecipeLine.php
├── ModifierGroup.php
├── ModifierOption.php
└── MenuItemSize.php
```

---

### 1. Ingredient Entity

**File:** `app/Modules/Product/Domain/Entities/Ingredient.php`

```php
<?php

namespace App\Modules\Product\Domain\Entities;

use App\Modules\Product\Domain\ValueObjects\UnitOfMeasure;
use Money\Money;

/**
 * Ingredient - Raw material used in recipes
 *
 * Wraps Product entity with F&B-specific semantics
 */
class Ingredient
{
    public function __construct(
        private readonly ProductId $id,
        private readonly string $name,
        private readonly UnitOfMeasure $unit,
        private readonly ?Money $costPrice,
        private readonly bool $isSellable,
        private readonly bool $isPerishable,
        private readonly ?int $reorderPoint,
    ) {}

    public function canBeSoldDirectly(): bool
    {
        return $this->isSellable;
    }

    public function isLowStock(int $currentQuantity): bool
    {
        if ($this->reorderPoint === null) {
            return false;
        }

        return $currentQuantity <= $this->reorderPoint;
    }

    public function requiresExpiryTracking(): bool
    {
        return $this->isPerishable;
    }

    public function calculateCost(float $quantity): Money
    {
        if ($this->costPrice === null) {
            throw new \DomainException("Ingredient {$this->name} has no cost price defined");
        }

        return $this->costPrice->multiply((string) $quantity);
    }
}
```

---

### 2. MenuItem Entity

**File:** `app/Modules/Catalog/Domain/Entities/MenuItem.php`

```php
<?php

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Product\Domain\ValueObjects\ProductId;
use App\Modules\Catalog\Domain\Collections\MenuItemSizeCollection;
use App\Modules\Catalog\Domain\Collections\ModifierGroupCollection;
use Money\Money;

/**
 * MenuItem - Sellable F&B item
 */
class MenuItem
{
    public function __construct(
        private readonly ProductId $id,
        private readonly string $name,
        private readonly MenuCategory $category,
        private readonly Money $basePrice,
        private readonly ?Recipe $recipe,
        private readonly MenuItemSizeCollection $sizes,
        private readonly ModifierGroupCollection $modifierGroups,
        private readonly bool $isActive,
    ) {}

    public function hasRecipe(): bool
    {
        return $this->recipe !== null;
    }

    public function hasSizes(): bool
    {
        return !$this->sizes->isEmpty();
    }

    public function hasModifiers(): bool
    {
        return !$this->modifierGroups->isEmpty();
    }

    public function calculatePrice(
        ?MenuItemSize $size = null,
        array $selectedModifiers = []
    ): Money {
        $price = $this->basePrice;

        // Apply size adjustment
        if ($size !== null) {
            $price = $price->add($size->getPriceAdjustment());
        }

        // Apply modifier adjustments
        foreach ($selectedModifiers as $modifier) {
            $price = $price->add($modifier->getPriceAdjustment());
        }

        return $price;
    }

    public function validateModifierSelection(array $selectedModifiers): void
    {
        foreach ($this->modifierGroups as $group) {
            $groupSelections = array_filter(
                $selectedModifiers,
                fn($mod) => $mod->getGroupId()->equals($group->getId())
            );

            $count = count($groupSelections);

            if ($group->isRequired() && $count === 0) {
                throw new \DomainException(
                    "Modifier group '{$group->getName()}' is required"
                );
            }

            if ($count < $group->getMinSelections()) {
                throw new \DomainException(
                    "Modifier group '{$group->getName()}' requires at least {$group->getMinSelections()} selections"
                );
            }

            if ($count > $group->getMaxSelections()) {
                throw new \DomainException(
                    "Modifier group '{$group->getName()}' allows at most {$group->getMaxSelections()} selections"
                );
            }
        }
    }
}
```

---

### 3. Recipe Entity

**File:** `app/Modules/Catalog/Domain/Entities/Recipe.php`

```php
<?php

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Collections\RecipeLineCollection;
use App\Modules\Catalog\Domain\ValueObjects\RecipeId;
use Money\Money;

/**
 * Recipe - Ingredient composition for a menu item
 */
class Recipe
{
    public function __construct(
        private readonly RecipeId $id,
        private readonly ProductId $menuItemId,
        private readonly string $name,
        private readonly RecipeLineCollection $lines,
        private readonly float $baseYield,
        private readonly int $version,
        private readonly bool $isActive,
    ) {}

    public function calculateCost(float $sizeMultiplier = 1.0): Money
    {
        return $this->lines->reduce(
            Money::USD(0),
            function (Money $total, RecipeLine $line) use ($sizeMultiplier) {
                $lineQuantity = $line->getQuantity() * $sizeMultiplier;
                $lineCost = $line->getIngredient()->calculateCost($lineQuantity);
                return $total->add($lineCost);
            }
        );
    }

    public function scale(float $multiplier): RecipeLineCollection
    {
        return $this->lines->map(
            fn(RecipeLine $line) => $line->scale($multiplier)
        );
    }

    public function applyModifiers(array $modifierOptions): RecipeLineCollection
    {
        $scaledLines = clone $this->lines;

        foreach ($modifierOptions as $modifier) {
            $effect = $modifier->getIngredientEffect();

            match($effect->getType()) {
                'ADD' => $scaledLines = $scaledLines->addIngredient(
                    $effect->getIngredient(),
                    $effect->getQuantity()
                ),
                'REMOVE' => $scaledLines = $scaledLines->removeIngredient(
                    $effect->getIngredient()
                ),
                'SUBSTITUTE' => $scaledLines = $scaledLines->substituteIngredient(
                    $effect->getOriginalIngredient(),
                    $effect->getSubstituteIngredient(),
                    $effect->getQuantity()
                ),
                default => throw new \InvalidArgumentException("Unknown effect type: {$effect->getType()}")
            };
        }

        return $scaledLines;
    }
}
```

---

## Application Services

### 1. RecipeService

**Responsibilities:**
- Calculate theoretical COGS
- Apply size multipliers and modifiers
- Generate ingredient shopping lists
- Validate recipe completeness

**File:** `app/Modules/Catalog/Application/Services/RecipeService.php`

**Key Methods:**
```php
public function calculateTheoretical​Cost(Recipe $recipe, float $sizeMultiplier): Money;
public function getIngredientRequirements(Recipe $recipe, int $quantity): array;
public function applyCustomizations(Recipe $recipe, MenuItemSize $size, array $modifiers): RecipeLineCollection;
public function validateRecipe(Recipe $recipe): RecipeValidationResult;
```

---

### 2. ModifierService

**Responsibilities:**
- Validate modifier selections against rules
- Calculate total modifier price adjustments
- Apply ingredient effects from modifiers

**File:** `app/Modules/Catalog/Application/Services/ModifierService.php`

**Key Methods:**
```php
public function validateSelection(ModifierGroup $group, array $selectedOptions): void;
public function calculatePriceAdjustment(array $selectedModifiers): Money;
public function getIngredientEffects(array $selectedModifiers): array;
```

---

### 3. MenuItemPricingService

**Responsibilities:**
- Calculate final menu item price with size + modifiers
- Apply consumption mode tax adjustments (France)
- Generate line item descriptions with modifiers

**File:** `app/Modules/Catalog/Application/Services/MenuItemPricingService.php`

**Key Methods:**
```php
public function calculateFinalPrice(
    MenuItem $item,
    ?MenuItemSize $size,
    array $modifiers
): Money;

public function generateDescription(
    MenuItem $item,
    ?MenuItemSize $size,
    array $modifiers
): string;

public function calculateTax(
    MenuItem $item,
    Money $price,
    ConsumptionMode $mode
): Money;
```

---

## API Controllers

### Controller List

1. **IngredientController** - CRUD for ingredients
2. **MenuItemController** - CRUD for menu items
3. **RecipeController** - CRUD for recipes
4. **ModifierGroupController** - CRUD for modifier groups
5. **ModifierOptionController** - CRUD for modifier options

---

### Example: MenuItemController

**File:** `app/Modules/Catalog/Presentation/Controllers/MenuItemController.php`

```php
<?php

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Application\Commands\CreateMenuItemCommand;
use App\Modules\Catalog\Application\Queries\MenuItemQuery;
use App\Modules\Catalog\Application\DTOs\MenuItemData;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuItemController extends Controller
{
    public function __construct(
        private readonly MenuItemQuery $query,
        private readonly CreateMenuItemCommand $createCommand,
    ) {}

    /**
     * List menu items with filters
     *
     * GET /api/v1/menu-items
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category_id' => 'sometimes|uuid',
            'is_active' => 'sometimes|boolean',
            'search' => 'sometimes|string|max:100',
        ]);

        $menuItems = $this->query->getAll($filters);

        return response()->json([
            'data' => MenuItemData::collection($menuItems),
        ]);
    }

    /**
     * Get single menu item with recipe and modifiers
     *
     * GET /api/v1/menu-items/{id}
     */
    public function show(string $id): JsonResponse
    {
        $menuItem = $this->query->getById($id);

        return response()->json([
            'data' => MenuItemData::fromEntity($menuItem),
        ]);
    }

    /**
     * Create new menu item
     *
     * POST /api/v1/menu-items
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'category_id' => 'required|uuid|exists:categories,id',
            'base_price' => 'required|numeric|min:0',
            'has_recipe' => 'required|boolean',
            'recipe' => 'required_if:has_recipe,true|array',
            'recipe.lines' => 'required_if:has_recipe,true|array|min:1',
            'recipe.lines.*.ingredient_id' => 'required|uuid|exists:products,id',
            'recipe.lines.*.quantity' => 'required|numeric|min:0',
            'recipe.lines.*.unit' => 'required|string|max:20',
            'sizes' => 'sometimes|array',
            'modifier_group_ids' => 'sometimes|array',
        ]);

        $menuItem = $this->createCommand->execute(
            MenuItemData::fromArray($validated)
        );

        return response()->json([
            'data' => MenuItemData::fromEntity($menuItem),
        ], 201);
    }

    // ... update, delete, etc.
}
```

---

## Testing Strategy

### Unit Tests (60 tests minimum)

**Domain Entity Tests:**
```php
// tests/Unit/Catalog/MenuItemTest.php
it('calculates price with size and modifiers correctly')
it('validates required modifier selections')
it('throws exception when required modifier missing')

// tests/Unit/Catalog/RecipeTest.php
it('calculates theoretical cost correctly')
it('scales recipe by multiplier')
it('applies ADD modifier correctly')
it('applies SUBSTITUTE modifier correctly')
```

**Service Tests:**
```php
// tests/Unit/Catalog/RecipeServiceTest.php
it('calculates COGS for basic recipe')
it('calculates COGS with size multiplier')
it('applies modifiers to recipe')
it('generates ingredient shopping list')
```

---

### Integration Tests (20 tests minimum)

**Repository Tests:**
```php
// tests/Integration/Catalog/MenuItemRepositoryTest.php
it('saves menu item with recipe and modifiers')
it('retrieves menu item with eager-loaded relationships')
it('filters menu items by category')
it('soft deletes menu item and cascades to recipe')
```

---

### Feature Tests (30 tests minimum)

**API Endpoint Tests:**
```php
// tests/Feature/API/MenuItemControllerTest.php
it('lists all menu items')
it('filters menu items by category')
it('creates menu item with recipe')
it('creates menu item with sizes and modifiers')
it('validates required fields')
it('returns 404 for non-existent menu item')
it('updates menu item price')
it('soft deletes menu item')
```

---

## Quality Gates

### Before Committing

```bash
# Run all tests
composer test

# Static analysis
./vendor/bin/phpstan analyse --level=8

# Code formatting
./vendor/bin/pint

# Generate types for frontend
php artisan typescript:transform
```

### Phase 1 Completion Criteria

- [ ] All 8 migrations written and tested (up + down)
- [ ] All domain entities implemented with business logic
- [ ] All repositories implemented with proper eager loading
- [ ] All services implemented with unit tests
- [ ] All API controllers implemented with feature tests
- [ ] 90%+ code coverage on domain layer
- [ ] PHPStan level 8 passes with zero errors
- [ ] No `mixed` or `any` types anywhere
- [ ] API documentation generated
- [ ] Migration rollback tested successfully

---

## Dependencies & Risks

### Dependencies on Existing Modules

- ✅ Product module (for `products` table)
- ✅ Inventory module (for stock tracking)
- ✅ Pricing module (for price calculations)
- ✅ Taxation module (for tax application)

### Risks

| Risk | Mitigation |
|------|------------|
| Product table becomes bloated | Add indexes, monitor query performance |
| Recipe calculation performance | Cache theoretical costs, optimize queries |
| Complex modifier validation | Write comprehensive test suite |
| Migration conflicts with existing data | Test migrations on copy of production data |

---

## Next Steps

After Phase 1 completion:
1. Deploy to staging environment
2. Seed test data (10 ingredients, 5 menu items, 3 modifier groups)
3. Manual testing of all CRUD operations
4. Performance testing with 1000+ menu items
5. Begin Phase 2: POS Integration

---

*Phase 1 estimated completion: End of Week 2*
