# F&B Boss - Phase 3: Advanced Features

**Phase:** 3 of 4
**Duration:** 2 weeks (80 hours)
**Status:** Planning
**Dependencies:** Phase 2 complete (POS Integration)

---

## Phase Overview

### Goals

1. Implement **optional** automatic inventory deduction based on recipes
2. Add consumption mode VAT calculation (France compliance)
3. Build restaurant voucher payment method support
4. Create tenant-level label configuration system
5. Implement COGS calculation and variance reporting
6. Add theoretical vs actual inventory tracking
7. Optimize performance for high-volume operations

### Deliverables

- [ ] Recipe-based automatic stock adjustment service
- [ ] Consumption mode tax calculation engine
- [ ] Restaurant voucher payment processor with daily limits
- [ ] Tenant label override system
- [ ] COGS calculation service with reporting
- [ ] Variance tracking dashboard
- [ ] Performance optimizations (query caching, eager loading)
- [ ] 30+ unit tests for new services
- [ ] Integration tests for inventory flows

---

## Feature 1: Automatic Inventory Deduction

### Overview

When enabled, selling a menu item automatically deducts ingredients from inventory based on the recipe and applied modifiers.

**Feature Flag:** `fnb_auto_inventory` (company-level setting)

**Default:** `false` (opt-in)

---

### Implementation

#### Service: RecipeBasedStockAdjustmentService

**File:** `app/Modules/Inventory/Domain/Services/RecipeBasedStockAdjustmentService.php`

**Responsibilities:**
- Calculate ingredient requirements from menu item sale
- Apply size multiplier to recipe quantities
- Add/remove ingredients based on modifiers
- Create stock adjustment transactions
- Handle insufficient stock scenarios

**Key Method:**
```php
<?php

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\Catalog\Domain\Entities\MenuItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Inventory\Domain\Entities\StockAdjustment;
use App\Modules\Inventory\Domain\Repositories\StockLevelRepository;
use Illuminate\Support\Facades\DB;

class RecipeBasedStockAdjustmentService
{
    public function __construct(
        private readonly StockLevelRepository $stockLevelRepo,
        private readonly RecipeService $recipeService,
    ) {}

    /**
     * Deduct ingredients for a menu item sale
     *
     * @param MenuItem $menuItem
     * @param int $quantity Number of menu items sold
     * @param MenuItemSize|null $size Selected size (affects multiplier)
     * @param ModifierOption[] $modifiers Applied modifiers
     * @param Location $location Stock location
     * @param string $reason Audit trail reason
     * @return StockAdjustment[]
     * @throws InsufficientStockException
     */
    public function deductForSale(
        MenuItem $menuItem,
        int $quantity,
        ?MenuItemSize $size,
        array $modifiers,
        Location $location,
        string $reason
    ): array {
        if (!$menuItem->hasRecipe()) {
            return []; // No recipe = no deduction
        }

        $recipe = $menuItem->getRecipe();

        // Calculate ingredient requirements
        $sizeMultiplier = $size?->getRecipeMultiplier() ?? 1.0;
        $finalRecipeLines = $this->recipeService->applyCustomizations(
            $recipe,
            $size,
            $modifiers
        );

        $adjustments = [];

        DB::transaction(function () use (
            $finalRecipeLines,
            $quantity,
            $sizeMultiplier,
            $location,
            $reason,
            &$adjustments
        ) {
            foreach ($finalRecipeLines as $line) {
                $ingredient = $line->getIngredient();
                $requiredQuantity = $line->getQuantity() * $quantity * $sizeMultiplier;

                // Lock stock level row for update
                $stockLevel = $this->stockLevelRepo->findByProductAndLocation(
                    $ingredient->getId(),
                    $location->getId(),
                    forUpdate: true
                );

                if (!$stockLevel || $stockLevel->getAvailableQuantity() < $requiredQuantity) {
                    throw new InsufficientStockException(
                        ingredient: $ingredient,
                        required: $requiredQuantity,
                        available: $stockLevel?->getAvailableQuantity() ?? 0
                    );
                }

                // Create adjustment
                $adjustment = StockAdjustment::create(
                    product: $ingredient,
                    location: $location,
                    quantityChange: -$requiredQuantity,
                    type: StockAdjustmentType::RECIPE_DEDUCTION,
                    reason: $reason
                );

                $adjustments[] = $adjustment;

                // Update stock level
                $stockLevel->decrement('quantity', $requiredQuantity);
            }
        });

        return $adjustments;
    }

    /**
     * Reverse deduction for void/return
     */
    public function reverseDeduction(
        StockAdjustment $originalAdjustment,
        string $reason
    ): StockAdjustment {
        return StockAdjustment::create(
            product: $originalAdjustment->getProduct(),
            location: $originalAdjustment->getLocation(),
            quantityChange: abs($originalAdjustment->getQuantityChange()),
            type: StockAdjustmentType::RECIPE_REVERSAL,
            reason: $reason,
            relatedAdjustmentId: $originalAdjustment->getId()
        );
    }
}
```

