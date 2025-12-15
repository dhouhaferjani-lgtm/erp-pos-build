# AutoERP Bug Fix Protocol v2.0

> **For Claude Code (Opus 4.5)** — Refined instructions based on actual codebase architecture.
> **Guiding Principles:** Context Preservation • Optimistic UI • Minimum Viable Fix

---

## Pre-Flight Checklist

Before starting any fix, verify these paths exist and note any differences:

```bash
# Frontend structure
ls -la apps/web/src/features/
ls -la apps/web/src/locales/
ls -la apps/web/src/components/

# Backend structure  
ls -la apps/api/app/Modules/
ls -la apps/api/routes/

# Shared types
ls -la packages/shared/types/
```

If paths differ from this document, update your working notes accordingly.

---

## Priority Tiers

| Tier | Description | Items |
|------|-------------|-------|
| **P0** | Broken functionality / Data loss risk | 3.1, 4.2, 4.3 |
| **P1** | User-facing bugs / Localization | 1.1, 1.2, 3.3, 4.1 |
| **P2** | UX improvements / Polish | 2.1, 2.2, 2.3, 2.4, 3.2 |

**Work order:** Complete all P0 items before moving to P1, etc.

---

## Phase 1: Global Localization (P1)

### 1.1 Static String Audit

**Problem:** UI language is set to French, but sidebar items, page titles, and table headers display in English.

**Affected Areas (verify these paths):**
```
apps/web/src/components/layout/Sidebar.tsx        [VERIFY]
apps/web/src/components/layout/AppShell.tsx       [VERIFY]
apps/web/src/features/dashboard/DashboardPage.tsx [VERIFY]
apps/web/src/features/documents/DocumentListPage.tsx
```

**Task:**
1. Search for hardcoded strings:
   ```bash
   grep -rn "Recent Documents\|Revenue\|Dashboard\|Settings" apps/web/src/
   ```
2. Replace each hardcoded string with a translation key using your i18n hook (likely `useTranslation` from react-i18next or a custom hook).
3. Add missing keys to locale files:
   ```
   apps/web/src/locales/fr/common.json   [VERIFY PATH]
   apps/web/src/locales/en/common.json   [VERIFY PATH]
   ```

**Pattern to follow:**
```tsx
// BEFORE
<span>Recent Documents</span>

// AFTER
const { t } = useTranslation();
<span>{t('sidebar.recentDocuments')}</span>
```

**Acceptance Criteria:**
- [ ] No English strings visible when app is set to French
- [ ] Switching language updates all sidebar/header text
- [ ] No missing translation warnings in console

---

### 1.2 Database Enum Mapping

**Problem:** Status badges display raw enum values (`Draft`, `Active`) instead of translated labels (`Brouillon`, `Actif`).

**Task:**
1. Create a status mapper utility:
   ```
   apps/web/src/utils/statusMapper.ts   [CREATE]
   ```

2. Implementation:
   ```typescript
   import { useTranslation } from 'react-i18next'; // or your i18n hook

   // Raw enum values from backend
   type DocumentStatus = 'draft' | 'active' | 'inactive' | 'confirmed' | 'cancelled';

   export const STATUS_TRANSLATION_KEYS: Record<DocumentStatus, string> = {
     draft: 'status.draft',
     active: 'status.active', 
     inactive: 'status.inactive',
     confirmed: 'status.confirmed',
     cancelled: 'status.cancelled',
   };

   // Hook for components
   export function useStatusLabel(status: string): string {
     const { t } = useTranslation();
     const key = STATUS_TRANSLATION_KEYS[status.toLowerCase() as DocumentStatus];
     return key ? t(key) : status; // Fallback to raw value if unknown
   }

   // Non-hook version for utilities
   export function getStatusTranslationKey(status: string): string {
     return STATUS_TRANSLATION_KEYS[status.toLowerCase() as DocumentStatus] ?? status;
   }
   ```

3. Add translations to locale files:
   ```json
   // fr/common.json
   {
     "status": {
       "draft": "Brouillon",
       "active": "Actif",
       "inactive": "Inactif",
       "confirmed": "Confirmé",
       "cancelled": "Annulé"
     }
   }
   ```

4. Update components rendering status badges. Search for:
   ```bash
   grep -rn "status\|Status" apps/web/src/features/ --include="*.tsx" | grep -i badge
   ```

