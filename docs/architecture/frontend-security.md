# Frontend Security - Route Guards & Module Protection

**Document Version:** 1.0
**Last Updated:** 2026-01-03
**Milestone:** 9 (Route Guards)

---

## Overview

The frontend security system provides multi-layered route protection based on:
1. **Vertical Configuration** - Which modules are enabled for the business type
2. **User Permissions** - What actions the user is allowed to perform

This ensures users only access features relevant to their business vertical AND permitted by their role.

**Key Components:**
- `ModuleGuard` - Vertical-based route protection
- `RequirePermission` - Permission-based route protection (existing)
- Combined layering for comprehensive security

---

## Architecture

### Security Layers

```
┌──────────────────────────────────────────────────────────────┐
│                    User Attempts Route Access                │
│                    /vehicles or /services                    │
└──────────────────────┬───────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────┐
│              LAYER 1: Authentication (RequireAuth)           │
│  Is user logged in?                                         │
│  - Not authenticated → Redirect to /login                   │
└──────────────────────┬───────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────┐
│        LAYER 2: Vertical Filtering (ModuleGuard)            │
│  Is this module enabled for the business vertical?          │
│  - Uses CompanyConfigContext.hasModule()                    │
│  - Not enabled → Redirect to /dashboard                     │
└──────────────────────┬───────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────┐
│     LAYER 3: Permission Check (RequirePermission)           │
│  Does user have permission for this action?                 │
│  - Uses usePermissions().canAccessModule()                  │
│  - No permission → Show 403 or redirect                     │
└──────────────────────┬───────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────┐
│                    Route Component Renders                   │
│              User has vertical + permission access           │
└──────────────────────────────────────────────────────────────┘
```

### Why This Order?

1. **Authentication first** - Fast check, no API calls needed
2. **Vertical second** - Eliminates entire modules quickly (simple map lookup)
3. **Permissions third** - Granular check for specific actions (more expensive)

---

## ModuleGuard Component

### Purpose

Protects routes by checking if a module is enabled for the current company's vertical configuration.

**File:** `apps/web/src/components/guards/ModuleGuard.tsx`

### API

```typescript
interface ModuleGuardProps {
  /**
   * Module name to check (e.g., "Vehicle", "Workshop", "Menu")
   * Must match the backend module name from vertical configuration.
   */
  module: string

  /**
   * Path to redirect to if module is not enabled
   * @default "/dashboard"
   */
  fallback?: string

  /**
   * Content to render if module is enabled
   */
  children: ReactNode
}
```

### Behavior

**When Module is Enabled:**
```tsx
// User with mechanic vertical accessing Vehicle route
<ModuleGuard module="Vehicle">
  <VehicleListPage />
</ModuleGuard>
// → Renders VehicleListPage
```

**When Module is NOT Enabled:**
```tsx
// User with pharmacy vertical accessing Vehicle route
<ModuleGuard module="Vehicle">
  <VehicleListPage />
</ModuleGuard>
// → Redirects to /dashboard
```

**During Loading:**
```tsx
// Config is being fetched
<ModuleGuard module="Vehicle">
  <VehicleListPage />
</ModuleGuard>
// → Renders null (prevents flash)
```

**On Error:**
```tsx
// Config fetch failed
<ModuleGuard module="Vehicle">
  <VehicleListPage />
</ModuleGuard>
// → Redirects to /dashboard (safe default)
```

### Usage Examples

#### Basic Usage

```tsx
import { ModuleGuard } from '@/components/guards'

<Route
  path="/vehicles"
  element={
    <ModuleGuard module="Vehicle">
      <VehicleListPage />
    </ModuleGuard>
  }
/>
```

#### With Custom Fallback

```tsx
<ModuleGuard module="Workshop" fallback="/">
  <ServicesPage />
</ModuleGuard>
```

#### Combined with RequirePermission

```tsx
<ModuleGuard module="Vehicle">          {/* Vertical filtering */}
  <RequirePermission moduleKey="vehicles">  {/* Permission filtering */}
    <VehicleListPage />
  </RequirePermission>
</ModuleGuard>
```

---

## Guard Layering Pattern

### Standard Pattern (REQUIRED)

For vertical-specific routes, ALWAYS use this pattern:

```tsx
<Route
  path="/vehicles"
  element={
    <ModuleGuard module="Vehicle">              {/* 1. Vertical check */}
      <RequirePermission moduleKey="vehicles">  {/* 2. Permission check */}
        <SuspenseWrapper>                       {/* 3. Lazy loading */}
          <VehicleListPage />                   {/* 4. Component */}
        </SuspenseWrapper>
      </RequirePermission>
    </ModuleGuard>
  }
/>
```

### Why This Order Matters

