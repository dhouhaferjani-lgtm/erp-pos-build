# Frontend Barcode Lookup & Submission — Design Spec

> **Sub-project 2 of 3:** Frontend Barcode Lookup & Submission
> **Date:** 2026-03-29
> **Depends on:** Sub-project 1 (Backend Foundation, complete on `feat/smart-prompts`)
> **Backend spec:** `docs/superpowers/specs/2026-03-28-erp-product-enrichment-integration-design.md`

---

## 1. Scope

### What this builds

- Barcode scanner detection as a shared hook (extracted from POS)
- Platform catalog lookup on the product creation form
- Form pre-fill from platform data when barcode is found
- Automatic enrichment submission on product save when barcode is not found
- Backend: new `ProductSubmissionController` + route for frontend submission
- Graceful degradation when platform is unavailable

### What this does NOT build

- POS barcode flow changes (POS keeps its own `useBarcodeLookup` for local product matching)
- Enrichment review UI (Sub-project 3)
- Photo upload during enrichment submission (deferred)
- Batch enrichment submission (deferred)

---

## 2. Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Scanner hook location | Shared `hooks/` | Scanner detection is domain-agnostic hardware logic. POS import updated, no re-export. |
| Query hooks location | `features/inventory/api/platformQueries.ts` | Follows `catalog/api/queries.ts` pattern — key factory + hooks together |
| Lookup result behavior | Auto-fill, leave editable | Least disruptive. Green highlight + banner for attribution. User retains full control. |
| Enrichment trigger | Automatic on product save | Opt-out checkbox (default checked). Zero extra clicks in happy path. |
| Photo upload | Deferred | Platform enriches from barcode + text. Photos are a follow-up enhancement. |
| Hook naming | `useCatalogLookup` | Distinguishes from POS `useBarcodeLookup` (local product match vs platform catalog). |

---

## 3. File Map

### Backend (new route for submission)

| Action | Path | Purpose |
|--------|------|---------|
| Create | `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php` | Endpoint for frontend to submit products for enrichment |
| Modify | `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php` | Add `POST /platform/submit-for-enrichment` route inside auth group |

### Shared

| Action | Path | Purpose |
|--------|------|---------|
| Move | `apps/web/src/hooks/useBarcodeScanner.ts` | Keyboard wedge detection, moved from `features/pos/hooks/` |
| Modify | `apps/web/src/features/pos/pages/POSPage.tsx` | Update import to `@/hooks/useBarcodeScanner` |
| Modify | `apps/web/src/features/pos/hooks/index.ts` | Update barrel export to re-export from `@/hooks/useBarcodeScanner` |

### Inventory Feature

| Action | Path | Purpose |
|--------|------|---------|
| Create | `apps/web/src/features/inventory/types/platform.ts` | Re-export generated types + local-only types (`LookupState`) |
| Create | `apps/web/src/features/inventory/api/platformApi.ts` | Raw API functions: `lookupBarcode()`, `submitForEnrichment()` |
| Create | `apps/web/src/features/inventory/api/platformQueries.ts` | Query key factory + `useCatalogLookup` + `useProductSubmission` |
| Create | `apps/web/src/features/inventory/components/BarcodeLookupInput.tsx` | Input with 5 states, scanner integration, design tokens |
| Create | `apps/web/src/features/inventory/components/CatalogBanner.tsx` | Status banner (found/not_found/error), design tokens |
| Modify | `apps/web/src/features/inventory/ProductForm.tsx` | Wire BarcodeLookupInput, enrichment checkbox, pre-fill logic |

### i18n

| Action | Path | Purpose |
|--------|------|---------|
| Modify | `apps/web/src/locales/en/inventory.json` | Add barcode lookup translation keys |
| Modify | `apps/web/src/locales/fr/inventory.json` | French translations |

---

## 4. Backend: ProductSubmissionController

The `ProductSubmissionService` exists but has no HTTP endpoint for the frontend. We need a thin controller.

File: `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php`

