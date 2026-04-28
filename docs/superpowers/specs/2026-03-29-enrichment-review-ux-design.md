# Enrichment Review UX — Design Spec

> **Sub-project 3 of 3:** Enrichment Review UX
> **Date:** 2026-03-29
> **Depends on:** Sub-project 1 (Backend, complete), Sub-project 2 (Frontend Lookup, complete)
> **Backend endpoints:** `GET /enrichment-results`, `GET /enrichment-results/{id}`, `POST /enrichment-results/{id}/accept`, `POST /enrichment-results/{id}/reject`

---

## 1. Scope

### What this builds

- Enrichment queue page — table of pending enrichment results with filtering and bulk actions
- Slide-over review panel — side-by-side comparison (user-entered vs enriched) with field-level checkboxes
- Bulk accept for high-confidence items
- Routing, sidebar navigation, and permission registration for the enrichment queue
- Frontend permission registration (`enrichment.view`, `enrichment.review` in `usePermissions`)

### What this does NOT build

- Notification dropdown (the bell icon is currently a static placeholder — building a full notification system is out of scope; we only prepare the navigation target for when it's built)
- Enrichment submission (done in Sub-project 2)
- Backend enrichment logic (done in Sub-project 1)
- Photo review (deferred)

---

## 2. Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Entry point | Batch queue is primary, individual review is drill-down | One UX, one page, one mental model. Even single items go through the queue. |
| Review panel | Slide-over (drawer) from right | Keeps queue visible as context, fast multi-item review flow |
| Checkbox pre-check logic | High = checked, Medium = heuristic, Low = unchecked | Reduces manual work for high-confidence enrichments |
| Bulk accept | "Accept all high-confidence" button | Initial setup mode needs fast throughput for hundreds of items |
| Notification | Deferred — no dropdown exists yet | Bell icon is a placeholder. Queue page is navigable directly from sidebar. |

---

## 3. File Map

### Enrichment Feature (new)

| Action | Path | Purpose |
|--------|------|---------|
| Create | `src/features/enrichment/` | New feature directory |
| Create | `src/features/enrichment/types/enrichment.ts` | TypeScript types for enrichment results |
| Create | `src/features/enrichment/api/enrichmentApi.ts` | Raw API functions |
| Create | `src/features/enrichment/api/enrichmentQueries.ts` | Query key factory + hooks |
| Create | `src/features/enrichment/components/EnrichmentQueueTable.tsx` | Queue table with filters and bulk actions |
| Create | `src/features/enrichment/components/EnrichmentReviewPanel.tsx` | Slide-over with side-by-side comparison |
| Create | `src/features/enrichment/components/FieldComparisonRow.tsx` | Single field row with checkbox |
| Create | `src/features/enrichment/components/QualityBadge.tsx` | Color-coded quality badge |
| Create | `src/features/enrichment/pages/EnrichmentQueuePage.tsx` | Page wrapper |
| Create | `src/features/enrichment/index.ts` | Barrel export |

### Routing, Navigation, Permissions

| Action | Path | Purpose |
|--------|------|---------|
| Modify | `src/routes/index.tsx` | Add lazy-loaded route with `RequirePermission`, `ModuleGuard`, `SuspenseWrapper` |
| Modify | `src/components/organisms/Sidebar/Sidebar.tsx` | Add "Enrichment Queue" to `inventoryChildren` |
| Modify | `src/hooks/usePermissions.ts` | Register `enrichment.view` and `enrichment.review` in `PERMISSIONS` map |
| Modify | `src/locales/en/common.json` | Add `navigation.enrichmentQueue` key for sidebar label |
| Modify | `src/locales/fr/common.json` | French sidebar label |

### i18n

| Action | Path | Purpose |
|--------|------|---------|
| Create | `src/locales/en/enrichment.json` | English translations for queue and review |
| Create | `src/locales/fr/enrichment.json` | French translations |
| Modify | `src/lib/i18n.ts` | Register `enrichment` namespace (import, resources, ns array — 3 places) |

### Design Token Rule

**ALL components in `src/features/enrichment/` must use design tokens from `@/lib/designTokens` exclusively.** No hardcoded Tailwind color classes. This applies to `EnrichmentQueueTable`, `EnrichmentReviewPanel`, `FieldComparisonRow`, `QualityBadge`, and `EnrichmentQueuePage`. Structural classes (flex, grid, rounded, padding) are fine.

---

## 4. Types

File: `src/features/enrichment/types/enrichment.ts`

The backend returns product data as **flat fields** (not nested), and `accepted_fields` as a **map** (not array). `field_confidence` values are **0-100 integers** (not 0-1 floats).

```typescript
export interface EnrichedProductData {
  name: string
  brand: string | null
  description: string | null
  classification: Record<string, unknown>
  ingredients: string[]
  images: Array<{ url: string; type?: string }>
  confidence_score: number           // 0-100
  enrichment_tier: string | null     // 'high' | 'medium' | 'low'
  field_confidence: Record<string, number> | null  // 0-100 per field
  enrichment_sources: string[] | null
  assigned_barcode: string | null
  assigned_barcode_type: string | null
}

export interface EnrichmentResult {
  id: string
  product_id: string
  product_name: string               // flat — from backend DTO
  product_barcode: string | null      // flat
  product_sku: string | null          // flat
  tracking_id: string
  status: 'pending_review' | 'accepted' | 'rejected'
  enriched_data: EnrichedProductData
  enrichment_quality: 'high' | 'medium' | 'low'
  assigned_barcode: string | null
  reviewed_at: string | null
  reviewed_by: string | null
  accepted_fields: Record<string, boolean> | null  // map, not array
  rejection_reason: string | null
  created_at: string
}

export interface EnrichmentResultsPage {
  data: EnrichmentResult[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    timestamp: string
    request_id: string
  }
}

export interface ComparisonField {
  key: string
  label: string
  userValue: string | null
  enrichedValue: string | null
  confidence: number | null  // 0-100
  checked: boolean
}
```

---

## 5. API Layer

File: `src/features/enrichment/api/enrichmentApi.ts`

```typescript
import { api } from '@/lib/api'
import type { EnrichmentResult, EnrichmentResultsPage } from '../types/enrichment'

// Paginated list — uses api.get directly (NOT apiGet) to preserve meta wrapper
export async function getEnrichmentResults(params?: {
  status?: string
  quality?: string
  page?: number
}): Promise<EnrichmentResultsPage> {
  const response = await api.get<EnrichmentResultsPage>('/enrichment-results', { params })
  return response.data
}

export async function getEnrichmentResult(id: string): Promise<EnrichmentResult> {
  const response = await api.get<{ data: EnrichmentResult }>(`/enrichment-results/${id}`)
  return response.data.data
}

export async function acceptEnrichmentResult(id: string, acceptedFields: string[]): Promise<void> {
  await api.post(`/enrichment-results/${id}/accept`, { accepted_fields: acceptedFields })
}

export async function rejectEnrichmentResult(id: string, reason?: string): Promise<void> {
  await api.post(`/enrichment-results/${id}/reject`, { reason })
}

export async function bulkAcceptEnrichmentResults(ids: string[]): Promise<void> {
  await Promise.all(ids.map(id => acceptEnrichmentResult(id, ['name', 'brand', 'description', 'barcode'])))
}
```

Note: `getEnrichmentResults` uses `api.get` (not `apiGet`) because the backend returns `{ data: [], meta: {} }` and `apiGet` would unwrap `data` and lose `meta`. This follows the documented double-unwrap pitfall for paginated endpoints.

---

## 6. Query Hooks

File: `src/features/enrichment/api/enrichmentQueries.ts`

```typescript
export const enrichmentKeys = {
  all: ['enrichment-results'] as const,
  lists: () => [...enrichmentKeys.all, 'list'] as const,
  list: (params?: { status?: string; quality?: string; page?: number }) =>
    [...enrichmentKeys.lists(), params] as const,
  details: () => [...enrichmentKeys.all, 'detail'] as const,
  detail: (id: string) => [...enrichmentKeys.details(), id] as const,
}

export function useEnrichmentResults(params?) // paginated query
export function useEnrichmentResult(id: string) // single detail, enabled when id is truthy
export function useAcceptEnrichment() // mutation, invalidates enrichmentKeys.all on success
export function useRejectEnrichment() // mutation, invalidates enrichmentKeys.all on success
export function useBulkAcceptEnrichment() // mutation for bulk accept, invalidates all on success
```

---

## 7. Components

### `EnrichmentQueuePage`

File: `src/features/enrichment/pages/EnrichmentQueuePage.tsx`

Page wrapper containing:
- Header with title, progress summary
- Quality filter dropdown
- "Accept all high-confidence" bulk action button (only visible if user has `enrichment.review` permission)
- `EnrichmentQueueTable` component
- `EnrichmentReviewPanel` slide-over (conditionally rendered when a row is selected)

State:
- `selectedResultId: string | null` — which row is open in the slide-over
- `qualityFilter: string | null` — filter dropdown value
- `selectedIds: Set<string>` — checkbox selection for bulk actions

On mount: reads `?highlight=` query param. If present, auto-opens slide-over for that item.

### `EnrichmentQueueTable`

File: `src/features/enrichment/components/EnrichmentQueueTable.tsx`

Props:
```typescript
interface EnrichmentQueueTableProps {
  results: EnrichmentResult[]
  selectedIds: Set<string>
  onToggleSelect: (id: string) => void
  onToggleSelectAll: () => void
  onRowClick: (id: string) => void
}
```

Columns: checkbox, product name (`product_name`), barcode (`product_barcode`), quality badge, assigned barcode, submitted time (relative).

### `EnrichmentReviewPanel`

File: `src/features/enrichment/components/EnrichmentReviewPanel.tsx`

Props:
```typescript
interface EnrichmentReviewPanelProps {
  resultId: string
  onClose: () => void
  onAccepted: () => void
  onRejected: () => void
}
```

Fetches result via `useEnrichmentResult(resultId)`. Renders side-by-side comparison.

**Accept button visible only if user has `enrichment.review` permission.** If user only has `enrichment.view`, the panel is read-only.

**Checkbox pre-check logic (field_confidence is 0-100):**
- `field_confidence[key] >= 80` → checked
- `field_confidence[key] >= 50` → checked for `name` and `brand`, unchecked for others
- `field_confidence[key] < 50` → unchecked
- If `field_confidence` is null: fall back to `enrichment_tier` — high = all checked, medium = name/brand checked, low = none checked

**User value source:** `enrichmentResult.product_name`, `enrichmentResult.product_barcode` (flat fields from backend DTO).

**Enriched value source:** `enrichmentResult.enriched_data.name`, `enrichmentResult.enriched_data.brand`, etc.

### `FieldComparisonRow`

File: `src/features/enrichment/components/FieldComparisonRow.tsx`

Props:
```typescript
interface FieldComparisonRowProps {
  label: string
  userValue: string | null
  enrichedValue: string | null
  checked: boolean
  onToggle: () => void
  highlight?: boolean  // true if values differ
}
```

### `QualityBadge`

File: `src/features/enrichment/components/QualityBadge.tsx`

Props: `{ quality: 'high' | 'medium' | 'low' }`

Uses `tokens.badge.success` / `tokens.badge.warning` / `tokens.badge.error` (or equivalent from design tokens).

---

## 8. Routing & Navigation

### Route (in `src/routes/index.tsx`)

Inside the inventory route group, add a lazy-loaded route following the existing pattern:

```typescript
const EnrichmentQueuePage = lazy(() => import('../features/enrichment/pages/EnrichmentQueuePage'))

// Inside <Route path="inventory"> children:
<Route
  path="enrichment-results"
  element={
    <ModuleGuard module="Inventory">
      <RequirePermission permission="enrichment.view">
        <SuspenseWrapper>
          <EnrichmentQueuePage />
        </SuspenseWrapper>
      </RequirePermission>
    </ModuleGuard>
  }
/>
```

### Sidebar (in Sidebar.tsx `inventoryChildren` array)

```typescript
{ key: 'enrichmentQueue', href: '/inventory/enrichment-results', icon: Sparkles }
```

Translation key: `common:navigation.enrichmentQueue` (added to `common.json`, NOT `enrichment.json`).

### Frontend Permissions (in `usePermissions.ts`)

Add to the `PERMISSIONS` map:
```typescript
'enrichment.view': 'enrichment.view',
'enrichment.review': 'enrichment.review',
```

---

## 9. i18n

### Namespace registration (3 places in `src/lib/i18n.ts`)

1. Import: `import enrichment from '../locales/en/enrichment.json'` (and fr)
2. Resources: add `enrichment` to the `en` and `fr` objects
3. Namespace array: add `'enrichment'` to the `ns` array

### Sidebar label (in `common.json`)

Add `"enrichmentQueue": "Enrichment Queue"` to `navigation` object in `en/common.json`.
Add `"enrichmentQueue": "File d'enrichissement"` in `fr/common.json`.

### Enrichment translations

File: `src/locales/en/enrichment.json` — full key set as described in the mockup.
File: `src/locales/fr/enrichment.json` — French equivalents.

---

## 10. Bulk Accept Flow

1. Filter results to `enrichment_quality === 'high'` AND `status === 'pending_review'`
2. Confirmation dialog: "Accept {n} high-confidence enrichments?"
3. `bulkAcceptEnrichmentResults(ids)` — accepts ALL fields for each
4. Progress toast → success toast → table refreshes

---

## 11. Reject Flow

1. User clicks "Reject" in slide-over
2. Inline text input appears for optional reason (not a modal)
3. Confirm → `POST /enrichment-results/{id}/reject`
4. Panel closes, table refreshes, toast shown

---

## 12. Error Handling

| Scenario | Behavior |
|----------|----------|
| Failed to load queue | Error state with retry button |
| Failed to load detail | Error message in panel with close button |
| Accept/reject fails | Toast error, panel stays open |
| Bulk accept partial failure | Toast with count of successes and failures |
| No pending results | Empty state illustration |

---

## 13. Testing Strategy

### Unit tests (Vitest)

- `QualityBadge` — correct color per quality level
- `FieldComparisonRow` — renders values, checkbox state, toggle, highlight
- `enrichmentApi` — correct endpoints and payloads

### Component tests

- `EnrichmentQueueTable` — rows, selection, row click
- `EnrichmentReviewPanel` — checkbox pre-check logic (0-100 scale), accept/reject

### Integration

- Full flow: load queue → click row → review → accept → queue refreshes