---

#### Integration with POS Receipt Creation

**File:** `app/Modules/POS/Application/Services/ReceiptCreationService.php`

```php
public function createReceipt(CreateReceiptCommand $command): Receipt
{
    return DB::transaction(function () use ($command) {
        // 1. Create receipt (existing logic)
        $receipt = $this->receiptRepo->create($command->toReceiptData());

        // 2. Check if auto-inventory enabled
        if ($this->companyConfig->isFeatureEnabled('fnb_auto_inventory')) {
            // 3. Deduct ingredients for each menu item line
            foreach ($command->getLines() as $lineData) {
                if ($lineData->isMenuItem()) {
                    try {
                        $this->recipeStockService->deductForSale(
                            menuItem: $lineData->getMenuItem(),
                            quantity: $lineData->getQuantity(),
                            size: $lineData->getSelectedSize(),
                            modifiers: $lineData->getSelectedModifiers(),
                            location: $command->getLocation(),
                            reason: "Sale - Receipt {$receipt->getReceiptNumber()}"
                        );
                    } catch (InsufficientStockException $e) {
                        // Rollback transaction
                        throw new CannotCompleteSaleException(
                            "Insufficient stock for {$e->getIngredient()->getName()}: " .
                            "required {$e->getRequired()}, available {$e->getAvailable()}"
                        );
                    }
                }
            }
        }

        return $receipt;
    });
}
```

---

### Configuration UI

**Company Settings:** `apps/web/src/features/settings/CompanySettings.tsx`

```tsx
<FormSection title={t('settings.fnb.inventoryManagement')}>
  <Switch
    label={t('settings.fnb.autoInventoryDeduction')}
    description={t('settings.fnb.autoInventoryDeductionDesc')}
    checked={settings.features.fnb_auto_inventory}
    onCheckedChange={(checked) =>
      updateSettings({ features: { fnb_auto_inventory: checked } })
    }
  />

  {settings.features.fnb_auto_inventory && (
    <Alert variant="info">
      <AlertCircle className="h-4 w-4" />
      <AlertDescription>
        {t('settings.fnb.autoInventoryWarning')}
      </AlertDescription>
    </Alert>
  )}
</FormSection>
```

---

## Feature 2: Consumption Mode VAT Calculation

### Overview

In France and some other countries, VAT rates differ based on consumption mode:
- **Sur Place** (dine-in): Standard VAT (10% for food, 20% for alcohol)
- **À Emporter** (takeaway): Reduced VAT (5.5% for non-alcoholic beverages)

---

### Implementation

#### Service: ConsumptionModeTaxCalculator

**File:** `app/Modules/Taxation/Domain/Services/ConsumptionModeTaxCalculator.php`

