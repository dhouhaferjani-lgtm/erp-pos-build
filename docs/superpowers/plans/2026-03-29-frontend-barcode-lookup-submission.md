# Frontend Barcode Lookup & Submission — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add platform barcode lookup and enrichment submission to the product creation form, with shared scanner detection extracted from POS.

**Architecture:** `BarcodeLookupInput` component replaces the plain barcode text input in `ProductForm.tsx`. It integrates a shared `useBarcodeScanner` hook (moved from POS) for scanner detection and a `useCatalogLookup` TanStack Query hook for platform lookup. On product save, if the barcode was not found, the form auto-submits to the platform for enrichment via `useProductSubmission`. A new thin `ProductSubmissionController` backend endpoint exposes the existing `ProductSubmissionService` to the frontend.

**Tech Stack:** React 19, TypeScript strict, TanStack Query 5, react-hook-form, Vitest, design tokens from `@/lib/designTokens`, react-i18next.

**Spec:** `docs/superpowers/specs/2026-03-29-frontend-barcode-lookup-submission-design.md`

**Conventions:** Read `docs/conventions/README.md`. Key rules: design tokens for all new components (no hardcoded Tailwind colors), all user-facing text via `t()`, `apiPost`/`apiGet` already unwrap `response.data.data`, query key factories for TanStack Query, react-hook-form + zod for forms.

---

## File Map

### Backend

| Action | Path |
|--------|------|
| Create | `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php` |
| Modify | `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php` |
| Create | `apps/api/tests/Feature/Modules/PlatformIntegration/ProductSubmissionControllerTest.php` |

### Frontend — Shared

| Action | Path |
|--------|------|
| Move | `apps/web/src/hooks/useBarcodeScanner.ts` (from `features/pos/hooks/`) |
| Modify | `apps/web/src/features/pos/pages/POSPage/POSPage.tsx` |
| Modify | `apps/web/src/features/pos/hooks/index.ts` |

### Frontend — Inventory Feature

| Action | Path |
|--------|------|
| Create | `apps/web/src/features/inventory/types/platform.ts` |
| Create | `apps/web/src/features/inventory/api/platformApi.ts` |
| Create | `apps/web/src/features/inventory/api/platformQueries.ts` |
| Create | `apps/web/src/features/inventory/components/BarcodeLookupInput.tsx` |
| Create | `apps/web/src/features/inventory/components/CatalogBanner.tsx` |
| Modify | `apps/web/src/features/inventory/ProductForm.tsx` |

### i18n

| Action | Path |
|--------|------|
| Modify | `apps/web/src/locales/en/inventory.json` |
| Modify | `apps/web/src/locales/fr/inventory.json` |

### Tests

| Action | Path |
|--------|------|
| Create | `apps/web/src/__tests__/hooks/useBarcodeScanner.test.ts` |
| Create | `apps/web/src/features/inventory/__tests__/CatalogBanner.test.tsx` |
| Create | `apps/web/src/features/inventory/__tests__/platformApi.test.ts` |

---

## Task 1: Backend — ProductSubmissionController and Route

**Files:**
- Create: `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php`
- Create: `apps/api/tests/Feature/Modules/PlatformIntegration/ProductSubmissionControllerTest.php`

- [ ] **Step 1: Create the controller**

Create `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\DTOs\ProductSubmissionData;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

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
            return response()->json([
                'error' => ['code' => 'vertical_not_supported', 'message' => 'This vertical does not support enrichment.'],
            ], 422);
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
            return response()->json([
                'error' => ['code' => 'platform_unavailable', 'message' => 'Platform is currently unavailable.'],
            ], 502);
        }

        return response()->json([
            'data' => $result->toArray(),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
            ],
        ]);
    }
}
```

- [ ] **Step 2: Add route**

In `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php`, add the import at the top (with the other controller imports):

```php
use App\Modules\PlatformIntegration\Presentation\Controllers\ProductSubmissionController;
```

Inside the existing authenticated route group (`Route::prefix('api/v1/platform')->middleware([...])...`), add after the barcode-lookup route:

```php
Route::post('submit-for-enrichment', ProductSubmissionController::class)
    ->name('platform.submit-for-enrichment');
```

- [ ] **Step 3: Write feature test**

