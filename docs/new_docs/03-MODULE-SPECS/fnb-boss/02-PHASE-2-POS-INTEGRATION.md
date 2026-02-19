# F&B Boss - Phase 2: POS Integration

**Phase:** 2 of 4
**Duration:** 2 weeks (80 hours)
**Status:** Planning
**Dependencies:** Phase 1 complete (data model + API endpoints)

---

## Phase Overview

### Goals

1. Build F&B-optimized POS user interface
2. Implement menu item selection with category navigation
3. Create modifier selection modal with validation
4. Integrate cart with recipe-aware pricing
5. Add consumption mode selection
6. Generate receipts with modifier details
7. Achieve seamless UX for coffee shop/café operations

### Deliverables

- [ ] 8 new React components for F&B POS
- [ ] Menu category grid with touch-optimized layout
- [ ] Modifier selection modal with validation rules
- [ ] Recipe-aware cart component
- [ ] Consumption mode toggle
- [ ] Receipt generation with detailed modifier breakdown
- [ ] 40+ component tests
- [ ] E2E test suite for complete sale flow

---

## UI/UX Design

### Screen Layout

The F&B POS uses a **two-panel layout** optimized for touchscreens:

```
┌────────────────────────────────────────────────────────────────┐
│  HEADER: Café Boss | Terminal: POS01 | Cashier: Marie         │
├──────────────────────────────┬─────────────────────────────────┤
│                              │                                 │
│  CATEGORY TABS               │  CART PANEL                     │
│  [Hot Drinks] [Cold] [Food]  │                                 │
│                              │  Order #42                      │
│  ┌──────┬──────┬──────┐      │  ┌─────────────────────────┐   │
│  │ Espre│Cappuc│Latte │      │  │ 1× Large Cappuccino     │   │
│  │ €2.50│ €3.50│€4.00 │      │  │    + Oat Milk (+€0.50)  │   │
│  │      │      │      │      │  │    + Extra Shot (+€0.80)│   │
│  └──────┴──────┴──────┘      │  │    = €5.80              │   │
│                              │  └─────────────────────────┘   │
│  ┌──────┬──────┬──────┐      │                                 │
│  │America│Moch │Flat  │      │  ┌─────────────────────────┐   │
│  │ €2.80│€4.20│€4.50 │      │  │ 2× Croissant            │   │
│  │      │     │      │      │  │    = €3.00              │   │
│  └──────┴──────┴──────┘      │  └─────────────────────────┘   │
│                              │                                 │
│  SEARCH: [_____________]     │  ─────────────────────────      │
│                              │  Subtotal:         €8.80        │
│                              │  Tax (10%):        €0.88        │
│                              │  ─────────────────────────      │
│                              │  TOTAL:            €9.68        │
│                              │                                 │
│                              │  🔵 Sur Place  ⚪ À Emporter   │
│                              │                                 │
│                              │  [Clear Cart] [💳 Pay]          │
└──────────────────────────────┴─────────────────────────────────┘
```

---

## Component Architecture

### Component Tree

```
FnBPOS/
├── FnBPOSLayout.tsx              (Main container)
│   ├── FnBHeader.tsx             (Terminal info, cashier, time)
│   ├── FnBMenuPanel.tsx          (Left panel - menu items)
│   │   ├── CategoryTabs.tsx
│   │   ├── MenuItemGrid.tsx
│   │   │   └── MenuItemCard.tsx
│   │   └── QuickSearch.tsx
│   │
│   └── FnBCartPanel.tsx          (Right panel - cart)
│       ├── CartLineItem.tsx
│       ├── ConsumptionModeToggle.tsx
│       ├── CartTotals.tsx
│       └── CartActions.tsx
│
├── Modals/
│   ├── MenuItemCustomizationModal.tsx
│   │   ├── SizeSelector.tsx
│   │   └── ModifierGroupSelector.tsx
│   │       └── ModifierOptionButton.tsx
│   │
│   └── PaymentModal.tsx          (Reuse from generic POS)
│
└── Hooks/
    ├── useMenuItems.ts
    ├── useModifiers.ts
    ├── useFnBCart.ts
    └── useRecipePricing.ts
```

---

## Component Specifications

### 1. FnBPOSLayout

**File:** `apps/web/src/features/pos/components/fnb/FnBPOSLayout.tsx`

**Purpose:** Main container managing F&B POS state and layout