```php
<?php

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Catalog\Domain\Entities\MenuItem;
use App\Modules\POS\Domain\ValueObjects\ConsumptionMode;
use Money\Money;

class ConsumptionModeTaxCalculator
{
    public function __construct(
        private readonly TaxRuleRepository $taxRuleRepo,
        private readonly CompanyConfigService $companyConfig,
    ) {}

    /**
     * Calculate tax based on item and consumption mode
     */
    public function calculateTax(
        MenuItem $menuItem,
        Money $netAmount,
        ConsumptionMode $mode
    ): Money {
        // Check if consumption mode affects tax for this country
        if (!$this->companyConfig->consumptionModeAffectsTax()) {
            return $this->calculateStandardTax($menuItem, $netAmount);
        }

        // Get applicable tax rate based on item category and mode
        $taxRate = $this->getTaxRate($menuItem, $mode);

        return $netAmount->multiply((string) ($taxRate / 100));
    }

    private function getTaxRate(MenuItem $menuItem, ConsumptionMode $mode): float
    {
        $category = $menuItem->getCategory();
        $taxCategory = $menuItem->getTaxCategory();

        // France-specific logic (example)
        if ($this->companyConfig->getCountry() === 'FR') {
            return match([$category->getType(), $mode]) {
                ['beverage_non_alcoholic', ConsumptionMode::A_EMPORTER] => 5.5,
                ['beverage_non_alcoholic', ConsumptionMode::SUR_PLACE] => 10.0,
                ['food', ConsumptionMode::A_EMPORTER] => 10.0,
                ['food', ConsumptionMode::SUR_PLACE] => 10.0,
                ['beverage_alcoholic', _] => 20.0,
                default => 10.0,
            };
        }

        // Default: use standard tax rate
        return $this->taxRuleRepo->getRate($taxCategory);
    }
}
```

---

#### Update Receipt Tax Calculation

**File:** `app/Modules/POS/Application/Services/ReceiptTaxCalculationService.php`

```php
public function calculateReceiptTotals(
    array $lines,
    ConsumptionMode $consumptionMode
): ReceiptTotals {
    $subtotal = Money::USD(0);
    $taxDetails = [];

    foreach ($lines as $line) {
        $lineSubtotal = $line->getUnitPrice()->multiply((string) $line->getQuantity());
        $subtotal = $subtotal->add($lineSubtotal);

        if ($line->isMenuItem()) {
            $lineTax = $this->consumptionModeTaxCalculator->calculateTax(
                menuItem: $line->getMenuItem(),
                netAmount: $lineSubtotal,
                mode: $consumptionMode
            );
        } else {
            $lineTax = $this->standardTaxCalculator->calculateTax(
                product: $line->getProduct(),
                netAmount: $lineSubtotal
            );
        }

        // Accumulate by tax category
        $taxCategory = $line->getTaxCategory();
        if (!isset($taxDetails[$taxCategory])) {
            $taxDetails[$taxCategory] = [
                'net' => Money::USD(0),
                'tax' => Money::USD(0),
            ];
        }

        $taxDetails[$taxCategory]['net'] = $taxDetails[$taxCategory]['net']->add($lineSubtotal);
        $taxDetails[$taxCategory]['tax'] = $taxDetails[$taxCategory]['tax']->add($lineTax);
    }

    $totalTax = array_reduce(
        $taxDetails,
        fn($sum, $detail) => $sum->add($detail['tax']),
        Money::USD(0)
    );

    $total = $subtotal->add($totalTax);

    return new ReceiptTotals($subtotal, $totalTax, $total, $taxDetails);
}
```

---

### Country Configuration

**Database:** `companies` table, `config` JSONB column

```json
{
  "country": "FR",
  "features": {
    "fnb_auto_inventory": true,
    "fnb_consumption_mode_affects_tax": true
  },
  "tax_rules": {
    "consumption_mode_enabled": true,
    "beverage_non_alcoholic": {
      "SUR_PLACE": 10.0,
      "A_EMPORTER": 5.5
    },
    "food": {
      "SUR_PLACE": 10.0,
      "A_EMPORTER": 10.0
    },
    "beverage_alcoholic": {
      "SUR_PLACE": 20.0,
      "A_EMPORTER": 20.0
    }
  }
}
```

---

## Feature 3: Restaurant Voucher Payment Method

### Overview

Support for **Titres-Restaurant** (Swile, Edenred, etc.) with compliance rules:
- Daily limit per card (e.g., €25)
- Working days only (configurable)
- Eligible items only (food, not merchandise)
- No change given for paper vouchers