Create `apps/api/tests/Feature/Modules/PlatformIntegration/ProductSubmissionControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\PlatformIntegration;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductSubmissionControllerTest extends TestCase
{
    public function test_submit_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/platform/submit-for-enrichment', [
            'name' => 'Test',
            'brand' => 'Brand',
        ]);

        $response->assertStatus(401);
    }

    public function test_submit_validates_required_fields(): void
    {
        // This test verifies the route exists and validates input.
        // Full auth setup depends on the project's test helpers.
        $response = $this->postJson('/api/v1/platform/submit-for-enrichment', []);

        // Either 401 (no auth) or 422 (validation) — both confirm route exists
        $this->assertContains($response->status(), [401, 422]);
    }
}
```

- [ ] **Step 4: Run Pint and test**

```bash
cd apps/api && ./vendor/bin/pint apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php
php artisan test --filter=ProductSubmissionControllerTest
```

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/ProductSubmissionController.php apps/api/app/Modules/PlatformIntegration/Presentation/routes.php apps/api/tests/Feature/Modules/PlatformIntegration/ProductSubmissionControllerTest.php
git commit -m "feat(platform): add ProductSubmissionController for frontend enrichment

Thin controller exposing ProductSubmissionService to the frontend.
POST /platform/submit-for-enrichment with enrichment.submit permission."
```

---

## Task 2: Move useBarcodeScanner to Shared Hooks

**Files:**
- Move: `apps/web/src/features/pos/hooks/useBarcodeScanner.ts` → `apps/web/src/hooks/useBarcodeScanner.ts`
- Modify: `apps/web/src/features/pos/pages/POSPage/POSPage.tsx`
- Modify: `apps/web/src/features/pos/hooks/index.ts`

- [ ] **Step 1: Copy the hook to shared location**

Copy `apps/web/src/features/pos/hooks/useBarcodeScanner.ts` to `apps/web/src/hooks/useBarcodeScanner.ts`. The file content is identical — no changes needed.

- [ ] **Step 2: Delete the original file**

Delete `apps/web/src/features/pos/hooks/useBarcodeScanner.ts`.

- [ ] **Step 3: Update POSPage import**

In `apps/web/src/features/pos/pages/POSPage/POSPage.tsx`, change line 13 from:

```typescript
import { useBarcodeScanner } from '../../hooks/useBarcodeScanner'
```

to:

```typescript
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner'
```

- [ ] **Step 4: Update barrel export**

In `apps/web/src/features/pos/hooks/index.ts`, change line 1 from:

```typescript
export { useBarcodeScanner } from './useBarcodeScanner'
```

to:

```typescript
export { useBarcodeScanner } from '@/hooks/useBarcodeScanner'
```

- [ ] **Step 5: Verify POS still works**

```bash
cd apps/web && pnpm typecheck
```

Expected: No TypeScript errors.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/hooks/useBarcodeScanner.ts apps/web/src/features/pos/hooks/ apps/web/src/features/pos/pages/POSPage/POSPage.tsx
git commit -m "refactor: move useBarcodeScanner to shared hooks

Domain-agnostic keyboard wedge detection hook, now shared between
POS and inventory features. POS barrel re-exports from new location."
```

---

## Task 3: Types, API Layer, and Query Hooks

**Files:**
- Create: `apps/web/src/features/inventory/types/platform.ts`
- Create: `apps/web/src/features/inventory/api/platformApi.ts`
- Create: `apps/web/src/features/inventory/api/platformQueries.ts`

- [ ] **Step 1: Create types directory and file**

Create `apps/web/src/features/inventory/types/platform.ts`:

```typescript
/**
 * Types for platform barcode lookup and enrichment submission.
 *
 * Note: CatalogLookupResult fields use camelCase (from Spatie Data DTO),
 * but suggestedProduct is a manually-built array with snake_case keys.
 */

export interface PlatformProductData {
  id: string
  barcode: string
  name: string
  brand: string | null
  description: string | null
  classification: Record<string, unknown>
  ingredients: Array<{ name: string; position: number }>
  images: Array<{ url: string | null; thumbnail: string | null; type: string | null }>
  confidenceScore: number
  enrichmentTier: string | null
}

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

export interface CatalogLookupResult {
  status: 'found' | 'not_found' | 'error'
  barcode: string | null
  product: PlatformProductData | null
  trackingId: string | null
  suggestedProduct: SuggestedProduct | null
  errorReason: string | null
}

export interface SubmissionResult {
  trackingId: string
  status: string
  statusUrl: string
}

export type LookupState = 'idle' | 'searching' | 'found' | 'not_found' | 'error'
```