```php
final class ProductSubmissionController extends Controller
{
    public function __construct(
        private readonly ProductSubmissionService $submissionService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('enrichment.submit')) {
            abort(403);
        }

        $validated = $request->validate([
            'barcode' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = $this->companyContext->requireCompany();
        $vertical = $company->tenant->vertical->platformVertical();

        if ($vertical === null) {
            return response()->json(['error' => 'Vertical not supported for enrichment'], 422);
        }

        $result = $this->submissionService->submit(new ProductSubmissionData(
            barcode: $validated['barcode'] ?? null,
            vertical: $vertical,
            name: $validated['name'],
            brand: $validated['brand'],
            category: $validated['category'] ?? null,
            description: $validated['description'] ?? null,
            attributes: null,
            photoIds: [],
            autoEnrich: true,
        ));

        if ($result === null) {
            return response()->json(['error' => 'Platform unavailable'], 502);
        }

        return response()->json([
            'data' => $result->toArray(),
            'meta' => ['timestamp' => now()->toIso8601String()],
        ]);
    }
}
```

Route (added to the authenticated group in `PlatformIntegration/Presentation/routes.php`):

```php
Route::post('submit-for-enrichment', ProductSubmissionController::class)
    ->name('platform.submit-for-enrichment');
```

---

## 5. Types

File: `apps/web/src/features/inventory/types/platform.ts`

Import generated types from `@autoerp/shared/types/generated` where available (per Rule 7: types flow from backend). Define local-only types here.

```typescript
// Re-export generated types from backend DTOs
// After running: php artisan typescript:transform
export type { App_Modules_PlatformIntegration_Application_DTOs_BarcodeLookupResultData as CatalogLookupResult } from '@autoerp/shared/types/generated'
export type { App_Modules_PlatformIntegration_Application_DTOs_SubmissionResultData as SubmissionResult } from '@autoerp/shared/types/generated'

// Local types not generated from backend
export interface SuggestedProduct {
  name: string
  barcode: string
  brand: string | null
  description: string | null
  platform_product_id: string
  classification: Record<string, unknown>
  ingredients: Array<{ name: string; position: number }>
  images: Array<{ url: string | null; thumbnail: string | null; type: string | null }>
}

export type LookupState = 'idle' | 'searching' | 'found' | 'not_found' | 'error'
```

Note: The generated type names depend on `typescript:transform` output. If the generated names differ, update the re-exports accordingly. The `SuggestedProduct` interface maps the `suggestedProduct` array from the backend response (snake_case keys, manually built — not a Spatie DTO).

---

## 6. API Layer

File: `apps/web/src/features/inventory/api/platformApi.ts`

```typescript
import { apiPost } from '@/lib/api'
import type { CatalogLookupResult, SubmissionResult } from '../types/platform'

export async function lookupBarcode(barcode: string): Promise<CatalogLookupResult> {
  return apiPost<CatalogLookupResult>('/platform/barcode-lookup', { barcode })
}

export async function submitForEnrichment(data: {
  barcode: string | null
  name: string
  brand: string
  category?: string
  description?: string
}): Promise<SubmissionResult> {
  return apiPost<SubmissionResult>('/platform/submit-for-enrichment', data)
}
```

Note: Paths are relative to the Axios `baseURL: '/api/v1'`. No `/api/v1` prefix in the paths.

Note: The backend response mixes camelCase (Spatie DTO fields like `trackingId`, `errorReason`) and snake_case (`suggestedProduct` array keys like `platform_product_id`). This is because the top-level response is a Spatie `Data` DTO (auto-camelCase), but `suggestedProduct` is a manually-built array.

---

## 7. Query Hooks

File: `apps/web/src/features/inventory/api/platformQueries.ts`

### Query Key Factory

```typescript
export const platformKeys = {
  all: ['platform'] as const,
  catalogLookup: (barcode: string) => [...platformKeys.all, 'catalog-lookup', barcode] as const,
}
```

### `useCatalogLookup`

```typescript
export function useCatalogLookup(barcode: string | null) {
  return useQuery({
    queryKey: platformKeys.catalogLookup(barcode ?? ''),
    queryFn: () => lookupBarcode(barcode!),
    enabled: barcode !== null && barcode.length >= 8,
    staleTime: 60 * 60 * 1000, // 1 hour — matches backend cache TTL
    retry: false, // backend circuit breaker handles retries
    refetchOnWindowFocus: false, // POST-based lookup — avoid refetching on tab focus
  })
}
```

### `useProductSubmission`