---

### Database Extension

**Migration:** `2026_01_15_100000_add_voucher_fields_to_payment_methods.php`

```sql
ALTER TABLE payment_methods
ADD COLUMN voucher_provider VARCHAR(50),         -- SWILE, EDENRED, UP, etc.
ADD COLUMN voucher_daily_limit DECIMAL(12,2),   -- Max usage per day
ADD COLUMN voucher_working_days_only BOOLEAN DEFAULT false,
ADD COLUMN voucher_eligible_categories JSONB;   -- Array of eligible category IDs

COMMENT ON COLUMN payment_methods.voucher_eligible_categories IS
'JSON array of product/menu category IDs eligible for voucher payment';
```

---

### Service: VoucherPaymentValidator

**File:** `app/Modules/Treasury/Domain/Services/VoucherPaymentValidator.php`

```php
<?php

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Treasury\Domain\Entities\PaymentMethod;
use App\Modules\Treasury\Domain\Exceptions\VoucherValidationException;
use Carbon\Carbon;
use Money\Money;

class VoucherPaymentValidator
{
    public function __construct(
        private readonly VoucherUsageRepository $usageRepo,
    ) {}

    /**
     * Validate voucher payment
     *
     * @throws VoucherValidationException
     */
    public function validate(
        PaymentMethod $method,
        Money $amount,
        array $cartLines,
        string $voucherSerial
    ): void {
        // 1. Check if today is eligible day
        if ($method->isWorkingDaysOnly() && !$this->isWorkingDay()) {
            throw VoucherValidationException::notWorkingDay();
        }

        // 2. Check daily limit
        $todayUsage = $this->usageRepo->getTodayUsage($voucherSerial);
        $projectedUsage = $todayUsage->add($amount);

        if ($projectedUsage->greaterThan($method->getVoucherDailyLimit())) {
            throw VoucherValidationException::dailyLimitExceeded(
                limit: $method->getVoucherDailyLimit(),
                current: $todayUsage,
                attempted: $amount
            );
        }

        // 3. Check eligible items
        $eligibleCategories = $method->getVoucherEligibleCategories();
        $ineligibleItems = [];

        foreach ($cartLines as $line) {
            $categoryId = $line->getMenuItem()
                ? $line->getMenuItem()->getCategory()->getId()
                : $line->getProduct()->getCategory()->getId();

            if (!in_array($categoryId, $eligibleCategories)) {
                $ineligibleItems[] = $line->getName();
            }
        }

        if (!empty($ineligibleItems)) {
            throw VoucherValidationException::ineligibleItems($ineligibleItems);
        }
    }

    private function isWorkingDay(): bool
    {
        $today = Carbon::now();

        // Check if Sunday
        if ($today->isSunday()) {
            return false;
        }

        // Check if public holiday (would need holiday calendar service)
        // For MVP, just check Sunday
        return true;
    }
}
```

---

### Frontend: Voucher Payment Form

**File:** `apps/web/src/features/pos/components/VoucherPaymentForm.tsx`