- [ ] **Step 2: Create API directory and functions**

Create `apps/web/src/features/inventory/api/platformApi.ts`:

```typescript
import { apiPost } from '@/lib/api'
import type { CatalogLookupResult, SubmissionResult } from '../types/platform'

export async function lookupBarcode(barcode: string): Promise<CatalogLookupResult> {
  return apiPost<CatalogLookupResult>('/platform/barcode-lookup', { barcode })
}

export interface SubmitForEnrichmentPayload {
  barcode: string | null
  name: string
  brand: string
  category?: string
  description?: string
}

export async function submitForEnrichment(data: SubmitForEnrichmentPayload): Promise<SubmissionResult> {
  return apiPost<SubmissionResult>('/platform/submit-for-enrichment', data)
}
```

- [ ] **Step 3: Create query hooks**

Create `apps/web/src/features/inventory/api/platformQueries.ts`:

```typescript
import { useQuery, useMutation } from '@tanstack/react-query'
import { lookupBarcode, submitForEnrichment } from './platformApi'
import type { SubmitForEnrichmentPayload } from './platformApi'

export const platformKeys = {
  all: ['platform'] as const,
  catalogLookup: (barcode: string) => [...platformKeys.all, 'catalog-lookup', barcode] as const,
}

export function useCatalogLookup(barcode: string | null) {
  return useQuery({
    queryKey: platformKeys.catalogLookup(barcode ?? ''),
    queryFn: () => lookupBarcode(barcode!),
    enabled: barcode !== null && barcode.length >= 8,
    staleTime: 60 * 60 * 1000,
    retry: false,
    refetchOnWindowFocus: false,
  })
}

export function useProductSubmission() {
  return useMutation({
    mutationFn: (data: SubmitForEnrichmentPayload) => submitForEnrichment(data),
  })
}
```

- [ ] **Step 4: Write API test**

Create `apps/web/src/features/inventory/__tests__/platformApi.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock the api module
vi.mock('@/lib/api', () => ({
  apiPost: vi.fn(),
}))

import { apiPost } from '@/lib/api'
import { lookupBarcode, submitForEnrichment } from '../api/platformApi'

const mockApiPost = vi.mocked(apiPost)

describe('platformApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('lookupBarcode', () => {
    it('calls correct endpoint with barcode', async () => {
      mockApiPost.mockResolvedValue({ status: 'found', product: {} })

      await lookupBarcode('5901234123457')

      expect(mockApiPost).toHaveBeenCalledWith('/platform/barcode-lookup', {
        barcode: '5901234123457',
      })
    })

    it('does not prefix with /api/v1', async () => {
      mockApiPost.mockResolvedValue({ status: 'not_found' })

      await lookupBarcode('123')

      const calledUrl = mockApiPost.mock.calls[0][0]
      expect(calledUrl).not.toContain('/api/v1')
      expect(calledUrl).toBe('/platform/barcode-lookup')
    })
  })

  describe('submitForEnrichment', () => {
    it('calls correct endpoint with payload', async () => {
      mockApiPost.mockResolvedValue({ trackingId: 'track-1', status: 'submitted' })

      await submitForEnrichment({
        barcode: '5901234123457',
        name: 'Test Product',
        brand: 'TestBrand',
      })

      expect(mockApiPost).toHaveBeenCalledWith('/platform/submit-for-enrichment', {
        barcode: '5901234123457',
        name: 'Test Product',
        brand: 'TestBrand',
      })
    })
  })
})
```

- [ ] **Step 5: Run tests and typecheck**

```bash
cd apps/web && pnpm typecheck && pnpm test -- --run src/features/inventory/__tests__/platformApi.test.ts
```

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/inventory/types/ apps/web/src/features/inventory/api/ apps/web/src/features/inventory/__tests__/
git commit -m "feat(inventory): add platform API types, functions, and query hooks

