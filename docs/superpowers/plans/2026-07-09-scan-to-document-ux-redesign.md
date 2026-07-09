# Scan-to-Document UX Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the scan upload → processing → review UX: dedicated upload page with dropzone, live staged processing on the detail page, PDF preview via pdf.js, review-primary sticky layout with lightbox, real `ProductPicker` + create-product-with-prefill per line, and in-page supplier creation via `AddPartnerModal` with prefill.

**Architecture:** FE-only (apps/web). No new endpoints; the only new data behavior is detail-page polling. New `ProductPrefill` contract mirrors the existing `PartnerPrefill`; both quick-create modals gain an additive optional `prefill` prop. `SourceViewer` is rewritten to fetch bytes and branch image/PDF (canvas render — the media route sends `X-Frame-Options: DENY`, so no iframes ever).

**Tech Stack:** React 19, TS strict, Tailwind 4, TanStack Query 5, react-hook-form, sonner (toasts), pdfjs-dist (NEW dependency, Task 6), vitest + @testing-library/react.

## Global Constraints

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.scan-to-doc`, branch `feat/scan-to-document`. All paths below are relative to `apps/web/` unless stated.
- **TS strict, no `any`** — use `unknown` + type guards.
- **No `parseFloat`/`Number()` on money/quantity in NEW code** (ESLint `no-parsefloat-on-money` fails CI). Prefill values stay **strings** end-to-end. (`AddQuickProductModal`'s existing internal `parseFloat` is pre-existing — do not add new ones.)
- **All user-facing text via `t()`** — namespace `documentIngestions` for the scan feature; add every new key to **all three** locale files: `src/locales/{en,fr,ar}/documentIngestions.json`.
- **Design tokens:** import from `@/lib/designTokens` (`tokens`, `textColors`, `borderColors`) + `cn` from `@/lib/utils`. No hardcoded Tailwind colors in new code.
- **Query keys:** every tenant-data query key wrapped in `tenantScopedKey([...])` (enforced by `tools/audit-tanstack-keys.mjs`).
- **API responses:** `apiGet`/`apiPost` already unwrap `response.data.data` — never double-unwrap. Server payloads are mixed snake/camel — coalesce via the `normalize*` helpers in `src/features/document-ingestions/api.ts`.
- **Tests:** run vitest **BY PATH** (`pnpm vitest run <path>`), never the full suite. After any hung run: `ps aux | grep '[n]ode.*vitest'` and kill workers.
- **Test pattern (established in `src/features/document-ingestions/__tests__/`):** local render helper wrapping `QueryClientProvider` (retry:false) + `MemoryRouter`; `vi.hoisted` + `vi.mock` for `@/lib/api` and `sonner`; set `useAuthStore`/`useCompanyStore` state directly in `beforeEach`; real i18n with `window.localStorage.setItem('autoerp-language', 'en')` and assertions on real English strings.
- **Commit after every task** (`git add <files> && git commit`), message style `feat(ingestion): …` / `feat(products): …` / `feat(partners): …` / `test(…): …`.
- **Status enum (exact):** `'uploaded' | 'extracting' | 'needs_review' | 'committing' | 'committed' | 'rejected' | 'failed'`. Doc kinds: `'supplier_invoice' | 'supplier_delivery_note'`.

---

### Task 1: `ProductPrefill` contract + `buildProductPrefill`

**Files:**
- Create: `src/features/products/productPrefill.ts`
- Create: `src/features/products/productPrefill.test.ts`
- Create: `src/features/document-ingestions/buildProductPrefill.ts`
- Create: `src/features/document-ingestions/buildProductPrefill.test.ts`

**Interfaces:**
- Consumes: `ExtractedLine`, `ExtractedField` from `src/features/document-ingestions/types.ts` (`ExtractedField = { value: string; confidence: number; sourceBbox: readonly unknown[] | null }`; `ExtractedLine` has `description`, `unitPrice`, `taxRate` each `ExtractedField | null`).
- Produces: `export interface ProductPrefill { name?: string; sale_price?: string; cost?: string; tax_rate?: string }`, `export function readProductPrefill(state: unknown): ProductPrefill | null`, `export function buildProductPrefill(line: ExtractedLine): ProductPrefill`. Tasks 2 and 8 depend on these exact names.

Mirror `src/features/partners/partnerPrefill.ts` (whitelist keys, trim, never throw) and `src/features/document-ingestions/buildSupplierPrefill.ts` (tests mirror `buildSupplierPrefill.test.ts` / `partnerPrefill.test.ts`). Note the domain decision: `cost` is carried in the contract (the purchase unit price IS the cost) but the quick-product modal has no cost field — actual costing is established by the receipt/invoice commit (WAC). Do NOT wire `cost` into any payload in this plan.

- [ ] **Step 1: Write failing tests**

`src/features/products/productPrefill.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { readProductPrefill } from './productPrefill'

describe('readProductPrefill', () => {
  it('returns null for non-object state', () => {
    expect(readProductPrefill(null)).toBeNull()
    expect(readProductPrefill('x')).toBeNull()
    expect(readProductPrefill([])).toBeNull()
  })

  it('returns null when productPrefill key is missing or not an object', () => {
    expect(readProductPrefill({})).toBeNull()
    expect(readProductPrefill({ productPrefill: 'nope' })).toBeNull()
  })

  it('keeps only whitelisted, non-empty string keys and trims them', () => {
    expect(
      readProductPrefill({
        productPrefill: {
          name: '  Paracetamol 500mg  ',
          sale_price: '12.500',
          cost: '9.000',
          tax_rate: '19',
          sku: 'DROP-ME',
          sale_priceX: 'DROP-ME',
          empty: '   ',
          num: 12,
        },
      }),
    ).toEqual({ name: 'Paracetamol 500mg', sale_price: '12.500', cost: '9.000', tax_rate: '19' })
  })

  it('returns null when nothing valid survives', () => {
    expect(readProductPrefill({ productPrefill: { name: '   ', sale_price: 5 } })).toBeNull()
  })
})
```

`src/features/document-ingestions/buildProductPrefill.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { buildProductPrefill } from './buildProductPrefill'
import type { ExtractedField, ExtractedLine } from './types'

function f(value: string): ExtractedField {
  return { value, confidence: 0.9, sourceBbox: null }
}

