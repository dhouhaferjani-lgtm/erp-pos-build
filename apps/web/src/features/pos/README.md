# POS Feature Module

Complete Point of Sale component library built with Test-Driven Development (TDD) and Atomic Design principles.

## Architecture

### Design Principles

- **Test-First Development**: All components have comprehensive test coverage (191 tests)
- **Atomic Design**: Clear component hierarchy from atoms → molecules → organisms → pages
- **Touch Optimization**: 48px minimum touch targets, larger padding/fonts when enabled
- **TypeScript Strict**: No `any` types, full type safety
- **Separation of Concerns**: Business logic in hooks, components are presentational

### Component Hierarchy

```
Pages (2)
├── POSPage (main transaction interface)
└── ShiftDashboardPage (shift management)

Organisms (3)
├── ProductGrid (product browsing)
├── TransactionCart (cart management)
└── Calculator (floating calculator)

Molecules (2)
├── ProductCard (product display)
└── CartLineItem (cart item)

Atoms (3)
├── POSButton (foundation button)
├── MoneyInput (currency input)
└── StockBadge (stock indicator)
```

## Test Coverage

| Layer | Components | Tests | Status |
|-------|------------|-------|--------|
| Atoms | 3 | 61 | ✅ All passing |
| Molecules | 2 | 37 | ✅ All passing |
| Organisms | 3 | 58 | ✅ All passing |
| Pages | 2 | 35 | ✅ All passing |
| **Total** | **10** | **191** | **✅ 100%** |

## Usage Examples

### Basic POS Page

```tsx
import { POSPage } from '@/features/pos'
import type { Product, CartItem } from '@/features/pos'

function POSRoute() {
  const products: Product[] = [
    {
      id: '1',
      name: 'Oil Filter',
      sku: 'OF-1234',
      price: '15.500',
      stock_quantity: 50,
      category: 'Filters',
    },
  ]

  const handleQuickCheckout = (items: CartItem[]) => {
    console.log('Processing checkout:', items)
    // Create invoice, process payment, etc.
  }

  const handleAdvancedPayments = (items: CartItem[]) => {
    console.log('Opening payment modal:', items)
    // Open split payment modal
  }

  const handleProductInfo = (product: Product) => {
    console.log('Show product details:', product)
    // Open product details modal
  }

  return (
    <POSPage
      products={products}
      onQuickCheckout={handleQuickCheckout}
      onAdvancedPayments={handleAdvancedPayments}
      onProductInfo={handleProductInfo}
      touchOptimized={true} // Enable for tablets
    />
  )
}
```

### Shift Management

```tsx
import { ShiftDashboardPage } from '@/features/pos'
import type { Shift, Terminal } from '@/features/pos'

function ShiftManagementRoute() {
  const terminal: Terminal = {
    id: 'term-1',
    name: 'POS Terminal 1',
    location: 'Main Counter',
  }

  const currentShift: Shift | null = {
    id: 'shift-123',
    shift_number: 42,
    terminal_id: 'term-1',
    cashier_name: 'John Doe',
    opening_cash: '100.000',
    expected_cash: '550.000',
    opened_at: '2026-01-09T08:00:00Z',
    status: 'open',
  }

  const handleOpenShift = async (openingCash: string) => {
    await api.post('/pos/shifts/open', {
      terminal_id: terminal.id,
      opening_cash: openingCash,
    })
  }

  const handleCloseShift = async (actualCash: string) => {
    await api.post(`/pos/shifts/${currentShift?.id}/close`, {
      actual_cash: actualCash,
    })
  }

  const handleCashDeposit = async (amount: string, reason: string) => {
    await api.post('/pos/cash-drawer/deposit', {
      shift_id: currentShift?.id,
      amount,
      reason,
    })
  }

  const handleGenerateXReport = async () => {
    const report = await api.post('/pos/reports/x', {
      terminal_id: terminal.id,
    })
    console.log('X Report:', report)
  }

  return (
    <ShiftDashboardPage
      terminal={terminal}
      currentShift={currentShift}
      onOpenShift={handleOpenShift}
      onCloseShift={handleCloseShift}
      onCashDeposit={handleCashDeposit}
      onCashPayout={async (amt, rsn) => {}}
      onGenerateXReport={handleGenerateXReport}
      touchOptimized={true}
    />
  )
}
```

### Using Individual Components

#### Product Grid Only