**Correct (ModuleGuard OUTSIDE):**
```tsx
<ModuleGuard module="Vehicle">
  <RequirePermission moduleKey="vehicles">
    <Component />
  </RequirePermission>
</ModuleGuard>
```
✅ Vertical check happens first (faster, eliminates entire modules)
✅ If vertical fails, permission check never runs (performance)
✅ Clear separation of concerns

**Incorrect (ModuleGuard INSIDE):**
```tsx
<RequirePermission moduleKey="vehicles">
  <ModuleGuard module="Vehicle">
    <Component />
  </RequirePermission>
</ModuleGuard>
```
❌ Permission check runs first (slower)
❌ If permission fails but vertical would have blocked anyway, wasted work
❌ Confusing error messages

---

## Protected Routes

### Vehicle Module Routes

**Backend Module:** `Vehicle`
**Verticals:** mechanic, body_shop, parts_retailer, car_glass, tire_shop

| Route | Component | Guards |
|-------|-----------|--------|
| `/vehicles` | VehicleListPage | ModuleGuard("Vehicle") + RequirePermission("vehicles") |
| `/vehicles/new` | VehicleForm | ModuleGuard("Vehicle") + RequirePermission("vehicles.create") |
| `/vehicles/:id` | VehicleDetailPage | ModuleGuard("Vehicle") + RequirePermission("vehicles") |
| `/vehicles/:id/edit` | VehicleForm | ModuleGuard("Vehicle") + RequirePermission("vehicles.edit") |

**Example Implementation:**
```tsx
{/* File: apps/web/src/routes/index.tsx */}

<Route
  path="vehicles"
  element={
    <ModuleGuard module="Vehicle">
      <RequirePermission moduleKey="vehicles">
        <SuspenseWrapper>
          <VehicleListPage />
        </SuspenseWrapper>
      </RequirePermission>
    </ModuleGuard>
  }
/>
```

---

### Services/Workshop Module Routes

**Backend Module:** `Workshop`
**Verticals:** mechanic, body_shop, car_glass

| Route | Component | Guards |
|-------|-----------|--------|
| `/services` | ServiceListPage | ModuleGuard("Workshop") + RequirePermission("services") |
| `/services/new` | ServiceForm | ModuleGuard("Workshop") + RequirePermission("services.create") |
| `/services/categories` | ServiceCategoryListPage | ModuleGuard("Workshop") + RequirePermission("services") |
| `/services/:id` | ServiceDetailPage | ModuleGuard("Workshop") + RequirePermission("services") |
| `/services/:id/edit` | ServiceForm | ModuleGuard("Workshop") + RequirePermission("services.edit") |

**Example Implementation:**
```tsx
<Route path="services">
  <Route
    index
    element={
      <ModuleGuard module="Workshop">
        <RequirePermission moduleKey="services">
          <SuspenseWrapper>
            <ServiceListPage />
          </SuspenseWrapper>
        </RequirePermission>
      </ModuleGuard>
    }
  />
</Route>
```

---

## Core Routes (No Module Guard)

These routes are available to ALL verticals and do NOT need ModuleGuard:

- `/dashboard` - Always accessible
- `/sales/*` - Sales module (core)
- `/purchases/*` - Purchases module (core)
- `/inventory/*` - Inventory module (core)
- `/treasury/*` - Treasury module (core)
- `/finance/*` - Finance module (core)
- `/pricing/*` - Pricing module (core)
- `/reports` - Reports (core)
- `/settings` - Settings (core)

**Pattern for Core Routes:**
```tsx
<Route
  path="/sales/invoices"
  element={
    {/* NO ModuleGuard - core module */}
    <RequirePermission moduleKey="sales">
      <SuspenseWrapper>
        <InvoiceListPage />
      </SuspenseWrapper>
    </RequirePermission>
  }
/>
```

---

## Adding Guards to New Vertical-Specific Routes

### Step 1: Identify if Route Needs Guard

**Needs ModuleGuard:**
- Module is in `config/verticals.php` as vertical-specific
- Module appears in some verticals but not others
- Examples: Vehicle, Workshop, Menu, Tables, BatchExpiry, Fleet

**Does NOT Need ModuleGuard:**
- Module is in ALL verticals (core module)
- Examples: Sales, Inventory, Treasury, Finance, Identity

### Step 2: Determine Module Name

Check `apps/api/config/verticals.php`:

```php
'mechanic' => [
    'default_modules' => [
        'Identity',    // Core
        'Vehicle',     // Vertical-specific ← Use "Vehicle" in ModuleGuard
        'Workshop',    // Vertical-specific ← Use "Workshop" in ModuleGuard
        'Sales',       // Core
    ],
],
```

### Step 3: Apply Guard to Route