**Props:**
```typescript
interface FnBPOSLayoutProps {
  terminal: Terminal
  cashier: User
  onComplete: (receipt: Receipt) => void
}
```

**State:**
```typescript
interface FnBPOSState {
  cart: CartItem[]
  selectedCategory: Category | null
  consumptionMode: 'SUR_PLACE' | 'A_EMPORTER'
  isCustomizationModalOpen: boolean
  selectedMenuItem: MenuItem | null
}
```

**Key Methods:**
```typescript
const addToCart = (item: MenuItem, customization: ItemCustomization) => void
const removeFromCart = (lineId: string) => void
const clearCart = () => void
const updateQuantity = (lineId: string, quantity: number) => void
const proceedToPayment = () => void
```

---

### 2. CategoryTabs

**File:** `apps/web/src/features/pos/components/fnb/CategoryTabs.tsx`

**Purpose:** Horizontal tab navigation for menu categories

**Design:**
```tsx
export function CategoryTabs({ categories, selectedId, onSelect }: Props) {
  return (
    <div className="flex space-x-2 overflow-x-auto border-b pb-2">
      {categories.map(category => (
        <button
          key={category.id}
          onClick={() => onSelect(category.id)}
          className={cn(
            "px-6 py-3 rounded-t-lg font-medium whitespace-nowrap",
            "transition-colors min-w-[120px]",
            selectedId === category.id
              ? "bg-blue-600 text-white"
              : "bg-gray-200 text-gray-700 hover:bg-gray-300"
          )}
        >
          {category.name}
          <span className="ml-2 text-sm opacity-75">
            ({category.itemCount})
          </span>
        </button>
      ))}
    </div>
  )
}
```

**Features:**
- Horizontal scrolling for many categories
- Badge showing item count per category
- Active state highlighting
- Touch-optimized tap targets (min 44px height)

---

### 3. MenuItemCard

**File:** `apps/web/src/features/pos/components/fnb/MenuItemCard.tsx`

**Purpose:** Touchscreen-optimized button for selecting menu items

**Design:**
```tsx
interface MenuItemCardProps {
  item: MenuItem
  onClick: (item: MenuItem) => void
}

export function MenuItemCard({ item, onClick }: MenuItemCardProps) {
  const { t } = useTranslation(['common', 'fnb'])

  return (
    <button
      onClick={() => onClick(item)}
      disabled={!item.isAvailable}
      className={cn(
        "relative flex flex-col items-center justify-between",
        "p-4 rounded-lg border-2 transition-all",
        "min-h-[140px] w-full", // Touch-optimized size
        item.isAvailable
          ? "border-gray-300 bg-white hover:border-blue-500 hover:shadow-lg active:scale-95"
          : "border-gray-200 bg-gray-100 opacity-50 cursor-not-allowed"
      )}
    >
      {/* Image */}
      {item.image ? (
        <img
          src={item.image}
          alt={item.name}
          className="w-16 h-16 object-cover rounded-md mb-2"
        />
      ) : (
        <div className="w-16 h-16 bg-gray-200 rounded-md mb-2 flex items-center justify-center">
          <Coffee className="w-8 h-8 text-gray-400" />
        </div>
      )}

      {/* Name */}
      <span className="font-medium text-center text-sm line-clamp-2">
        {item.name}
      </span>

      {/* Price */}
      <span className="text-lg font-bold text-blue-600 mt-auto">
        {formatMoney(item.basePrice)}
      </span>

      {/* Badges */}
      <div className="absolute top-2 right-2 flex flex-col gap-1">
        {item.hasModifiers && (
          <Badge variant="secondary" size="sm">
            <Settings className="w-3 h-3" />
          </Badge>
        )}
        {item.hasSizes && (
          <Badge variant="secondary" size="sm">
            S/M/L
          </Badge>
        )}
        {!item.isAvailable && (
          <Badge variant="destructive" size="sm">
            {t('common.unavailable')}
          </Badge>
        )}
      </div>
    </button>
  )
}
```

---

### 4. MenuItemCustomizationModal

**File:** `apps/web/src/features/pos/components/fnb/MenuItemCustomizationModal.tsx`

**Purpose:** Modal for selecting size and modifiers before adding to cart

**Flow:**
1. **Size Selection** (if item has sizes)
2. **Required Modifiers** (must select before continuing)
3. **Optional Modifiers** (can skip)
4. **Review & Add** (shows final price)

