# POS System Architecture

**Status:** ✅ Milestone 10 Complete - Phase 1 Implementation
**Last Updated:** 2026-01-03
**Test Coverage:** 21/21 tests passing

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Architecture Overview](#architecture-overview)
3. [Phase 1: StandardPOS](#phase-1-standardpos)
4. [Phase 2: Specialized Variants](#phase-2-specialized-variants)
5. [Component API](#component-api)
6. [Vertical Adaptation](#vertical-adaptation)
7. [Testing Strategy](#testing-strategy)
8. [Implementation Details](#implementation-details)
9. [Future Enhancements](#future-enhancements)

---

## Executive Summary

The POS (Point of Sale) system is designed as a **phased implementation** that serves multiple business verticals through configuration-driven adaptation.

**Phase 1** (Current): Single `StandardPOS` component that adapts to all verticals
**Phase 2** (Future): Specialized variants for verticals with fundamentally different UX requirements

### Design Principles

1. **Configuration over Code**: Use vertical configuration to drive UI behavior
2. **Progressive Enhancement**: Start simple (Phase 1), specialize when needed (Phase 2)
3. **Vertical Awareness**: Integrate with `CompanyConfigContext` for automatic adaptation
4. **Test-Driven**: Comprehensive test coverage across multiple verticals
5. **i18n First**: All user-facing strings use translation keys

---

## Architecture Overview

### Component Hierarchy

```
POSPage (Loader/Router)
└── StandardPOS (Phase 1 - Universal)
    ├── CompanyConfigContext (Vertical configuration)
    ├── Header (Vertical name, currency, locale)
    ├── Product Search Area
    └── Cart Sidebar
        ├── Cart Items
        ├── Cart Total
        └── Checkout Button
```

### Future Architecture (Phase 2)

```
POSPage (Loader/Router)
├── StandardPOS (Default for most verticals)
├── RestaurantPOS (Table management, kitchen tickets)
├── PharmacyPOS (Prescription lookup, controlled substances)
└── WorkshopPOS (Vehicle selection, job cards, labor)
```

### File Structure

```
apps/web/src/pages/POS/
├── POSPage.tsx                    # Loader component (decides which variant to load)
├── StandardPOS.tsx                # Phase 1 universal POS component
├── index.ts                       # Barrel exports
└── __tests__/
    ├── POSPage.test.tsx          # Loader tests (10 tests)
    └── StandardPOS.test.tsx      # Component tests (11 tests)
```

---

## Phase 1: StandardPOS

### Design Philosophy

**StandardPOS** is a universal POS component that serves all business verticals by adapting its behavior based on configuration rather than code branching.

### Supported Verticals (Phase 1)

All 12 business verticals use StandardPOS in Phase 1:

**IziPOS Product:**
- `retail` - General retail stores
- `fashion` - Clothing and accessories
- `coffee_shop` - Cafés and coffee shops
- `pharmacy` - Basic pharmacy sales (specialized variant in Phase 2)
- `parapharmacy` - Health products without prescriptions
- `restaurant` - Basic food service (specialized variant in Phase 2)

**Otospex Product:**
- `mechanic` - Automotive service centers (parts counter)
- `parts_retailer` - Auto parts stores
- `car_glass` - Windshield replacement specialists
- `tire_shop` - Tire retailers and service
- `service_station` - Quick service centers
- `body_shop` - Automotive body repair

### Key Features

1. **Vertical-Aware Header**
   - Displays current vertical name (e.g., "retail", "pharmacy")
   - Shows currency and locale from company configuration
   - POS title uses i18n translation

2. **Product Search**
   - Search box with placeholder text (i18n)
   - Product grid (placeholder in Phase 1)
   - Ready for product catalog integration

3. **Cart Management**
   - Cart items list with quantity and pricing
   - Cart total calculation
   - Clear cart functionality
   - Empty cart state with visual feedback

4. **Responsive Layout**
   - Two-column layout: Products (left) + Cart (right)
   - Cart sidebar fixed at 384px width
   - Scrollable product area

5. **Error Handling**
   - Loading state (returns null, handled by parent)
   - Error state with user-friendly message (i18n)
   - Graceful degradation when config unavailable

### Adaptation Mechanisms

StandardPOS adapts to verticals through:

1. **`data-vertical` Attribute**: Set on root element for CSS targeting
2. **Currency Display**: Uses `config.currency` for all monetary values
3. **Locale Information**: Displays `config.locale` in header
4. **Translation Context**: Uses vertical-aware translation keys
5. **Future: Feature Toggles**: Can show/hide features based on vertical config

---

## Phase 2: Specialized Variants

### When to Create a Specialized Variant

Only create a specialized variant when the UX is **fundamentally different**, not just styled differently:

**Criteria for Specialization:**
- Requires unique UI elements not applicable to other verticals
- Has vertical-specific workflows that don't map to standard POS
- User expectations differ significantly from standard retail

### Planned Variants

#### 1. RestaurantPOS

**Unique Requirements:**
- Table management (select table, split tables, transfer orders)
- Course sequencing (appetizers, mains, desserts)
- Kitchen ticket printing with order routing
- Modifier selection (cooking preferences, allergies)
- Split bill functionality

**Implementation:**
```typescript
// apps/web/src/pages/POS/variants/RestaurantPOS.tsx
export function RestaurantPOS() {
  // Table selection UI
  // Course management
  // Kitchen ticket integration
  // Extends StandardPOS base functionality
}
```

#### 2. PharmacyPOS

**Unique Requirements:**
- Prescription lookup and verification
- Controlled substance logging (regulatory compliance)
- Dosage warnings and drug interactions
- Insurance claim integration
- Batch and expiry tracking display

**Implementation:**
```typescript
// apps/web/src/pages/POS/variants/PharmacyPOS.tsx
export function PharmacyPOS() {
  // Prescription search
  // Controlled substance log
  // Drug interaction warnings
  // Insurance integration
}
```

#### 3. WorkshopPOS

**Unique Requirements:**
- Vehicle selection modal (VIN lookup, plate search)
- Job card integration (link sales to service orders)
- Labor time tracking
- Parts catalog with vehicle compatibility
- Service package selection

**Implementation:**
```typescript
// apps/web/src/pages/POS/variants/WorkshopPOS.tsx
export function WorkshopPOS() {
  // Vehicle selector
  // Job card integration
  // Labor tracking
  // Parts compatibility check
}
```

### Variant Loader Pattern (Phase 2)

```typescript
// apps/web/src/pages/POS/POSPage.tsx (Phase 2)
import { lazy, Suspense } from 'react'
import { useCompanyConfig } from '../../contexts'
import { StandardPOS } from './StandardPOS'

// Lazy load specialized variants
const RestaurantPOS = lazy(() => import('./variants/RestaurantPOS'))
const PharmacyPOS = lazy(() => import('./variants/PharmacyPOS'))
const WorkshopPOS = lazy(() => import('./variants/WorkshopPOS'))

// Map verticals to POS variants
const POS_VARIANTS: Record<string, React.ComponentType> = {
  restaurant: RestaurantPOS,
  pharmacy: PharmacyPOS,
  mechanic: WorkshopPOS,  // For service counter POS
}

export function POSPage() {
  const { config, isLoading } = useCompanyConfig()

  if (isLoading || !config) {
    return null
  }

  // Determine which variant to load
  const POSComponent = POS_VARIANTS[config.vertical] ?? StandardPOS

  return (
    <Suspense fallback={<LoadingScreen />}>
      <POSComponent />
    </Suspense>
  )
}
```

---

## Component API

### POSPage

**Purpose:** Loader/router component that determines which POS variant to load

**Props:** None (uses `CompanyConfigContext`)

**Behavior:**
- Reads vertical from `CompanyConfigContext`
- Returns `null` during loading
- Returns `null` on config error (StandardPOS handles error display)
- Renders StandardPOS in Phase 1
- Will render specialized variants in Phase 2 based on vertical

**Example Usage:**
```tsx
import { POSPage } from './pages/POS'

// In routes
<Route path="/pos/sell" element={<POSPage />} />
```

### StandardPOS

**Purpose:** Universal POS component for Phase 1

**Props:** None (uses `CompanyConfigContext`)

**State:**
- `searchQuery: string` - Product search input
- `cart: CartItem[]` - Shopping cart items

**Behavior:**
- Returns `null` during loading (prevents flash)
- Shows error state when config unavailable
- Displays vertical name in header
- Shows currency and locale from config
- Manages cart state locally
- Uses i18n for all user-facing text

**Example Usage:**
```tsx
import { StandardPOS } from './pages/POS'

// Direct use (testing, development)
<StandardPOS />

// Production use (via POSPage loader)
<POSPage />  {/* Loads StandardPOS automatically */}
```

### Cart Item Type

```typescript
interface CartItem {
  id: string          // Product ID
  name: string        // Product name
  price: number       // Unit price
  quantity: number    // Quantity in cart
}
```

---

## Vertical Adaptation

### How StandardPOS Adapts to Different Verticals

StandardPOS uses the `CompanyConfigContext` to access vertical configuration and adapt its behavior:

#### 1. Visual Adaptation

```tsx
// Root element has data-vertical attribute for CSS targeting
<div data-vertical={config.vertical}>
  {/* POS UI */}
</div>
```

**CSS Usage:**
```css
/* Vertical-specific styles */
[data-vertical="pharmacy"] {
  /* Pharmacy-specific overrides */
}

[data-vertical="restaurant"] {
  /* Restaurant-specific overrides */
}
```

#### 2. Currency and Locale

```tsx
// Currency display
<span>{config.currency} {amount.toFixed(2)}</span>

// Locale display (informational)
<span>{config.locale}</span>
```

#### 3. Vertical Name Display

```tsx
// Shows human-readable vertical name
<span className="...">
  {config.vertical.replace('_', ' ')}
</span>
```

#### 4. Translation Context

All text uses i18n translation keys from `sales:pos.*`:

```tsx
const { t } = useTranslation(['common', 'sales'])

<h1>{t('sales:pos.title')}</h1>
<input placeholder={t('sales:pos.searchProducts')} />
<p>{t('sales:pos.emptyCart')}</p>
```

### Vertical Configuration Example

```typescript
// Pharmacy vertical
{
  vertical: 'pharmacy',
  default_modules: ['Identity', 'Sales', 'Inventory', 'Product', 'BatchExpiry'],
  currency: 'TND',
  locale: 'fr_TN',
  country_code: 'TN'
}

// Retail vertical
{
  vertical: 'retail',
  default_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
  currency: 'USD',
  locale: 'en_US',
  country_code: 'US'
}
```

---

## Testing Strategy

### Test Coverage

**Total Tests:** 21 (100% passing)
- POSPage: 10 tests
- StandardPOS: 11 tests

### Test Categories

#### 1. Vertical Adaptation Tests (10 tests)

Tests that StandardPOS and POSPage correctly load for different verticals:

```typescript
describe('Phase 1: StandardPOS for All Verticals', () => {
  it('loads StandardPOS for retail vertical')
  it('loads StandardPOS for pharmacy vertical')
  it('loads StandardPOS for restaurant vertical')
  it('loads StandardPOS for mechanic vertical')
  it('loads StandardPOS for coffee shop vertical')
  it('loads StandardPOS for parts retailer vertical')
  // ...
})
```

**Coverage:**
- ✅ retail, pharmacy, restaurant, mechanic
- ✅ coffee_shop, parts_retailer
- ✅ All verticals render StandardPOS
- ✅ `data-vertical` attribute set correctly

#### 2. State Management Tests (3 tests)

Tests for loading, error, and cart states:

```typescript
describe('Loading State', () => {
  it('shows loading state while config is being fetched')
  it('returns null during loading (no flash)')
})

describe('Error State', () => {
  it('handles config fetch error gracefully')
  it('does not render POS interface on error')
})

describe('Cart Functionality', () => {
  it('shows empty cart initially')
  it('displays cart total section')
})
```

#### 3. Configuration Integration Tests (4 tests)

Tests that verify proper integration with CompanyConfigContext:

```typescript
describe('Config Integration', () => {
  it('passes vertical to StandardPOS component')
  it('uses company config context for vertical detection')
  it('displays currency from config')
  it('displays locale from config')
})
```

#### 4. UI Element Tests (4 tests)

Tests that verify key UI elements are present:

```typescript
describe('Retail Vertical', () => {
  it('renders POS interface for retail vertical')
  it('shows product search in retail POS')
})

describe('Vertical-Specific Features', () => {
  it('adapts UI based on vertical configuration')
})
```

### Test Utilities

**Mock Configuration:**
```typescript
const retailConfig = {
  vertical: 'retail',
  default_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
  enabled_extras: [],
  all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
  currency: 'USD',
  locale: 'en_US',
  country_code: 'US',
}

vi.mocked(api.apiGet).mockResolvedValue(retailConfig)
```

**Render Helper:**
```typescript
const renderPOS = () => {
  return render(
    <QueryClientProvider client={queryClient}>
      <CompanyConfigProvider>
        <StandardPOS />
      </CompanyConfigProvider>
    </QueryClientProvider>
  )
}
```

### Running Tests

```bash
# Run POS tests only
pnpm test src/pages/POS

# Run with coverage
pnpm test:coverage src/pages/POS

# Watch mode for development
pnpm test:watch src/pages/POS
```

---

## Implementation Details

### Translation Keys

All POS translations are in `sales:pos.*` namespace:

**English (`en/sales.json`):**
```json
{
  "pos": {
    "title": "Point of Sale",
    "searchProducts": "Search products...",
    "noProducts": "No products to display",
    "cart": "Cart",
    "items": "items",
    "emptyCart": "Empty cart",
    "noItems": "No items in cart",
    "subtotal": "Subtotal",
    "total": "Total",
    "checkout": "Checkout"
  }
}
```

**French (`fr/sales.json`):**
```json
{
  "pos": {
    "title": "Point de vente",
    "searchProducts": "Rechercher des produits...",
    "noProducts": "Aucun produit à afficher",
    "cart": "Panier",
    "items": "articles",
    "emptyCart": "Panier vide",
    "noItems": "Aucun article dans le panier",
    "subtotal": "Sous-total",
    "total": "Total",
    "checkout": "Paiement"
  }
}
```

### Styling Conventions

**Layout:**
- Uses Tailwind CSS utility classes
- Flex layout with full-height screen
- Two-column layout: `flex-1` (products) + `w-96` (cart)

**Colors:**
- Primary: `blue-600` (buttons, icons, badges)
- Text: `gray-900` (headings), `gray-600` (body), `gray-500` (placeholder)
- Borders: `gray-200` (dividers), `gray-300` (inputs)
- Error: `red-600`

**Responsiveness:**
- Desktop-first design (POS typically used on fixed terminals)
- Fixed cart width (384px / `w-96`)
- Scrollable product area
- Responsive padding and spacing

### Error Handling

**Loading State:**
```typescript
if (isLoading) {
  return null  // Prevents flash of content
}
```

**Error State:**
```typescript
if (error || !config) {
  return (
    <div className="flex h-screen items-center justify-center">
      <div className="text-center">
        <p className="text-lg text-red-600">{t('common:error')}</p>
        <p className="text-sm text-gray-600">
          {error?.message || t('common:error.generic')}
        </p>
      </div>
    </div>
  )
}
```

### Cart Logic

**Total Calculation:**
```typescript
const cartTotal = cart.reduce(
  (sum, item) => sum + item.price * item.quantity,
  0
)
```

**Item Count:**
```typescript
const cartItemCount = cart.reduce(
  (sum, item) => sum + item.quantity,
  0
)
```

**Clear Cart:**
```typescript
const handleClearCart = () => {
  setCart([])
}
```

---

## Future Enhancements

### Phase 1 Improvements

**Before Phase 2 Specialization:**

1. **Product Catalog Integration**
   - Load products from API
   - Product search functionality
   - Add to cart functionality
   - Product categories/filtering

2. **Barcode Scanning**
   - USB barcode scanner support
   - Webcam-based scanning (mobile)
   - Product lookup by barcode

3. **Checkout Flow**
   - Payment method selection
   - Cash/change calculation
   - Receipt printing
   - Email receipt option

4. **Session Management**
   - Open/close POS session
   - Cash drawer tracking
   - Z-report generation

5. **Offline Capability**
   - Service worker for offline POS
   - Local product catalog cache
   - Sync sales when online

6. **Performance Optimization**
   - Memoize cart calculations with `useMemo`
   - Optimize large product catalogs
   - Virtual scrolling for product grid

### Phase 2 Specialization

**Shared Components to Extract:**

1. **POSLayout**
   ```tsx
   // Shared layout for all POS variants
   <POSLayout header={<CustomHeader />} sidebar={<CustomSidebar />}>
     <ProductArea />
   </POSLayout>
   ```

2. **CartManager Hook**
   ```tsx
   // Shared cart logic
   const { cart, addItem, removeItem, updateQuantity, clearCart } = useCartManager()
   ```

3. **ProductSearch Component**
   ```tsx
   // Reusable product search
   <ProductSearch onSelect={handleProductSelect} />
   ```

4. **CheckoutModal**
   ```tsx
   // Shared checkout flow
   <CheckoutModal cart={cart} onComplete={handleCheckout} />
   ```

**Variant-Specific Features:**

1. **RestaurantPOS**
   - Table layout grid
   - Course tagging UI
   - Kitchen display integration
   - Table transfer modal

2. **PharmacyPOS**
   - Prescription input form
   - Drug interaction checker
   - Controlled substance logger
   - Insurance eligibility verification

3. **WorkshopPOS**
   - Vehicle selector modal
   - Job card integration panel
   - Labor hour calculator
   - Parts compatibility checker

### Performance Targets

**Phase 2 Performance Goals:**
- Initial load: < 1 second
- Product search: < 100ms response
- Add to cart: < 50ms
- Checkout: < 500ms to complete

---

## Migration Guide (Phase 1 → Phase 2)

### When to Migrate a Vertical to a Specialized Variant

Migrate when:
1. User feedback indicates UX doesn't match expectations
2. Vertical-specific features can't be cleanly added to StandardPOS
3. Performance requires vertical-specific optimizations

### Migration Steps

1. **Create Variant Component**
   ```bash
   # Create new variant file
   touch apps/web/src/pages/POS/variants/RestaurantPOS.tsx
   ```

2. **Extract Common Logic**
   ```tsx
   // Move shared logic to hooks
   export function usePOSBase() {
     const { cart, addItem, removeItem } = useCartManager()
     const { config } = useCompanyConfig()
     return { cart, addItem, removeItem, config }
   }
   ```

3. **Update POSPage Loader**
   ```tsx
   const POS_VARIANTS: Record<string, React.ComponentType> = {
     restaurant: RestaurantPOS,  // Add new variant
     // ... other variants
   }
   ```

4. **Add Variant Tests**
   ```bash
   # Create test file for new variant
   touch apps/web/src/pages/POS/variants/__tests__/RestaurantPOS.test.tsx
   ```

5. **Update Documentation**
   - Document variant-specific features
   - Update architecture diagrams
   - Add migration notes

---

## Troubleshooting

### Common Issues

**Issue:** POS not loading for a vertical
- **Check:** `CompanyConfigContext` is providing configuration
- **Verify:** Vertical is in `all_enabled_modules`
- **Debug:** Check browser console for React errors

**Issue:** Translations showing as keys (e.g., `sales:pos.title`)
- **Check:** Translation files have `pos` object
- **Verify:** i18next is initialized
- **Debug:** Check browser Network tab for locale file loading

**Issue:** Cart total is incorrect
- **Check:** `CartItem.price` is a number, not string
- **Verify:** Quantity is being updated correctly
- **Debug:** Add `console.log` in cart total calculation

**Issue:** Tests failing for a specific vertical
- **Check:** Mock configuration includes all required fields
- **Verify:** `data-vertical` attribute is being set
- **Debug:** Use `screen.debug()` in test to inspect rendered output

### Debug Mode

Enable debug logging:

```typescript
// Add to StandardPOS.tsx for debugging
useEffect(() => {
  console.log('[POS] Loaded for vertical:', config?.vertical)
  console.log('[POS] Cart state:', cart)
  console.log('[POS] Cart total:', cartTotal)
}, [config, cart, cartTotal])
```

---

## References

- [Frontend Security Documentation](./frontend-security.md) - Route guards
- [Frontend Navigation Documentation](./frontend-navigation.md) - Dynamic navigation
- [Frontend Contexts Documentation](./frontend-contexts.md) - CompanyConfigContext
- [CLAUDE.md](../../CLAUDE.md) - Coding conventions (i18n, TypeScript)
- [MULTI-APP-SCAFFOLDING-FINAL.md](../new_docs/MULTI-APP-SCAFFOLDING-FINAL.md) - Vertical system spec

---

## Changelog

**2026-01-03 - Milestone 10 Complete**
- ✅ Implemented StandardPOS component (Phase 1)
- ✅ Implemented POSPage loader
- ✅ Added 21 comprehensive tests (all passing)
- ✅ Added i18n translations (English + French)
- ✅ Integrated with CompanyConfigContext
- ✅ Documented architecture for Phase 2 expansion
- ✅ Opus 4.5 architecture review: 47/60 (CONDITIONAL PASS)
- ✅ Fixed i18n violations from Opus review
- ✅ All CLAUDE.md conventions followed

**Next Steps:**
- [ ] Integrate product catalog API
- [ ] Implement add-to-cart functionality
- [ ] Add checkout flow
- [ ] Implement barcode scanning
- [ ] Create POS session management