```typescript
export function useProductSubmission() {
  return useMutation({
    mutationFn: submitForEnrichment,
  })
}
```

Fire-and-forget mutation. No query invalidation needed — enrichment is a background process. Caller shows toast on success/error.

---

## 8. Components

### `BarcodeLookupInput`

File: `apps/web/src/features/inventory/components/BarcodeLookupInput.tsx`

**Props:**
```typescript
interface BarcodeLookupInputProps {
  onProductData: (data: SuggestedProduct) => void
  onLookupStateChange: (state: LookupState) => void
  defaultBarcode?: string
}
```

**Behavior:**
1. Renders a text input for barcode entry
2. Integrates `useBarcodeScanner` from `@/hooks/useBarcodeScanner` — on scan, sets barcode value and triggers lookup immediately
3. On manual typing, debounces 300ms then triggers lookup (only when length >= 8)
4. Uses `useCatalogLookup` with the current barcode value
5. Shows inline loading spinner during lookup
6. On `found`: calls `onProductData` with `suggestedProduct`, calls `onLookupStateChange('found')`
7. On `not_found`: calls `onLookupStateChange('not_found')`
8. On error: calls `onLookupStateChange('error')`
9. User can clear barcode to reset to `idle` state

**Scanner vs typing detection:**
- The shared `useBarcodeScanner` hook handles scanner detection (< 50ms between keystrokes)
- For manual typing: the component uses a local `useState` with `useEffect` + `setTimeout(300ms)` debounce
- When scanner fires `onScan`, it sets the input value and immediately triggers lookup (no debounce)

**Design tokens:** All styling via `tokens`, `textColors`, `borderColors` from `@/lib/designTokens`. No hardcoded Tailwind colors.

**i18n:** All user-facing text via `t()` with namespace `inventory`.

### `CatalogBanner`

File: `apps/web/src/features/inventory/components/CatalogBanner.tsx`

**Props:**
```typescript
interface CatalogBannerProps {
  state: LookupState
  confidenceTier?: string | null
}
```

**Renders:**
- `idle`: nothing (hidden)
- `searching`: nothing (spinner is inline on the input)
- `found`: green banner — `t('inventory:barcodeLookup.catalogFound')` with confidence tier
- `not_found`: yellow banner — `t('inventory:barcodeLookup.notInCatalog')`
- `error`: red banner — `t('inventory:barcodeLookup.catalogUnavailable')`

**Design tokens:** All colors via design tokens. No hardcoded Tailwind color classes.

---

## 9. ProductForm Integration

File: `apps/web/src/features/inventory/ProductForm.tsx` (modify)

### Changes:

1. **Replace barcode `<input>` with `<BarcodeLookupInput>`**
   - Pass `onProductData` callback that calls `setValue('name', ...)`, `setValue('description', ...)` etc. with `{ shouldDirty: false }`
   - Pass `onLookupStateChange` callback that updates local `lookupState` state
   - Store the `suggestedProduct` in a ref for use during save

2. **Add `CatalogBanner` above the form fields section**
   - Rendered between the form header and the first field section
   - Driven by the `lookupState` local state

3. **Add enrichment checkbox**
   - Only visible when `lookupState === 'not_found'`
   - Default checked
   - Local state: `enrichmentOptIn: boolean`
   - Rendered below the barcode field, in the same section

4. **Modify save handler — use `mutateAsync` for sequencing**
   - Current pattern: `useMutation` with `onSuccess` that navigates immediately
   - Change: Use `mutateAsync` in `onSubmit` to control sequencing:
     ```
     const product = await createProduct.mutateAsync(formData)
     if (lookupState === 'not_found' && enrichmentOptIn) {
       submission.mutate({ barcode, name, brand, ... })
       toast: "Product saved. Submitted for catalog enrichment."
     } else if (lookupState === 'found') {
       toast: "Product saved with catalog data."
     } else {
       toast: "Product saved."
     }
     navigate('/inventory/products')
     ```
   - This ensures enrichment mutation fires before navigation
   - The enrichment mutation itself is fire-and-forget (no await)

5. **Pre-fill field highlighting**
   - When `lookupState === 'found'`, track which fields were pre-filled in a `Set<string>`
   - Apply a subtle green background to pre-filled fields using design tokens (`colors.success[50]` background, `colors.success[300]` border)
   - Highlighting clears if user edits the field

