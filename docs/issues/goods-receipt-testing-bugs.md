# Goods Receipt & Related Features - Bug Analysis Report

> **Generated:** 2025-12-13
> **Context:** User testing of the new Goods Receipt List Page and related features
> **Priority Order:** Critical -> High -> Medium -> Low

---

## Executive Summary

After testing the new Goods Receipt List Page and related workflows, 7 distinct issues were identified. This document provides root cause analysis and recommended fixes for each.

| # | Issue | Severity | Root Cause | Effort |
|---|-------|----------|------------|--------|
| 1 | Stock Level creation missing `company_id` | **CRITICAL** | Backend bug | 1hr |
| 2 | No default location error | HIGH | Missing setup/validation | 2hr |
| 3 | Receive Goods button requires double-click | MEDIUM | UI state issue | 30min |
| 4 | Settings page 403 error | HIGH | Missing permission | 1hr |
| 5 | Country selector is text field | MEDIUM | UI incomplete | 1hr |
| 6 | Currency hardcoded to USD | MEDIUM | Frontend defaults | 2hr |
| 7 | Additional costs not editable | LOW | Feature incomplete | 4hr |

---

## Issue #1: Stock Level Creation Missing `company_id` (CRITICAL)

### Symptom
When attempting to receive goods or add a location, the following SQL error occurs:
```
SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column "company_id"
of relation "stock_levels" violates not-null constraint
```

### Root Cause
**File:** `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`
**Lines:** 44-51 (also 178-185 for returns)

When creating a new `StockLevel` record, the `company_id` field is not included:

```php
// CURRENT (BROKEN)
$stockLevel = StockLevel::create([
    'id' => Str::uuid()->toString(),
    'product_id' => $product->id,
    'location_id' => $location->id,
    'tenant_id' => $product->tenant_id,
    'quantity' => '0',
    'reserved' => '0',
]);
// Missing: 'company_id' => $location->company_id,
```

### Fix
Add `company_id` to all `StockLevel::create()` calls in `WeightedAverageCostService.php`:

```php
// Lines 44-51
$stockLevel = StockLevel::create([
    'id' => Str::uuid()->toString(),
    'product_id' => $product->id,
    'location_id' => $location->id,
    'tenant_id' => $product->tenant_id,
    'company_id' => $location->company_id, // ADD THIS
    'quantity' => '0',
    'reserved' => '0',
]);

// Lines 178-185 (same fix for recordReturn method)
$stockLevel = StockLevel::create([
    'id' => Str::uuid()->toString(),
    'product_id' => $product->id,
    'location_id' => $location->id,
    'tenant_id' => $product->tenant_id,
    'company_id' => $location->company_id, // ADD THIS
    'quantity' => '0',
    'reserved' => '0',
]);
```

### Verification
After fix, run:
```bash
php artisan test --filter=WeightedAverageCostServiceTest
```

---

## Issue #2: No Default Location Error

### Symptom
When clicking "Receive Goods" on a purchase order, error message:
```
No location configured for company. Please set up at least one location.
```

### Root Cause
**File:** `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php`
**Lines:** 232-248

The `getDefaultLocation()` method throws a `RuntimeException` if no location exists for the company. This is correct behavior, but the user experience is poor.

### Fix (Multiple Parts)

**Part A: Better Error Message in Backend**
Change the error to include a helpful message:
```php
private function getDefaultLocation(Document $document): Location
{
    $location = $document->company->locations()
        ->where('is_default', true)
        ->first();

    if ($location === null) {
        $location = $document->company->locations()->first();
    }

    if ($location === null) {
        throw new \DomainException(
            'No stock location configured. Please add at least one location in Settings > Company before receiving goods.'
        );
    }

    return $location;
}
```

**Part B: Frontend Guidance**
In `GoodsReceiptListPage.tsx`, add an empty state that guides users to create a location:
```tsx
// Add check for location existence and show guidance
const { data: locations } = useQuery({
    queryKey: ['company-locations'],
    queryFn: () => api.get('/locations'),
});

if (!locations?.data?.length) {
    return (
        <div className="text-center py-12">
            <MapPin className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-4 text-lg font-medium">Setup Required</h3>
            <p className="text-gray-500">
                Add a stock location before receiving goods.
            </p>
            <Link to="/settings/locations" className="btn-primary mt-4">
                Add Location
            </Link>
        </div>
    );
}
```

**Part C: Company Onboarding Check**
Consider adding location setup to the company onboarding flow or showing a banner in the dashboard if no locations exist.

---

## Issue #3: Receive Goods Button Requires Double-Click

### Symptom
First click on "Receive All" button does nothing. Second click shows the confirmation modal.

### Likely Root Cause
**File:** `apps/web/src/features/purchases/GoodsReceiptListPage.tsx`
**Lines:** 121-124, 338

The `handleReceiveClick` function sets state, but there might be a React rendering issue or the button click handler might have `stopPropagation` or event issues.

Current code:
```tsx
const handleReceiveClick = (po: PurchaseOrder) => {
    setSelectedPO(po)
    setShowReceiveModal(true)
}

// Button usage
<button
    type="button"
    onClick={() => { handleReceiveClick(po) }}
    ...
>
```

### Fix
The issue is likely the arrow function wrapper. Try:
```tsx
// Option 1: Direct handler binding
onClick={handleReceiveClick.bind(null, po)}

// Option 2: Use a simple handler without wrapper
onClick={() => handleReceiveClick(po)}
// Note: The current code has extra braces, change from:
onClick={() => { handleReceiveClick(po) }}
// To:
onClick={() => handleReceiveClick(po)}
```

Also verify the `ConfirmDialog` component opens correctly. The issue may be in ConfirmDialog state management.