```tsx
interface VoucherPaymentFormProps {
  method: PaymentMethod
  amount: number
  onSubmit: (data: VoucherPaymentData) => void
}

export function VoucherPaymentForm({
  method,
  amount,
  onSubmit
}: VoucherPaymentFormProps) {
  const { t } = useTranslation(['treasury'])
  const [serial, setSerial] = useState('')
  const [error, setError] = useState<string | null>(null)

  // Query today's usage for this voucher
  const { data: usage, isLoading } = useQuery({
    queryKey: ['voucher-usage', serial],
    queryFn: () => fetchVoucherUsage(serial),
    enabled: serial.length >= 10,
  })

  const remainingLimit = method.voucherDailyLimit - (usage?.todayTotal ?? 0)
  const canPayAmount = amount <= remainingLimit

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!canPayAmount) {
      setError(
        t('treasury.voucher.dailyLimitExceeded', {
          limit: formatMoney(method.voucherDailyLimit),
          used: formatMoney(usage?.todayTotal ?? 0),
        })
      )
      return
    }

    onSubmit({
      voucherProvider: method.voucherProvider,
      voucherSerial: serial,
      amount,
    })
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <Label htmlFor="voucher-serial">
          {t('treasury.voucher.serialNumber')}
        </Label>
        <Input
          id="voucher-serial"
          value={serial}
          onChange={(e) => setSerial(e.target.value)}
          placeholder="1234-5678-9012-3456"
          required
          autoFocus
        />
      </div>

      {serial && usage && (
        <Alert variant={canPayAmount ? 'info' : 'warning'}>
          <AlertDescription>
            {t('treasury.voucher.dailyUsage')}:<br />
            {t('treasury.voucher.usedToday')}: {formatMoney(usage.todayTotal)}<br />
            {t('treasury.voucher.remaining')}: {formatMoney(remainingLimit)}
          </AlertDescription>
        </Alert>
      )}

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <Button type="submit" disabled={!canPayAmount || isLoading}>
        {t('treasury.processPayment')}
      </Button>
    </form>
  )
}
```

---

## Feature 4: Tenant Label Configuration

### Overview

Allow tenants to customize UI labels (e.g., "Product" → "Ingredient", "Menu Item" → "Dish").

---

### Database: Tenant Settings

**Table:** `tenant_settings` (JSONB column in `tenants` table)

```json
{
  "label_overrides": {
    "product": {
      "singular": "Ingredient",
      "plural": "Ingredients"
    },
    "menu_item": {
      "singular": "Dish",
      "plural": "Dishes"
    },
    "recipe": {
      "singular": "Recipe",
      "plural": "Recipes"
    }
  }
}
```

---

### Frontend: Label Service

**File:** `apps/web/src/lib/labels.ts`

```typescript
interface LabelConfig {
  [key: string]: {
    singular: string
    plural: string
  }
}

const DEFAULT_LABELS: LabelConfig = {
  product: { singular: 'Product', plural: 'Products' },
  menu_item: { singular: 'Menu Item', plural: 'Menu Items' },
  ingredient: { singular: 'Ingredient', plural: 'Ingredients' },
  recipe: { singular: 'Recipe', plural: 'Recipes' },
}

export function useLabels() {
  const { data: tenant } = useTenantSettings()
  const overrides = tenant?.settings?.label_overrides ?? {}

  const getLabel = (key: string, plural = false): string => {
    const override = overrides[key]
    const defaultLabel = DEFAULT_LABELS[key]

    if (override) {
      return plural ? override.plural : override.singular
    }

    if (defaultLabel) {
      return plural ? defaultLabel.plural : defaultLabel.singular
    }

    // Fallback
    return plural ? `${key}s` : key
  }

  return { getLabel }
}
```

**Usage:**
```tsx
function MenuItemList() {
  const { getLabel } = useLabels()

  return (
    <div>
      <h1>{getLabel('menu_item', true)}</h1>
      <Button>Add {getLabel('menu_item')}</Button>
    </div>
  )
}
```

---

## Feature 5: COGS Calculation & Variance Reporting

### Overview

Track theoretical cost (from recipes) vs actual cost (from purchases) to identify:
- Recipe inaccuracies
- Waste/spoilage
- Theft
- Portion control issues

---

### Service: COGSCalculationService

**File:** `app/Modules/Catalog/Application/Services/COGSCalculationService.php`

```php
public function calculateTheoreticalCOGS(
    MenuItem $menuItem,
    int $quantitySold,
    ?MenuItemSize $size,
    array $modifiers
): Money {
    $recipe = $menuItem->getRecipe();
    $sizeMultiplier = $size?->getRecipeMultiplier() ?? 1.0;

    $unitCost = $recipe->calculateCost($sizeMultiplier);

    // Apply modifier effects
    foreach ($modifiers as $modifier) {
        if ($modifier->hasIngredientEffect()) {
            $effect = $modifier->getIngredientEffect();

            if ($effect->getType() === 'ADD') {
                $ingredientCost = $effect->getIngredient()
                    ->calculateCost($effect->getQuantity());
                $unitCost = $unitCost->add($ingredientCost);
            }
        }
    }

    return $unitCost->multiply((string) $quantitySold);
}
```

