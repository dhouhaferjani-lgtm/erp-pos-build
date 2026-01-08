# Frontend Contexts - Multi-App Vertical System

**Document Version:** 1.0
**Last Updated:** 2025-12-30
**Milestone:** 7 (Frontend Contexts)

---

## Overview

The frontend uses React Context API to provide global configuration for the multi-app vertical system. This allows components throughout the application to access:

1. **Product Configuration** (IziPOS vs Otospex) - Determines branding, features, and vertical focus
2. **Company Configuration** (Vertical & Modules) - Determines which modules are available for the current company

These contexts work together to enable dynamic feature toggling and vertical-specific UI behavior.

---

## Architecture

### Context Hierarchy

```tsx
<ErrorBoundary>
  <ProductConfigProvider>           {/* 1. Product detection (env var) */}
    <AuthProvider>                   {/* 2. Authentication */}
      <CompanyProvider>              {/* 3. Company/tenant context */}
        <CompanyConfigProvider>      {/* 4. Module configuration (API) */}
          <LocationProvider>         {/* 5. Multi-location support */}
            <AppRoutes />            {/* 6. Application routes */}
          </LocationProvider>
        </CompanyConfigProvider>
      </CompanyProvider>
    </AuthProvider>
  </ProductConfigProvider>
</ErrorBoundary>
```

**Why this order?**

1. **ProductConfigProvider** is outermost because it doesn't depend on authentication or API calls - it reads from environment variable
2. **AuthProvider** comes next to establish user session
3. **CompanyProvider** requires authentication to fetch company data
4. **CompanyConfigProvider** requires both authentication and company context to fetch vertical configuration
5. **LocationProvider** may depend on company configuration for multi-location features

---

## ProductConfigContext

### Purpose

Provides product variant configuration based on environment variable `VITE_APP_PRODUCT`. This enables the same codebase to serve different products with different branding and feature sets.

### Supported Products

| Product | Environment Value | Name | Description |
|---------|------------------|------|-------------|
| **IziPOS** | `izipos` | IziPOS | Modern POS and business management system |
| **Otospex** | `otospex` | Otospex | Complete automotive business management solution |

### Configuration

Set the product in your `.env` file:

```bash
VITE_APP_PRODUCT=izipos   # For IziPOS
VITE_APP_PRODUCT=otospex  # For Otospex
```

**Default:** If not set or invalid, defaults to `izipos`

### API

```typescript
export type Product = 'izipos' | 'otospex'

interface ProductConfigContextValue {
  product: Product                // Current product ('izipos' or 'otospex')
  isIziPOS: boolean              // Convenience flag for IziPOS
  isOtospex: boolean             // Convenience flag for Otospex
  productName: string            // Display name ('IziPOS' or 'Otospex')
  productDescription: string     // Product description
}
```

### Usage

```tsx
import { useProductConfig } from '@/contexts'

function Header() {
  const { productName, isIziPOS, isOtospex } = useProductConfig()

  return (
    <header>
      <h1>{productName}</h1>
      {isIziPOS && <POSQuickActions />}
      {isOtospex && <VehicleLookup />}
    </header>
  )
}
```

### Implementation Details

- **Performance:** Context value is memoized with empty dependency array since product never changes at runtime
- **Validation:** Validates environment variable and logs warning if invalid
- **Type Safety:** Uses TypeScript union type for strict product values
- **Case Handling:** Normalizes to lowercase for case-insensitive matching

**Source:** `apps/web/src/contexts/ProductConfigContext.tsx`

---

## CompanyConfigContext

### Purpose

Fetches and provides the effective module configuration for the current company based on:
- Tenant's vertical (e.g., 'mechanic', 'pharmacy', 'restaurant')
- Default modules for that vertical
- Additional extras enabled for the tenant

### Backend Integration

Calls `GET /api/v1/company/config` which returns:

```json
{
  "data": {
    "vertical": "mechanic",
    "default_modules": ["Identity", "Vehicle", "Workshop"],
    "enabled_extras": ["Fleet", "Appointments"],
    "all_enabled_modules": ["Identity", "Vehicle", "Workshop", "Fleet", "Appointments"]
  }
}
```

### API

```typescript
export interface CompanyConfig {
  vertical: string                // Business vertical
  default_modules: string[]       // Default modules for this vertical
  enabled_extras: string[]        // Additional modules enabled
  all_enabled_modules: string[]   // Combined list (default + extras)
}

interface CompanyConfigContextValue {
  config: CompanyConfig | null    // Configuration (null while loading)
  isLoading: boolean             // Loading state
  error: Error | null            // Error state
  hasModule: (moduleName: string) => boolean  // Utility function
}
```

### Usage

#### Conditional Rendering