```tsx
// File: apps/web/src/routes/index.tsx

import { ModuleGuard } from '../components/guards'

<Route
  path="/your-route"
  element={
    <ModuleGuard module="YourModuleName">  {/* ← PascalCase module name */}
      <RequirePermission moduleKey="your_module">
        <SuspenseWrapper>
          <YourComponent />
        </SuspenseWrapper>
      </RequirePermission>
    </ModuleGuard>
  }
/>
```

### Step 4: Add Tests

```tsx
// File: apps/web/src/components/guards/__tests__/ModuleGuard.test.tsx

describe('Your Module', () => {
  it('allows access when module is enabled', async () => {
    const config = {
      vertical: 'your_vertical',
      all_enabled_modules: ['Identity', 'YourModule'],
    }
    vi.mocked(api.apiGet).mockResolvedValue(config)

    renderWithRouter('YourModule')

    const content = await screen.findByText('Module Accessible')
    expect(content).toBeInTheDocument()
  })

  it('redirects when module is not enabled', async () => {
    const config = {
      vertical: 'other_vertical',
      all_enabled_modules: ['Identity', 'Sales'],
    }
    vi.mocked(api.apiGet).mockResolvedValue(config)

    renderWithRouter('YourModule')

    await waitFor(() => {
      const fallback = screen.getByText('Dashboard Fallback')
      expect(fallback).toBeInTheDocument()
    })
  })
})
```

---

## Testing Strategy

### Unit Tests (ModuleGuard Component)

**File:** `apps/web/src/components/guards/__tests__/ModuleGuard.test.tsx`

**14 comprehensive tests covering:**

1. **Mechanic Vertical (2 tests)**
   - Allows access to Vehicle module
   - Allows access to Workshop module

2. **Pharmacy Vertical (2 tests)**
   - Blocks access to Vehicle module
   - Blocks access to Workshop module

3. **Custom Fallback (1 test)**
   - Redirects to custom path when specified

4. **Core Modules (2 tests)**
   - Allows access to Sales module for any vertical
   - Allows access to Inventory module for any vertical

5. **Loading State (1 test)**
   - Shows nothing while config is loading

6. **Error State (1 test)**
   - Redirects to dashboard on config fetch error

7. **Case Handling (2 tests)**
   - Correctly handles PascalCase module names
   - Correctly handles multi-word module names

8. **Enabled Extras (2 tests)**
   - Allows access to Fleet extra module
   - Allows access to Appointments extra module

9. **Module Not in List (1 test)**
   - Redirects when module not in all_enabled_modules

### Running Tests

```bash
npm test -- src/components/guards/__tests__/ModuleGuard.test.tsx
```

**Expected Output:**
```
✓ ModuleGuard - Route Protection (14 tests)
  ✓ Mechanic Vertical > allows access when module is enabled
  ✓ Mechanic Vertical > allows access to Workshop module
  ✓ Pharmacy Vertical > redirects when module not enabled
  ✓ Pharmacy Vertical > redirects when Workshop not available
  ✓ Custom Fallback Path > redirects to custom fallback
  ✓ Core Modules > allows access to Sales module
  ✓ Core Modules > allows access to Inventory module
  ✓ Loading State > shows loading state
  ✓ Error State > redirects to dashboard on error
  ✓ Module Key Mapping > correctly handles PascalCase
  ✓ Module Key Mapping > correctly handles multi-word names
  ✓ Integration with Enabled Extras > allows access to Fleet
  ✓ Integration with Enabled Extras > allows access to Appointments
  ✓ Module Not in All Enabled Modules > redirects when not in list

Test Files  1 passed (1)
Tests  14 passed (14)
```

---

## Troubleshooting

### Issue: User sees 404 when accessing vertical-specific route

**Symptoms:** User with pharmacy vertical tries to access `/vehicles` and gets 404

**Diagnosis:**
```tsx
// Check if ModuleGuard is applied to route
<Route path="/vehicles" element={
  <ModuleGuard module="Vehicle">  // ← Should be present
    <VehicleListPage />
  </ModuleGuard>
} />
```

**Expected Behavior:** Should redirect to `/dashboard`, not show 404

**Fix:** Ensure ModuleGuard is wrapping the route element

---

### Issue: User redirected but module should be available

**Symptoms:** User with mechanic vertical is redirected from `/vehicles`

**Diagnosis Steps:**

1. Check company config in browser console:
   ```javascript
   const { config } = useCompanyConfig()
   console.log(config.all_enabled_modules)
   // Should include 'Vehicle' for mechanic
   ```

2. Check module name case:
   ```tsx
   <ModuleGuard module="Vehicle">  // ✓ Correct (PascalCase)
   <ModuleGuard module="vehicle">  // ✗ Wrong (lowercase)
   ```

3. Check vertical configuration:
   ```bash
   # Backend
   php artisan tinker
   > $tenant = Tenant::first()
   > $tenant->vertical
   // Should be 'mechanic'
   ```