---

### Variance Report

**Query:** Calculate variance for a date range

```sql
WITH theoretical AS (
    SELECT
        rl.ingredient_id,
        SUM(rl.quantity * prl.quantity * COALESCE(mis.recipe_multiplier, 1.0)) AS total_quantity,
        SUM(rl.line_cost * prl.quantity * COALESCE(mis.recipe_multiplier, 1.0)) AS total_cost
    FROM pos_receipts pr
    JOIN pos_receipt_lines prl ON prl.receipt_id = pr.id
    JOIN recipes r ON r.menu_item_id = prl.menu_item_id
    JOIN recipe_lines rl ON rl.recipe_id = r.id
    LEFT JOIN menu_item_sizes mis ON mis.id = prl.selected_size_id
    WHERE pr.posted_at BETWEEN '2026-01-01' AND '2026-01-31'
      AND pr.tenant_id = 'xxx'
    GROUP BY rl.ingredient_id
),
actual AS (
    SELECT
        product_id AS ingredient_id,
        SUM(quantity_change) AS total_quantity,
        SUM(quantity_change * unit_cost) AS total_cost
    FROM stock_adjustments
    WHERE adjustment_type = 'RECIPE_DEDUCTION'
      AND created_at BETWEEN '2026-01-01' AND '2026-01-31'
      AND tenant_id = 'xxx'
    GROUP BY product_id
)
SELECT
    p.name AS ingredient_name,
    t.total_quantity AS theoretical_qty,
    a.total_quantity AS actual_qty,
    (a.total_quantity - t.total_quantity) AS variance_qty,
    t.total_cost AS theoretical_cost,
    a.total_cost AS actual_cost,
    (a.total_cost - t.total_cost) AS variance_cost,
    CASE
        WHEN t.total_cost > 0
        THEN ((a.total_cost - t.total_cost) / t.total_cost) * 100
        ELSE 0
    END AS variance_percentage
FROM theoretical t
LEFT JOIN actual a ON a.ingredient_id = t.ingredient_id
JOIN products p ON p.id = t.ingredient_id
ORDER BY ABS(variance_cost) DESC;
```

---

## Performance Optimizations

### Query Caching

**Cache menu items by category:**
```php
public function getMenuItemsByCategory(string $categoryId): Collection
{
    return Cache::tags(['menu_items', "category_{$categoryId}"])
        ->remember("menu_items.category.{$categoryId}", 3600, function () use ($categoryId) {
            return MenuItem::where('category_id', $categoryId)
                ->with(['sizes', 'modifierGroups.options', 'recipe.lines'])
                ->get();
        });
}
```

### Eager Loading

**Load all POS dependencies in one query:**
```php
public function getMenuItemForPOS(string $id): MenuItem
{
    return MenuItem::with([
        'category',
        'sizes' => fn($q) => $q->where('is_available', true)->orderBy('sort_order'),
        'modifierGroups' => fn($q) => $q->where('is_active', true)->orderBy('sort_order'),
        'modifierGroups.options' => fn($q) => $q->where('is_available', true)->orderBy('sort_order'),
        'recipe.lines.ingredient',
    ])->findOrFail($id);
}
```

---

## Phase 3 Completion Criteria

- [ ] Automatic inventory deduction working with feature flag
- [ ] Consumption mode VAT calculation accurate for France
- [ ] Restaurant voucher validation enforces all rules
- [ ] Tenant label configuration applied across UI
- [ ] COGS calculation matches expected values
- [ ] Variance report identifies discrepancies
- [ ] All services have 90%+ test coverage
- [ ] Performance: Menu load < 500ms, Receipt creation < 1s
- [ ] No N+1 query issues

---

## Next Steps

After Phase 3:
1. Performance testing with 100+ concurrent orders
2. Variance report validation with pilot café data
3. User training on advanced features
4. Begin Phase 4: Loyalty Integration

---

*Phase 3 estimated completion: End of Week 6*