**Design:**
```tsx
interface CustomizationModalProps {
  item: MenuItem
  isOpen: boolean
  onClose: () => void
  onAddToCart: (customization: ItemCustomization) => void
}

export function MenuItemCustomizationModal({
  item,
  isOpen,
  onClose,
  onAddToCart
}: CustomizationModalProps) {
  const { t } = useTranslation(['fnb'])
  const [selectedSize, setSelectedSize] = useState<MenuItemSize | null>(
    item.sizes.find(s => s.isDefault) ?? null
  )
  const [selectedModifiers, setSelectedModifiers] = useState<Map<string, ModifierOption[]>>(
    new Map()
  )

  // Validate required modifiers
  const canAddToCart = useMemo(() => {
    return item.modifierGroups.every(group => {
      if (!group.isRequired) return true

      const selections = selectedModifiers.get(group.id) ?? []
      return selections.length >= group.minSelections &&
             selections.length <= group.maxSelections
    })
  }, [item.modifierGroups, selectedModifiers])

  // Calculate final price
  const finalPrice = useMemo(() => {
    let price = item.basePrice

    if (selectedSize) {
      price = price + selectedSize.priceAdjustment
    }

    Array.from(selectedModifiers.values())
      .flat()
      .forEach(modifier => {
        price = price + modifier.priceAdjustment
      })

    return price
  }, [item.basePrice, selectedSize, selectedModifiers])

  const handleAddToCart = () => {
    onAddToCart({
      menuItem: item,
      size: selectedSize,
      modifiers: Array.from(selectedModifiers.values()).flat(),
      finalPrice
    })
    onClose()
  }

  return (
    <Dialog open={isOpen} onClose={onClose} size="large">
      <DialogHeader>
        <DialogTitle>{item.name}</DialogTitle>
        <DialogDescription>
          {t('fnb.customizeYourOrder')}
        </DialogDescription>
      </DialogHeader>

      <DialogBody className="space-y-6">
        {/* Size Selection */}
        {item.hasSizes && (
          <div>
            <h3 className="font-medium mb-3">{t('fnb.selectSize')}</h3>
            <div className="grid grid-cols-3 gap-3">
              {item.sizes.map(size => (
                <SizeOption
                  key={size.id}
                  size={size}
                  isSelected={selectedSize?.id === size.id}
                  onSelect={() => setSelectedSize(size)}
                />
              ))}
            </div>
          </div>
        )}

        {/* Modifier Groups */}
        {item.modifierGroups.map(group => (
          <ModifierGroupSelector
            key={group.id}
            group={group}
            selectedOptions={selectedModifiers.get(group.id) ?? []}
            onSelectionChange={(options) => {
              setSelectedModifiers(prev => {
                const next = new Map(prev)
                next.set(group.id, options)
                return next
              })
            }}
          />
        ))}

        {/* Price Summary */}
        <div className="border-t pt-4">
          <div className="flex justify-between items-center text-lg font-bold">
            <span>{t('fnb.total')}</span>
            <span className="text-blue-600">{formatMoney(finalPrice)}</span>
          </div>
        </div>
      </DialogBody>

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>
          {t('common.cancel')}
        </Button>
        <Button
          variant="primary"
          onClick={handleAddToCart}
          disabled={!canAddToCart}
        >
          {t('fnb.addToOrder')}
        </Button>
      </DialogFooter>
    </Dialog>
  )
}
```

---

### 5. ModifierGroupSelector

**File:** `apps/web/src/features/pos/components/fnb/ModifierGroupSelector.tsx`

**Purpose:** Renders a single modifier group with selection validation