```tsx
import { ProductGrid } from '@/features/pos'

function ProductBrowser() {
  const [cartProductIds, setCartProductIds] = useState<string[]>([])

  return (
    <ProductGrid
      products={products}
      onAddToCart={(product) => {
        setCartProductIds((prev) => [...prev, product.id])
      }}
      onShowProductInfo={(product) => {
        // Show modal
      }}
      cartProductIds={cartProductIds}
      showSearch={true}
      showCategoryFilter={true}
      touchOptimized={false}
    />
  )
}
```

#### Transaction Cart Only

```tsx
import { TransactionCart } from '@/features/pos'
import type { CartItem } from '@/features/pos'

function CheckoutSidebar() {
  const [items, setItems] = useState<CartItem[]>([])

  return (
    <TransactionCart
      items={items}
      onUpdateQuantity={(productId, qty) => {
        setItems((prev) =>
          prev.map((item) =>
            item.product.id === productId
              ? { ...item, quantity: qty }
              : item
          )
        )
      }}
      onRemoveItem={(productId) => {
        setItems((prev) =>
          prev.filter((item) => item.product.id !== productId)
        )
      }}
      onQuickCheckout={() => {}}
      onAdvancedPayments={() => {}}
      onOpenCalculator={() => {}}
      onClearCart={() => setItems([])}
    />
  )
}
```

#### Floating Calculator

```tsx
import { Calculator } from '@/features/pos'

function MyPage() {
  const [isOpen, setIsOpen] = useState(false)

  return (
    <>
      <button onClick={() => setIsOpen(true)}>Open Calculator</button>
      <Calculator
        isOpen={isOpen}
        onClose={() => setIsOpen(false)}
        touchOptimized={true}
      />
    </>
  )
}
```

## Component APIs

### POSPage

Main POS transaction interface that composes all organisms.

**Props:**
- `products: Product[]` - Array of products to display
- `onQuickCheckout: (items: CartItem[]) => void` - Quick checkout handler
- `onAdvancedPayments: (items: CartItem[]) => void` - Advanced payments handler
- `onProductInfo: (product: Product) => void` - Product info handler
- `selectedCustomer?: Customer | null` - Currently selected customer
- `onChangeCustomer?: () => void` - Change customer handler
- `touchOptimized?: boolean` - Enable touch optimization (default: false)
- `isLoading?: boolean` - Show loading state (default: false)

**Features:**
- 60/40 split layout (products left, cart right)
- Keyboard shortcuts (Ctrl+K for calculator)
- Real-time cart updates
- Product highlighting when in cart
- Empty/loading states

### ShiftDashboardPage

Shift management interface for cashiers.

**Props:**
- `terminal: Terminal` - Terminal information
- `currentShift: Shift | null` - Current open shift or null
- `onOpenShift: (openingCash: string) => Promise<void>`
- `onCloseShift: (actualCash: string) => Promise<void>`
- `onCashDeposit: (amount: string, reason: string) => Promise<void>`
- `onCashPayout: (amount: string, reason: string) => Promise<void>`
- `onGenerateXReport: () => Promise<void>`
- `touchOptimized?: boolean`

**Features:**
- Open/close shift workflow
- Cash variance calculation
- Shift duration tracking
- Cash drawer operations (deposits, payouts)
- X report generation
- Visual shift status indicators

### ProductGrid

Product browsing with search and filtering.

**Props:**
- `products: Product[]`
- `onAddToCart: (product: Product) => void`
- `onShowProductInfo: (product: Product) => void`
- `cartProductIds: string[]` - IDs of products in cart
- `showSearch?: boolean` (default: true)
- `showCategoryFilter?: boolean` (default: true)
- `showProductCount?: boolean` (default: true)
- `touchOptimized?: boolean`

**Features:**
- Real-time search (by name or SKU)
- Category filtering
- Responsive grid layout
- Stock level indicators
- "Added" badges for items in cart
- Empty/loading states

### TransactionCart

Cart management with checkout actions.

**Props:**
- `items: CartItem[]`
- `onUpdateQuantity: (productId: string, newQuantity: number) => void`
- `onRemoveItem: (productId: string) => void`
- `onQuickCheckout: () => void`
- `onAdvancedPayments: () => void`
- `onOpenCalculator?: () => void`
- `selectedCustomer?: Customer | null`
- `onChangeCustomer?: () => void`
- `onClearCart?: () => void`
- `touchOptimized?: boolean`

**Features:**
- Real-time totals calculation (subtotal, tax, total)
- Quantity controls (increment/decrement)
- Remove items
- Customer selection display
- Clear cart button
- Calculator button
- Empty cart state

### Calculator

Floating calculator modal.

**Props:**
- `isOpen: boolean`
- `onClose: () => void`
- `touchOptimized?: boolean`

