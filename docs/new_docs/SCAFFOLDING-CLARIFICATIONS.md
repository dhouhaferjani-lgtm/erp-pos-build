# Multi-App Scaffolding - Clarifications

Answers to review questions for MULTI-APP-SCAFFOLDING-V2.md implementation.

---

## 1. JSONB vs Junction Table for Extras

**Decision: JSONB is fine for Phase 1.**

The extras list is small and static (appointments, fleet_management, etc.). We're not tracking when extras were enabled/disabled or by whom. If audit trail becomes a requirement later, we migrate to junction table. Don't over-engineer now.

---

## 2. Company Override of Tenant Vertical

**Decision: Remove entirely. Not needed.**

One tenant = one vertical. Period. Remove the `vertical` column from `companies` table.

**For multi-business scenarios (franchise with different business types):**

Instead of company-level override, we'll implement a **tenant switching** feature later:

- User belongs to an Organization (optional grouping layer)
- Organization can own multiple Tenants
- Each Tenant has its own vertical (fully isolated)
- User logs in once, can switch between tenants (like Slack workspaces)

This is cleaner because:
- Each tenant is fully isolated with its own vertical
- RLS works perfectly at tenant level
- No hybrid states or ambiguity
- Additive feature—doesn't require redesign

**For now:** One user, one tenant, one vertical. Tenant switching is a future enhancement.

---

## 3. Product Detection at Runtime

**Decision: Option A - Environment variable `APP_PRODUCT`**

Single codebase, single Docker image, different env var per deployment:
- `app.izipos.com` → `APP_PRODUCT=izipos`
- `app.otospex.com` → `APP_PRODUCT=otospex`

**ProductService implementation:**

```php
public function current(): Product
{
    return Product::from(config('app.product')); // reads APP_PRODUCT from env
}
```

**In config/app.php:**

```php
'product' => env('APP_PRODUCT', 'izipos'),
```

This is simplest. Domain detection adds unnecessary complexity since we deploy separate containers. Database storage risks tenant being on wrong product.

---

## 4. Module Dependencies

**Decision: Add dependency configuration to `config/modules.php` and auto-resolve.**

Dependencies are module properties, not vertical properties. Add to `config/modules.php`:

```php
'dependencies' => [
    'Recipe' => ['Product', 'Inventory'],
    'Workshop' => ['Vehicle', 'Inventory'],
    'Tables' => ['Menu'],
    'Fleet' => ['Vehicle', 'Customer'],
    'Appointments' => ['Customer'],
],
```

**Update `ModuleLoaderService::getEnabledModules()`** to resolve dependencies automatically:

```php
public function getEnabledModules(Vertical $vertical, array $extras = []): array
{
    $modules = array_merge(
        $this->verticalConfig->getCoreModules($vertical),
        $this->resolveExtras($vertical, $extras)
    );
    
    // Auto-resolve dependencies
    return $this->resolveDependencies($modules);
}

private function resolveDependencies(array $modules): array
{
    $dependencies = config('modules.dependencies', []);
    $resolved = $modules;
    
    foreach ($modules as $module) {
        if (isset($dependencies[$module])) {
            $resolved = array_merge($resolved, $dependencies[$module]);
        }
    }
    
    return array_unique($resolved);
}
```

This prevents broken states where a module is enabled without its dependencies.

---

## 5. POS Variants

**Decision: Option A for Phase 1 - Single `StandardPOS` component that adapts based on vertical config.**

**Phase 1 implementation:**
- One `StandardPOS` component
- Shows/hides buttons based on `pos_features` from vertical config
- Different color scheme per vertical (from config)
- Different default views (grid vs list) based on vertical

**Phase 2 specialization** (only where UX is fundamentally different):
- `RestaurantPOS` - table management, kitchen tickets, courses
- `PharmacyPOS` - prescription lookup, dosage warnings, controlled substance handling
- `WorkshopPOS` - vehicle selection, labor time tracking, job cards

**Verticals that use StandardPOS** (with styling/feature differences):
- retail
- fashion  
- coffee_shop
- parts_retailer
- car_glass
- tire_shop
- service_station
- parapharmacy

---

## Summary Table

| Question | Decision |
|----------|----------|
| 1. Extras storage | JSONB, no junction table |
| 2. Company vertical override | **Remove entirely** - use tenant switching later |
| 3. Product detection | `APP_PRODUCT` env var via `config('app.product')` |
| 4. Module dependencies | Add to `config/modules.php`, auto-resolve in ModuleLoaderService |
| 5. POS variants | Single `StandardPOS` Phase 1, specialize 3 variants (Restaurant, Pharmacy, Workshop) in Phase 2 |

---

## Additional Notes

### Migration Section
Remove the "Migration Strategy" section (Section 13) from the document. We're still in development with no real customer data—only test/dummy companies. No backfill logic needed.

### Tenant Switching (Future Feature)
When implementing multi-tenant access for users:

```
Organizations Table (future)
├── id
├── name
├── owner_user_id
└── created_at

Organization_Tenants (junction)
├── organization_id
├── tenant_id
└── added_at

User can:
├── Belong to organization
├── Have direct tenant access
└── Switch between accessible tenants via UI dropdown
```

This is not needed for Phase 1. Document as future enhancement only.