**Acceptance Criteria:**
- [ ] All status badges show translated text
- [ ] Unknown status values display raw value (no crash)
- [ ] Mapper is reusable across all modules

---

## Phase 2: CRM & Sales UX (P2)

### 2.1 Context-Aware Partner Type

**Problem:** "Add Customer" from `/sales/customers` shows an empty Type dropdown instead of defaulting to "Customer".

**Affected Files (verify):**
```
apps/web/src/features/partners/components/PartnerForm.tsx    [VERIFY]
apps/web/src/features/partners/hooks/usePartnerForm.ts       [VERIFY]
```

**Task:**
1. Detect the current route context
2. Set default value and optionally hide the Type field

**Implementation approach:**
```tsx
// In the form component or hook
import { useLocation } from 'react-router-dom'; // or your router

function usePartnerTypeDefault() {
  const location = useLocation();
  
  if (location.pathname.includes('/sales/customers')) {
    return { defaultType: 'customer', hideTypeField: true };
  }
  if (location.pathname.includes('/purchases/suppliers')) {
    return { defaultType: 'supplier', hideTypeField: true };
  }
  return { defaultType: undefined, hideTypeField: false };
}
```

**Acceptance Criteria:**
- [ ] Navigating to `/sales/customers` → Add → Type defaults to "Customer"
- [ ] Navigating to `/purchases/suppliers` → Add → Type defaults to "Supplier"
- [ ] Type field is hidden or disabled when context is unambiguous
- [ ] Direct navigation to `/partners/create` still shows Type selector

---

### 2.2 Product Combobox Empty State

**Problem:** Product dropdown in Quote creation is empty until user types.

**Affected Files (verify):**
```
apps/web/src/features/documents/components/LineItemForm.tsx      [VERIFY]
apps/web/src/features/products/components/ProductCombobox.tsx    [VERIFY]
apps/web/src/features/products/hooks/useProductSearch.ts         [VERIFY]
```

**Task:**
1. Fetch recent/popular products on dropdown open (before typing)
2. Show "Recent Products" header

**Implementation approach:**
```tsx
// In your product search hook or combobox
const [isOpen, setIsOpen] = useState(false);

// Query that runs when dropdown opens
const { data: recentProducts } = useQuery({
  queryKey: ['products', 'recent'],
  queryFn: () => api.get('/products', { 
    params: { sort: '-updated_at', limit: 10 } 
  }),
  enabled: isOpen && !searchQuery, // Only when open and no search
});

// In the dropdown render
{!searchQuery && recentProducts?.length > 0 && (
  <>
    <div className="px-2 py-1 text-sm text-gray-500 font-medium">
      {t('products.recentProducts')}
    </div>
    {recentProducts.map(product => (
      <ComboboxOption key={product.id} value={product} />
    ))}
  </>
)}
```

**Acceptance Criteria:**
- [ ] Opening dropdown (click/focus) shows up to 10 recent products immediately
- [ ] "Recent Products" header is visible and translated
- [ ] Typing switches to search results (header changes or disappears)
- [ ] Selection works correctly for both recent and search results

---

### 2.3 Pricing Defaults on Product Selection

**Problem:** Adding a line item results in `0.00` Unit Price and `0.00` Tax.

**Affected Files (verify):**
```
apps/web/src/features/documents/components/LineItemForm.tsx  [VERIFY]
apps/web/src/features/documents/hooks/useLineItems.ts        [VERIFY]
```

**Task:**
1. On product selection, fetch/use product pricing data
2. Pre-fill Unit Price from `list_price` or `sales_price`
3. Pre-fill Tax from product's tax category or customer's region default

**Implementation approach:**
```tsx
// When product is selected in line item
const handleProductSelect = (product: Product) => {
  // Update the line item with product data
  updateLineItem({
    product_id: product.id,
    description: product.name,
    unit_price: product.sales_price ?? product.list_price ?? 0,
    tax_rate: product.default_tax_rate ?? getRegionDefaultTax(customer?.region),
    quantity: 1, // or keep existing
  });
};
```

**Backend consideration:** Ensure the product endpoint returns pricing fields:
```
GET /api/products/{id}
Response should include: sales_price, list_price, default_tax_rate
```

**Acceptance Criteria:**
- [ ] Selecting a product fills Unit Price with non-zero value (if product has price)
- [ ] Tax field is pre-filled based on product or region
- [ ] User can still manually override both fields
- [ ] Products with `price = 0` explicitly show 0 (not a bug)