**Design:**
```tsx
interface ModifierGroupSelectorProps {
  group: ModifierGroup
  selectedOptions: ModifierOption[]
  onSelectionChange: (options: ModifierOption[]) => void
}

export function ModifierGroupSelector({
  group,
  selectedOptions,
  onSelectionChange
}: ModifierGroupSelectorProps) {
  const { t } = useTranslation(['fnb'])

  const handleToggle = (option: ModifierOption) => {
    if (group.selectionType === 'SINGLE') {
      // Radio behavior - replace selection
      onSelectionChange([option])
    } else {
      // Checkbox behavior - toggle
      const isSelected = selectedOptions.some(o => o.id === option.id)

      if (isSelected) {
        onSelectionChange(selectedOptions.filter(o => o.id !== option.id))
      } else {
        if (selectedOptions.length < group.maxSelections) {
          onSelectionChange([...selectedOptions, option])
        }
      }
    }
  }

  const selectionCount = selectedOptions.length
  const isValid = selectionCount >= group.minSelections &&
                  selectionCount <= group.maxSelections

  return (
    <div className={cn(
      "border rounded-lg p-4",
      group.isRequired && !isValid ? "border-red-500" : "border-gray-300"
    )}>
      {/* Group Header */}
      <div className="flex items-center justify-between mb-3">
        <h3 className="font-medium">
          {group.name}
          {group.isRequired && <span className="text-red-500 ml-1">*</span>}
        </h3>
        <span className="text-sm text-gray-600">
          {group.selectionType === 'SINGLE'
            ? t('fnb.selectOne')
            : t('fnb.selectUpTo', { count: group.maxSelections })}
        </span>
      </div>

      {/* Options Grid */}
      <div className={cn(
        "grid gap-2",
        group.displayStyle === 'GRID' ? "grid-cols-2" : "grid-cols-1"
      )}>
        {group.options.map(option => (
          <ModifierOptionButton
            key={option.id}
            option={option}
            isSelected={selectedOptions.some(o => o.id === option.id)}
            isDisabled={
              !option.isAvailable ||
              (selectionCount >= group.maxSelections &&
               !selectedOptions.some(o => o.id === option.id))
            }
            selectionType={group.selectionType}
            onToggle={() => handleToggle(option)}
          />
        ))}
      </div>

      {/* Validation Message */}
      {group.isRequired && selectionCount < group.minSelections && (
        <p className="text-sm text-red-600 mt-2">
          {t('fnb.mustSelectAtLeast', { count: group.minSelections })}
        </p>
      )}
    </div>
  )
}
```

---

### 6. CartLineItem

**File:** `apps/web/src/features/pos/components/fnb/CartLineItem.tsx`

**Purpose:** Display cart line with modifiers and controls

**Design:**
```tsx
interface CartLineItemProps {
  line: CartLine
  onUpdateQuantity: (lineId: string, quantity: number) => void
  onRemove: (lineId: string) => void
}

export function CartLineItem({ line, onUpdateQuantity, onRemove }: CartLineItemProps) {
  return (
    <div className="border rounded-lg p-3 bg-white">
      {/* Header Row */}
      <div className="flex items-start justify-between mb-2">
        <div className="flex-1">
          <div className="flex items-center gap-2">
            {/* Quantity Controls */}
            <div className="flex items-center border rounded">
              <button
                onClick={() => onUpdateQuantity(line.id, line.quantity - 1)}
                className="px-2 py-1 hover:bg-gray-100"
                disabled={line.quantity <= 1}
              >
                <Minus className="w-4 h-4" />
              </button>
              <span className="px-3 py-1 font-medium">{line.quantity}×</span>
              <button
                onClick={() => onUpdateQuantity(line.id, line.quantity + 1)}
                className="px-2 py-1 hover:bg-gray-100"
              >
                <Plus className="w-4 h-4" />
              </button>
            </div>

            {/* Item Name */}
            <span className="font-medium">{line.menuItem.name}</span>
          </div>

          {/* Size */}
          {line.size && (
            <span className="text-sm text-gray-600 ml-12">
              {line.size.name}
            </span>
          )}

          {/* Modifiers */}
          {line.modifiers.length > 0 && (
            <ul className="ml-12 mt-1 space-y-0.5">
              {line.modifiers.map(modifier => (
                <li key={modifier.id} className="text-sm text-gray-600">
                  + {modifier.name}
                  {modifier.priceAdjustment !== 0 && (
                    <span className="ml-1">
                      ({formatMoney(modifier.priceAdjustment, { showSign: true })})
                    </span>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>

        {/* Price & Remove */}
        <div className="flex items-start gap-2">
          <span className="font-bold">
            {formatMoney(line.totalPrice)}
          </span>
          <button
            onClick={() => onRemove(line.id)}
            className="text-red-600 hover:text-red-700 p-1"
          >
            <Trash2 className="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>
  )
}
```

---

### 7. ConsumptionModeToggle

**File:** `apps/web/src/features/pos/components/fnb/ConsumptionModeToggle.tsx`

**Purpose:** Toggle between "Sur Place" and "À Emporter"