---

## Issue #4: Settings Page 403 Error

### Symptom
When trying to save company settings, user gets a 403 Forbidden error.

### Root Cause
**File:** `apps/api/app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php`
**Line:** 14-17

```php
public function authorize(): bool
{
    return $this->user()?->can('settings.update') ?? false;
}
```

The user does not have the `settings.update` permission assigned to their role.

### Fix
**Option A: Assign permission to admin role**
Add `settings.update` to the admin role in the database or seeder.

**Option B: Check role assignment**
```bash
php artisan tinker
>>> $user = \App\Modules\Identity\Domain\User::find('user-id');
>>> $user->getAllPermissions()->pluck('name');
```

If `settings.update` is missing, assign it:
```php
$user->givePermissionTo('settings.update');
// OR assign via role
$role = \Spatie\Permission\Models\Role::findByName('admin');
$role->givePermissionTo('settings.update');
```

**Option C: Add to seeder**
In `database/seeders/RolesAndPermissionsSeeder.php`, ensure `settings.update` is assigned to admin role.

---

## Issue #5: Country Selector is Text Field (Not Dropdown)

### Symptom
In company settings, the country field is a plain text input instead of a dropdown with country options.

### Root Cause
**File:** `apps/web/src/features/settings/CompanyPage.tsx`
**Lines:** 353-363

The country field uses a basic text input:
```tsx
<input
    type="text"
    id="country"
    value={formData.address?.country ?? ''}
    onChange={(e) => { handleAddressChange('country', e.target.value) }}
    placeholder="France"
    className="..."
/>
```

### Fix
Replace with a country select component:
```tsx
import { countries } from '../../lib/countries'; // Create country list

<select
    id="country"
    value={formData.address?.country ?? ''}
    onChange={(e) => { handleAddressChange('country', e.target.value) }}
    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 ..."
>
    <option value="">Select country</option>
    {countries.map((country) => (
        <option key={country.code} value={country.code}>
            {country.name}
        </option>
    ))}
</select>
```

Also need to create `lib/countries.ts`:
```typescript
export const countries = [
    { code: 'TN', name: 'Tunisia' },
    { code: 'FR', name: 'France' },
    { code: 'IT', name: 'Italy' },
    { code: 'DE', name: 'Germany' },
    { code: 'GB', name: 'United Kingdom' },
    { code: 'DZ', name: 'Algeria' },
    { code: 'MA', name: 'Morocco' },
    // ... add more as needed
];
```

---

## Issue #6: Currency Hardcoded to USD

### Symptom
In various places, currency displays as USD/$, even when company currency is TND.

### Root Cause
**Multiple Files** - The `formatCurrency` function defaults to USD:

Example from `GoodsReceiptListPage.tsx:132-137`:
```tsx
const formatCurrency = (amount: number, currency: string) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency || 'USD', // Default to USD
    }).format(amount)
}
```

### Fix
**Part A: Use company currency from context**
Create a currency utility that uses company settings:
```typescript
// lib/currency.ts
import { useCompanySettings } from '../hooks/useCompanySettings';

export function useFormatCurrency() {
    const { data: settings } = useCompanySettings();
    const companyCurrency = settings?.currency_code || 'TND';
    const locale = settings?.locale === 'fr' ? 'fr-TN' : 'en-US';

    return (amount: number, currencyOverride?: string) => {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: currencyOverride || companyCurrency,
        }).format(amount);
    };
}
```

**Part B: Fix all hardcoded instances**
Search for `currency: currency || 'USD'` and `currency || 'USD'` across the codebase and replace with proper company currency.

Files to check:
- `GoodsReceiptListPage.tsx`
- `DocumentDetailPage.tsx`
- `DocumentListPage.tsx`
- `PartnerDetailPage.tsx`
- `PaymentListPage.tsx`
- All dashboard/report pages

---

## Issue #7: Additional Costs Not Editable from Document Edit

### Symptom
Cannot add additional order costs from the purchase order edit page.

### Root Cause
The additional costs section may not be rendered for purchase orders in edit mode, or the feature is incomplete.

**File:** `apps/web/src/features/documents/DocumentForm.tsx`

### Fix
1. First verify if the Additional Costs component exists
2. Check if it's conditionally rendered only for certain document types
3. Ensure the API endpoints for additional costs are wired up

The API routes exist at:
- `GET /documents/{document}/additional-costs`
- `POST /documents/{document}/additional-costs`
- `PATCH /documents/{document}/additional-costs/{cost}`

Need to verify the frontend component is using these endpoints and is visible for purchase orders.

---

## Priority Action Items

### Immediate (Today)
1. **Fix #1** - Add `company_id` to StockLevel creation (CRITICAL - blocks receiving)
2. **Fix #4** - Add `settings.update` permission to admin role (blocks settings)

### Short-term (This Week)
3. **Fix #2** - Improve location error handling and guidance
4. **Fix #3** - Debug double-click issue on Receive button
5. **Fix #5** - Convert country field to dropdown

### Medium-term (Next Sprint)
6. **Fix #6** - Centralize currency formatting with company defaults
7. **Fix #7** - Complete Additional Costs UI for purchase orders

---

## Testing Checklist

After fixes, verify:
- [ ] Can receive goods for a PO with products (Issue #1)
- [ ] Clear error message when no locations exist (Issue #2)
- [ ] Single click opens receive confirmation (Issue #3)
- [ ] Admin can save company settings (Issue #4)
- [ ] Country shows as dropdown with options (Issue #5)
- [ ] Currency displays correctly based on company settings (Issue #6)
- [ ] Can add additional costs to purchase orders (Issue #7)