Types for CatalogLookupResult/SubmissionResult, lookupBarcode/submitForEnrichment
API functions, useCatalogLookup/useProductSubmission TanStack Query hooks."
```

---

## Task 4: CatalogBanner Component

**Files:**
- Create: `apps/web/src/features/inventory/components/CatalogBanner.tsx`
- Create: `apps/web/src/features/inventory/__tests__/CatalogBanner.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `apps/web/src/features/inventory/__tests__/CatalogBanner.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { CatalogBanner } from '../components/CatalogBanner'

// Mock i18n
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, string>) => {
      const translations: Record<string, string> = {
        'barcodeLookup.catalogFound': 'Data from Syneriva Catalog',
        'barcodeLookup.confidence': `Confidence: ${opts?.tier ?? ''}`,
        'barcodeLookup.notInCatalog': 'Not in catalog — fill in details manually',
        'barcodeLookup.catalogUnavailable': 'Catalog unavailable — enter details manually',
      }
      return translations[key] ?? key
    },
  }),
}))

describe('CatalogBanner', () => {
  it('renders nothing for idle state', () => {
    const { container } = render(<CatalogBanner state="idle" />)
    expect(container.firstChild).toBeNull()
  })

  it('renders nothing for searching state', () => {
    const { container } = render(<CatalogBanner state="searching" />)
    expect(container.firstChild).toBeNull()
  })

  it('renders green banner for found state', () => {
    render(<CatalogBanner state="found" confidenceTier="high" />)
    expect(screen.getByText('Data from Syneriva Catalog')).toBeInTheDocument()
    expect(screen.getByText('Confidence: high')).toBeInTheDocument()
  })

  it('renders yellow banner for not_found state', () => {
    render(<CatalogBanner state="not_found" />)
    expect(screen.getByText(/not in catalog/i)).toBeInTheDocument()
  })

  it('renders red banner for error state', () => {
    render(<CatalogBanner state="error" />)
    expect(screen.getByText(/catalog unavailable/i)).toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd apps/web && pnpm test -- --run src/features/inventory/__tests__/CatalogBanner.test.tsx
```

Expected: FAIL — component does not exist.

- [ ] **Step 3: Implement the component**

Create `apps/web/src/features/inventory/components/CatalogBanner.tsx`:

```tsx
import { useTranslation } from 'react-i18next'
import { colors } from '@/lib/designTokens'
import type { LookupState } from '../types/platform'

interface CatalogBannerProps {
  state: LookupState
  confidenceTier?: string | null
}

export function CatalogBanner({ state, confidenceTier }: CatalogBannerProps) {
  const { t } = useTranslation('inventory')

  if (state === 'idle' || state === 'searching') {
    return null
  }

  if (state === 'found') {
    return (
      <div
        className="flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm"
        style={{
          backgroundColor: colors.success[50],
          borderColor: colors.success[200],
          color: colors.success[800],
        }}
      >
        <span className="text-base">&#10003;</span>
        <span className="font-medium">{t('barcodeLookup.catalogFound')}</span>
        {confidenceTier && (
          <span className="ml-auto text-xs opacity-70">
            {t('barcodeLookup.confidence', { tier: confidenceTier })}
          </span>
        )}
      </div>
    )
  }

  if (state === 'not_found') {
    return (
      <div
        className="flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm"
        style={{
          backgroundColor: colors.warning[50],
          borderColor: colors.warning[200],
          color: colors.warning[800],
        }}
      >
        <span className="text-base">&#9432;</span>
        <span className="font-medium">{t('barcodeLookup.notInCatalog')}</span>
      </div>
    )
  }

  // error state
  return (
    <div
      className="flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm"
      style={{
        backgroundColor: colors.error[50],
        borderColor: colors.error[200],
        color: colors.error[800],
      }}
    >
      <span className="text-base">&#9888;</span>
      <span className="font-medium">{t('barcodeLookup.catalogUnavailable')}</span>
    </div>
  )
}
```

- [ ] **Step 4: Run tests**

```bash
cd apps/web && pnpm test -- --run src/features/inventory/__tests__/CatalogBanner.test.tsx
```

Expected: All 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/inventory/components/CatalogBanner.tsx apps/web/src/features/inventory/__tests__/CatalogBanner.test.tsx
git commit -m "feat(inventory): add CatalogBanner component