**Design:**
```tsx
interface ConsumptionModeToggleProps {
  mode: ConsumptionMode
  onChange: (mode: ConsumptionMode) => void
  affectsTax: boolean
}

export function ConsumptionModeToggle({
  mode,
  onChange,
  affectsTax
}: ConsumptionModeToggleProps) {
  const { t } = useTranslation(['fnb'])

  return (
    <div className="flex items-center gap-3">
      <span className="text-sm font-medium text-gray-700">
        {t('fnb.consumptionMode')}:
      </span>

      <div className="flex border rounded-lg overflow-hidden">
        <button
          onClick={() => onChange('SUR_PLACE')}
          className={cn(
            "px-4 py-2 font-medium transition-colors",
            mode === 'SUR_PLACE'
              ? "bg-blue-600 text-white"
              : "bg-white text-gray-700 hover:bg-gray-50"
          )}
        >
          🪑 {t('fnb.surPlace')}
        </button>

        <button
          onClick={() => onChange('A_EMPORTER')}
          className={cn(
            "px-4 py-2 font-medium transition-colors border-l",
            mode === 'A_EMPORTER'
              ? "bg-blue-600 text-white"
              : "bg-white text-gray-700 hover:bg-gray-50"
          )}
        >
          🥡 {t('fnb.aEmporter')}
        </button>
      </div>

      {affectsTax && (
        <Tooltip content={t('fnb.consumptionModeAffectsTax')}>
          <Info className="w-4 h-4 text-gray-400" />
        </Tooltip>
      )}
    </div>
  )
}
```

---

## Data Fetching Hooks

### useFnBCart Hook

**File:** `apps/web/src/features/pos/hooks/useFnBCart.ts`

**Purpose:** Manage F&B cart state with recipe-aware pricing

```typescript
interface CartLine {
  id: string
  menuItem: MenuItem
  size: MenuItemSize | null
  modifiers: ModifierOption[]
  quantity: number
  unitPrice: number
  totalPrice: number
}

export function useFnBCart() {
  const [lines, setLines] = useState<CartLine[]>([])
  const [consumptionMode, setConsumptionMode] = useState<ConsumptionMode>('SUR_PLACE')

  const addItem = (customization: ItemCustomization) => {
    const lineId = generateCartLineId(customization)

    // Check if identical item already in cart
    const existingLineIndex = lines.findIndex(line => line.id === lineId)

    if (existingLineIndex >= 0) {
      // Increment quantity
      const updatedLines = [...lines]
      updatedLines[existingLineIndex].quantity += 1
      updatedLines[existingLineIndex].totalPrice =
        updatedLines[existingLineIndex].unitPrice *
        updatedLines[existingLineIndex].quantity
      setLines(updatedLines)
    } else {
      // Add new line
      const newLine: CartLine = {
        id: lineId,
        menuItem: customization.menuItem,
        size: customization.size,
        modifiers: customization.modifiers,
        quantity: 1,
        unitPrice: customization.finalPrice,
        totalPrice: customization.finalPrice,
      }
      setLines([...lines, newLine])
    }
  }

  const updateQuantity = (lineId: string, quantity: number) => {
    if (quantity <= 0) {
      removeItem(lineId)
      return
    }

    setLines(lines.map(line =>
      line.id === lineId
        ? { ...line, quantity, totalPrice: line.unitPrice * quantity }
        : line
    ))
  }

  const removeItem = (lineId: string) => {
    setLines(lines.filter(line => line.id !== lineId))
  }

  const clearCart = () => {
    setLines([])
  }

  // Calculate totals
  const subtotal = lines.reduce((sum, line) => sum + line.totalPrice, 0)
  const tax = calculateTax(subtotal, consumptionMode)
  const total = subtotal + tax

  return {
    lines,
    consumptionMode,
    setConsumptionMode,
    addItem,
    updateQuantity,
    removeItem,
    clearCart,
    subtotal,
    tax,
    total,
    itemCount: lines.reduce((sum, line) => sum + line.quantity, 0),
  }
}
```

---

## API Integration

### Menu Items API

**Endpoint:** `GET /api/v1/menu-items`

**Query Parameters:**
```typescript
interface MenuItemsQueryParams {
  category_id?: string
  is_active?: boolean
  search?: string
  include?: 'sizes,modifierGroups,recipe'
}
```

**Response:**
```typescript
interface MenuItemsResponse {
  data: MenuItem[]
}
```

---

### Create Receipt with Modifiers

**Endpoint:** `POST /api/v1/pos/receipts`