---

### 2.4 In-Flow Product Creation

**Problem:** Cannot create a new product without leaving the Quote screen.

**Affected Files (verify):**
```
apps/web/src/features/products/components/ProductCombobox.tsx     [VERIFY]
apps/web/src/features/products/components/ProductFormModal.tsx    [VERIFY OR CREATE]
```

**Task:**
1. Add "Create new" button in product dropdown footer
2. Open product creation modal (reuse existing form)
3. On success: close modal, auto-select new product

**Implementation approach:**
```tsx
// In ProductCombobox
const [showCreateModal, setShowCreateModal] = useState(false);

// Dropdown footer
<div className="border-t p-2">
  <Button 
    variant="ghost" 
    onClick={() => setShowCreateModal(true)}
    className="w-full justify-start"
  >
    <PlusIcon className="mr-2 h-4 w-4" />
    {t('products.createNew')}
  </Button>
</div>

// Modal with callback
<ProductFormModal 
  open={showCreateModal}
  onClose={() => setShowCreateModal(false)}
  onSuccess={(newProduct) => {
    setShowCreateModal(false);
    onProductSelect(newProduct); // Select the newly created product
  }}
/>
```

**Acceptance Criteria:**
- [ ] "Create new" button visible at bottom of product dropdown
- [ ] Clicking opens modal without navigating away
- [ ] After saving product, modal closes automatically
- [ ] New product is auto-selected in the line item
- [ ] Quote data is preserved (not lost during modal interaction)

---

## Phase 3: Order Management (P0/P1)

### 3.1 Ghost Error on Quote Save (P0)

**Problem:** Saving a quote shows error toast "Cannot read properties of undefined" but quote actually saves successfully.

**Affected Files (verify):**
```
apps/web/src/features/documents/hooks/useDocumentMutations.ts   [VERIFY]
apps/web/src/features/documents/hooks/useQuoteMutation.ts       [VERIFY]
apps/web/src/features/sales/hooks/useQuoteMutation.ts           [VERIFY]
```

**Diagnosis steps:**
```bash
# Find the mutation
grep -rn "saveQuote\|createQuote\|useMutation" apps/web/src/features/ --include="*.ts"

# Check for response handling
grep -rn "response.data" apps/web/src/features/documents/
```

**Likely cause:** Frontend expects `response.data.id` but backend returns:
- `204 No Content` (empty response), or
- Different response structure (e.g., `response.id` instead of `response.data.id`)

**Fix pattern:**
```typescript
// BEFORE (problematic)
onSuccess: (response) => {
  toast.success(t('quote.saved'));
  navigate(`/quotes/${response.data.id}`);
},
onError: (error) => {
  toast.error(error.message);
}

// AFTER (defensive)
onSuccess: (response) => {
  toast.success(t('quote.saved'));
  // Handle both response structures
  const id = response?.data?.id ?? response?.id;
  if (id) {
    navigate(`/quotes/${id}`);
  } else {
    // Refresh list if no ID returned
    queryClient.invalidateQueries(['quotes']);
  }
},
onError: (error) => {
  // Only show error if it's actually an error
  if (error) {
    toast.error(error?.message ?? t('errors.unknown'));
  }
}
```

**Also check:** Ensure success and error toasts are mutually exclusive (not both firing).

**Acceptance Criteria:**
- [ ] Saving a valid quote shows only success toast
- [ ] No console errors after successful save
- [ ] Failed saves show only error toast with useful message
- [ ] Navigation after save works correctly

---

### 3.2 Sales Order Payment Button (P2)

**Problem:** Confirmed Sales Orders have no way to record deposits before invoicing.

**Affected Files (verify):**
```
apps/web/src/features/documents/pages/SalesOrderDetailPage.tsx  [VERIFY]
apps/web/src/features/sales/pages/SalesOrderDetailPage.tsx      [VERIFY]
```

**Task:**
1. Add "Register Payment" button for orders with status = `confirmed`
2. Open payment modal or navigate with pre-filled context

**Implementation:**
```tsx
// In SalesOrderDetailPage
{order.status === 'confirmed' && (
  <Button onClick={handleRegisterPayment}>
    {t('orders.registerPayment')}
  </Button>
)}

const handleRegisterPayment = () => {
  // Option A: Modal (preferred for context preservation)
  setShowPaymentModal(true);
  
  // Option B: Navigate with state
  navigate('/treasury/payments/create', {
    state: {
      sourceDocument: {
        type: 'sales_order',
        id: order.id,
        reference: order.reference,
        partner_id: order.partner_id,
        amount: order.total_amount,
      }
    }
  });
};
```