```tsx
import { useCompanyConfig } from '@/contexts'

function VehicleFeature() {
  const { hasModule } = useCompanyConfig()

  if (!hasModule('Vehicle')) {
    return null // Hide if Vehicle module not enabled
  }

  return <VehicleManagement />
}
```

#### Navigation Filtering

```tsx
import { useCompanyConfig } from '@/contexts'

function Sidebar() {
  const { config, hasModule } = useCompanyConfig()

  const menuItems = [
    { name: 'Dashboard', path: '/', module: null },
    { name: 'Vehicles', path: '/vehicles', module: 'Vehicle' },
    { name: 'Workshop', path: '/workshop', module: 'Workshop' },
    { name: 'Appointments', path: '/appointments', module: 'Appointments' },
  ]

  return (
    <nav>
      {menuItems
        .filter(item => !item.module || hasModule(item.module))
        .map(item => (
          <NavLink key={item.path} to={item.path}>
            {item.name}
          </NavLink>
        ))
      }
    </nav>
  )
}
```

#### Loading State

```tsx
import { useCompanyConfig } from '@/contexts'

function Dashboard() {
  const { config, isLoading, error } = useCompanyConfig()

  if (isLoading) {
    return <LoadingSpinner />
  }

  if (error) {
    return <ErrorMessage error={error} />
  }

  return (
    <div>
      <h1>Welcome to {config?.vertical} Dashboard</h1>
      {/* Rest of dashboard */}
    </div>
  )
}
```

### Implementation Details

- **Caching:** Uses React Query with 1-hour `staleTime` since config rarely changes
- **Retry Strategy:** Retries once on failure to handle transient network issues
- **Performance:** Context value and `hasModule` function are memoized to prevent unnecessary re-renders
- **Error Handling:** Throws error if hook used outside provider
- **Type Safety:** Full TypeScript typing for all returned values

**Source:** `apps/web/src/contexts/CompanyConfigContext.tsx`

---

## Performance Considerations

### ProductConfigContext

- ✅ **Optimal:** Context value memoized with empty dependency array
- ✅ **Static:** Product never changes at runtime, so no re-renders
- ✅ **Lightweight:** No API calls, no side effects

### CompanyConfigContext

- ✅ **Memoized:** Context value wrapped in `useMemo` to prevent re-renders
- ✅ **Cached:** React Query caches data for 1 hour
- ✅ **Smart Retry:** Only retries once on failure
- ⚠️ **API Call:** Makes one API call on mount (cached thereafter)

### Provider Placement

Both providers are placed high in the component tree to ensure:
1. Single instance across entire application
2. Configuration available to all components
3. Minimal re-renders (providers rarely update)

---

## Testing Patterns

### Unit Testing ProductConfigContext

```typescript
import { renderHook } from '@testing-library/react'
import { ProductConfigProvider, useProductConfig } from '../ProductConfigContext'

it('provides IziPOS config when env var is set', () => {
  import.meta.env.VITE_APP_PRODUCT = 'izipos'

  const wrapper = ({ children }) => (
    <ProductConfigProvider>{children}</ProductConfigProvider>
  )

  const { result } = renderHook(() => useProductConfig(), { wrapper })

  expect(result.current.product).toBe('izipos')
  expect(result.current.isIziPOS).toBe(true)
  expect(result.current.isOtospex).toBe(false)
})
```

### Unit Testing CompanyConfigContext

```typescript
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CompanyConfigProvider, useCompanyConfig } from '../CompanyConfigContext'
import * as api from '../../lib/api'

// Mock API
vi.mock('../../lib/api', () => ({
  apiGet: vi.fn(),
}))

it('provides company config when loaded', async () => {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  const mockConfig = {
    vertical: 'mechanic',
    default_modules: ['Identity', 'Vehicle', 'Workshop'],
    enabled_extras: ['Fleet'],
    all_enabled_modules: ['Identity', 'Vehicle', 'Workshop', 'Fleet'],
  }

  vi.mocked(api.apiGet).mockResolvedValueOnce(mockConfig)

  const wrapper = ({ children }) => (
    <QueryClientProvider client={queryClient}>
      <CompanyConfigProvider>{children}</CompanyConfigProvider>
    </QueryClientProvider>
  )

  const { result } = renderHook(() => useCompanyConfig(), { wrapper })

  expect(result.current.isLoading).toBe(true)

  await waitFor(() => {
    expect(result.current.isLoading).toBe(false)
  })

  expect(result.current.config).toEqual(mockConfig)
  expect(result.current.hasModule('Vehicle')).toBe(true)
  expect(result.current.hasModule('BatchExpiry')).toBe(false)
})
```

**See:**
- `apps/web/src/contexts/__tests__/ProductConfigContext.test.tsx` (10 tests)
- `apps/web/src/contexts/__tests__/CompanyConfigContext.test.tsx` (10 tests)

