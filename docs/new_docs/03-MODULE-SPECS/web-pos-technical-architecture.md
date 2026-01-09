# Web POS Technical Architecture

**Date:** 2026-01-08
**Status:** Technical Blueprint
**Purpose:** Ensure maintainable, testable, iterative development

---

## Table of Contents

1. [Atomic Design Structure](#atomic-design-structure)
2. [Component Hierarchy](#component-hierarchy)
3. [Separation of Concerns](#separation-of-concerns)
4. [Test-Driven Development Strategy](#test-driven-development-strategy)
5. [State Management Architecture](#state-management-architecture)
6. [Code Organization](#code-organization)
7. [Calculator Feature](#calculator-feature)
8. [Development Workflow](#development-workflow)

---

## 1. Atomic Design Structure

### Design System Layers

```
┌─────────────────────────────────────────────────────────────────┐
│                          PAGES                                   │
│  Full screens with routing (ShiftDashboardPage, POSPage)        │
├─────────────────────────────────────────────────────────────────┤
│                        TEMPLATES                                 │
│  Page layouts without business logic (POSLayout, DashboardLayout)│
├─────────────────────────────────────────────────────────────────┤
│                        ORGANISMS                                 │
│  Complex components (ProductGrid, TransactionCart, CheckoutModal)│
├─────────────────────────────────────────────────────────────────┤
│                        MOLECULES                                 │
│  Simple combinations (ProductCard, CartLineItem, PaymentSelector)│
├─────────────────────────────────────────────────────────────────┤
│                          ATOMS                                   │
│  Basic building blocks (Button, Input, Badge, Icon)             │
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
apps/web/src/features/pos/
├── atoms/                          # Basic building blocks
│   ├── POSButton.tsx               # POS-specific button (touch-optimized)
│   ├── POSButton.test.tsx          # Unit test
│   ├── MoneyInput.tsx              # Formatted money input
│   ├── MoneyInput.test.tsx
│   ├── StockBadge.tsx              # Stock status badge (green/yellow/red)
│   ├── StockBadge.test.tsx
│   ├── CategoryChip.tsx            # Category filter chip
│   ├── CategoryChip.test.tsx
│   └── index.ts                    # Barrel export
│
├── molecules/                      # Simple combinations
│   ├── ProductCard/
│   │   ├── ProductCard.tsx         # Product display card
│   │   ├── ProductCard.test.tsx
│   │   ├── ProductCard.stories.tsx # Storybook story
│   │   └── index.ts
│   ├── CartLineItem/
│   │   ├── CartLineItem.tsx        # Single cart item with qty controls
│   │   ├── CartLineItem.test.tsx
│   │   └── index.ts
│   ├── PaymentMethodButton/
│   │   ├── PaymentMethodButton.tsx # Payment type selector button
│   │   ├── PaymentMethodButton.test.tsx
│   │   └── index.ts
│   ├── CustomerDisplayCard/
│   │   ├── CustomerDisplayCard.tsx # Shows selected customer info
│   │   ├── CustomerDisplayCard.test.tsx
│   │   └── index.ts
│   ├── ShiftStatusBadge/
│   │   ├── ShiftStatusBadge.tsx    # Shift status indicator
│   │   ├── ShiftStatusBadge.test.tsx
│   │   └── index.ts
│   └── index.ts
│
├── organisms/                      # Complex components
│   ├── ProductGrid/
│   │   ├── ProductGrid.tsx         # Grid of products with search/filter
│   │   ├── ProductGrid.test.tsx
│   │   ├── useProductGrid.ts       # Business logic hook
│   │   ├── useProductGrid.test.ts
│   │   └── index.ts
│   ├── TransactionCart/
│   │   ├── TransactionCart.tsx     # Full cart with totals
│   │   ├── TransactionCart.test.tsx
│   │   ├── useCart.ts              # Cart state hook
│   │   ├── useCart.test.ts
│   │   └── index.ts
│   ├── CheckoutModal/
│   │   ├── CheckoutModal.tsx       # Checkout flow modal
│   │   ├── CheckoutModal.test.tsx
│   │   ├── QuickCheckout.tsx       # Cash-only quick checkout
│   │   ├── QuickCheckout.test.tsx
│   │   ├── AdvancedCheckout.tsx    # Multi-payment checkout
│   │   ├── AdvancedCheckout.test.tsx
│   │   └── index.ts
│   ├── CustomerSelector/
│   │   ├── CustomerSelector.tsx    # Customer search/select/add
│   │   ├── CustomerSelector.test.tsx
│   │   ├── CustomerSearchList.tsx
│   │   ├── QuickAddForm.tsx
│   │   └── index.ts
│   ├── ProductInfoModal/
│   │   ├── ProductInfoModal.tsx    # Product details modal
│   │   ├── ProductInfoModal.test.tsx
│   │   ├── DetailsTab.tsx
│   │   ├── RelatedProductsTab.tsx
│   │   ├── SuppliersTab.tsx
│   │   └── index.ts
│   ├── CashDrawerPanel/
│   │   ├── CashDrawerPanel.tsx     # Cash operations panel
│   │   ├── CashDrawerPanel.test.tsx
│   │   ├── DepositForm.tsx
│   │   ├── PayoutForm.tsx
│   │   └── index.ts
│   ├── Calculator/
│   │   ├── Calculator.tsx          # Floating calculator widget
│   │   ├── Calculator.test.tsx
│   │   ├── CalculatorButton.tsx    # Calculator icon button
│   │   └── index.ts
│   └── index.ts
│
├── templates/                      # Page layouts
│   ├── POSLayout/
│   │   ├── POSLayout.tsx           # Main POS layout (split view)
│   │   ├── POSLayout.test.tsx
│   │   ├── POSTopBar.tsx           # Top navigation bar
│   │   ├── POSBottomBar.tsx        # Quick actions bar
│   │   └── index.ts
│   ├── ShiftDashboardLayout/
│   │   ├── ShiftDashboardLayout.tsx
│   │   └── index.ts
│   └── index.ts
│
├── pages/                          # Full page components
│   ├── ShiftDashboardPage/
│   │   ├── ShiftDashboardPage.tsx
│   │   ├── ShiftDashboardPage.test.tsx
│   │   └── index.ts
│   ├── POSPage/
│   │   ├── POSPage.tsx
│   │   ├── POSPage.test.tsx
│   │   └── index.ts
│   └── index.ts
│
├── hooks/                          # Custom hooks (logic only)
│   ├── useCurrentShift.ts          # Fetch current shift
│   ├── useCurrentShift.test.ts
│   ├── useCartStore.ts             # Zustand cart store
│   ├── useCartStore.test.ts
│   ├── useProductSearch.ts         # Product search with debounce
│   ├── useProductSearch.test.ts
│   ├── useCheckout.ts              # Checkout mutation logic
│   ├── useCheckout.test.ts
│   ├── useKeyboardShortcuts.ts     # Global keyboard handlers
│   ├── useKeyboardShortcuts.test.ts
│   ├── useTouchOptimization.ts     # Detect and adapt to touch
│   ├── useTouchOptimization.test.ts
│   └── index.ts
│
├── api/                            # API client functions
│   ├── shiftApi.ts                 # Shift endpoints
│   ├── shiftApi.test.ts
│   ├── receiptApi.ts               # Receipt creation
│   ├── receiptApi.test.ts
│   ├── cashDrawerApi.ts            # Cash drawer ops
│   ├── cashDrawerApi.test.ts
│   ├── reportApi.ts                # X/Z reports
│   ├── reportApi.test.ts
│   └── index.ts
│
├── utils/                          # Pure utility functions
│   ├── calculations.ts             # Tax, totals calculations
│   ├── calculations.test.ts
│   ├── formatters.ts               # Money, date formatters
│   ├── formatters.test.ts
│   ├── validators.ts               # Input validators
│   ├── validators.test.ts
│   └── index.ts
│
├── types/                          # TypeScript types
│   ├── cart.ts
│   ├── shift.ts
│   ├── receipt.ts
│   ├── customer.ts
│   └── index.ts
│
└── __tests__/                      # Integration tests
    ├── checkout-flow.test.tsx      # End-to-end checkout
    ├── shift-lifecycle.test.tsx    # Open -> transactions -> close
    └── cash-drawer.test.tsx        # Cash operations flow
```

---

## 2. Component Hierarchy

### Atomic Design Breakdown

#### **Atoms** (Fully Reusable, No Business Logic)

```typescript
// apps/web/src/features/pos/atoms/POSButton.tsx

interface POSButtonProps {
  variant: 'primary' | 'secondary' | 'danger' | 'success'
  size: 'sm' | 'md' | 'lg' | 'xl'
  touchOptimized?: boolean
  children: React.ReactNode
  onClick?: () => void
  disabled?: boolean
  loading?: boolean
  icon?: React.ReactNode
  fullWidth?: boolean
}

export function POSButton({
  variant = 'primary',
  size = 'md',
  touchOptimized = false,
  ...props
}: POSButtonProps) {
  // Pure presentation component
  // No API calls, no business logic
  // Only handles visual presentation
}
```

**Test Strategy:**

```typescript
// POSButton.test.tsx

describe('POSButton', () => {
  it('renders with correct variant classes', () => {
    const { container } = render(<POSButton variant="primary">Click</POSButton>)
    expect(container.firstChild).toHaveClass('bg-blue-600')
  })

  it('applies touch-optimized styles when flag is set', () => {
    const { container } = render(
      <POSButton touchOptimized>Click</POSButton>
    )
    expect(container.firstChild).toHaveClass('px-8', 'py-6', 'text-xl')
  })

  it('calls onClick when clicked', () => {
    const onClick = vi.fn()
    const { getByText } = render(<POSButton onClick={onClick}>Click</POSButton>)
    fireEvent.click(getByText('Click'))
    expect(onClick).toHaveBeenCalledTimes(1)
  })

  it('does not call onClick when disabled', () => {
    const onClick = vi.fn()
    const { getByText } = render(
      <POSButton onClick={onClick} disabled>Click</POSButton>
    )
    fireEvent.click(getByText('Click'))
    expect(onClick).not.toHaveBeenCalled()
  })
})
```

#### **Molecules** (Combination of Atoms, Minimal Logic)

```typescript
// apps/web/src/features/pos/molecules/ProductCard/ProductCard.tsx

interface ProductCardProps {
  product: Product
  onAddToCart: (product: Product) => void
  onShowInfo: (product: Product) => void
  isInCart?: boolean
}

export function ProductCard({
  product,
  onAddToCart,
  onShowInfo,
  isInCart = false
}: ProductCardProps) {
  // Uses atoms: POSButton, StockBadge, Image
  // No API calls - all data passed via props
  // All actions delegated to parent via callbacks

  return (
    <div className="product-card">
      <img src={product.image_url} alt={product.name} />

      <div className="product-info">
        <h3>{product.name}</h3>
        <p>{product.sku}</p>
        <StockBadge quantity={product.stock_quantity} />

        <div className="price">
          {formatMoney(product.price)}
        </div>
      </div>

      <div className="actions">
        <POSButton
          variant="secondary"
          size="sm"
          icon={<InfoIcon />}
          onClick={() => onShowInfo(product)}
        />

        <POSButton
          variant={isInCart ? 'success' : 'primary'}
          onClick={() => onAddToCart(product)}
          disabled={product.stock_quantity === 0}
        >
          {isInCart ? 'In Cart' : 'Add'}
        </POSButton>
      </div>
    </div>
  )
}
```

**Test Strategy:**

```typescript
// ProductCard.test.tsx

describe('ProductCard', () => {
  const mockProduct = {
    id: '1',
    name: 'Oil Filter',
    sku: 'OF-1234',
    price: '15.50',
    stock_quantity: 45
  }

  it('displays product information correctly', () => {
    const { getByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )

    expect(getByText('Oil Filter')).toBeInTheDocument()
    expect(getByText('OF-1234')).toBeInTheDocument()
    expect(getByText('15.50 TND')).toBeInTheDocument()
  })

  it('calls onAddToCart when add button clicked', () => {
    const onAddToCart = vi.fn()
    const { getByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={onAddToCart}
        onShowInfo={vi.fn()}
      />
    )

    fireEvent.click(getByText('Add'))
    expect(onAddToCart).toHaveBeenCalledWith(mockProduct)
  })

  it('disables add button when out of stock', () => {
    const outOfStockProduct = { ...mockProduct, stock_quantity: 0 }
    const { getByText } = render(
      <ProductCard
        product={outOfStockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
      />
    )

    expect(getByText('Add')).toBeDisabled()
  })

  it('shows "In Cart" state when product is in cart', () => {
    const { getByText } = render(
      <ProductCard
        product={mockProduct}
        onAddToCart={vi.fn()}
        onShowInfo={vi.fn()}
        isInCart
      />
    )

    expect(getByText('In Cart')).toBeInTheDocument()
  })
})
```

#### **Organisms** (Complex Components with Business Logic)

```typescript
// apps/web/src/features/pos/organisms/ProductGrid/ProductGrid.tsx

interface ProductGridProps {
  onProductSelect: (product: Product) => void
}

export function ProductGrid({ onProductSelect }: ProductGridProps) {
  // Uses hook for business logic
  const {
    products,
    isLoading,
    searchTerm,
    setSearchTerm,
    selectedCategory,
    setSelectedCategory,
    categories
  } = useProductGrid()

  // Uses molecules: ProductCard
  // Handles: search, filtering, loading states
  // Delegates actions to parent

  if (isLoading) return <ProductGridSkeleton />

  return (
    <div className="product-grid">
      <SearchBar
        value={searchTerm}
        onChange={setSearchTerm}
        placeholder={t('pos.searchProducts')}
      />

      <CategoryFilter
        categories={categories}
        selected={selectedCategory}
        onSelect={setSelectedCategory}
      />

      <div className="grid grid-cols-4 gap-4">
        {products.map(product => (
          <ProductCard
            key={product.id}
            product={product}
            onAddToCart={onProductSelect}
            onShowInfo={handleShowInfo}
          />
        ))}
      </div>
    </div>
  )
}

// Separate business logic hook
// apps/web/src/features/pos/organisms/ProductGrid/useProductGrid.ts

export function useProductGrid() {
  const [searchTerm, setSearchTerm] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<string | null>(null)

  // Debounced search
  const debouncedSearchTerm = useDebounce(searchTerm, 300)

  // React Query for data fetching
  const { data: products = [], isLoading } = useQuery({
    queryKey: ['products', debouncedSearchTerm, selectedCategory],
    queryFn: () => fetchProducts({
      search: debouncedSearchTerm,
      category_id: selectedCategory,
      limit: 50
    })
  })

  // Fetch categories
  const { data: categories = [] } = useQuery({
    queryKey: ['categories'],
    queryFn: fetchCategories
  })

  return {
    products,
    isLoading,
    searchTerm,
    setSearchTerm,
    selectedCategory,
    setSelectedCategory,
    categories
  }
}
```

**Test Strategy:**

```typescript
// useProductGrid.test.ts (Hook test)

describe('useProductGrid', () => {
  it('fetches products on mount', async () => {
    const { result } = renderHook(() => useProductGrid())

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
      expect(result.current.products).toHaveLength(10)
    })
  })

  it('debounces search term', async () => {
    const { result } = renderHook(() => useProductGrid())

    act(() => {
      result.current.setSearchTerm('oil')
    })

    // Should not fetch immediately
    expect(mockFetchProducts).not.toHaveBeenCalled()

    // Wait for debounce
    await waitFor(() => {
      expect(mockFetchProducts).toHaveBeenCalledWith(
        expect.objectContaining({ search: 'oil' })
      )
    }, { timeout: 400 })
  })

  it('filters by category', async () => {
    const { result } = renderHook(() => useProductGrid())

    act(() => {
      result.current.setSelectedCategory('category-1')
    })

    await waitFor(() => {
      expect(mockFetchProducts).toHaveBeenCalledWith(
        expect.objectContaining({ category_id: 'category-1' })
      )
    })
  })
})

// ProductGrid.test.tsx (Component test)

describe('ProductGrid', () => {
  it('renders search bar and category filter', () => {
    const { getByPlaceholderText, getByText } = render(
      <ProductGrid onProductSelect={vi.fn()} />
    )

    expect(getByPlaceholderText('Search products...')).toBeInTheDocument()
    expect(getByText('All')).toBeInTheDocument() // Category chip
  })

  it('displays products in grid', async () => {
    const { findByText } = render(
      <ProductGrid onProductSelect={vi.fn()} />
    )

    await findByText('Oil Filter')
    await findByText('Air Filter')
  })

  it('calls onProductSelect when product added to cart', async () => {
    const onProductSelect = vi.fn()
    const { findByText, getByText } = render(
      <ProductGrid onProductSelect={onProductSelect} />
    )

    await findByText('Oil Filter')

    const addButton = getByText('Add')
    fireEvent.click(addButton)

    expect(onProductSelect).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'Oil Filter' })
    )
  })
})
```

---

## 3. Separation of Concerns

### Clean Architecture Principles

```
┌─────────────────────────────────────────────────────────────────┐
│                      PRESENTATION LAYER                          │
│  Components (atoms, molecules, organisms, templates, pages)     │
│  - Render UI                                                     │
│  - Handle user interactions                                      │
│  - Delegate business logic to hooks                              │
├─────────────────────────────────────────────────────────────────┤
│                      BUSINESS LOGIC LAYER                        │
│  Custom Hooks (useCart, useCheckout, useProductGrid)            │
│  - State management                                              │
│  - Data fetching orchestration                                   │
│  - Business rules                                                │
│  - NO UI rendering                                               │
├─────────────────────────────────────────────────────────────────┤
│                      DATA ACCESS LAYER                           │
│  API Functions (shiftApi, receiptApi, productApi)               │
│  - HTTP requests                                                 │
│  - Request/response mapping                                      │
│  - Error handling                                                │
│  - NO business logic                                             │
├─────────────────────────────────────────────────────────────────┤
│                      UTILITY LAYER                               │
│  Pure Functions (calculations, formatters, validators)          │
│  - Stateless transformations                                     │
│  - No side effects                                               │
│  - Easily testable                                               │
└─────────────────────────────────────────────────────────────────┘
```

### Example: Checkout Flow

**❌ BAD (Everything in Component):**

```typescript
// DON'T DO THIS!
export function CheckoutModal() {
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleCheckout = async () => {
    setIsLoading(true)
    try {
      // Business logic mixed with component
      const subtotal = cart.items.reduce((sum, item) => sum + item.total, 0)
      const taxAmount = subtotal * 0.19
      const total = subtotal + taxAmount

      // API call in component
      const response = await fetch('/api/v1/pos/receipts', {
        method: 'POST',
        body: JSON.stringify({ /* ... */ })
      })

      // Response handling in component
      if (!response.ok) throw new Error('Failed')

      const receipt = await response.json()

      // More business logic
      await updateStockLevels(receipt.lines)
      await recordCashOperation(receipt)

      toast.success('Sale completed')
      clearCart()
      navigate('/pos')
    } catch (err) {
      setError(err.message)
    } finally {
      setIsLoading(false)
    }
  }

  return <button onClick={handleCheckout}>Checkout</button>
}
```

**✅ GOOD (Separation of Concerns):**

```typescript
// Presentation Layer (Component)
// apps/web/src/features/pos/organisms/CheckoutModal/CheckoutModal.tsx

export function CheckoutModal({ isOpen, onClose }: CheckoutModalProps) {
  // Delegate to business logic hook
  const { checkout, isLoading, error } = useCheckout()

  const handleCheckout = async (paymentData: PaymentData) => {
    const success = await checkout(paymentData)
    if (success) {
      onClose()
      // Navigation handled by hook
    }
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose}>
      {error && <ErrorAlert message={error} />}

      <CheckoutForm onSubmit={handleCheckout} isLoading={isLoading} />
    </Modal>
  )
}

// Business Logic Layer (Hook)
// apps/web/src/features/pos/hooks/useCheckout.ts

export function useCheckout() {
  const navigate = useNavigate()
  const { items, clearCart } = useCartStore()
  const { currentShift } = useCurrentShift()
  const queryClient = useQueryClient()

  const checkoutMutation = useMutation({
    mutationFn: async (paymentData: PaymentData) => {
      // Delegate to data access layer
      return await createReceipt({
        shift_id: currentShift.id,
        lines: items.map(item => ({
          product_id: item.product.id,
          quantity: item.quantity,
          unit_price: item.unit_price
        })),
        payments: paymentData.payments
      })
    },
    onSuccess: (receipt) => {
      // Business logic: clear cart, invalidate queries, navigate
      clearCart()
      queryClient.invalidateQueries(['pos', 'shift'])
      queryClient.invalidateQueries(['products']) // Stock levels changed
      toast.success(t('pos.checkout.success'))
      navigate('/pos')
    },
    onError: (error) => {
      toast.error(error.message)
    }
  })

  return {
    checkout: checkoutMutation.mutateAsync,
    isLoading: checkoutMutation.isLoading,
    error: checkoutMutation.error?.message
  }
}

// Data Access Layer (API Function)
// apps/web/src/features/pos/api/receiptApi.ts

export interface CreateReceiptRequest {
  shift_id: string
  lines: ReceiptLineInput[]
  payments: PaymentInput[]
  customer_id?: string
  notes?: string
}

export async function createReceipt(
  data: CreateReceiptRequest
): Promise<Receipt> {
  return apiPost<Receipt>('/pos/receipts', data)
  // apiPost already handles unwrapping, error mapping, etc.
}

// Utility Layer (Pure Functions)
// apps/web/src/features/pos/utils/calculations.ts

export function calculateCartTotals(items: CartItem[]): CartTotals {
  const subtotal = items.reduce((sum, item) => {
    return sum + parseFloat(item.line_total)
  }, 0)

  const taxAmount = items.reduce((sum, item) => {
    return sum + parseFloat(item.tax_amount)
  }, 0)

  const total = subtotal + taxAmount

  return {
    subtotal: formatMoney(subtotal),
    taxAmount: formatMoney(taxAmount),
    total: formatMoney(total)
  }
}
```

---

## 4. Test-Driven Development Strategy

### TDD Workflow (Red-Green-Refactor)

```
┌─────────────────────────────────────────────────────────────────┐
│  1. RED: Write failing test first                               │
│     - Define expected behavior                                   │
│     - Test fails (component doesn't exist yet)                   │
├─────────────────────────────────────────────────────────────────┤
│  2. GREEN: Write minimum code to pass test                      │
│     - Implement just enough to make test pass                    │
│     - Don't worry about optimization yet                         │
├─────────────────────────────────────────────────────────────────┤
│  3. REFACTOR: Improve code while keeping tests green            │
│     - Extract common logic                                       │
│     - Improve naming                                             │
│     - Remove duplication                                         │
│     - Tests stay green throughout                                │
└─────────────────────────────────────────────────────────────────┘
```

### Example: TDD for CartLineItem

**Step 1: RED - Write Test First**

```typescript
// apps/web/src/features/pos/molecules/CartLineItem/CartLineItem.test.tsx

describe('CartLineItem', () => {
  const mockItem: CartItem = {
    product: {
      id: '1',
      name: 'Oil Filter',
      sku: 'OF-1234'
    },
    quantity: 2,
    unit_price: '15.50',
    line_total: '31.00',
    tax_amount: '5.89'
  }

  it('displays product name and SKU', () => {
    const { getByText } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    expect(getByText('Oil Filter')).toBeInTheDocument()
    expect(getByText('SKU: OF-1234')).toBeInTheDocument()
  })

  it('displays quantity with controls', () => {
    const { getByText, getByRole } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    expect(getByText('2')).toBeInTheDocument()
    expect(getByRole('button', { name: '-' })).toBeInTheDocument()
    expect(getByRole('button', { name: '+' })).toBeInTheDocument()
  })

  it('calls onUpdateQuantity when + button clicked', () => {
    const onUpdateQuantity = vi.fn()
    const { getByRole } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={onUpdateQuantity}
        onRemove={vi.fn()}
      />
    )

    fireEvent.click(getByRole('button', { name: '+' }))
    expect(onUpdateQuantity).toHaveBeenCalledWith(mockItem.product.id, 3)
  })

  it('calls onUpdateQuantity when - button clicked', () => {
    const onUpdateQuantity = vi.fn()
    const { getByRole } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={onUpdateQuantity}
        onRemove={vi.fn()}
      />
    )

    fireEvent.click(getByRole('button', { name: '-' }))
    expect(onUpdateQuantity).toHaveBeenCalledWith(mockItem.product.id, 1)
  })

  it('calls onRemove when quantity becomes 0', () => {
    const itemWithQty1 = { ...mockItem, quantity: 1 }
    const onRemove = vi.fn()
    const { getByRole } = render(
      <CartLineItem
        item={itemWithQty1}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
      />
    )

    fireEvent.click(getByRole('button', { name: '-' }))
    expect(onRemove).toHaveBeenCalledWith(mockItem.product.id)
  })

  it('displays line total', () => {
    const { getByText } = render(
      <CartLineItem
        item={mockItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
      />
    )

    expect(getByText('31.00 TND')).toBeInTheDocument()
  })
})
```

**Step 2: GREEN - Implement Component**

```typescript
// apps/web/src/features/pos/molecules/CartLineItem/CartLineItem.tsx

interface CartLineItemProps {
  item: CartItem
  onUpdateQuantity: (productId: string, newQuantity: number) => void
  onRemove: (productId: string) => void
}

export function CartLineItem({
  item,
  onUpdateQuantity,
  onRemove
}: CartLineItemProps) {
  const handleIncrement = () => {
    onUpdateQuantity(item.product.id, item.quantity + 1)
  }

  const handleDecrement = () => {
    if (item.quantity === 1) {
      onRemove(item.product.id)
    } else {
      onUpdateQuantity(item.product.id, item.quantity - 1)
    }
  }

  return (
    <div className="cart-line-item">
      <div className="product-info">
        <div className="name">{item.product.name}</div>
        <div className="sku">SKU: {item.product.sku}</div>
      </div>

      <div className="quantity-controls">
        <button onClick={handleDecrement} aria-label="-">-</button>
        <span>{item.quantity}</span>
        <button onClick={handleIncrement} aria-label="+">+</button>
      </div>

      <div className="line-total">
        {item.line_total} TND
      </div>
    </div>
  )
}
```

**Step 3: REFACTOR - Improve Code**

```typescript
// Extract quantity controls into separate component
// apps/web/src/features/pos/molecules/CartLineItem/QuantityControls.tsx

interface QuantityControlsProps {
  quantity: number
  onIncrement: () => void
  onDecrement: () => void
}

export function QuantityControls({
  quantity,
  onIncrement,
  onDecrement
}: QuantityControlsProps) {
  return (
    <div className="quantity-controls">
      <POSButton
        variant="secondary"
        size="sm"
        onClick={onDecrement}
        aria-label="Decrease quantity"
      >
        <MinusIcon />
      </POSButton>

      <span className="quantity-display">{quantity}</span>

      <POSButton
        variant="secondary"
        size="sm"
        onClick={onIncrement}
        aria-label="Increase quantity"
      >
        <PlusIcon />
      </POSButton>
    </div>
  )
}

// Updated CartLineItem using extracted component
export function CartLineItem({ item, onUpdateQuantity, onRemove }: CartLineItemProps) {
  // ... (handlers stay the same)

  return (
    <div className="cart-line-item">
      <ProductInfo product={item.product} />

      <QuantityControls
        quantity={item.quantity}
        onIncrement={handleIncrement}
        onDecrement={handleDecrement}
      />

      <LineTotal amount={item.line_total} />
    </div>
  )
}
```

### Test Coverage Requirements

| Layer | Coverage Target | Focus |
|-------|----------------|-------|
| Atoms | 100% | All props, all variants, all states |
| Molecules | 95%+ | User interactions, edge cases |
| Organisms | 90%+ | Business logic, integration |
| Hooks | 95%+ | All branches, async behavior |
| Utils | 100% | Pure functions, easy to test |
| API | 85%+ | Request/response mapping, errors |

### Testing Tools

```json
{
  "dependencies": {
    "vitest": "^1.0.0",
    "@testing-library/react": "^14.0.0",
    "@testing-library/user-event": "^14.0.0",
    "@testing-library/react-hooks": "^8.0.1",
    "msw": "^2.0.0"
  }
}
```

**Mock Service Worker (MSW) for API Mocking:**

```typescript
// apps/web/src/features/pos/__mocks__/handlers.ts

import { http, HttpResponse } from 'msw'

export const handlers = [
  // Mock current shift endpoint
  http.get('/api/v1/pos/shifts/current/:terminalId', () => {
    return HttpResponse.json({
      data: {
        id: 'shift-1',
        shift_number: 42,
        status: 'OPEN',
        opening_cash: '200.00',
        opened_at: '2026-01-08T09:00:00Z'
      }
    })
  }),

  // Mock product search
  http.get('/api/v1/products', ({ request }) => {
    const url = new URL(request.url)
    const search = url.searchParams.get('search')

    return HttpResponse.json({
      data: mockProducts.filter(p =>
        p.name.toLowerCase().includes(search?.toLowerCase() || '')
      )
    })
  }),

  // Mock receipt creation
  http.post('/api/v1/pos/receipts', async ({ request }) => {
    const body = await request.json()

    return HttpResponse.json({
      data: {
        id: 'receipt-1',
        receipt_number: 'SHOP01-POS01-2026-00042',
        total_amount: body.total,
        created_at: new Date().toISOString()
      }
    })
  })
]
```

---

## 5. State Management Architecture

### State Categories

```
┌─────────────────────────────────────────────────────────────────┐
│  SERVER STATE (React Query)                                      │
│  - Current shift                                                 │
│  - Products                                                      │
│  - Customer data                                                 │
│  - Cash drawer balance                                           │
│  - Reports                                                       │
│  ✓ Cached, auto-refetch, optimistic updates                     │
├─────────────────────────────────────────────────────────────────┤
│  CLIENT STATE (Zustand)                                          │
│  - Cart items (not persisted until checkout)                    │
│  - Selected customer (session only)                              │
│  - UI state (modal open/close, calculator visible)              │
│  ✓ Fast, no network, cleared on checkout                        │
├─────────────────────────────────────────────────────────────────┤
│  URL STATE (React Router)                                        │
│  - Current page                                                  │
│  - Modal routes (if applicable)                                 │
│  ✓ Shareable, browser back/forward                              │
├─────────────────────────────────────────────────────────────────┤
│  LOCAL STATE (useState)                                          │
│  - Form inputs                                                   │
│  - Temporary UI state (dropdown open, hover)                    │
│  ✓ Component-scoped, minimal                                    │
└─────────────────────────────────────────────────────────────────┘
```

### Cart Store (Zustand)

```typescript
// apps/web/src/features/pos/hooks/useCartStore.ts

interface CartState {
  items: CartItem[]
  selectedCustomer: Customer | null

  // Actions
  addItem: (product: Product) => void
  removeItem: (productId: string) => void
  updateQuantity: (productId: string, quantity: number) => void
  clearCart: () => void
  setCustomer: (customer: Customer | null) => void

  // Computed (selectors)
  subtotal: () => number
  taxAmount: () => number
  total: () => number
  itemCount: () => number
}

export const useCartStore = create<CartState>((set, get) => ({
  items: [],
  selectedCustomer: null,

  addItem: (product) => {
    set((state) => {
      const existingItem = state.items.find(
        item => item.product.id === product.id
      )

      if (existingItem) {
        // Increment quantity if already in cart
        return {
          items: state.items.map(item =>
            item.product.id === product.id
              ? { ...item, quantity: item.quantity + 1 }
              : item
          )
        }
      }

      // Add new item
      return {
        items: [
          ...state.items,
          {
            product,
            quantity: 1,
            unit_price: product.price,
            line_total: product.price,
            tax_amount: calculateTax(product.price, product.tax_rate)
          }
        ]
      }
    })
  },

  removeItem: (productId) => {
    set((state) => ({
      items: state.items.filter(item => item.product.id !== productId)
    }))
  },

  updateQuantity: (productId, quantity) => {
    set((state) => ({
      items: state.items.map(item =>
        item.product.id === productId
          ? {
              ...item,
              quantity,
              line_total: calculateLineTotal(item.unit_price, quantity),
              tax_amount: calculateTax(
                calculateLineTotal(item.unit_price, quantity),
                item.product.tax_rate
              )
            }
          : item
      )
    }))
  },

  clearCart: () => {
    set({ items: [], selectedCustomer: null })
  },

  setCustomer: (customer) => {
    set({ selectedCustomer: customer })
  },

  // Computed selectors (memoized)
  subtotal: () => {
    const items = get().items
    return items.reduce((sum, item) => sum + parseFloat(item.line_total), 0)
  },

  taxAmount: () => {
    const items = get().items
    return items.reduce((sum, item) => sum + parseFloat(item.tax_amount), 0)
  },

  total: () => {
    return get().subtotal() + get().taxAmount()
  },

  itemCount: () => {
    const items = get().items
    return items.reduce((sum, item) => sum + item.quantity, 0)
  }
}))

// Selector optimization (only re-render when needed)
export const useCartItems = () => useCartStore(state => state.items)
export const useCartTotal = () => useCartStore(state => state.total())
export const useCartCustomer = () => useCartStore(state => state.selectedCustomer)
```

**Test Strategy:**

```typescript
// useCartStore.test.ts

describe('useCartStore', () => {
  beforeEach(() => {
    // Reset store before each test
    useCartStore.setState({ items: [], selectedCustomer: null })
  })

  it('adds new item to cart', () => {
    const { addItem, items } = useCartStore.getState()

    addItem(mockProduct)

    expect(items).toHaveLength(1)
    expect(items[0].product.id).toBe(mockProduct.id)
    expect(items[0].quantity).toBe(1)
  })

  it('increments quantity if item already in cart', () => {
    const { addItem, items } = useCartStore.getState()

    addItem(mockProduct)
    addItem(mockProduct)

    expect(items).toHaveLength(1)
    expect(items[0].quantity).toBe(2)
  })

  it('removes item from cart', () => {
    const { addItem, removeItem, items } = useCartStore.getState()

    addItem(mockProduct)
    removeItem(mockProduct.id)

    expect(items).toHaveLength(0)
  })

  it('calculates subtotal correctly', () => {
    const { addItem, subtotal } = useCartStore.getState()

    addItem({ ...mockProduct, price: '10.00' })
    addItem({ ...mockProduct2, price: '20.00' })

    expect(subtotal()).toBe(30.00)
  })

  it('clears cart', () => {
    const { addItem, clearCart, items } = useCartStore.getState()

    addItem(mockProduct)
    addItem(mockProduct2)
    clearCart()

    expect(items).toHaveLength(0)
  })
})
```

---

## 6. Code Organization

### File Naming Conventions

```
# Components
PascalCase.tsx          # ProductCard.tsx, CheckoutModal.tsx

# Hooks
camelCase.ts            # useCart.ts, useProductSearch.ts

# Utils
camelCase.ts            # formatters.ts, calculations.ts

# Types
camelCase.ts            # cart.ts, shift.ts

# Tests
*.test.tsx              # ProductCard.test.tsx
*.test.ts               # useCart.test.ts

# Storybook (optional)
*.stories.tsx           # ProductCard.stories.tsx
```

### Barrel Exports (index.ts)

```typescript
// apps/web/src/features/pos/atoms/index.ts

export { POSButton } from './POSButton'
export { MoneyInput } from './MoneyInput'
export { StockBadge } from './StockBadge'
export { CategoryChip } from './CategoryChip'

// Usage in other files
import { POSButton, StockBadge } from '@/features/pos/atoms'
```

### Import Aliases

```typescript
// tsconfig.json
{
  "compilerOptions": {
    "paths": {
      "@/features/*": ["./src/features/*"],
      "@/components/*": ["./src/components/*"],
      "@/hooks/*": ["./src/hooks/*"],
      "@/utils/*": ["./src/utils/*"]
    }
  }
}

// Usage
import { POSButton } from '@/features/pos/atoms'
import { useTranslation } from '@/hooks/useTranslation'
import { formatMoney } from '@/utils/formatters'
```

---

## 7. Calculator Feature

### Placement & Design

**Top Bar Integration:**

```
┌─────────────────────────────────────────────────────────────────┐
│ [IziPOS] | SHOP01-POS01 | Shift #42 | 💰 1,450 | John Doe | 🖩 ⚙️│
└─────────────────────────────────────────────────────────────────┘
                                                                  ↑
                                                         Calculator Button
```

**Calculator Component:**

```typescript
// apps/web/src/features/pos/organisms/Calculator/Calculator.tsx

interface CalculatorProps {
  isOpen: boolean
  onClose: () => void
}

export function Calculator({ isOpen, onClose }: CalculatorProps) {
  const [display, setDisplay] = useState('0')
  const [previousValue, setPreviousValue] = useState<number | null>(null)
  const [operation, setOperation] = useState<string | null>(null)

  // Calculator logic
  const handleNumber = (num: string) => {
    setDisplay(prev => prev === '0' ? num : prev + num)
  }

  const handleOperation = (op: string) => {
    setPreviousValue(parseFloat(display))
    setOperation(op)
    setDisplay('0')
  }

  const handleEquals = () => {
    if (previousValue === null || operation === null) return

    const current = parseFloat(display)
    let result = 0

    switch (operation) {
      case '+': result = previousValue + current; break
      case '-': result = previousValue - current; break
      case '×': result = previousValue * current; break
      case '÷': result = previousValue / current; break
    }

    setDisplay(result.toString())
    setPreviousValue(null)
    setOperation(null)
  }

  const handleClear = () => {
    setDisplay('0')
    setPreviousValue(null)
    setOperation(null)
  }

  if (!isOpen) return null

  return (
    <div className="calculator-floating-panel">
      <div className="calculator-header">
        <span>Calculator</span>
        <button onClick={onClose}>✕</button>
      </div>

      <div className="calculator-display">{display}</div>

      <div className="calculator-buttons">
        {/* Number pad */}
        <button onClick={() => handleNumber('7')}>7</button>
        <button onClick={() => handleNumber('8')}>8</button>
        <button onClick={() => handleNumber('9')}>9</button>
        <button onClick={() => handleOperation('÷')}>÷</button>

        <button onClick={() => handleNumber('4')}>4</button>
        <button onClick={() => handleNumber('5')}>5</button>
        <button onClick={() => handleNumber('6')}>6</button>
        <button onClick={() => handleOperation('×')}>×</button>

        <button onClick={() => handleNumber('1')}>1</button>
        <button onClick={() => handleNumber('2')}>2</button>
        <button onClick={() => handleNumber('3')}>3</button>
        <button onClick={() => handleOperation('-')}>-</button>

        <button onClick={() => handleNumber('0')}>0</button>
        <button onClick={() => handleNumber('.')}>.</button>
        <button onClick={handleEquals}>=</button>
        <button onClick={() => handleOperation('+')}>+</button>

        <button onClick={handleClear} className="span-2">Clear</button>
      </div>
    </div>
  )
}
```

**Calculator Button (Top Bar):**

```typescript
// apps/web/src/features/pos/organisms/Calculator/CalculatorButton.tsx

export function CalculatorButton() {
  const [isOpen, setIsOpen] = useState(false)

  // Keyboard shortcut: Ctrl/Cmd + K
  useKeyboardShortcut('k', () => setIsOpen(true), { ctrlOrMeta: true })

  return (
    <>
      <button
        onClick={() => setIsOpen(true)}
        className="calculator-button"
        title="Calculator (Ctrl+K)"
        aria-label="Open calculator"
      >
        🖩
      </button>

      <Calculator isOpen={isOpen} onClose={() => setIsOpen(false)} />
    </>
  )
}
```

**Floating Panel Design:**

```
┌─────────────────────────────┐
│ Calculator            [✕]  │
├─────────────────────────────┤
│                             │
│         [1,234.56]          │  <- Display
│                             │
├─────────────────────────────┤
│  [7]  [8]  [9]  [÷]        │
│  [4]  [5]  [6]  [×]        │
│  [1]  [2]  [3]  [-]        │
│  [0]  [.]  [=]  [+]        │
│  [  Clear  ]                │
└─────────────────────────────┘

# Positioning
- Anchored to top-right corner
- z-index: 1000 (above modals)
- Draggable (optional)
- Touch-optimized buttons (min 48px)
```

**Test Strategy:**

```typescript
// Calculator.test.tsx

describe('Calculator', () => {
  it('performs basic addition', () => {
    const { getByText } = render(<Calculator isOpen onClose={vi.fn()} />)

    fireEvent.click(getByText('5'))
    fireEvent.click(getByText('+'))
    fireEvent.click(getByText('3'))
    fireEvent.click(getByText('='))

    expect(getByText('8')).toBeInTheDocument() // Display shows result
  })

  it('performs subtraction', () => {
    const { getByText } = render(<Calculator isOpen onClose={vi.fn()} />)

    fireEvent.click(getByText('10'))
    fireEvent.click(getByText('-'))
    fireEvent.click(getByText('4'))
    fireEvent.click(getByText('='))

    expect(getByText('6')).toBeInTheDocument()
  })

  it('clears display', () => {
    const { getByText } = render(<Calculator isOpen onClose={vi.fn()} />)

    fireEvent.click(getByText('9'))
    fireEvent.click(getByText('9'))
    fireEvent.click(getByText('Clear'))

    expect(getByText('0')).toBeInTheDocument()
  })

  it('closes when close button clicked', () => {
    const onClose = vi.fn()
    const { getByText } = render(<Calculator isOpen onClose={onClose} />)

    fireEvent.click(getByText('✕'))

    expect(onClose).toHaveBeenCalled()
  })
})
```

---

## 8. Development Workflow

### Step-by-Step Implementation Process

**Phase 1: Foundation (Week 1)**

```
Day 1-2: Atoms & Molecules
├── Create POSButton atom (TDD)
│   ├── Write tests first
│   ├── Implement component
│   ├── Add Storybook story (optional)
│   └── Verify all tests pass
├── Create MoneyInput atom
├── Create StockBadge atom
├── Create ProductCard molecule
└── Create CartLineItem molecule

Day 3-4: Organisms
├── Create ProductGrid organism
│   ├── Create useProductGrid hook first (TDD)
│   ├── Test hook in isolation
│   ├── Create component using hook
│   └── Integration test
├── Create TransactionCart organism
└── Create CustomerSelector organism

Day 5: Templates & Pages
├── Create POSLayout template
├── Create ShiftDashboardPage
├── Wire up routing
└── End-to-end test
```

**Git Commit Strategy:**

```bash
# Atomic commits per feature
git commit -m "feat(pos): add POSButton atom with touch optimization"
git commit -m "test(pos): add unit tests for POSButton"
git commit -m "feat(pos): add ProductCard molecule"
git commit -m "test(pos): add tests for ProductCard interactions"
git commit -m "feat(pos): add useProductGrid hook with debounced search"
git commit -m "test(pos): add hook tests for useProductGrid"
```

**Pull Request Process:**

```
1. Create feature branch
   git checkout -b feat/pos-product-grid

2. Implement with TDD (red-green-refactor)
   - Write test
   - Implement
   - Refactor
   - Repeat

3. Run all checks
   pnpm test              # All tests pass
   pnpm lint              # No lint errors
   pnpm typecheck         # No type errors

4. Create PR with description
   - What: Product grid with search and filters
   - Why: Allow cashiers to find products quickly
   - How: useProductGrid hook + ProductGrid component
   - Tests: 95% coverage, all passing
   - Screenshots: [attach grid UI screenshot]

5. Code review
   - Address feedback
   - Update tests if needed
   - Re-run checks

6. Merge to main
   - Squash commits (or keep atomic commits)
   - Delete feature branch
```

### Code Review Checklist

**Before Creating PR:**
- [ ] All tests pass (no skipped tests)
- [ ] Test coverage meets requirements (90%+)
- [ ] No TypeScript errors
- [ ] No ESLint warnings
- [ ] Components follow atomic design principles
- [ ] Business logic extracted to hooks
- [ ] No API calls in components
- [ ] All text uses i18n translation keys
- [ ] Accessible (keyboard navigation, ARIA labels)
- [ ] Touch-optimized (if applicable)

**Reviewer Checklist:**
- [ ] Code follows separation of concerns
- [ ] Tests are meaningful (not just coverage)
- [ ] Error handling is robust
- [ ] Loading states handled
- [ ] No hardcoded values
- [ ] Performance considerations addressed
- [ ] Naming is clear and consistent
- [ ] Comments explain "why", not "what"

---

## 9. Performance Optimization Guidelines

### Memoization Strategy

```typescript
// Use React.memo for expensive components
export const ProductCard = React.memo(function ProductCard({
  product,
  onAddToCart,
  onShowInfo
}: ProductCardProps) {
  // Component implementation
}, (prevProps, nextProps) => {
  // Custom comparison (only re-render if product changed)
  return prevProps.product.id === nextProps.product.id &&
         prevProps.product.stock_quantity === nextProps.product.stock_quantity
})

// Use useMemo for expensive calculations
function CartTotals({ items }: CartTotalsProps) {
  const totals = useMemo(() => calculateCartTotals(items), [items])

  return <div>{totals.total}</div>
}

// Use useCallback for event handlers passed to children
function ProductGrid({ onProductSelect }: ProductGridProps) {
  const handleAddToCart = useCallback((product: Product) => {
    onProductSelect(product)
  }, [onProductSelect])

  return (
    <div>
      {products.map(product => (
        <ProductCard
          key={product.id}
          product={product}
          onAddToCart={handleAddToCart} // Stable reference
        />
      ))}
    </div>
  )
}
```

### Virtual Scrolling (If > 100 products)

```typescript
import { useVirtualizer } from '@tanstack/react-virtual'

export function ProductGrid({ products }: ProductGridProps) {
  const parentRef = useRef<HTMLDivElement>(null)

  const virtualizer = useVirtualizer({
    count: products.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 200, // Product card height
    overscan: 5 // Render 5 extra items off-screen
  })

  return (
    <div ref={parentRef} style={{ height: '600px', overflow: 'auto' }}>
      <div
        style={{
          height: `${virtualizer.getTotalSize()}px`,
          position: 'relative'
        }}
      >
        {virtualizer.getVirtualItems().map(virtualItem => (
          <div
            key={virtualItem.key}
            style={{
              position: 'absolute',
              top: 0,
              left: 0,
              width: '100%',
              transform: `translateY(${virtualItem.start}px)`
            }}
          >
            <ProductCard product={products[virtualItem.index]} />
          </div>
        ))}
      </div>
    </div>
  )
}
```

---

## 10. Accessibility Requirements

### WCAG 2.1 Level AA Compliance

**Keyboard Navigation:**
- All interactive elements focusable
- Logical tab order
- Enter/Space activate buttons
- Escape closes modals
- Arrow keys navigate grids

**Screen Reader Support:**
- Semantic HTML (button, nav, article)
- ARIA labels for icon-only buttons
- ARIA live regions for dynamic content
- Focus management in modals

**Example:**

```typescript
export function ProductCard({ product, onAddToCart }: ProductCardProps) {
  return (
    <article
      className="product-card"
      role="article"
      aria-label={`Product: ${product.name}`}
    >
      <img
        src={product.image_url}
        alt={product.name}
        role="img"
      />

      <h3>{product.name}</h3>

      <button
        onClick={() => onAddToCart(product)}
        aria-label={`Add ${product.name} to cart`}
        disabled={product.stock_quantity === 0}
        aria-disabled={product.stock_quantity === 0}
      >
        Add to Cart
      </button>
    </article>
  )
}
```

---

## Summary Checklist

Before starting implementation, verify:

- [ ] Atomic design structure understood
- [ ] Component hierarchy planned
- [ ] Separation of concerns clear
- [ ] TDD workflow ready (red-green-refactor)
- [ ] Test coverage targets defined
- [ ] State management strategy decided
- [ ] Calculator feature planned
- [ ] Git workflow established
- [ ] Code review process defined
- [ ] Performance optimization guidelines noted
- [ ] Accessibility requirements understood

**Next Step:** Start with Phase 1A - Create atoms (POSButton, MoneyInput, StockBadge) using TDD.

---

*Document End*