**Acceptance Criteria:**
- [ ] "Register Payment" button visible only on confirmed orders
- [ ] Payment form pre-fills: Partner, Reference, suggested Amount
- [ ] Payment is linked to the Sales Order for later reconciliation
- [ ] User returns to Sales Order after recording payment

---

### 3.3 Conversion Error Feedback (P1)

**Problem:** Converting Sales Order → Delivery fails silently without explaining why.

**Affected Files (verify):**
```
apps/web/src/features/documents/hooks/useDocumentConversion.ts  [VERIFY]
apps/api/app/Modules/Sales/Application/Commands/              [VERIFY]
apps/api/app/Modules/Inventory/Application/Commands/          [VERIFY]
```

**Task:**
1. Backend: Return specific error codes/messages for conversion failures
2. Frontend: Display user-friendly error messages

**Backend pattern (Laravel):**
```php
// In conversion handler
if ($insufficientStock) {
    throw new DomainException(
        "Cannot convert: Insufficient stock for {$item->product->name}. " .
        "Required: {$item->quantity}, Available: {$available}",
        422
    );
}

if ($creditLimitExceeded) {
    throw new DomainException(
        "Cannot convert: Customer credit limit exceeded by " . 
        Number::currency($exceeded, 'EUR'),
        422
    );
}
```

**Frontend pattern:**
```typescript
onError: (error) => {
  // Extract meaningful message
  const message = error.response?.data?.message 
    ?? error.message 
    ?? t('errors.conversionFailed');
  
  toast.error(message, { duration: 5000 }); // Longer duration for detailed errors
}
```

**Acceptance Criteria:**
- [ ] Stock shortage shows: "Cannot convert: Insufficient stock for [Product]. Required: X, Available: Y"
- [ ] Credit limit shows: "Cannot convert: Customer credit limit exceeded"
- [ ] Other errors show specific reason, not generic "Error"
- [ ] Error toast stays visible long enough to read

---

## Phase 4: Invoicing & Treasury (P0/P1)

### 4.1 "Post" Button Terminology (P1)

**Problem:** "Post" button on invoices is ambiguous.

**Affected Files (verify):**
```
apps/web/src/features/documents/pages/InvoiceDetailPage.tsx  [VERIFY]
apps/web/src/features/accounting/pages/InvoiceDetailPage.tsx [VERIFY]
```

**Task:**
1. Find the "Post" button
2. Change label to translated "Post to Ledger" or "Confirm & Post"

```bash
# Find the button
grep -rn "Post\|post" apps/web/src/features/ --include="*.tsx" | grep -i button
```

**Change:**
```tsx
// BEFORE
<Button onClick={handlePost}>Post</Button>

// AFTER
<Button onClick={handlePost}>{t('invoices.actions.postToLedger')}</Button>
```

**Add translation:**
```json
// fr/invoices.json
{
  "actions": {
    "postToLedger": "Valider et comptabiliser"
  }
}
```

**Acceptance Criteria:**
- [ ] Button text is clear and translated
- [ ] Hover/tooltip optionally explains the action
- [ ] No layout break from longer text

---

### 4.2 Payment Context Loss (P0 — CRITICAL)

**Problem:** "Record Payment" from an Invoice redirects to empty payment form, losing all context.

**Affected Files (verify):**
```
apps/web/src/features/documents/pages/InvoiceDetailPage.tsx      [VERIFY]
apps/web/src/features/treasury/pages/PaymentCreatePage.tsx       [VERIFY]
apps/web/src/features/treasury/components/PaymentForm.tsx        [VERIFY]
```

**Root cause options:**
1. Navigation doesn't pass state
2. Payment form doesn't read incoming state
3. Full page redirect loses React state

**Recommended fix: Use a Modal**