---

## Error Handling

### ProductConfigContext

**Invalid Environment Variable:**
```typescript
// If VITE_APP_PRODUCT='invalid_value'
// Console warning: "Invalid VITE_APP_PRODUCT value: invalid_value. Defaulting to 'izipos'."
// Returns: product = 'izipos'
```

**Used Outside Provider:**
```typescript
// Throws: "useProductConfig must be used within a ProductConfigProvider"
```

### CompanyConfigContext

**API Failure:**
```typescript
const { config, error } = useCompanyConfig()

if (error) {
  // error is Error | null
  return <ErrorMessage message={error.message} />
}
```

**Used Outside Provider:**
```typescript
// Throws: "useCompanyConfig must be used within a CompanyConfigProvider"
```

---

## Migration Guide

### Adding New Product Variant

1. Update `Product` type in `ProductConfigContext.tsx`:
   ```typescript
   export type Product = 'izipos' | 'otospex' | 'newproduct'
   ```

2. Add to `PRODUCT_INFO` mapping:
   ```typescript
   const PRODUCT_INFO: Record<Product, ProductInfo> = {
     izipos: { ... },
     otospex: { ... },
     newproduct: {
       name: 'New Product',
       description: 'Description here',
     },
   }
   ```

3. Update validation in `getCurrentProduct()`:
   ```typescript
   if (normalized === 'izipos' || normalized === 'otospex' || normalized === 'newproduct') {
     return normalized
   }
   ```

4. Add tests for new product

### Adding Module-Specific Features

Use the `hasModule` utility:

```tsx
function FeatureToggle() {
  const { hasModule } = useCompanyConfig()

  return (
    <>
      {hasModule('NewModule') && <NewFeature />}
    </>
  )
}
```

---

## Troubleshooting

### Issue: "useProductConfig must be used within a ProductConfigProvider"

**Cause:** Component trying to use `useProductConfig()` is not a child of `ProductConfigProvider`

**Fix:** Ensure `ProductConfigProvider` wraps your component tree (should be in `App.tsx`)

### Issue: "useCompanyConfig must be used within a CompanyConfigProvider"

**Cause:** Component trying to use `useCompanyConfig()` is not a child of `CompanyConfigProvider`

**Fix:** Ensure `CompanyConfigProvider` wraps your component tree (should be in `App.tsx`)

### Issue: Product always shows as 'izipos' even when env var is set

**Cause:** Environment variables in Vite require `VITE_` prefix

**Fix:** Use `VITE_APP_PRODUCT=otospex` (not `APP_PRODUCT=otospex`)

**Restart dev server:** Environment variables are loaded at build time

### Issue: Company config shows stale data after changing tenant vertical

**Cause:** React Query cache hasn't invalidated

**Fix:** Backend automatically invalidates cache on tenant update. If still stale, check:
1. `TenantObserver` is registered in `AppServiceProvider`
2. Cache key matches: `tenant_config:{tenant_id}`
3. Frontend React Query is not overriding `staleTime`

---

## Related Documentation

- **Backend:** `docs/api/company-config.md` - Company Config API endpoint
- **Backend:** `docs/architecture/security.md` - RequireModule middleware
- **Backend:** `docs/architecture/verticals.md` - All 12 business verticals
- **Frontend:** `docs/architecture/frontend-navigation.md` - Dynamic navigation (next milestone)
- **Frontend:** `docs/architecture/frontend-security.md` - Route guards (next milestone)

---

## Files

### Implementation
- `apps/web/src/contexts/CompanyConfigContext.tsx` (91 lines)
- `apps/web/src/contexts/ProductConfigContext.tsx` (120 lines)
- `apps/web/src/contexts/index.ts` (11 lines - barrel exports)
- `apps/web/src/App.tsx` (provider hierarchy)

### Tests
- `apps/web/src/contexts/__tests__/CompanyConfigContext.test.tsx` (10 tests)
- `apps/web/src/contexts/__tests__/ProductConfigContext.test.tsx` (10 tests)

**Total:** 20 tests, all passing

---

## Quality Metrics

**Opus 4.5 Audit Results (2025-12-30):**
- **Overall:** PASS - Production Ready
- **TypeScript Quality:** 9/10
- **React Patterns:** 8.5/10 (improved to 10/10 after memoization fixes)
- **Production Readiness:** READY

**Test Coverage:**
- CompanyConfigContext: 10/10 scenarios covered
- ProductConfigContext: 10/10 scenarios covered
- Integration: Provider hierarchy tested

---

*Document Version: 1.0*
*Last Updated: 2025-12-30*
*Milestone: 7 (Frontend Contexts - Day 11-12)*