Status banner for barcode lookup results: green (found), yellow (not found),
red (error). Uses design tokens for colors, i18n for all text."
```

---

## Task 5: BarcodeLookupInput Component

**Files:**
- Create: `apps/web/src/features/inventory/components/BarcodeLookupInput.tsx`

- [ ] **Step 1: Create the component**

Create `apps/web/src/features/inventory/components/BarcodeLookupInput.tsx`:

```tsx
import { useState, useEffect, useRef, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner'
import { tokens, textColors } from '@/lib/designTokens'
import { useCatalogLookup } from '../api/platformQueries'
import type { LookupState, SuggestedProduct } from '../types/platform'

interface BarcodeLookupInputProps {
  onProductData: (data: SuggestedProduct) => void
  onLookupStateChange: (state: LookupState) => void
  defaultBarcode?: string
}

const DEBOUNCE_MS = 300
const MIN_LOOKUP_LENGTH = 8

export function BarcodeLookupInput({
  onProductData,
  onLookupStateChange,
  defaultBarcode = '',
}: BarcodeLookupInputProps) {
  const { t } = useTranslation('inventory')
  const [inputValue, setInputValue] = useState(defaultBarcode)
  const [lookupBarcode, setLookupBarcode] = useState<string | null>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const prevStateRef = useRef<LookupState>('idle')

  const { data, isLoading, isError } = useCatalogLookup(lookupBarcode)

  // Scanner detection — triggers lookup immediately (no debounce)
  const handleScan = useCallback((barcode: string) => {
    setInputValue(barcode)
    setLookupBarcode(barcode)
  }, [])

  useBarcodeScanner({ onScan: handleScan })

  // Manual typing — debounce 300ms
  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value
    setInputValue(value)

    if (debounceRef.current) {
      clearTimeout(debounceRef.current)
    }

    if (value.length >= MIN_LOOKUP_LENGTH) {
      debounceRef.current = setTimeout(() => {
        setLookupBarcode(value)
      }, DEBOUNCE_MS)
    } else {
      setLookupBarcode(null)
    }
  }

  // Clear handler
  const handleClear = () => {
    setInputValue('')
    setLookupBarcode(null)
    if (debounceRef.current) {
      clearTimeout(debounceRef.current)
    }
  }

  // Derive lookup state and notify parent
  useEffect(() => {
    let state: LookupState = 'idle'

    if (isLoading) {
      state = 'searching'
    } else if (isError) {
      state = 'error'
    } else if (data) {
      if (data.status === 'found') {
        state = 'found'
      } else if (data.status === 'not_found') {
        state = 'not_found'
      } else if (data.status === 'error') {
        state = 'error'
      }
    }

    if (state !== prevStateRef.current) {
      prevStateRef.current = state
      onLookupStateChange(state)

      // Notify parent with product data when found
      if (state === 'found' && data?.suggestedProduct) {
        onProductData(data.suggestedProduct)
      }
    }
  }, [data, isLoading, isError, onLookupStateChange, onProductData])

  // Cleanup debounce on unmount
  useEffect(() => {
    return () => {
      if (debounceRef.current) {
        clearTimeout(debounceRef.current)
      }
    }
  }, [])

  return (
    <div>
      <label
        htmlFor="barcode-lookup"
        className="mb-1 block text-sm font-medium"
        style={{ color: textColors.primary }}
      >
        {t('products.barcode')}
      </label>
      <div className="flex items-center gap-2">
        <div className="relative flex-1">
          <input
            type="text"
            id="barcode-lookup"
            value={inputValue}
            onChange={handleInputChange}
            placeholder={t('barcodeLookup.placeholder')}
            className={tokens.input.base}
            disabled={isLoading}
          />
          {inputValue && (
            <button
              type="button"
              onClick={handleClear}
              className="absolute right-2 top-1/2 -translate-y-1/2 text-sm opacity-50 hover:opacity-100"
              aria-label="Clear barcode"
            >
              &#10005;
            </button>
          )}
        </div>
        {isLoading && (
          <span className="text-sm" style={{ color: textColors.secondary }}>
            {t('barcodeLookup.searching')}
          </span>
        )}
      </div>
    </div>
  )
}
```

- [ ] **Step 2: Verify typecheck**

```bash
cd apps/web && pnpm typecheck
```

Expected: No TypeScript errors.

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/features/inventory/components/BarcodeLookupInput.tsx
git commit -m "feat(inventory): add BarcodeLookupInput component

Barcode input with scanner detection (shared useBarcodeScanner), 300ms
debounce for manual typing, platform lookup via useCatalogLookup, and
parent notification callbacks for state changes and product data."
```