function line(overrides: Partial<ExtractedLine>): ExtractedLine {
  return {
    description: null, supplierRef: null, quantity: null, unitPrice: null,
    taxRate: null, lineTotal: null, batchNumber: null, expiryDate: null,
    ...overrides,
  } as ExtractedLine
}

describe('buildProductPrefill', () => {
  it('maps description → name, unitPrice → sale_price AND cost, taxRate → tax_rate (strings preserved)', () => {
    expect(
      buildProductPrefill(line({ description: f(' Doliprane 1g '), unitPrice: f('4.850'), taxRate: f('7') })),
    ).toEqual({ name: 'Doliprane 1g', sale_price: '4.850', cost: '4.850', tax_rate: '7' })
  })

  it('drops non-numeric price/tax values but keeps the name', () => {
    expect(
      buildProductPrefill(line({ description: f('Widget'), unitPrice: f('N/A'), taxRate: f('unknown') })),
    ).toEqual({ name: 'Widget' })
  })

  it('returns {} for a line with no usable fields', () => {
    expect(buildProductPrefill(line({ description: f('   ') }))).toEqual({})
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm vitest run src/features/products/productPrefill.test.ts src/features/document-ingestions/buildProductPrefill.test.ts`
Expected: FAIL — modules not found.

- [ ] **Step 3: Implement**

`src/features/products/productPrefill.ts`:

```ts
export interface ProductPrefill {
  name?: string
  sale_price?: string
  cost?: string
  tax_rate?: string
}

const PRODUCT_PREFILL_KEYS = ['name', 'sale_price', 'cost', 'tax_rate'] as const

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function readProductPrefill(state: unknown): ProductPrefill | null {
  if (!isRecord(state)) { return null }
  const candidate = state['productPrefill']
  if (!isRecord(candidate)) { return null }
  const result: ProductPrefill = {}
  for (const key of PRODUCT_PREFILL_KEYS) {
    const value = candidate[key]
    if (typeof value !== 'string') { continue }
    const trimmed = value.trim()
    if (trimmed.length === 0) { continue }
    result[key] = trimmed
  }
  return Object.keys(result).length > 0 ? result : null
}
```

`src/features/document-ingestions/buildProductPrefill.ts`:

```ts
import type { ProductPrefill } from '@/features/products/productPrefill'
import type { ExtractedField, ExtractedLine } from './types'

// Matches plain decimal strings only — prefill values feed form fields as
// strings (precision contract: no float parsing on money).
const NUMERIC_PATTERN = /^-?\d+(\.\d+)?$/

function fieldValue(field: ExtractedField | null): string | undefined {
  if (!field || typeof field.value !== 'string') { return undefined }
  const trimmed = field.value.trim()
  return trimmed.length > 0 ? trimmed : undefined
}

function numericFieldValue(field: ExtractedField | null): string | undefined {
  const value = fieldValue(field)
  if (value === undefined || !NUMERIC_PATTERN.test(value)) { return undefined }
  return value
}

export function buildProductPrefill(line: ExtractedLine): ProductPrefill {
  const result: ProductPrefill = {}
  const name = fieldValue(line.description)
  if (name !== undefined) { result.name = name }
  const unitPrice = numericFieldValue(line.unitPrice)
  if (unitPrice !== undefined) {
    result.sale_price = unitPrice
    result.cost = unitPrice
  }
  const taxRate = numericFieldValue(line.taxRate)
  if (taxRate !== undefined) { result.tax_rate = taxRate }
  return result
}
```

- [ ] **Step 4: Run tests to verify they pass**

Same command as Step 2. Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/products/productPrefill.ts apps/web/src/features/products/productPrefill.test.ts apps/web/src/features/document-ingestions/buildProductPrefill.ts apps/web/src/features/document-ingestions/buildProductPrefill.test.ts
git commit -m "feat(products): ProductPrefill contract + buildProductPrefill from extracted lines"
```

---

### Task 2: `AddQuickProductModal` — additive `prefill` prop

**Files:**
- Modify: `src/components/organisms/AddQuickProductModal/AddQuickProductModal.tsx` (props lines 33–49, defaultValues lines 85–93, reset-on-open effect lines 96–106)
- Create: `src/components/organisms/AddQuickProductModal/AddQuickProductModal.test.tsx`

**Interfaces:**
- Consumes: `ProductPrefill` from `@/features/products/productPrefill` (Task 1).
- Produces: `AddQuickProductModalProps` gains `prefill?: ProductPrefill`. Existing callers (`src/features/documents/components/DocumentLineEditor.tsx:857–872` — the only production call site) must be untouched and behave identically. `onSuccess?: (product: Product) => void` unchanged (`Product.sale_price`/`tax_rate` are numbers). Task 8 depends on `<AddQuickProductModal isOpen onClose prefill onSuccess>`.

The form fields are strings (`name`, `sku`, `sale_price`, `tax_rate`, `tax_configuration_id: string | null`). Prefill seeds `name`, `sale_price`, `tax_rate` on open; `sku` and `tax_configuration_id` are never prefilled; `prefill.cost` is intentionally ignored (see Task 1 note). The reset-on-open `useEffect` is the single seeding point — extend it, don't add a second effect.

- [ ] **Step 1: Write failing test**

`src/components/organisms/AddQuickProductModal/AddQuickProductModal.test.tsx` — follow the feature test pattern (QueryClientProvider with retry:false; mock `@/lib/api` with `vi.hoisted`; set `useAuthStore`/`useCompanyStore` in `beforeEach`; `localStorage.setItem('autoerp-language', 'en')`; `cleanup` in `afterEach`). Check the component's actual imports first — mock exactly what it uses (`apiPost`, and whatever `TaxConfigurationField` fetches: mock `@/lib/api`'s `api.get`/`apiGet` to return an empty tax-configuration list). Tests:

```tsx
it('seeds name, sale_price and tax_rate from prefill when opened', async () => {
  renderModal({
    isOpen: true,
    prefill: { name: 'Doliprane 1g', sale_price: '4.850', cost: '4.850', tax_rate: '7' },
  })
  expect(await screen.findByLabelText(/name/i)).toHaveValue('Doliprane 1g')
  expect(screen.getByLabelText(/price/i)).toHaveValue('4.850')
  // sku stays empty — never prefilled
  expect(screen.getByLabelText(/sku/i)).toHaveValue('')
})

it('behaves exactly as before when prefill is absent (all fields empty)', async () => {
  renderModal({ isOpen: true })
  expect(await screen.findByLabelText(/name/i)).toHaveValue('')
  expect(screen.getByLabelText(/price/i)).toHaveValue('')
})

it('re-seeds from prefill each time the modal reopens', async () => {
  const { rerender } = renderModal({ isOpen: true, prefill: { name: 'A' } })
  // user edits then closes
  await userEvent.clear(await screen.findByLabelText(/name/i))
  rerender(buildModal({ isOpen: false, prefill: { name: 'A' } }))
  rerender(buildModal({ isOpen: true, prefill: { name: 'A' } }))
  expect(await screen.findByLabelText(/name/i)).toHaveValue('A')
})
```

(Adjust `getByLabelText` matchers to the component's real label strings — read them from the JSX before writing assertions; assert on the real English strings.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/components/organisms/AddQuickProductModal/AddQuickProductModal.test.tsx`
Expected: first test FAILS (no `prefill` prop exists — TS error / empty field). The no-prefill test may already pass; that's fine, it's the regression guard.

- [ ] **Step 3: Implement**

In `AddQuickProductModal.tsx`:

```ts
import type { ProductPrefill } from '@/features/products/productPrefill'

export interface AddQuickProductModalProps {
  isOpen: boolean
  onClose: () => void
  onSuccess?: (product: Product) => void
  /**
   * Optional seed values applied every time the modal opens (create-only,
   * additive — absent prefill keeps the previous empty-form behavior).
   * `cost` is accepted for contract parity but not used: the quick form has
   * no cost field; costing comes from the purchase commit (WAC).
   */
  prefill?: ProductPrefill
}
```

Extend the reset-on-open effect (keep the existing shape — reset both `defaultValues` and the on-open reset to the same seeded object):

```ts
useEffect(() => {
  if (isOpen) {
    reset({
      name: prefill?.name ?? '',
      sku: '',
      sale_price: prefill?.sale_price ?? '',
      tax_rate: prefill?.tax_rate ?? '',
      tax_configuration_id: null,
    })
  }
}, [isOpen, prefill, reset])
```

- [ ] **Step 4: Run test to verify it passes**

Same command. Expected: PASS. Then verify existing callers still compile: `pnpm exec tsc --noEmit -p tsconfig.app.json` (or the project's `pnpm typecheck` if scoped runs aren't available).

- [ ] **Step 5: Run the existing consumer test to prove no regression**

Run: `pnpm vitest run src/features/documents/components/DocumentLineEditor.test.tsx`
Expected: PASS unchanged.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/organisms/AddQuickProductModal/
git commit -m "feat(products): AddQuickProductModal accepts optional prefill seed"
```

---

### Task 3: `AddPartnerModal` — additive `prefill` prop (PartnerPrefill → modal field mapping)

**Files:**
- Modify: `src/components/organisms/AddPartnerModal/AddPartnerModal.tsx` (props lines 45–67, defaultValues lines 134–146, reset-on-open effect lines 149–164)
- Create: `src/components/organisms/AddPartnerModal/AddPartnerModal.test.tsx`

**Interfaces:**
- Consumes: `PartnerPrefill` from `@/features/partners/partnerPrefill` (exists — 9 optional string keys: `name, vat_number, phone, email, street_address, city, state, postal_code, country_code`).
- Produces: `AddPartnerModalProps` gains `prefill?: PartnerPrefill`. Existing callers unaffected: `src/features/documents/DocumentForm.tsx:681–693`, `src/features/treasury/PaymentForm.tsx:1233–1242`. `onSuccess?: (partner: Partner) => void` unchanged. Task 9 depends on `<AddPartnerModal isOpen onClose partnerType="supplier" prefill onSuccess>`.

**Field-name mapping (the modal's `PartnerFormData` differs from `PartnerPrefill`):** `street_address → address`, `vat_number → tax_id`, `country_code → country`, and **drop `state`** (the modal has no state field). `buildSupplierPrefill` already guarantees `country_code` is a validated uppercase ISO-2 string. **Before implementing, check the modal's country field:** if it is a select fed by a countries list (like `PartnerForm.tsx:259–277`), only apply `country_code` when it matches a loaded option; if it's a plain text input, seed directly.

- [ ] **Step 1: Write failing test**

`src/components/organisms/AddPartnerModal/AddPartnerModal.test.tsx` — same harness pattern as Task 2 (mock `@/lib/api`'s `apiPost` + any lookups the modal makes). Tests:

```tsx
it('maps PartnerPrefill onto the modal fields when opened', async () => {
  renderModal({
    isOpen: true,
    partnerType: 'supplier',
    prefill: {
      name: 'PharmaDistrib SARL', vat_number: 'TN1234567', phone: '71 234 567',
      email: 'contact@pharmadistrib.tn', street_address: '12 Rue de Carthage',
      city: 'Tunis', state: 'Tunis', postal_code: '1000', country_code: 'TN',
    },
  })
  expect(await screen.findByLabelText(/name/i)).toHaveValue('PharmaDistrib SARL')
  expect(screen.getByLabelText(/tax/i)).toHaveValue('TN1234567')       // vat_number → tax_id
  expect(screen.getByLabelText(/address/i)).toHaveValue('12 Rue de Carthage') // street_address → address
  expect(screen.getByLabelText(/city/i)).toHaveValue('Tunis')
  expect(screen.getByLabelText(/postal/i)).toHaveValue('1000')
})

it('unchanged without prefill: all fields empty', async () => {
  renderModal({ isOpen: true, partnerType: 'supplier' })
  expect(await screen.findByLabelText(/name/i)).toHaveValue('')
})

it('submits mapped values (never seeds notes or any commercial field)', async () => {
  // fill required fields via prefill, submit, assert apiPost('/partners', payload)
  // payload contains address/tax_id keys and NO street_address/vat_number/state keys
})
```

(Again: read the modal's real label strings first and assert those.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/components/organisms/AddPartnerModal/AddPartnerModal.test.tsx`
Expected: FAIL — no `prefill` prop.

- [ ] **Step 3: Implement**

```ts
import type { PartnerPrefill } from '../../../features/partners/partnerPrefill'
// (match the file's existing relative-import style, or switch this file to '@/' if trivial)

export interface AddPartnerModalProps {
  isOpen: boolean
  onClose: () => void
  partnerType?: 'customer' | 'supplier' | undefined
  onSuccess?: (partner: Partner) => void
  /**
   * Optional seed values applied on open. Keys use the shared PartnerPrefill
   * contract; this modal maps street_address→address, vat_number→tax_id,
   * country_code→country and ignores `state` (no such field here).
   */
  prefill?: PartnerPrefill
}
```

Extend the reset-on-open effect:

```ts
useEffect(() => {
  if (isOpen) {
    reset({
      name: prefill?.name ?? '',
      type: defaultType,
      email: prefill?.email ?? '',
      phone: prefill?.phone ?? '',
      address: prefill?.street_address ?? '',
      city: prefill?.city ?? '',
      postal_code: prefill?.postal_code ?? '',
      country: prefill?.country_code ?? '',
      tax_id: prefill?.vat_number ?? '',
      notes: '',
    })
  }
}, [isOpen, prefill, defaultType, reset])
```

(Adapt the `country` line per the Step-1 country-field check.)

- [ ] **Step 4: Run test to verify it passes**

Same command. Expected: PASS.

- [ ] **Step 5: Run existing consumer tests to prove no regression**

Run: `pnpm vitest run src/features/documents/DocumentForm.test.tsx src/features/treasury/PaymentForm.test.tsx`
Expected: PASS unchanged.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/organisms/AddPartnerModal/
git commit -m "feat(partners): AddPartnerModal accepts optional PartnerPrefill seed"
```

---

### Task 4: Upload page `/purchases/scans/new` (replaces `UploadIngestionDialog`)

**Files:**
- Create: `src/features/document-ingestions/UploadScanPage.tsx`
- Create: `src/features/document-ingestions/__tests__/UploadScanPage.test.tsx`
- Modify: `src/routes/index.tsx` (lazy imports ~lines 58–59; add `scans/new` route BEFORE `scans/:id` inside the `purchases` parent, ~line 906)
- Modify: `src/features/document-ingestions/DocumentIngestionListPage.tsx` (button → `Link to="/purchases/scans/new"`, drop `uploadOpen` state ~line 39 and the dialog render ~line 144)
- Delete: `src/features/document-ingestions/UploadIngestionDialog.tsx`
- Modify: `src/features/document-ingestions/__tests__/DocumentIngestionListPage.test.tsx` (remove upload-dialog tests — they move to UploadScanPage.test.tsx; add link assertion)
- Modify: `src/locales/{en,fr,ar}/documentIngestions.json` (new `upload.*` keys)

**Interfaces:**
- Consumes: `useUploadDocumentIngestion()` from `./queries` (mutation over `uploadDocumentIngestion({ kind, file })` → `Promise<DocumentIngestionSummary>` with `.id`); `DocumentKind = 'supplier_invoice' | 'supplier_delivery_note'`.
- Produces: default-exported page component (match the feature's lazy-import export pattern used by the other two pages); route `/purchases/scans/new` gated `RequirePermission permission="document-ingestions.view"` + `SuspenseWrapper`, same as siblings at `src/routes/index.tsx:896–915`.

**Design (spec §5.1):**
- Dropzone `<div>` with `onDragOver` (preventDefault + drag-over visual state), `onDragLeave`, `onDrop` (first file of `event.dataTransfer.files`), containing a **keyboard-focusable browse `<button type="button">`** that clicks a hidden `<input type="file" accept="image/*,application/pdf">`. Dashed border (`border-dashed` + `borderColors.default`, drag-over → brand border).
- Doc-type `<select>` with the two `DocumentKind` options (port from the dialog, keys `kinds.supplier_delivery_note` / `kinds.supplier_invoice`).
- Selected-file feedback: filename + human-readable size; image → `URL.createObjectURL` thumbnail (revoke on replace/unmount); PDF → `FileText` glyph from `lucide-react` + name (**no pdf.js here** — glyph is the spec-sanctioned option for upload; pdf.js arrives in Task 6 for the review pane). Remove/replace button (`X` icon) clears the file.
- Validation (port `validate()` + `MAX_UPLOAD_BYTES = 20 * 1024 * 1024` from the dialog, then extend): missing kind/file → existing messages; wrong type (`!file.type.startsWith('image/') && file.type !== 'application/pdf'`) → NEW `upload.errors.invalidType`; too large → NEW `upload.errors.fileTooLargeDetailed` with interpolation `{{max}}`/`{{actual}}` in MB (e.g. "PDF or image up to 20 MB — this file is 24.1 MB."). Keep the `DUPLICATE_DOCUMENT` → `upload.errors.duplicate` branch on submit.
- Submit button (`actions.startScan` NEW key, or keep `actions.startExtraction`) → `await uploadMutation.mutateAsync({ kind, file })` → `navigate(\`/purchases/scans/${summary.id}\`)`.
- New i18n keys (add to en + fr + ar): `upload.title`, `upload.dropHint`, `upload.browse`, `upload.selectedFile`, `upload.remove`, `upload.errors.invalidType`, `upload.errors.fileTooLargeDetailed`, `actions.startScan`. Reuse existing `upload.fileHint`, `upload.errors.*`, `kinds.*`.

- [ ] **Step 1: Write failing tests** — `__tests__/UploadScanPage.test.tsx`, using the ListPage test harness pattern (mock `@/lib/api` `api.post`; `MemoryRouter initialEntries={['/purchases/scans/new']}` + a `Routes` stub for `/purchases/scans/:id` asserting navigation). Test cases (real English strings):
  1. selecting a file via the hidden input (fire `change` with a `new File(['x'], 'invoice.pdf', { type: 'application/pdf' })`) shows filename + PDF glyph;
  2. dropping an image file (`fireEvent.drop` with `dataTransfer: { files: [file] }`) shows a thumbnail (`<img>` present);
  3. a 25 MB file (`new File([new ArrayBuffer(25 * 1024 * 1024)], 'big.pdf', { type: 'application/pdf' })`) → specific error mentioning both limits;
  4. a `.txt` file → invalid-type error;
  5. valid kind+file submit → `api.post` called with multipart FormData containing `kind` and `file`, then navigation to `/purchases/scans/ing-9` (mock response id `ing-9`);
  6. browse button is a real `<button>` (keyboard operable) — `screen.getByRole('button', { name: /browse/i })`.
- [ ] **Step 2: Run to verify failure** — `pnpm vitest run src/features/document-ingestions/__tests__/UploadScanPage.test.tsx` → FAIL (module missing).
- [ ] **Step 3: Implement** `UploadScanPage.tsx` per the design block above (port the dialog's mutation/error/i18n wiring; this page owns `kind`, `file`, `error`, `dragOver` state; `useEffect` cleanup revokes the object URL).
- [ ] **Step 4: Wire routing + list page.** Add lazy import + `scans/new` route (before `scans/:id`); replace the list-page button with `<Link to="/purchases/scans/new">` styled as before; remove `uploadOpen` state + dialog; delete `UploadIngestionDialog.tsx`; update the list-page test (upload-dialog cases removed, assert the link's `href`).
- [ ] **Step 5: Run to verify pass** — `pnpm vitest run src/features/document-ingestions/__tests__/UploadScanPage.test.tsx src/features/document-ingestions/__tests__/DocumentIngestionListPage.test.tsx` → PASS. `grep -r "UploadIngestionDialog" src/` → no hits.
- [ ] **Step 6: Commit**

```bash
git add -A apps/web/src/features/document-ingestions apps/web/src/routes/index.tsx apps/web/src/locales
git commit -m "feat(ingestion): dedicated upload page with dropzone, file feedback and specific errors"
```

---

### Task 5: Detail-page polling + staged processing state (fixes "Extraction is not available" bug)

**Files:**
- Modify: `src/features/document-ingestions/queries.ts` (`useDocumentIngestion`, lines 51–60: add `refetchInterval`)
- Create: `src/features/document-ingestions/components/ProcessingState.tsx`
- Modify: `src/features/document-ingestions/ReviewIngestionPage.tsx` (branching lines 152–183; add ready-toast effect)
- Modify: `src/index.css` (or the app's CSS entry — wherever global styles live): `@keyframes scan-sweep`
- Modify: `src/locales/{en,fr,ar}/documentIngestions.json`
- Test: extend `src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx`

**Interfaces:**
- Consumes: `IngestionStatus`, `DocumentIngestionDetail` (has `error?: { code?; message? }`, `sourceUrl?/source_url?`); `useReExtractDocumentIngestion` (existing); `toast` from `sonner`; `sourceUrl(detail)` helper at `ReviewIngestionPage.tsx:21–23`.
- Produces: `ProcessingState` component with props `{ status: IngestionStatus; thumbnailUrl: string | null; startedAt?: string }`; `useDocumentIngestion` polls at 4000ms while status ∈ {`uploaded`, `extracting`, `committing`} and stops otherwise.

**Design (spec §5.2):**
- `queries.ts`:

```ts
refetchInterval: (query) => {
  const status = query.state.data?.status
  return status === 'uploaded' || status === 'extracting' || status === 'committing' ? 4000 : false
},
```

- `ProcessingState`: three-step stepper — `steps.uploaded` (✓ done always), `steps.extracting` (active while `extracting`, pending while `uploaded` with `steps.queued` hint), `steps.ready` (pending). Elapsed-time line (`useState` seconds + 1s `setInterval` from mount, format m:ss). Thumbnail (`thumbnailUrl` → `<img>` with an absolutely-positioned scan-line `<div>` using `motion-safe:animate-[scan-sweep_2.5s_ease-in-out_infinite]`; keyframes translate a horizontal gradient bar top↔bottom; no animation under `prefers-reduced-motion` — the `motion-safe:` variant handles this). Non-blocking copy: `processing.keepWorking` ("You can keep working — we'll notify you when it's ready.") + a `<Link to="/purchases/scans">` back-to-list. **Neutral styling — no red/error tones** (`textColors.tertiary`, `tokens.card.base`).
- `ReviewIngestionPage` branching (replace the single `!detail.extraction` block at lines 167–183):
  1. `status === 'uploaded' || status === 'extracting'` → `<ProcessingState status={detail.status} thumbnailUrl={sourceUrl(detail)} />` — **regardless of `detail.extraction`**;
  2. `status === 'failed'` → failure card: `detail.error?.message ?? t('review.extractionFailed')` (NEW key naming the problem) + the existing Re-run extraction button (reuse lines 172–180 logic);
  3. remaining `!detail.extraction` (edge: needs_review with no payload) → keep the current `review.noExtraction` card;
  4. else → review layout (unchanged here).
  So `review.noExtraction` can never render for an in-flight scan — the reported bug.
- Ready toast + auto-advance: polling makes the render flip automatically; add a transition effect:

```ts
const prevStatusRef = useRef<IngestionStatus | null>(null)
useEffect(() => {
  const prev = prevStatusRef.current
  if ((prev === 'uploaded' || prev === 'extracting') && detail?.status === 'needs_review') {
    toast.success(t('messages.readyForReview'))
  }
  prevStatusRef.current = detail?.status ?? null
}, [detail?.status, t])
```

- New i18n keys (en/fr/ar): `processing.title`, `processing.steps.uploaded`, `processing.steps.extracting`, `processing.steps.queued`, `processing.steps.ready`, `processing.keepWorking`, `processing.backToList`, `processing.elapsed` (`"{{time}} elapsed"`), `messages.readyForReview`, `review.extractionFailed`.

- [ ] **Step 1: Write failing tests** in `__tests__/ReviewIngestionPage.test.tsx` (use the existing `detailResponse(overrides)` factory; `vi.useFakeTimers()` where needed):
  1. `status: 'extracting', extraction: null` → stepper visible (`Extracting`), "keep working" copy present, and **"Extraction is not available" NOT in the document**;
  2. `status: 'uploaded'` → queued/pending extracting step;
  3. `status: 'failed', error: { message: 'Provider timeout' }` → "Provider timeout" + Re-run extraction button; clicking it calls `POST /document-ingestions/ing-1/extract`;
  4. poll flip: first `api.get` returns `extracting`, second returns `needs_review` with extraction → after refetch the review layout renders and `toast.success` was called once;
  5. `useDocumentIngestion` polling unit: with data status `extracting` the query's `refetchInterval` resolves 4000, with `needs_review` → false (test via exported helper or by asserting refetches under fake timers).
- [ ] **Step 2: Run to verify failure** — `pnpm vitest run src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx` → new tests FAIL (existing ones must still pass BEFORE the change; if any existing test asserts the old in-flight behavior, rewrite it consciously as part of Step 1 — that test cemented the bug).
- [ ] **Step 3: Implement** (queries change, `ProcessingState`, page branching, toast effect, keyframes, i18n ×3).
- [ ] **Step 4: Run to verify pass** — same command → ALL PASS.
- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/document-ingestions apps/web/src/index.css apps/web/src/locales
git commit -m "feat(ingestion): staged non-blocking processing state with detail polling and ready toast"
```

---

### Task 6: `SourceViewer` — real PDF rendering via pdf.js

**Files:**
- Modify: `apps/web/package.json` (add `pdfjs-dist`)
- Rewrite: `src/features/document-ingestions/components/SourceViewer.tsx`
- Create: `src/features/document-ingestions/components/SourceViewer.test.tsx`

**Interfaces:**
- Consumes: `sourceUrl: string | null` prop (unchanged — signed media URL; the serve route sends `X-Frame-Options: DENY`, so NEVER iframe/embed/object).
- Produces: same `SourceViewer({ sourceUrl })` export; NEW optional `onActivate?: () => void` prop (click/Enter on the rendered media — Task 7's lightbox hook; when absent, media is not interactive). Keeps the always-visible `review.openSource` new-tab link.

**Design (spec §5.3, decision: client-side pdf.js):**
- `pnpm add pdfjs-dist` (latest). Worker setup at module top:

```ts
import * as pdfjs from 'pdfjs-dist'
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
pdfjs.GlobalWorkerOptions.workerSrc = pdfWorkerUrl
```

- Content detection by bytes, not extension (signed URLs have none): `fetch(sourceUrl)` → `arrayBuffer` → first 5 bytes `=== '%PDF-'` → PDF branch; otherwise blob → `URL.createObjectURL` → `<img>` (revoke on change/unmount).
- PDF branch: `pdfjs.getDocument({ data }).promise` → `getPage(1)` → viewport scaled to container width (`containerRef.current.clientWidth / viewport.width`) → render into `<canvas ref>`. Multi-page: render page 1 + a simple `review.pageOf` ("Page {{page}} of {{total}}") prev/next pager when `numPages > 1` (cheap once the plumbing exists; each nav re-renders the canvas).
- States: `loading` (spinner/skeleton in the 640px box) → `image` | `pdf` | `error`. Error state: clear message `review.previewFailed` (NEW key, names the problem: "The preview could not be rendered.") + the open-in-new-tab link — never a silent blank box. Guard `canvas.getContext('2d')` returning null (jsdom) by entering the error state.
- Abort/staleness: track the in-flight `sourceUrl` in a ref; ignore resolutions for stale URLs (component may re-render with a fresh signed URL).

- [ ] **Step 1: Write failing tests** — `SourceViewer.test.tsx`: `vi.mock('pdfjs-dist', ...)` (hoisted: `getDocument` → `{ promise: Promise.resolve({ numPages: 1, getPage: async () => ({ getViewport: () => ({ width: 800, height: 1000 }), render: () => ({ promise: Promise.resolve() }) }) }) }`) and `vi.mock('pdfjs-dist/build/pdf.worker.min.mjs?url', () => ({ default: 'worker.js' }))`; stub `global.fetch` per test; stub `HTMLCanvasElement.prototype.getContext` to return a minimal 2d-context object. Cases:
  1. image bytes (fetch resolves PNG magic) → `<img>` rendered with an object URL;
  2. `%PDF-` bytes → `getDocument` called, `<canvas>` present, no `<img>`;
  3. fetch rejects → `review.previewFailed` text + open-in-new-tab link still present;
  4. `sourceUrl === null` → existing `review.sourceMissing` card;
  5. with `onActivate` set, clicking the media fires it.
- [ ] **Step 2: Run to verify failure** — `pnpm vitest run src/features/document-ingestions/components/SourceViewer.test.tsx` → FAIL.
- [ ] **Step 3: Implement** (dependency, rewrite per design; keep `tokens.card.base` shell, `aria-label={t('review.source')}`, i18n keys `review.previewFailed`, `review.pageOf` in en/fr/ar).
- [ ] **Step 4: Run to verify pass** — same command → PASS. Also `pnpm vitest run src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx` (page imports the viewer) → PASS.
- [ ] **Step 5: Verify the Vite build accepts the worker import** — `pnpm build` (or `pnpm exec vite build`) completes without unresolved-module errors.
- [ ] **Step 6: Commit**

```bash
git add apps/web/package.json apps/web/pnpm-lock.yaml apps/web/src/features/document-ingestions/components apps/web/src/locales
git commit -m "feat(ingestion): SourceViewer renders PDFs in-app via pdf.js with honest fallback"
```

(If the lockfile lives at the repo root, add that path instead.)

---

### Task 7: Review layout — review-primary, sticky preview, lightbox zoom

**Files:**
- Modify: `src/features/document-ingestions/ReviewIngestionPage.tsx` (grid line 255; wrap `SourceViewer`; add lightbox state)
- Modify: `src/locales/{en,fr,ar}/documentIngestions.json` (`review.zoom`, `review.zoomHint`)
- Test: extend `__tests__/ReviewIngestionPage.test.tsx`

**Interfaces:**
- Consumes: `SourceViewer` with `onActivate` (Task 6); `Modal` from `@/components/organisms/Modal` (`size="xl"` → `max-w-4xl`; statics `Modal.Content`).
- Produces: no new exports — layout + an in-page lightbox.

**Design (spec §5.3, owner-locked review-primary):** replace line 255:

```tsx
<div className="grid gap-6 xl:grid-cols-[minmax(300px,0.7fr)_minmax(0,1.4fr)]">
  <div className="xl:sticky xl:top-4 xl:self-start">
    <SourceViewer sourceUrl={sourceUrl(detail)} onActivate={() => { setLightboxOpen(true) }} />
  </div>
  <div className="space-y-4">…(existing right column unchanged)…</div>
</div>
```

DOM order keeps preview first (left in LTR); the review column is now the wider `1.4fr` track. Lightbox: `const [lightboxOpen, setLightboxOpen] = useState(false)` + `<Modal isOpen={lightboxOpen} onClose={...} size="xl" title={t('review.source')}>` containing a second `<SourceViewer sourceUrl={sourceUrl(detail)} />` (no `onActivate`) — pdf.js/img re-render at modal width gives the zoom; no new lightbox component (none exists in the app; `Modal` is the reused primitive).

- [ ] **Step 1: Write failing tests**:
  1. the grid container's `className` contains `xl:grid-cols-[minmax(300px,0.7fr)_minmax(0,1.4fr)]` and the preview wrapper contains `xl:sticky` (query via `container.querySelector`, or `data-testid="review-grid"` / `"preview-pane"` added in implementation — prefer testids over class-dumps);
  2. activating the preview (click) opens a dialog (`screen.getByRole('dialog')`) containing a second source render; closing it returns focus.
- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass** — `pnpm vitest run src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx` → PASS.
- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/document-ingestions apps/web/src/locales
git commit -m "feat(ingestion): review-primary layout with sticky preview and lightbox zoom"
```

---

### Task 8: Line product mapping — `ProductPicker` + create-with-prefill

**Files:**
- Modify: `src/features/document-ingestions/components/LineMappingTable.tsx` (replace the `<select>` at lines 68–86)
- Modify: `src/locales/{en,fr,ar}/documentIngestions.json`
- Test: create `src/features/document-ingestions/components/LineMappingTable.test.tsx` (or extend the page test if the table is only tested there — check first; a dedicated component test is preferred)

**Interfaces:**
- Consumes: `ProductPicker`, `ProductPickerValue` from `@/components/molecules/pickers` (props `value: ProductPickerValue | null`, `onChange`, `productType`, `label`, `testId`; `ProductPickerValue = { id, sku, name, sale_price?, currency?, quantity_decimals?, requires_batch_tracking? }`); `AddQuickProductModal` with `prefill` (Task 2; `onSuccess(product)` where `product.tax_rate: number`); `buildProductPrefill` (Task 1); existing props `lines: ExtractedLine[]`, `values: ReviewedLineState[]`, `productCandidates: ProductCandidate[][]`, `onChange(index, value)`.
- Produces: unchanged `LineMappingTable` external contract (parent `ReviewIngestionPage` needs no changes; commit gating still keys off `values[i].productId`).

**Design (spec §5.3 fix #3):** per line:
- **Candidate chips stay** (don't regress OCR matching): render `candidates` as small quick-pick `<button>` chips above the picker; clicking sets that product (same `vatRate` autofill rule as today: `vatRate: value.vatRate || candidate.taxRate || candidate.tax_rate || ''`).
- **`ProductPicker`** (`productType="all"`, `testId={\`line-product-picker-${index}\`}`) for full catalog search. It needs a `ProductPickerValue`, but `ReviewedLineState` stores only `productId` → keep a local `const [knownProducts, setKnownProducts] = useState<Record<string, ProductPickerValue>>({})` registry inside `LineMappingTable`; every selection path (chip, picker, created) writes into it; `value = values[i].productId ? knownProducts[values[i].productId] ?? { id: productId, sku: '', name: candidateName ?? t('review.selectedProduct') } : null`. On picker change: `onChange(index, { ...value, productId: next?.id ?? '', vatRate: value.vatRate })`.
- **"＋ New product"** button per line → `const [createForIndex, setCreateForIndex] = useState<number | null>(null)`; single `<AddQuickProductModal isOpen={createForIndex !== null} prefill={createForIndex !== null ? buildProductPrefill(lines[createForIndex]) : undefined} onClose={() => setCreateForIndex(null)} onSuccess={(product) => { /* register in knownProducts, select into line createForIndex, autofill vatRate from String(product.tax_rate) if empty, close */ }} />` rendered once after the table. **`String(product.tax_rate)` is a display/register conversion of an API number, not float math on money — acceptable; do NOT parseFloat anything.**
- New i18n keys: `review.newProduct` ("＋ New product"), `review.suggested` (chips group label), `review.selectedProduct`.

- [ ] **Step 1: Write failing tests** (mock `@/lib/api` for ProductPicker's `GET /products` and AddQuickProductModal's `POST /products`; stores + i18n per pattern):
  1. line with candidates → chips render; clicking a chip sets `onChange` with that `productId` and autofilled `vatRate`;
  2. typing in the picker fires the products search (`api.get`/`apiGet` called with `search=…`) and selecting a result calls `onChange` with its id;
  3. clicking "＋ New product" opens the modal with `name` seeded from the line's `description` and price seeded from `unitPrice` (assert input values — this proves `buildProductPrefill` wiring);
  4. modal `onSuccess` (mock `POST /products` → `{ id: 'p-9', name: 'Doliprane', tax_rate: 7, ... }`) → line now has `productId 'p-9'` and picker shows "Doliprane".
- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass** — `pnpm vitest run src/features/document-ingestions/components/LineMappingTable.test.tsx src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx` → PASS (page test may need its product-select queries updated from `<select>` to the picker — update consciously).
- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/document-ingestions apps/web/src/locales
git commit -m "feat(ingestion): real product picker per line with create-product prefilled from the scan"
```

---

### Task 9: Supplier creation in-page via `AddPartnerModal`

**Files:**
- Modify: `src/features/document-ingestions/ReviewIngestionPage.tsx` (`onCreateSupplier` at lines 266–268; add modal + created-supplier state)
- Test: extend `__tests__/ReviewIngestionPage.test.tsx`

**Interfaces:**
- Consumes: `AddPartnerModal` with `prefill` (Task 3), `partnerType="supplier"`, `onSuccess(partner: { id, name, … })`; `buildSupplierPrefill(detail.extraction?.supplier)` (existing import at line 9); `SupplierPicker` props `candidates`, `value`, `onChange`, `onCreateSupplier` (`SupplierCandidate` shape — read it in `types.ts` and construct the created-supplier entry to match, typically `{ id, name }` + optional score fields).
- Produces: no contract changes; the `/purchases/suppliers/new` navigation path is REMOVED from this page (`buildSupplierPrefill` and the full-page `PartnerForm` prefill path remain for other callers).

**Design (spec §5.3 fix #4):**

```tsx
const [addSupplierOpen, setAddSupplierOpen] = useState(false)
const [createdSuppliers, setCreatedSuppliers] = useState<SupplierCandidate[]>([])
// SupplierPicker:
candidates={[...(detail.suggestions?.supplierCandidates ?? []), ...createdSuppliers]}
onCreateSupplier={() => { setAddSupplierOpen(true) }}
// after the picker section:
<AddPartnerModal
  isOpen={addSupplierOpen}
  onClose={() => { setAddSupplierOpen(false) }}
  partnerType="supplier"
  prefill={buildSupplierPrefill(detail.extraction?.supplier)}
  onSuccess={(partner) => {
    setCreatedSuppliers((current) => [...current, toSupplierCandidate(partner)])
    setSupplierId(partner.id)
    setAddSupplierOpen(false)
  }}
/>
```

Check whether `AddPartnerModal` already calls `onClose` internally after success (read the component) — don't double-close if so. Keep the `newSupplierHint`. The `navigate('/purchases/suppliers/new', …)` line is deleted.

- [ ] **Step 1: Write failing tests**:
  1. clicking the create-supplier `+` button renders a dialog (`getByRole('dialog')`) with the name field seeded from the extraction's supplier name — and `mockNavigate` was NOT called (this is the route-change regression guard);
  2. submitting the modal (mock `POST /partners` → `{ id: 'sup-9', name: 'PharmaDistrib', type: 'supplier', … }`) selects `sup-9` in the supplier `<select>` (it appears as a chosen option);
  3. commercial fields never seeded — the modal payload contains no keys beyond `PartnerFormData` (reuses Task 3's guarantee; assert payload keys).
- [ ] **Step 2: Run to verify failure** → FAIL (currently navigates away).
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass** — `pnpm vitest run src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx` → PASS.
- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/document-ingestions
git commit -m "feat(ingestion): create supplier in-page via AddPartnerModal seeded from the scan"
```

---

### Task 10: Per-field validation cues (machine tick vs human check)

**Files:**
- Modify: `src/features/document-ingestions/components/LineMappingTable.tsx`
- Modify: `src/features/document-ingestions/ReviewIngestionPage.tsx` (pass `initialValues`)
- Modify: `src/locales/{en,fr,ar}/documentIngestions.json`
- Test: extend `LineMappingTable.test.tsx`

**Interfaces:**
- Consumes: `initialLines(detail)` in `ReviewIngestionPage.tsx` (the pure seeding function — export it if not already) producing the pristine `ReviewedLineState[]`.
- Produces: `LineMappingTable` gains prop `initialValues: ReviewedLineState[]`. Cue rule per editable field (`quantity`, `unitPrice`, `vatRate`): current === initial → machine state (grey `Check` icon from `lucide-react`, `textColors.tertiary`, `aria-label={t('review.machineValue')}`); current !== initial → human state (green — use the design-token success color, NOT a raw Tailwind green — `CheckCheck` icon, `aria-label={t('review.editedValue')}`). Keep the existing low-confidence highlighting untouched.

New i18n keys: `review.machineValue` ("Read from document"), `review.editedValue` ("Edited by you").

- [ ] **Step 1: Write failing tests**:
  1. pristine line → fields show the machine cue (query by `aria-label` "Read from document");
  2. after `userEvent` edits the quantity input → that field's cue flips to "Edited by you"; the untouched unitPrice keeps the machine cue.
- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement** (pure render-time comparison — no new state; `values[i].quantity !== initialValues[i]?.quantity` etc.).
- [ ] **Step 4: Run to verify pass** — `pnpm vitest run src/features/document-ingestions/components/LineMappingTable.test.tsx src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx` → PASS.
- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/document-ingestions apps/web/src/locales
git commit -m "feat(ingestion): machine-read vs human-edited cues on review line fields"
```

---

### Task 11 (final): Whole-branch gates, review, merge, live verify

Run by the ORCHESTRATOR (not an implementation subagent), per the session's definition of done:

- [ ] **Step 1: Gates** — from `apps/web/`: `pnpm lint`, `pnpm typecheck`, and vitest BY PATH over every test file touched in Tasks 1–10 (single command listing all paths). Kill vitest zombies afterwards.
- [ ] **Step 2: Whole-branch adversarial review** — dispatch a reviewer subagent over `git diff` from the pre-Task-1 commit to HEAD; verdict must be READY-TO-MERGE; fix findings via follow-up subagents.
- [ ] **Step 3: Live verification** — local db-per-tenant stack (recipe: memory `reference_local_db_per_tenant_demo_launch`; erp-ml needs `ANTHROPIC_API_KEY` + `EXTRACTION_SERVICE_TOKEN`): upload a **real PDF** and a **real photographed invoice** through `/purchases/scans/new`; watch the stepper live; confirm PDF renders in review; create a product and a supplier from the review via the new modals; commit the document. Playwright MCP drives the browser.
- [ ] **Step 4: Merge to LOCAL dev** (fast-forward discipline; do NOT push origin/dev). Update memory (`project_scan_to_document_spec_b`) + the handoff doc.

## Self-Review Notes (spec §-by-§ coverage)

- §5.1 upload page → Task 4. §5.2 processing → Task 5. §5.3 preview/pdf.js → Task 6; layout/sticky/lightbox → Task 7; product line → Task 8 (+1, 2); supplier modal → Task 9 (+3); confidence cues → Task 10. §5.4 shared additions → Tasks 1–3. §8 test list maps 1:1 onto the per-task Step-1 blocks. §6 polling → Task 5. §7 out-of-scope respected (no backend, no batch upload, no auto-commit).
- Type consistency spot-checks: `ProductPrefill` keys (`name/sale_price/cost/tax_rate`) match Tasks 1→2→8; `prefill` prop name shared by both modals (Tasks 2, 3 → 8, 9); `onActivate` (Task 6 → 7); `initialValues` (Task 10); status strings match the generated enum everywhere.
- Known judgment calls (flagged for the reviewer): candidate chips kept alongside `ProductPicker` (anti-regression, not in spec text); `prefill.cost` carried but unused (WAC rationale); `committing` included in detail polling (a commit in flight should refresh too); PDF pager included in Task 6 (cheap once pdf.js renders page 1).