```tsx
// InvoiceDetailPage.tsx
const [showPaymentModal, setShowPaymentModal] = useState(false);

<Button onClick={() => setShowPaymentModal(true)}>
  {t('invoices.actions.recordPayment')}
</Button>

<PaymentModal
  open={showPaymentModal}
  onClose={() => setShowPaymentModal(false)}
  prefill={{
    partner_id: invoice.partner_id,
    partner_name: invoice.partner?.name,
    amount: invoice.amount_residual, // Remaining balance, not total
    reference: invoice.number,
    linked_document_id: invoice.id,
    linked_document_type: 'invoice',
  }}
  onSuccess={() => {
    setShowPaymentModal(false);
    queryClient.invalidateQueries(['invoices', invoice.id]);
    toast.success(t('payments.recorded'));
  }}
/>
```

**Alternative: URL state (if modal not feasible)**
```tsx
// Navigation with state
navigate('/treasury/payments/create', {
  state: {
    prefill: {
      partner_id: invoice.partner_id,
      amount: invoice.amount_residual,
      reference: invoice.number,
      linked_document_id: invoice.id,
      return_to: `/invoices/${invoice.id}`,
    }
  }
});

// In PaymentCreatePage - read state
const location = useLocation();
const prefill = location.state?.prefill;

useEffect(() => {
  if (prefill) {
    form.reset(prefill);
  }
}, [prefill]);

// On save/cancel - return to origin
const handleComplete = () => {
  if (prefill?.return_to) {
    navigate(prefill.return_to);
  } else {
    navigate('/treasury/payments');
  }
};
```

**Acceptance Criteria:**
- [ ] Clicking "Record Payment" from invoice pre-fills: Partner, Amount (residual), Reference
- [ ] Payment is linked to the invoice (stored relationship)
- [ ] After Save or Cancel, user returns to the same invoice
- [ ] Amount shown is remaining balance, not total invoice amount

---

### 4.3 Treasury Repository 403 Error (P0)

**Problem:** Creating a Bank Account or Cash Register returns 403 Forbidden.

**Affected Files (verify):**
```
apps/api/app/Modules/Treasury/Presentation/routes.php                    [VERIFY]
apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryController.php  [VERIFY]
apps/web/src/features/treasury/pages/RepositoryListPage.tsx              [VERIFY]
```

**Diagnosis:**
```bash
# Check route middleware
grep -A5 "repositories" apps/api/app/Modules/Treasury/Presentation/routes.php

# Check policy
grep -rn "RepositoryPolicy\|can.*repositories" apps/api/
```

**Backend fixes to check:**

1. **Route has correct permission:**
   ```php
   Route::post('/repositories', [RepositoryController::class, 'store'])
       ->middleware('can:repositories.create'); // or 'can:repositories.manage'
   ```

2. **User role has permission:**
   ```php
   // In your permission seeder or role config
   'admin' => ['repositories.create', 'repositories.manage', ...],
   'user' => ['repositories.view'], // Missing create?
   ```

3. **Policy is registered:**
   ```php
   // AuthServiceProvider
   protected $policies = [
       Repository::class => RepositoryPolicy::class,
   ];
   ```

**Frontend fix — hide button when no permission:**
```tsx
// RepositoryListPage.tsx
const { data: permissions } = usePermissions(); // or however you check permissions

{permissions?.includes('repositories.create') && (
  <Button onClick={() => navigate('/treasury/repositories/create')}>
    {t('treasury.addRepository')}
  </Button>
)}
```

**Acceptance Criteria:**
- [ ] Admin user can create repositories without 403
- [ ] Users without permission don't see "Add Repository" button
- [ ] If button is shown, action succeeds (no permission mismatch)

---

## Verification Checklist

After completing all fixes, run:

```bash
# Linting & Types
cd apps/web && npm run lint && npm run typecheck

# Backend tests
cd apps/api && php artisan test

# E2E smoke test (if available)
npm run test:e2e

# Manual verification
# 1. Switch language to French → all strings translated
# 2. Create a Quote → add line item → price pre-fills
# 3. Save Quote → only success toast, no errors
# 4. From Invoice → Record Payment → form pre-filled, return to invoice
# 5. Create Treasury Repository → no 403
```

---

## Out of Scope (Separate Tickets)

The following items from the original audit require separate implementation efforts:

1. **Sales & Accounting Module Implementation** — Building full GL posting flows
2. **Tier-2 Audit/Event Store** — TimescaleDB infrastructure
3. **Shared DTO Regeneration** — `php artisan typescript:transform` workflow
4. **Treasury Integration Tests** — Comprehensive test coverage

These are feature work, not bug fixes. Create separate tickets with proper estimation.