**Common Fixes:**
- Verify module name matches backend exactly (case-sensitive)
- Check tenant's vertical is set correctly
- Verify module is in vertical's default_modules or enabled_extras

---

### Issue: Permission error after passing module guard

**Symptoms:** User redirected by ModuleGuard works, but gets 403 Forbidden

**Diagnosis:**
- ModuleGuard passed (vertical allows module)
- RequirePermission failed (user lacks permission)

**Fix:** Assign the appropriate permission to the user's role
```sql
-- Check user permissions
SELECT * FROM model_has_permissions
WHERE model_id = 'user_id'
AND permission_id IN (
  SELECT id FROM permissions WHERE name LIKE 'vehicles.%'
);
```

---

### Issue: Loading state shows flash of content

**Symptoms:** Component briefly renders before redirecting

**Diagnosis:** ModuleGuard is not handling loading state

**Expected Behavior:**
```tsx
if (isLoading) {
  return null  // ← Prevents flash
}
```

**Fix:** Ensure ModuleGuard returns `null` during loading (check implementation)

---

## Security Checklist

### Before Deploying a New Vertical-Specific Feature

- [ ] Module added to `config/verticals.php` for appropriate verticals
- [ ] Backend RequireModule middleware applied to API routes
- [ ] Frontend ModuleGuard applied to all feature routes
- [ ] ModuleGuard is OUTER wrapper (before RequirePermission)
- [ ] Module name matches backend exactly (PascalCase)
- [ ] Tests added for vertical showing/hiding module
- [ ] Sidebar navigation item has vertical filtering (MODULE_NAME_MAP)
- [ ] All sub-routes protected (list, new, detail, edit, etc.)

### Verifying Route Protection

```tsx
// Checklist for each vertical-specific route:
<Route path="/feature" element={
  <ModuleGuard module="ModuleName">     {/* 1. Has ModuleGuard? */}
    <RequirePermission moduleKey="feature">  {/* 2. Has RequirePermission? */}
      <SuspenseWrapper>                      {/* 3. Has SuspenseWrapper? */}
        <Component />                        {/* 4. Component renders */}
      </SuspenseWrapper>
    </RequirePermission>
  </ModuleGuard>
} />
```

---

## Performance Considerations

### ModuleGuard Performance

**Rendering Behavior:**
- **On mount:** Reads from CompanyConfigContext (already cached)
- **During loading:** Returns `null` (no DOM rendering)
- **After load:** One-time module check via `hasModule()` (simple array includes)
- **Re-renders:** Only when config changes (rare)

**Cost per Route:**
- **Memory:** Negligible (<1KB per guard instance)
- **CPU:** O(n) array includes check where n = number of modules (~10)
- **Network:** Zero (uses cached config)

### Optimization Tips

1. **Config is Cached:** CompanyConfigContext caches for 1 hour, no repeated API calls
2. **Guards are Lightweight:** Simple boolean check, no expensive operations
3. **Loading Returns Null:** No DOM manipulation during loading state
4. **Memoized Functions:** `hasModule` is memoized in CompanyConfigContext

---

## Related Documentation

- **Backend:** `docs/architecture/security.md` - RequireModule middleware
- **Backend:** `docs/api/company-config.md` - Company Config API
- **Backend:** `docs/architecture/verticals.md` - Vertical configuration
- **Frontend:** `docs/architecture/frontend-contexts.md` - CompanyConfigContext
- **Frontend:** `docs/architecture/frontend-navigation.md` - Dynamic Sidebar filtering
- **Backend:** `apps/api/config/verticals.php` - Module-to-vertical mapping

---

## Files

### Implementation
- `apps/web/src/components/guards/ModuleGuard.tsx` (70 lines)
- `apps/web/src/components/guards/index.ts` (barrel export)
- `apps/web/src/routes/index.tsx` (route definitions with guards)

### Tests
- `apps/web/src/components/guards/__tests__/ModuleGuard.test.tsx` (14 tests)

---

## Quality Metrics

**Opus 4.5 Audit Results (2026-01-03):**
- **Overall:** PASS - Production Ready
- **TypeScript Quality:** 9/10
- **React Patterns:** 10/10
- **Test Coverage:** 10/10
- **Security (Route Protection):** 10/10

**Test Results:**
- 14 tests passing
- Covers all major verticals
- Covers loading and error states
- Covers custom fallbacks and extras

**Route Coverage:**
- ✅ Vehicle routes: 4/4 protected
- ✅ Services routes: 5/5 protected
- ✅ Core routes: Correctly NOT guarded

---

*Document Version: 1.0*
*Last Updated: 2026-01-03*
*Milestone: 9 (Route Guards - Day 15)*