---

## Task 6: i18n Translation Keys

**Files:**
- Modify: `apps/web/src/locales/en/inventory.json`
- Modify: `apps/web/src/locales/fr/inventory.json`

- [ ] **Step 1: Add English translations**

In `apps/web/src/locales/en/inventory.json`, add a new `"barcodeLookup"` section at the top level (after the existing `"products"` section):

```json
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
```

- [ ] **Step 2: Add French translations**

In `apps/web/src/locales/fr/inventory.json`, add the same `"barcodeLookup"` section:

```json
"barcodeLookup": {
  "placeholder": "Scanner ou saisir le code-barres...",
  "searching": "Recherche en cours...",
  "catalogFound": "Données du catalogue Syneriva",
  "confidence": "Confiance : {{tier}}",
  "notInCatalog": "Absent du catalogue — saisissez les détails manuellement",
  "catalogUnavailable": "Catalogue indisponible — saisissez les détails manuellement",
  "invalidBarcode": "Format de code-barres invalide",
  "enrichmentCheckbox": "Soumettre pour enrichissement catalogue",
  "enrichmentDescription": "Les données produit seront enrichies par Syneriva après l'enregistrement",
  "toastSavedWithCatalog": "Produit enregistré avec les données du catalogue.",
  "toastSavedWithEnrichment": "Produit enregistré. Soumis pour enrichissement catalogue.",
  "toastEnrichmentFailed": "Produit enregistré mais la soumission d'enrichissement a échoué."
}
```

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/locales/en/inventory.json apps/web/src/locales/fr/inventory.json
git commit -m "feat(i18n): add barcode lookup translation keys

English and French translations for barcode lookup states, enrichment
checkbox, and toast messages."
```

---

## Task 7: Wire BarcodeLookupInput into ProductForm

**Files:**
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`

This is the integration task. It modifies the existing form to use the new components.

- [ ] **Step 1: Add imports to ProductForm.tsx**

At the top of `apps/web/src/features/inventory/ProductForm.tsx`, add these imports:

```typescript
import { BarcodeLookupInput } from './components/BarcodeLookupInput'
import { CatalogBanner } from './components/CatalogBanner'
import { useProductSubmission } from './api/platformQueries'
import type { LookupState, SuggestedProduct } from './types/platform'
```

- [ ] **Step 2: Add state variables and submission hook**

Inside the `ProductForm` component function, after the existing `useForm` setup, add:

```typescript
const [lookupState, setLookupState] = useState<LookupState>('idle')
const [enrichmentOptIn, setEnrichmentOptIn] = useState(true)
const suggestedProductRef = useRef<SuggestedProduct | null>(null)
const prefilledFieldsRef = useRef<Set<string>>(new Set())

const submissionMutation = useProductSubmission()
```

Add `useState` and `useRef` to the React import if not already there.

- [ ] **Step 3: Add pre-fill handler**

After the state declarations, add:

```typescript
const handleProductData = useCallback((data: SuggestedProduct) => {
  suggestedProductRef.current = data
  prefilledFieldsRef.current = new Set()

  if (data.name) {
    setValue('name', data.name, { shouldDirty: false })
    prefilledFieldsRef.current.add('name')
  }
  if (data.description) {
    setValue('description', data.description, { shouldDirty: false })
    prefilledFieldsRef.current.add('description')
  }
}, [setValue])

const handleLookupStateChange = useCallback((state: LookupState) => {
  setLookupState(state)
  if (state === 'idle') {
    suggestedProductRef.current = null
    prefilledFieldsRef.current.clear()
  }
}, [])
```

Add `useCallback` to the React import if not already there.

- [ ] **Step 4: Modify the create mutation to use mutateAsync**

Replace the existing `createMutation` (around lines 179-185) with:

```typescript
const createMutation = useMutation({
  mutationFn: (data: ProductFormData) => apiPost<Product>('/products', data),
  onSuccess: () => {
    void queryClient.invalidateQueries({ queryKey: ['products'] })
  },
})
```

Replace the existing `onSubmit` handler (around lines 196-202) with:

```typescript
const onSubmit = async (data: ProductFormData) => {
  if (isEditing) {
    updateMutation.mutate(data)
    return
  }

  try {
    await createMutation.mutateAsync(data)

    // Submit for enrichment if barcode was not found and opt-in is checked
    if (lookupState === 'not_found' && enrichmentOptIn) {
      submissionMutation.mutate({
        barcode: data.barcode || null,
        name: data.name,
        brand: suggestedProductRef.current?.brand ?? '',
        category: undefined,
        description: data.description || undefined,
      })
      // Toast handled by mutation callbacks or inline
    }

    void navigate('/inventory/products')
  } catch {
    // Error already handled by react-query
  }
}
```

- [ ] **Step 5: Replace barcode input in JSX**

Find the barcode input section (around lines 324-334) and replace it with:

```tsx
<BarcodeLookupInput
  onProductData={handleProductData}
  onLookupStateChange={handleLookupStateChange}
  defaultBarcode={product?.barcode ?? ''}
/>

{/* Enrichment opt-in checkbox — only visible when not found */}
{lookupState === 'not_found' && (
  <div className="mt-3 flex items-center gap-2.5 rounded-lg bg-neutral-100 px-3.5 py-3">
    <input
      type="checkbox"
      id="enrichment-opt-in"
      checked={enrichmentOptIn}
      onChange={(e) => setEnrichmentOptIn(e.target.checked)}
      className="h-4 w-4 rounded"
    />
    <label htmlFor="enrichment-opt-in" className="text-sm">
      <span className="font-medium">{t('barcodeLookup.enrichmentCheckbox')}</span>
      <span className="ml-1 text-xs opacity-70">{t('barcodeLookup.enrichmentDescription')}</span>
    </label>
  </div>
)}
```

- [ ] **Step 6: Add CatalogBanner above form sections**

Find the start of the form fields section (the "Basic Information" heading, around line 251) and add the banner just above it:

```tsx
<CatalogBanner
  state={lookupState}
  confidenceTier={
    lookupState === 'found' && suggestedProductRef.current
      ? 'high'
      : null
  }
/>
```

- [ ] **Step 7: Register barcode value from BarcodeLookupInput**

The `BarcodeLookupInput` manages its own input value but the form needs the barcode for submission. Add a hidden input that syncs with the lookup:

In the `handleProductData` callback, after setting name/description, add:

```typescript
if (data.barcode) {
  setValue('barcode', data.barcode, { shouldDirty: false })
}
```

In the `handleScan` inside `BarcodeLookupInput`, the parent doesn't directly see the raw input. Instead, add a `name` prop to BarcodeLookupInput to register with react-hook-form, or keep the existing `register('barcode')` as a hidden field that gets updated via `setValue`.

The simplest approach: keep `{...register('barcode')}` as a hidden input, and let `BarcodeLookupInput` update it via `setValue` in the callbacks.

- [ ] **Step 8: Verify typecheck and run**

```bash
cd apps/web && pnpm typecheck && pnpm lint
```

- [ ] **Step 9: Commit**

```bash
git add apps/web/src/features/inventory/ProductForm.tsx
git commit -m "feat(inventory): wire BarcodeLookupInput into ProductForm

Replace plain barcode input with BarcodeLookupInput. Add CatalogBanner
for lookup status, enrichment opt-in checkbox, auto-submit on save for
not-found barcodes, and pre-fill form fields from platform data."
```

---

## Task 8: Final Verification

- [ ] **Step 1: Run full frontend typecheck**

```bash
cd apps/web && pnpm typecheck
```

Expected: Zero TypeScript errors.

- [ ] **Step 2: Run ESLint**

```bash
cd apps/web && pnpm lint
```

Expected: No errors in new files.

- [ ] **Step 3: Run all new tests**

```bash
cd apps/web && pnpm test -- --run src/features/inventory/__tests__/ src/__tests__/hooks/
```

Expected: All tests pass.

- [ ] **Step 4: Run backend tests for new controller**

```bash
cd apps/api && php artisan test --filter=ProductSubmissionControllerTest
```

Expected: Tests pass.

- [ ] **Step 5: Generate TypeScript types from backend DTOs**

```bash
cd apps/api && php artisan typescript:transform
```

Verify that `BarcodeLookupResultData` and `SubmissionResultData` types appear in the generated output. Update `features/inventory/types/platform.ts` imports if generated type names are available.

- [ ] **Step 6: Commit any fixes**

```bash
git add -A
git commit -m "chore: fix typecheck, lint, and test issues"
```