**Request Body:**
```typescript
interface CreateReceiptRequest {
  terminal_id: string
  cashier_id: string
  consumption_mode: 'SUR_PLACE' | 'A_EMPORTER'
  lines: Array<{
    menu_item_id: string
    quantity: number
    selected_size_id?: string
    selected_modifiers: Array<{
      modifier_group_id: string
      modifier_group_name: string
      modifier_option_id: string
      modifier_option_name: string
      price_adjustment: number
    }>
  }>
  payments: Payment[]
}
```

---

## Testing Strategy

### Component Tests

**Example: ModifierGroupSelector.test.tsx**
```typescript
describe('ModifierGroupSelector', () => {
  it('renders all modifier options', () => {
    const group = mockModifierGroup({ options: 3 })
    render(<ModifierGroupSelector group={group} ... />)

    expect(screen.getAllByRole('button')).toHaveLength(3)
  })

  it('enforces single selection for radio type', () => {
    const group = mockModifierGroup({ selectionType: 'SINGLE' })
    const onSelectionChange = vi.fn()

    render(<ModifierGroupSelector
      group={group}
      selectedOptions={[]}
      onSelectionChange={onSelectionChange}
    />)

    // Select first option
    fireEvent.click(screen.getByText('Option 1'))
    expect(onSelectionChange).toHaveBeenCalledWith([group.options[0]])

    // Select second option - should replace first
    fireEvent.click(screen.getByText('Option 2'))
    expect(onSelectionChange).toHaveBeenCalledWith([group.options[1]])
  })

  it('enforces max selections for multiple type', () => {
    const group = mockModifierGroup({
      selectionType: 'MULTIPLE',
      maxSelections: 2
    })

    // ... test max selection enforcement
  })

  it('shows validation error for required group with no selection', () => {
    const group = mockModifierGroup({ isRequired: true })

    render(<ModifierGroupSelector
      group={group}
      selectedOptions={[]}
      onSelectionChange={vi.fn()}
    />)

    expect(screen.getByText(/must select at least/i)).toBeInTheDocument()
  })
})
```

---

### E2E Tests

**Example: complete-fnb-sale.spec.ts** (Playwright)
```typescript
test('complete F&B sale with modifiers', async ({ page }) => {
  // Login as cashier
  await loginAsCashier(page)

  // Navigate to F&B POS
  await page.goto('/pos/fnb')

  // Select "Hot Drinks" category
  await page.click('text=Hot Drinks')

  // Click on "Cappuccino"
  await page.click('text=Cappuccino')

  // Customization modal should open
  await expect(page.locator('[role=dialog]')).toBeVisible()

  // Select size "Large"
  await page.click('text=Large')

  // Select milk type "Oat Milk"
  await page.click('text=Oat Milk')

  // Add extra shot
  await page.click('text=Extra Shot')

  // Verify price updated
  await expect(page.locator('[data-testid=total-price]')).toHaveText('€5.80')

  // Add to order
  await page.click('text=Add to Order')

  // Verify cart shows modifier details
  await expect(page.locator('[data-testid=cart]')).toContainText('+ Oat Milk (+€0.50)')
  await expect(page.locator('[data-testid=cart]')).toContainText('+ Extra Shot (+€0.80)')

  // Proceed to payment
  await page.click('[data-testid=pay-button]')

  // Complete payment
  await page.click('text=Cash')
  await page.fill('[name=amount_tendered]', '10')
  await page.click('text=Complete Payment')

  // Receipt should show modifiers
  await expect(page.locator('[data-testid=receipt]')).toContainText('Oat Milk')
  await expect(page.locator('[data-testid=receipt]')).toContainText('Extra Shot')
})
```

---

## Phase 2 Completion Criteria

- [ ] All F&B POS components implemented and tested
- [ ] Menu category navigation working smoothly
- [ ] Modifier selection validates all rules (min/max/required)
- [ ] Cart calculates prices correctly with all customizations
- [ ] Consumption mode toggle updates tax calculation (if enabled)
- [ ] Receipt generation includes full modifier breakdown
- [ ] 90%+ component test coverage
- [ ] E2E tests cover critical sale flows
- [ ] No TypeScript errors in strict mode
- [ ] Performance: Item selection to cart < 500ms

---

## Next Steps

After Phase 2:
1. Deploy to staging
2. User acceptance testing with pilot café
3. Gather feedback on UX flow
4. Optimize performance if needed
5. Begin Phase 3: Advanced Features

---

*Phase 2 estimated completion: End of Week 4*