**Features:**
- All basic operations (+, -, ×, ÷)
- Decimal support
- Percentage calculation
- Backspace/clear
- Chained operations
- Division by zero handling
- Resets on close

## Types

### Product

```typescript
interface Product {
  id: string
  name: string
  sku: string
  price: string // TND with 3 decimals (e.g., "15.500")
  stock_quantity: number
  category?: string
  image_url?: string
}
```

### CartItem

```typescript
interface CartItem {
  id: string // Unique cart item ID
  product: {
    id: string
    name: string
    sku: string
    price: string
  }
  quantity: number
  unit_price: string
  line_total: string
  tax_amount?: string
}
```

### Customer

```typescript
interface Customer {
  id: string
  name: string
  phone?: string
}
```

### Shift

```typescript
interface Shift {
  id: string
  shift_number: number
  terminal_id: string
  cashier_name: string
  opening_cash: string
  expected_cash?: string
  actual_cash?: string
  variance?: string
  opened_at: string // ISO 8601
  closed_at?: string // ISO 8601
  status: 'open' | 'closed'
}
```

### Terminal

```typescript
interface Terminal {
  id: string
  name: string
  location: string
}
```

## Touch Optimization

All components support a `touchOptimized` prop that enables tablet/mobile-friendly UI:

- **Touch Targets**: Minimum 48px tap areas
- **Font Sizes**: Larger text for readability
- **Padding**: Increased spacing for easier tapping
- **Button Sizes**: Larger buttons with more padding

Enable for tablet POS installations:
```tsx
<POSPage touchOptimized={true} {...props} />
```

## Keyboard Shortcuts

### POSPage
- `Ctrl+K` / `Cmd+K` - Open calculator

### Calculator
- `0-9` - Number input
- `+`, `-`, `×`, `÷` - Operations
- `.` - Decimal point
- `%` - Percentage
- `Enter` / `=` - Equals
- `Backspace` - Delete last digit
- `C` - Clear
- `Esc` - Close calculator

## Testing

### Run All POS Tests

```bash
npm test -- src/features/pos --run
```

### Run Specific Component Tests

```bash
# Atoms
npm test -- src/features/pos/atoms/POSButton.test.tsx --run

# Molecules
npm test -- src/features/pos/molecules/ProductCard --run

# Organisms
npm test -- src/features/pos/organisms/ProductGrid --run

# Pages
npm test -- src/features/pos/pages/POSPage --run
```

### Test Coverage

All components have:
- ✅ Unit tests for all props and behaviors
- ✅ User interaction tests (click, type, etc.)
- ✅ Edge case handling
- ✅ Accessibility (aria-labels, roles)
- ✅ Touch optimization variants
- ✅ Loading/empty states

## Implementation Notes

### Currency Format

All prices use Tunisian Dinar (TND) with 3 decimal places:
- Input: `"15.500"`
- Display: `"15.500 TND"`
- Validation: Max 3 decimal places

### Cart Item IDs

Cart items need unique IDs separate from product IDs to support:
- Same product multiple times (future: with different modifiers)
- Proper React key management

Generate unique IDs:
```typescript
{
  id: `cart-${Date.now()}-${product.id}`,
  product: product,
  // ...
}
```

### State Management

Components are **fully controlled** - parent manages all state:
- POSPage manages cart state
- ShiftDashboardPage manages shift state
- All callbacks required for state updates

No internal state except UI concerns (search, filters, calculator display).

## Future Enhancements

The component library is ready for:
- [ ] Integration with backend API (receipts, shifts)
- [ ] Customer search/selection modal
- [ ] Product details modal
- [ ] Split payment modal
- [ ] Barcode scanner integration
- [ ] Receipt printer integration (Tauri app)
- [ ] Offline mode (Tauri app with SQLite)
- [ ] Multi-language support (i18n)

## Architecture Decisions

### Why Atomic Design?

- Clear component boundaries
- Easy to test in isolation
- Reusable across different pages
- Scales well as features grow

### Why Controlled Components?

- Parent owns all business logic
- Components stay presentational
- Easy to integrate with any state management
- Testable without complex setup

### Why Touch Optimization?

- Single adaptive UI (not separate mobile/desktop)
- Tablet POS is common use case
- Cost-effective for small businesses
- 48px targets meet accessibility standards

## Contributing

When adding new POS components:

1. **Write tests first** (TDD)
2. **Follow Atomic Design** (place in correct layer)
3. **Add touch optimization** (`touchOptimized` prop)
4. **Use TypeScript strict** (no `any` types)
5. **Document props** (JSDoc comments)
6. **Export from index.ts** (barrel export)
7. **Update this README** (usage examples)

## License

Part of the AutoERP project.