6. **Brand handling**
   - The product form currently has no `brand` field
   - Store `brand` from platform lookup in local state (not in the form)
   - Pass it to the submission mutation payload from the stored `suggestedProduct` data
   - If the product's vertical has automotive metadata, map `brand` to `supplier_brand` in automotive metadata

### Form field mapping (platform → form):

| Platform field | Form field | Notes |
|----------------|------------|-------|
| `name` | `name` | Direct mapping via `setValue` |
| `brand` | (local state) | No form field — stored for submission payload |
| `description` | `description` | Direct mapping via `setValue` |
| `barcode` | `barcode` | Already set from input |
| `platform_product_id` | Included in save payload | Stored on product record via backend |

### i18n keys touched in ProductForm modifications:

Only new text added to the form (enrichment checkbox label, toasts) uses `t()`. Existing hardcoded strings in `ProductForm.tsx` are not migrated (out of scope per Rule 4 — no scope creep).

---

## 10. Shared Hook Migration

### Move `useBarcodeScanner`

1. Copy `apps/web/src/features/pos/hooks/useBarcodeScanner.ts` → `apps/web/src/hooks/useBarcodeScanner.ts`
2. Delete the original file
3. Update `apps/web/src/features/pos/pages/POSPage.tsx` import to `@/hooks/useBarcodeScanner`
4. Update barrel export at `apps/web/src/features/pos/hooks/index.ts` to re-export from `@/hooks/useBarcodeScanner`
5. Check all other consumers (grep for `useBarcodeScanner`) and update imports

The hook's API stays identical — no changes to its interface.

---

## 11. i18n Translation Keys

Add to `apps/web/src/locales/en/inventory.json`:

```json
{
  "barcodeLookup": {
    "placeholder": "Scan or type barcode...",
    "searching": "Looking up...",
    "catalogFound": "Data from Syneriva Catalog",
    "confidence": "Confidence: {{tier}}",
    "notInCatalog": "Not in catalog — fill in details manually",
    "catalogUnavailable": "Catalog unavailable — enter details manually",
    "invalidBarcode": "Invalid barcode format",
    "enrichmentCheckbox": "Submit for catalog enrichment",
    "enrichmentDescription": "Product data will be enriched by Syneriva after saving",
    "toastSavedWithCatalog": "Product saved with catalog data.",
    "toastSavedWithEnrichment": "Product saved. Submitted for catalog enrichment.",
    "toastEnrichmentFailed": "Product saved but enrichment submission failed."
  }
}
```

French translations follow the same structure in `fr/inventory.json`.

---

## 12. Error Handling

| Scenario | Behavior |
|----------|----------|
| Platform returns 500 / timeout | `useCatalogLookup` returns error → CatalogBanner shows red warning → form works normally |
| Circuit breaker open | Backend returns error status → same as above |
| Network offline | Query fails → same error state → form works normally |
| Enrichment submission fails | Toast: `t('barcodeLookup.toastEnrichmentFailed')` → product is saved locally regardless |
| Invalid barcode (EAN-13 check digit) | Backend returns error → `CatalogBanner` shows `t('barcodeLookup.invalidBarcode')` |
| Vertical not supported | Backend returns 422 → submission silently skipped (no enrichment available for this vertical) |

The form **never blocks on platform availability**. Every error path degrades to manual entry.

---

## 13. Testing Strategy

### Unit tests (Vitest)

- `useBarcodeScanner` — keystroke timing detection, Enter key trigger, buffer reset
- `useCatalogLookup` — query enabled/disabled logic, stale time, refetchOnWindowFocus disabled
- `BarcodeLookupInput` — 5 state transitions, scanner callback, debounce behavior, design token usage
- `CatalogBanner` — correct banner for each state, i18n keys used
- `platformApi` — correct endpoint paths (no `/api/v1` prefix), payload shapes

### Integration tests

- `ProductForm` with `BarcodeLookupInput` — full flow: enter barcode → lookup → pre-fill → save with enrichment

### Backend test

- `ProductSubmissionController` — permission check, validation, correct service call, vertical resolution

### What NOT to test

- Backend API behavior (tested in Sub-project 1)
- Scanner hardware interaction (test the hook's callback interface instead)
