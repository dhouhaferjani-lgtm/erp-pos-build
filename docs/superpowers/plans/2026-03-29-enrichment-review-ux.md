# Enrichment Review UX — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the enrichment queue page with batch review, slide-over detail panel with field-level accept/reject, and wire into routing/sidebar/permissions.

**Architecture:** New `enrichment` feature directory following existing feature patterns (`api/`, `components/`, `pages/`, `types/`). Queue table as primary entry point. Slide-over panel opens on row click for individual review. Bulk accept for high-confidence items. All components use design tokens exclusively — no hardcoded Tailwind colors.

**Tech Stack:** React 19, TypeScript strict, TanStack Query 5, design tokens from `@/lib/designTokens`, react-i18next, Vitest.

**Spec:** `docs/superpowers/specs/2026-03-29-enrichment-review-ux-design.md`

**Conventions:** All new feature files use design tokens exclusively (CLAUDE.md Rule 18). All user-facing text via `t()`. Paginated endpoints use `api.get` directly (not `apiGet`) to preserve meta wrapper. Follow `features/catalog/` directory pattern.

---

## File Map

### Enrichment Feature (new `src/features/enrichment/`)

| Action | Path | Purpose |
|--------|------|---------|
| Create | `src/features/enrichment/types/enrichment.ts` | TypeScript types |
| Create | `src/features/enrichment/api/enrichmentApi.ts` | Raw API functions |
| Create | `src/features/enrichment/api/enrichmentQueries.ts` | Query key factory + hooks |
| Create | `src/features/enrichment/components/QualityBadge.tsx` | Color-coded quality pill |
| Create | `src/features/enrichment/components/FieldComparisonRow.tsx` | Side-by-side field row |
| Create | `src/features/enrichment/components/EnrichmentReviewPanel.tsx` | Slide-over review panel |
| Create | `src/features/enrichment/components/EnrichmentQueueTable.tsx` | Queue table with selection |
| Create | `src/features/enrichment/pages/EnrichmentQueuePage.tsx` | Page wrapper |
| Create | `src/features/enrichment/index.ts` | Barrel export |

### Routing, Navigation, Permissions

| Action | Path | Purpose |
|--------|------|---------|
| Modify | `src/routes/index.tsx` | Add lazy route at ~line 1040 |
| Modify | `src/components/organisms/Sidebar/Sidebar.tsx` | Add to inventoryChildren at ~line 139 |
| Modify | `src/hooks/usePermissions.ts` | Register enrichment permissions |

### i18n

| Action | Path | Purpose |
|--------|------|---------|
| Create | `src/locales/en/enrichment.json` | English translations |
| Create | `src/locales/fr/enrichment.json` | French translations |
| Modify | `src/locales/en/common.json` | Sidebar navigation label |
| Modify | `src/locales/fr/common.json` | French sidebar label |
| Modify | `src/lib/i18n.ts` | Register enrichment namespace (3 places) |

### Tests

| Action | Path | Purpose |
|--------|------|---------|
| Create | `src/features/enrichment/__tests__/QualityBadge.test.tsx` | Badge rendering tests |
| Create | `src/features/enrichment/__tests__/FieldComparisonRow.test.tsx` | Comparison row tests |
| Create | `src/features/enrichment/__tests__/enrichmentApi.test.ts` | API function tests |

---

## Task 1: Types, API Layer, and Query Hooks

**Files:**
- Create: `apps/web/src/features/enrichment/types/enrichment.ts`
- Create: `apps/web/src/features/enrichment/api/enrichmentApi.ts`
- Create: `apps/web/src/features/enrichment/api/enrichmentQueries.ts`
- Create: `apps/web/src/features/enrichment/__tests__/enrichmentApi.test.ts`

- [ ] **Step 1: Create directories**

```bash
mkdir -p apps/web/src/features/enrichment/{types,api,components,pages,__tests__}
```

- [ ] **Step 2: Create types file**

Create `apps/web/src/features/enrichment/types/enrichment.ts`:

```typescript
export interface EnrichedProductData {
  name: string
  brand: string | null
  description: string | null
  classification: Record<string, unknown>
  ingredients: string[]
  images: Array<{ url: string; type?: string }>
  confidence_score: number
  enrichment_tier: string | null
  field_confidence: Record<string, number> | null
  enrichment_sources: string[] | null
  assigned_barcode: string | null
  assigned_barcode_type: string | null
}

export interface EnrichmentResult {
  id: string
  product_id: string
  product_name: string
  product_barcode: string | null
  product_sku: string | null
  tracking_id: string
  status: 'pending_review' | 'accepted' | 'rejected'
  enriched_data: EnrichedProductData
  enrichment_quality: 'high' | 'medium' | 'low'
  assigned_barcode: string | null
  reviewed_at: string | null
  reviewed_by: string | null
  accepted_fields: Record<string, boolean> | null
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
  confidence: number | null
  checked: boolean
}
```

- [ ] **Step 3: Create API functions**

Create `apps/web/src/features/enrichment/api/enrichmentApi.ts`:

```typescript
import { api } from '@/lib/api'
import type { EnrichmentResult, EnrichmentResultsPage } from '../types/enrichment'

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

export async function acceptEnrichmentResult(
  id: string,
  acceptedFields: string[],
): Promise<void> {
  await api.post(`/enrichment-results/${id}/accept`, { accepted_fields: acceptedFields })
}

export async function rejectEnrichmentResult(
  id: string,
  reason?: string,
): Promise<void> {
  await api.post(`/enrichment-results/${id}/reject`, { reason })
}

export async function bulkAcceptEnrichmentResults(ids: string[]): Promise<void> {
  await Promise.all(
    ids.map((id) => acceptEnrichmentResult(id, ['name', 'brand', 'description', 'barcode'])),
  )
}
```

- [ ] **Step 4: Create query hooks**

Create `apps/web/src/features/enrichment/api/enrichmentQueries.ts`:

```typescript
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getEnrichmentResults,
  getEnrichmentResult,
  acceptEnrichmentResult,
  rejectEnrichmentResult,
  bulkAcceptEnrichmentResults,
} from './enrichmentApi'

export const enrichmentKeys = {
  all: ['enrichment-results'] as const,
  lists: () => [...enrichmentKeys.all, 'list'] as const,
  list: (params?: { status?: string; quality?: string; page?: number }) =>
    [...enrichmentKeys.lists(), params] as const,
  details: () => [...enrichmentKeys.all, 'detail'] as const,
  detail: (id: string) => [...enrichmentKeys.details(), id] as const,
}

export function useEnrichmentResults(params?: {
  status?: string
  quality?: string
  page?: number
}) {
  return useQuery({
    queryKey: enrichmentKeys.list(params),
    queryFn: () => getEnrichmentResults(params),
  })
}

export function useEnrichmentResult(id: string) {
  return useQuery({
    queryKey: enrichmentKeys.detail(id),
    queryFn: () => getEnrichmentResult(id),
    enabled: id.length > 0,
  })
}

export function useAcceptEnrichment() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, acceptedFields }: { id: string; acceptedFields: string[] }) =>
      acceptEnrichmentResult(id, acceptedFields),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: enrichmentKeys.all })
    },
  })
}

export function useRejectEnrichment() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) =>
      rejectEnrichmentResult(id, reason),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: enrichmentKeys.all })
    },
  })
}

export function useBulkAcceptEnrichment() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (ids: string[]) => bulkAcceptEnrichmentResults(ids),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: enrichmentKeys.all })
    },
  })
}
```

- [ ] **Step 5: Create API test**

Create `apps/web/src/features/enrichment/__tests__/enrichmentApi.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

import { api } from '@/lib/api'
import {
  getEnrichmentResults,
  getEnrichmentResult,
  acceptEnrichmentResult,
  rejectEnrichmentResult,
} from '../api/enrichmentApi'

const mockGet = vi.mocked(api.get)
const mockPost = vi.mocked(api.post)

describe('enrichmentApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('getEnrichmentResults', () => {
    it('calls paginated endpoint and returns full response with meta', async () => {
      const mockResponse = {
        data: {
          data: [{ id: '1', product_name: 'Test' }],
          meta: { current_page: 1, total: 10 },
        },
      }
      mockGet.mockResolvedValue(mockResponse)

      const result = await getEnrichmentResults({ quality: 'high' })

      expect(mockGet).toHaveBeenCalledWith('/enrichment-results', {
        params: { quality: 'high' },
      })
      expect(result.data).toHaveLength(1)
      expect(result.meta.total).toBe(10)
    })

    it('does NOT use apiGet (preserves meta wrapper)', async () => {
      mockGet.mockResolvedValue({ data: { data: [], meta: {} } })
      await getEnrichmentResults()
      // Verifies we call api.get directly, not apiGet which would double-unwrap
      expect(mockGet).toHaveBeenCalledWith('/enrichment-results', { params: undefined })
    })
  })

  describe('getEnrichmentResult', () => {
    it('unwraps single result from data wrapper', async () => {
      mockGet.mockResolvedValue({
        data: { data: { id: '1', product_name: 'Test' } },
      })

      const result = await getEnrichmentResult('1')

      expect(result.id).toBe('1')
      expect(mockGet).toHaveBeenCalledWith('/enrichment-results/1')
    })
  })

  describe('acceptEnrichmentResult', () => {
    it('posts accepted fields to correct endpoint', async () => {
      mockPost.mockResolvedValue({})

      await acceptEnrichmentResult('abc', ['name', 'description'])

      expect(mockPost).toHaveBeenCalledWith('/enrichment-results/abc/accept', {
        accepted_fields: ['name', 'description'],
      })
    })
  })

  describe('rejectEnrichmentResult', () => {
    it('posts rejection with reason', async () => {
      mockPost.mockResolvedValue({})

      await rejectEnrichmentResult('abc', 'Data is wrong')

      expect(mockPost).toHaveBeenCalledWith('/enrichment-results/abc/reject', {
        reason: 'Data is wrong',
      })
    })
  })
})
```

- [ ] **Step 6: Run tests and typecheck**

```bash
cd apps/web && pnpm typecheck && pnpm test -- --run src/features/enrichment/__tests__/enrichmentApi.test.ts
```

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/enrichment/types/ apps/web/src/features/enrichment/api/ apps/web/src/features/enrichment/__tests__/enrichmentApi.test.ts
git commit -m "feat(enrichment): add types, API layer, and query hooks

Types matching backend flat DTO shape (product_name not nested).
API uses api.get directly for paginated endpoint (avoids double-unwrap).
Query hooks with key factory and mutation invalidation."
```

---

## Task 2: QualityBadge and FieldComparisonRow Components

**Files:**
- Create: `apps/web/src/features/enrichment/components/QualityBadge.tsx`
- Create: `apps/web/src/features/enrichment/components/FieldComparisonRow.tsx`
- Create: `apps/web/src/features/enrichment/__tests__/QualityBadge.test.tsx`
- Create: `apps/web/src/features/enrichment/__tests__/FieldComparisonRow.test.tsx`

- [ ] **Step 1: Create QualityBadge**

Create `apps/web/src/features/enrichment/components/QualityBadge.tsx`:

```tsx
import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'

interface QualityBadgeProps {
  quality: 'high' | 'medium' | 'low'
}

const qualityStyles: Record<string, string> = {
  high: tokens.badge.green,
  medium: tokens.badge.yellow,
  low: tokens.badge.red,
}

export function QualityBadge({ quality }: QualityBadgeProps) {
  const { t } = useTranslation('enrichment')

  return (
    <span className={`${tokens.badge.base} ${qualityStyles[quality]}`}>
      {t(`quality.${quality}`)}
    </span>
  )
}
```

- [ ] **Step 2: Create FieldComparisonRow**

Create `apps/web/src/features/enrichment/components/FieldComparisonRow.tsx`:

```tsx
import { textColors, colors } from '@/lib/designTokens'

interface FieldComparisonRowProps {
  label: string
  userValue: string | null
  enrichedValue: string | null
  checked: boolean
  onToggle: () => void
  highlight?: boolean
}

export function FieldComparisonRow({
  label,
  userValue,
  enrichedValue,
  checked,
  onToggle,
  highlight = false,
}: FieldComparisonRowProps) {
  return (
    <div className="grid grid-cols-2 gap-0">
      {/* User value (left) */}
      <div className="border-r border-b px-3.5 py-3">
        <div className={`mb-1 text-xs ${textColors.secondary}`}>{label}</div>
        <div className={`text-sm ${textColors.primary}`}>
          {userValue ?? <span className={textColors.disabled}>—</span>}
        </div>
      </div>

      {/* Enriched value (right) */}
      <div
        className="border-b px-3.5 py-3 flex items-start gap-2"
        style={{
          backgroundColor: highlight ? colors.success[50] : undefined,
        }}
      >
        <input
          type="checkbox"
          checked={checked}
          onChange={onToggle}
          className="mt-1 h-4 w-4 rounded"
          style={{ accentColor: colors.success[600] }}
        />
        <div className="flex-1">
          <div className={`mb-1 text-xs ${textColors.secondary}`}>{label}</div>
          <div className={`text-sm ${checked ? 'font-medium' : ''}`}
            style={{ color: checked ? colors.success[800] : undefined }}
          >
            {enrichedValue ?? <span className={textColors.disabled}>—</span>}
          </div>
        </div>
      </div>
    </div>
  )
}
```

- [ ] **Step 3: Write QualityBadge test**

Create `apps/web/src/features/enrichment/__tests__/QualityBadge.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QualityBadge } from '../components/QualityBadge'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        'quality.high': 'High',
        'quality.medium': 'Medium',
        'quality.low': 'Low',
      }
      return map[key] ?? key
    },
  }),
}))

describe('QualityBadge', () => {
  it('renders high quality with green styling', () => {
    render(<QualityBadge quality="high" />)
    const badge = screen.getByText('High')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain('bg-green')
  })

  it('renders medium quality with yellow styling', () => {
    render(<QualityBadge quality="medium" />)
    const badge = screen.getByText('Medium')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain('bg-yellow')
  })

  it('renders low quality with red styling', () => {
    render(<QualityBadge quality="low" />)
    const badge = screen.getByText('Low')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain('bg-red')
  })
})
```

- [ ] **Step 4: Write FieldComparisonRow test**

Create `apps/web/src/features/enrichment/__tests__/FieldComparisonRow.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { FieldComparisonRow } from '../components/FieldComparisonRow'

describe('FieldComparisonRow', () => {
  it('renders user and enriched values', () => {
    render(
      <FieldComparisonRow
        label="Name"
        userValue="User Name"
        enrichedValue="Enriched Name"
        checked={false}
        onToggle={() => {}}
      />,
    )
    expect(screen.getByText('User Name')).toBeInTheDocument()
    expect(screen.getByText('Enriched Name')).toBeInTheDocument()
    expect(screen.getAllByText('Name')).toHaveLength(2)
  })

  it('renders dash for null values', () => {
    render(
      <FieldComparisonRow
        label="Brand"
        userValue={null}
        enrichedValue={null}
        checked={false}
        onToggle={() => {}}
      />,
    )
    expect(screen.getAllByText('—')).toHaveLength(2)
  })

  it('calls onToggle when checkbox is clicked', () => {
    const onToggle = vi.fn()
    render(
      <FieldComparisonRow
        label="Name"
        userValue="A"
        enrichedValue="B"
        checked={false}
        onToggle={onToggle}
      />,
    )
    fireEvent.click(screen.getByRole('checkbox'))
    expect(onToggle).toHaveBeenCalledOnce()
  })

  it('checkbox reflects checked state', () => {
    render(
      <FieldComparisonRow
        label="Name"
        userValue="A"
        enrichedValue="B"
        checked={true}
        onToggle={() => {}}
      />,
    )
    expect(screen.getByRole('checkbox')).toBeChecked()
  })
})
```

- [ ] **Step 5: Run tests**

```bash
cd apps/web && pnpm test -- --run src/features/enrichment/__tests__/QualityBadge.test.tsx src/features/enrichment/__tests__/FieldComparisonRow.test.tsx
```

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/enrichment/components/QualityBadge.tsx apps/web/src/features/enrichment/components/FieldComparisonRow.tsx apps/web/src/features/enrichment/__tests__/
git commit -m "feat(enrichment): add QualityBadge and FieldComparisonRow components

Atomic components for the review UI. QualityBadge uses badge design
tokens (green/yellow/red). FieldComparisonRow renders side-by-side
field values with checkbox for accept/reject."
```

---

## Task 3: EnrichmentReviewPanel (Slide-over)

**Files:**
- Create: `apps/web/src/features/enrichment/components/EnrichmentReviewPanel.tsx`

- [ ] **Step 1: Create the slide-over panel**

Create `apps/web/src/features/enrichment/components/EnrichmentReviewPanel.tsx`:

```tsx
import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { tokens, textColors, colors, borderColors } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import { useEnrichmentResult, useAcceptEnrichment, useRejectEnrichment } from '../api/enrichmentQueries'
import { QualityBadge } from './QualityBadge'
import { FieldComparisonRow } from './FieldComparisonRow'
import type { ComparisonField } from '../types/enrichment'
import { toast } from 'sonner'

interface EnrichmentReviewPanelProps {
  resultId: string
  onClose: () => void
  onAccepted: () => void
  onRejected: () => void
}

function buildComparisonFields(
  result: NonNullable<ReturnType<typeof useEnrichmentResult>['data']>,
): ComparisonField[] {
  const { enriched_data, product_name, product_barcode } = result
  const fc = enriched_data.field_confidence

  const fields: ComparisonField[] = []

  // Name — always shown
  fields.push({
    key: 'name',
    label: 'Name',
    userValue: product_name,
    enrichedValue: enriched_data.name,
    confidence: fc?.name ?? null,
    checked: getDefaultChecked('name', fc, enriched_data.enrichment_tier),
  })

  // Brand — shown if enriched value exists
  if (enriched_data.brand) {
    fields.push({
      key: 'brand',
      label: 'Brand',
      userValue: null, // no brand field on product
      enrichedValue: enriched_data.brand,
      confidence: fc?.brand ?? null,
      checked: getDefaultChecked('brand', fc, enriched_data.enrichment_tier),
    })
  }

  // Description — shown if enriched value exists
  if (enriched_data.description) {
    fields.push({
      key: 'description',
      label: 'Description',
      userValue: null, // would need to come from product, not in flat DTO
      enrichedValue: enriched_data.description,
      confidence: fc?.description ?? null,
      checked: getDefaultChecked('description', fc, enriched_data.enrichment_tier),
    })
  }

  // Barcode — shown if assigned
  if (result.assigned_barcode) {
    fields.push({
      key: 'barcode',
      label: 'Barcode',
      userValue: product_barcode,
      enrichedValue: result.assigned_barcode,
      confidence: fc?.barcode ?? null,
      checked: getDefaultChecked('barcode', fc, enriched_data.enrichment_tier),
    })
  }

  return fields
}

function getDefaultChecked(
  key: string,
  fieldConfidence: Record<string, number> | null,
  enrichmentTier: string | null,
): boolean {
  if (fieldConfidence && key in fieldConfidence) {
    const score = fieldConfidence[key]
    if (score >= 80) return true
    if (score >= 50 && (key === 'name' || key === 'brand')) return true
    return false
  }

  // Fallback to tier-based logic
  if (enrichmentTier === 'high') return true
  if (enrichmentTier === 'medium' && (key === 'name' || key === 'brand')) return true
  return false
}

export function EnrichmentReviewPanel({
  resultId,
  onClose,
  onAccepted,
  onRejected,
}: EnrichmentReviewPanelProps) {
  const { t } = useTranslation('enrichment')
  const { hasPermission } = usePermissions()
  const canReview = hasPermission('enrichment.review')

  const { data: result, isLoading, isError } = useEnrichmentResult(resultId)
  const acceptMutation = useAcceptEnrichment()
  const rejectMutation = useRejectEnrichment()

  const [showRejectInput, setShowRejectInput] = useState(false)
  const [rejectReason, setRejectReason] = useState('')

  const comparisonFields = useMemo(
    () => (result ? buildComparisonFields(result) : []),
    [result],
  )

  const [checkedFields, setCheckedFields] = useState<Record<string, boolean>>({})

  // Initialize checked state from comparison fields when data loads
  const effectiveChecked = useMemo(() => {
    const base: Record<string, boolean> = {}
    for (const field of comparisonFields) {
      base[field.key] = checkedFields[field.key] ?? field.checked
    }
    return base
  }, [comparisonFields, checkedFields])

  const handleToggle = (key: string) => {
    setCheckedFields((prev) => ({ ...prev, [key]: !effectiveChecked[key] }))
  }

  const handleAccept = () => {
    const accepted = Object.entries(effectiveChecked)
      .filter(([, v]) => v)
      .map(([k]) => k)

    if (accepted.length === 0) return

    acceptMutation.mutate(
      { id: resultId, acceptedFields: accepted },
      {
        onSuccess: () => {
          toast.success(t('review.accepted'))
          onAccepted()
        },
        onError: () => {
          toast.error(t('review.acceptFailed', 'Failed to accept enrichment.'))
        },
      },
    )
  }

  const handleReject = () => {
    rejectMutation.mutate(
      { id: resultId, reason: rejectReason || undefined },
      {
        onSuccess: () => {
          toast.success(t('review.rejected'))
          onRejected()
        },
        onError: () => {
          toast.error(t('review.rejectFailed', 'Failed to reject enrichment.'))
        },
      },
    )
  }

  if (isLoading) {
    return (
      <div className="fixed inset-y-0 right-0 z-50 flex w-[520px] items-center justify-center border-l bg-white shadow-xl">
        <span className={textColors.secondary}>{t('common:status.loading', 'Loading...')}</span>
      </div>
    )
  }

  if (isError || !result) {
    return (
      <div className="fixed inset-y-0 right-0 z-50 flex w-[520px] flex-col border-l bg-white shadow-xl">
        <div className="flex items-center justify-between border-b px-5 py-4">
          <span className={textColors.error}>Error loading enrichment result</span>
          <button onClick={onClose} className="opacity-60 hover:opacity-100">
            <X className="h-5 w-5" />
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="fixed inset-y-0 right-0 z-50 flex w-[520px] flex-col border-l bg-white shadow-xl overflow-y-auto">
      {/* Header */}
      <div className="flex items-center justify-between border-b px-5 py-4">
        <div>
          <div className={`text-base font-bold ${textColors.primary}`}>
            {result.product_name}
          </div>
          <div className={`mt-0.5 flex items-center gap-2 text-xs ${textColors.secondary}`}>
            {result.product_barcode && (
              <span className="font-mono">{result.product_barcode}</span>
            )}
            <QualityBadge quality={result.enrichment_quality} />
          </div>
        </div>
        <button onClick={onClose} className="opacity-60 hover:opacity-100">
          <X className="h-5 w-5" />
        </button>
      </div>

      {/* Assigned barcode notice */}
      {result.assigned_barcode && (
        <div
          className="mx-5 mt-3 rounded-lg border px-3.5 py-2.5 text-sm"
          style={{
            backgroundColor: colors.primary[50],
            borderColor: colors.primary[200],
            color: colors.primary[800],
          }}
        >
          {t('review.barcodeAssigned', { barcode: result.assigned_barcode })}
        </div>
      )}

      {/* Side-by-side headers */}
      <div className="mx-5 mt-4 grid grid-cols-2 gap-0 rounded-t-lg border border-b-0 overflow-hidden">
        <div
          className="px-3.5 py-2.5 text-xs font-semibold uppercase tracking-wider"
          style={{ backgroundColor: colors.neutral[100], color: colors.neutral[600] }}
        >
          {t('review.yourData')}
        </div>
        <div
          className="px-3.5 py-2.5 text-xs font-semibold uppercase tracking-wider"
          style={{ backgroundColor: colors.success[50], color: colors.success[800] }}
        >
          {t('review.enrichedData')}
        </div>
      </div>

      {/* Field comparison rows */}
      <div className="mx-5 border rounded-b-lg overflow-hidden">
        {comparisonFields.map((field) => (
          <FieldComparisonRow
            key={field.key}
            label={field.label}
            userValue={field.userValue}
            enrichedValue={field.enrichedValue}
            checked={effectiveChecked[field.key] ?? false}
            onToggle={() => handleToggle(field.key)}
            highlight={field.userValue !== field.enrichedValue}
          />
        ))}
      </div>

      {/* Checkbox hint */}
      <div className={`mx-5 mt-2 rounded px-3 py-2 text-xs ${textColors.secondary}`}
        style={{ backgroundColor: colors.neutral[50] }}
      >
        {t('review.checkboxHint')}
      </div>

      {/* Actions */}
      {canReview && (
        <div className="mt-auto border-t px-5 py-4">
          {showRejectInput ? (
            <div className="space-y-2">
              <input
                type="text"
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                placeholder={t('review.rejectReason')}
                className={tokens.input.base}
                autoFocus
              />
              <div className="flex gap-2">
                <button
                  onClick={() => setShowRejectInput(false)}
                  className={`flex-1 rounded-lg border px-3 py-2 text-sm ${textColors.primary}`}
                >
                  {t('review.cancelReject')}
                </button>
                <button
                  onClick={handleReject}
                  disabled={rejectMutation.isPending}
                  className={`flex-1 rounded-lg px-3 py-2 text-sm text-white ${tokens.button.danger}`}
                >
                  {t('review.confirmReject')}
                </button>
              </div>
            </div>
          ) : (
            <div className="flex gap-2 justify-end">
              <button
                onClick={() => setShowRejectInput(true)}
                className={`rounded-lg border px-4 py-2 text-sm ${textColors.primary}`}
              >
                {t('review.reject')}
              </button>
              <button
                onClick={handleAccept}
                disabled={acceptMutation.isPending}
                className={`rounded-lg px-4 py-2 text-sm text-white ${tokens.button.primary}`}
              >
                {t('review.acceptSelected')}
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
```

- [ ] **Step 2: Typecheck**

```bash
cd apps/web && pnpm typecheck
```

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/features/enrichment/components/EnrichmentReviewPanel.tsx
git commit -m "feat(enrichment): add EnrichmentReviewPanel slide-over

Side-by-side comparison with field-level checkboxes. Pre-check logic
based on field_confidence (0-100 scale) with tier fallback. Inline
reject reason input. Permission-gated accept/reject buttons."
```

---

## Task 4: EnrichmentQueueTable and EnrichmentQueuePage

**Files:**
- Create: `apps/web/src/features/enrichment/components/EnrichmentQueueTable.tsx`
- Create: `apps/web/src/features/enrichment/pages/EnrichmentQueuePage.tsx`
- Create: `apps/web/src/features/enrichment/index.ts`

- [ ] **Step 1: Create EnrichmentQueueTable**

Create `apps/web/src/features/enrichment/components/EnrichmentQueueTable.tsx`:

```tsx
import { useTranslation } from 'react-i18next'
import { textColors, colors } from '@/lib/designTokens'
import { QualityBadge } from './QualityBadge'
import type { EnrichmentResult } from '../types/enrichment'

interface EnrichmentQueueTableProps {
  results: EnrichmentResult[]
  selectedIds: Set<string>
  onToggleSelect: (id: string) => void
  onToggleSelectAll: () => void
  onRowClick: (id: string) => void
}

function timeAgo(dateStr: string): string {
  const seconds = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000)
  if (seconds < 60) return 'just now'
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  return `${days}d ago`
}

export function EnrichmentQueueTable({
  results,
  selectedIds,
  onToggleSelect,
  onToggleSelectAll,
  onRowClick,
}: EnrichmentQueueTableProps) {
  const { t } = useTranslation('enrichment')
  const allSelected = results.length > 0 && results.every((r) => selectedIds.has(r.id))

  return (
    <div className="overflow-x-auto rounded-lg border" style={{ borderColor: colors.neutral[200] }}>
      <table className="w-full text-sm">
        <thead>
          <tr style={{ backgroundColor: colors.neutral[50] }}>
            <th className="w-10 px-4 py-3 text-left">
              <input
                type="checkbox"
                checked={allSelected}
                onChange={onToggleSelectAll}
                className="h-4 w-4 rounded"
              />
            </th>
            <th className={`px-4 py-3 text-left font-semibold ${textColors.primary}`}>
              {t('queue.columns.product')}
            </th>
            <th className={`px-4 py-3 text-left font-semibold ${textColors.primary}`}>
              {t('queue.columns.barcode')}
            </th>
            <th className={`px-4 py-3 text-left font-semibold ${textColors.primary}`}>
              {t('queue.columns.quality')}
            </th>
            <th className={`px-4 py-3 text-left font-semibold ${textColors.primary}`}>
              {t('queue.columns.assignedBarcode')}
            </th>
            <th className={`px-4 py-3 text-left font-semibold ${textColors.primary}`}>
              {t('queue.columns.submitted')}
            </th>
          </tr>
        </thead>
        <tbody>
          {results.map((result) => (
            <tr
              key={result.id}
              onClick={() => onRowClick(result.id)}
              className="cursor-pointer border-t transition-colors hover:bg-green-50"
              style={{ borderColor: colors.neutral[100] }}
            >
              <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
                <input
                  type="checkbox"
                  checked={selectedIds.has(result.id)}
                  onChange={() => onToggleSelect(result.id)}
                  className="h-4 w-4 rounded"
                />
              </td>
              <td className={`px-4 py-3 font-medium ${textColors.primary}`}>
                {result.product_name}
              </td>
              <td className={`px-4 py-3 font-mono ${textColors.secondary}`}>
                {result.product_barcode ?? '—'}
              </td>
              <td className="px-4 py-3">
                <QualityBadge quality={result.enrichment_quality} />
              </td>
              <td className="px-4 py-3" style={{ color: colors.success[600] }}>
                {result.assigned_barcode ?? <span className={textColors.secondary}>—</span>}
              </td>
              <td className={`px-4 py-3 ${textColors.secondary}`}>
                {timeAgo(result.created_at)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
```

- [ ] **Step 2: Create EnrichmentQueuePage**

Create `apps/web/src/features/enrichment/pages/EnrichmentQueuePage.tsx`:

```tsx
import { useState, useEffect, useMemo, useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { textColors, colors, tokens } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import { useEnrichmentResults, useBulkAcceptEnrichment } from '../api/enrichmentQueries'
import { EnrichmentQueueTable } from '../components/EnrichmentQueueTable'
import { EnrichmentReviewPanel } from '../components/EnrichmentReviewPanel'
import { toast } from 'sonner'

export function EnrichmentQueuePage() {
  const { t } = useTranslation('enrichment')
  const { hasPermission } = usePermissions()
  const canReview = hasPermission('enrichment.review')
  const [searchParams] = useSearchParams()

  const [qualityFilter, setQualityFilter] = useState<string | undefined>(undefined)
  const [selectedResultId, setSelectedResultId] = useState<string | null>(null)
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())

  const { data, isLoading, isError, refetch } = useEnrichmentResults({
    status: 'pending_review',
    quality: qualityFilter,
  })

  const bulkAccept = useBulkAcceptEnrichment()

  const results = data?.data ?? []
  const total = data?.meta?.total ?? 0

  // Count by quality
  const qualityCounts = useMemo(() => {
    const counts = { high: 0, medium: 0, low: 0 }
    for (const r of results) {
      counts[r.enrichment_quality]++
    }
    return counts
  }, [results])

  // Auto-open slide-over from notification link
  useEffect(() => {
    const highlightId = searchParams.get('highlight')
    if (highlightId) {
      setSelectedResultId(highlightId)
    }
  }, [searchParams])

  const handleToggleSelect = useCallback((id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })
  }, [])

  const handleToggleSelectAll = useCallback(() => {
    setSelectedIds((prev) => {
      if (prev.size === results.length) {
        return new Set()
      }
      return new Set(results.map((r) => r.id))
    })
  }, [results])

  const handleBulkAcceptHigh = () => {
    const highIds = results
      .filter((r) => r.enrichment_quality === 'high')
      .map((r) => r.id)

    if (highIds.length === 0) return

    if (!confirm(t('queue.bulkAcceptConfirm', { count: highIds.length }))) return

    toast.info(t('queue.bulkAcceptProgress', { count: highIds.length }))

    bulkAccept.mutate(highIds, {
      onSuccess: () => {
        toast.success(t('queue.bulkAcceptSuccess', { count: highIds.length }))
        setSelectedIds(new Set())
      },
      onError: () => {
        toast.error('Some enrichments failed to accept.')
      },
    })
  }

  const handlePanelClose = () => {
    setSelectedResultId(null)
  }

  const handleAccepted = () => {
    setSelectedResultId(null)
    void refetch()
  }

  const handleRejected = () => {
    setSelectedResultId(null)
    void refetch()
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <span className={textColors.secondary}>{t('common:status.loading', 'Loading...')}</span>
      </div>
    )
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center justify-center gap-4 py-20">
        <span className={textColors.error}>Failed to load enrichment queue</span>
        <button onClick={() => void refetch()} className={tokens.button.primary + ' rounded-lg px-4 py-2 text-sm text-white'}>
          Retry
        </button>
      </div>
    )
  }

  return (
    <div className="flex min-h-full flex-col gap-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className={`text-2xl font-bold ${textColors.primary}`}>{t('queue.title')}</h1>
          <p className={`mt-1 text-sm ${textColors.secondary}`}>
            {t('queue.summary', { total })}
            {' · '}
            {t('queue.summaryDetail', {
              high: qualityCounts.high,
              medium: qualityCounts.medium,
              low: qualityCounts.low,
            })}
          </p>
        </div>
        <div className="flex items-center gap-3">
          <select
            value={qualityFilter ?? ''}
            onChange={(e) => setQualityFilter(e.target.value || undefined)}
            className={tokens.select.base}
          >
            <option value="">{t('queue.filterAll')}</option>
            <option value="high">{t('queue.filterHigh')}</option>
            <option value="medium">{t('queue.filterMedium')}</option>
            <option value="low">{t('queue.filterLow')}</option>
          </select>
          {canReview && (
            <button
              onClick={handleBulkAcceptHigh}
              disabled={qualityCounts.high === 0 || bulkAccept.isPending}
              className={`rounded-lg px-4 py-2 text-sm text-white ${tokens.button.primary} disabled:opacity-50`}
            >
              {t('queue.bulkAcceptHigh')}
            </button>
          )}
        </div>
      </div>

      {/* Table */}
      {results.length === 0 ? (
        <div className="flex flex-col items-center justify-center rounded-lg border py-16"
          style={{ borderColor: colors.neutral[200] }}
        >
          <p className={`text-sm ${textColors.secondary}`}>{t('queue.emptyState')}</p>
        </div>
      ) : (
        <EnrichmentQueueTable
          results={results}
          selectedIds={selectedIds}
          onToggleSelect={handleToggleSelect}
          onToggleSelectAll={handleToggleSelectAll}
          onRowClick={setSelectedResultId}
        />
      )}

      {/* Slide-over panel */}
      {selectedResultId && (
        <EnrichmentReviewPanel
          resultId={selectedResultId}
          onClose={handlePanelClose}
          onAccepted={handleAccepted}
          onRejected={handleRejected}
        />
      )}
    </div>
  )
}
```

- [ ] **Step 3: Create barrel export**

Create `apps/web/src/features/enrichment/index.ts`:

```typescript
export { EnrichmentQueuePage } from './pages/EnrichmentQueuePage'
```

- [ ] **Step 4: Typecheck**

```bash
cd apps/web && pnpm typecheck
```

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/enrichment/components/EnrichmentQueueTable.tsx apps/web/src/features/enrichment/pages/EnrichmentQueuePage.tsx apps/web/src/features/enrichment/index.ts
git commit -m "feat(enrichment): add queue table and page

EnrichmentQueueTable with checkbox selection, quality filter, and bulk
accept. EnrichmentQueuePage orchestrates table + slide-over panel.
Reads ?highlight= for notification deep linking."
```

---

## Task 5: i18n, Permissions, Routing, Sidebar

**Files:**
- Create: `apps/web/src/locales/en/enrichment.json`
- Create: `apps/web/src/locales/fr/enrichment.json`
- Modify: `apps/web/src/locales/en/common.json`
- Modify: `apps/web/src/locales/fr/common.json`
- Modify: `apps/web/src/lib/i18n.ts`
- Modify: `apps/web/src/hooks/usePermissions.ts`
- Modify: `apps/web/src/routes/index.tsx`
- Modify: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`

- [ ] **Step 1: Create English translations**

Create `apps/web/src/locales/en/enrichment.json`:

```json
{
  "queue": {
    "title": "Enrichment Queue",
    "summary": "{{total}} items pending review",
    "summaryDetail": "{{high}} high · {{medium}} medium · {{low}} low",
    "filterAll": "All qualities",
    "filterHigh": "High",
    "filterMedium": "Medium",
    "filterLow": "Low",
    "bulkAcceptHigh": "Accept all high-confidence",
    "bulkAcceptConfirm": "Accept {{count}} high-confidence enrichments? All fields will be merged.",
    "bulkAcceptProgress": "Accepting {{count}} enrichments...",
    "bulkAcceptSuccess": "{{count}} enrichments accepted.",
    "emptyState": "No enrichment results to review.",
    "columns": {
      "product": "Product",
      "barcode": "Barcode",
      "quality": "Quality",
      "assignedBarcode": "Assigned Barcode",
      "submitted": "Submitted"
    }
  },
  "review": {
    "yourData": "Your Data",
    "enrichedData": "Enriched Data",
    "acceptSelected": "Accept selected fields",
    "reject": "Reject",
    "rejectReason": "Reason (optional)",
    "confirmReject": "Confirm reject",
    "cancelReject": "Cancel",
    "accepted": "Enrichment accepted.",
    "rejected": "Enrichment rejected.",
    "acceptFailed": "Failed to accept enrichment. Please try again.",
    "rejectFailed": "Failed to reject enrichment. Please try again.",
    "barcodeAssigned": "Syneriva barcode assigned: {{barcode}}",
    "checkboxHint": "✓ checked = high confidence · ☐ unchecked = low confidence · Toggle any field"
  },
  "quality": {
    "high": "High",
    "medium": "Medium",
    "low": "Low"
  }
}
```

- [ ] **Step 2: Create French translations**

Create `apps/web/src/locales/fr/enrichment.json`:

```json
{
  "queue": {
    "title": "File d'enrichissement",
    "summary": "{{total}} éléments en attente de révision",
    "summaryDetail": "{{high}} haute · {{medium}} moyenne · {{low}} basse",
    "filterAll": "Toutes les qualités",
    "filterHigh": "Haute",
    "filterMedium": "Moyenne",
    "filterLow": "Basse",
    "bulkAcceptHigh": "Accepter tous (haute confiance)",
    "bulkAcceptConfirm": "Accepter {{count}} enrichissements haute confiance ? Tous les champs seront fusionnés.",
    "bulkAcceptProgress": "Acceptation de {{count}} enrichissements...",
    "bulkAcceptSuccess": "{{count}} enrichissements acceptés.",
    "emptyState": "Aucun résultat d'enrichissement à réviser.",
    "columns": {
      "product": "Produit",
      "barcode": "Code-barres",
      "quality": "Qualité",
      "assignedBarcode": "Code-barres attribué",
      "submitted": "Soumis"
    }
  },
  "review": {
    "yourData": "Vos données",
    "enrichedData": "Données enrichies",
    "acceptSelected": "Accepter les champs sélectionnés",
    "reject": "Rejeter",
    "rejectReason": "Raison (optionnel)",
    "confirmReject": "Confirmer le rejet",
    "cancelReject": "Annuler",
    "accepted": "Enrichissement accepté.",
    "rejected": "Enrichissement rejeté.",
    "acceptFailed": "Échec de l'acceptation. Veuillez réessayer.",
    "rejectFailed": "Échec du rejet. Veuillez réessayer.",
    "barcodeAssigned": "Code-barres Syneriva attribué : {{barcode}}",
    "checkboxHint": "✓ coché = haute confiance · ☐ décoché = basse confiance · Basculer n'importe quel champ"
  },
  "quality": {
    "high": "Haute",
    "medium": "Moyenne",
    "low": "Basse"
  }
}
```

- [ ] **Step 3: Add sidebar label to common.json**

In `apps/web/src/locales/en/common.json`, find the `"navigation"` section and add:
```json
"enrichmentQueue": "Enrichment Queue"
```

In `apps/web/src/locales/fr/common.json`, add:
```json
"enrichmentQueue": "File d'enrichissement"
```

- [ ] **Step 4: Register enrichment namespace in i18n.ts**

In `apps/web/src/lib/i18n.ts`, make 3 changes:

1. Add imports (after the last locale import, around line 34):
```typescript
import enEnrichment from '../locales/en/enrichment.json'
import frEnrichment from '../locales/fr/enrichment.json'
```

2. Add to `en` resources object (after `'smart-prompts': enSmartPrompts,`):
```typescript
enrichment: enEnrichment,
```

3. Add to `fr` resources object (after the corresponding fr entry):
```typescript
enrichment: frEnrichment,
```

4. Add `'enrichment'` to the `ns` array (line 178).

5. Add to `ar` resources (English fallback):
```typescript
enrichment: enEnrichment,
```

- [ ] **Step 5: Register permissions in usePermissions.ts**

In `apps/web/src/hooks/usePermissions.ts`:

Add to `PERMISSIONS` map (around line 106):
```typescript
'enrichment.view': ['admin', 'manager'],
'enrichment.review': ['admin', 'manager'],
```

Add to `MODULE_PERMISSIONS` map (around line 133):
```typescript
enrichment: ['enrichment.view'],
```

- [ ] **Step 6: Add route to routes/index.tsx**

Add lazy import (around line 99, after other inventory imports):
```typescript
const EnrichmentQueuePage = lazy(() => import('../features/enrichment/pages/EnrichmentQueuePage').then((m) => ({ default: m.EnrichmentQueuePage })))
```

Inside the `<Route path="inventory">` group (before the closing `</Route>` around line 1040), add:
```tsx
<Route
  path="enrichment-results"
  element={
    <RequirePermission moduleKey="inventory">
      <SuspenseWrapper>
        <EnrichmentQueuePage />
      </SuspenseWrapper>
    </RequirePermission>
  }
/>
```

- [ ] **Step 7: Add sidebar item**

In `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`, add to `inventoryChildren` array (after the `counting` entry, before `priceLists`):

```typescript
{ key: 'enrichmentQueue', href: '/inventory/enrichment-results', icon: Sparkles },
```

Add `Sparkles` to the lucide-react import at the top of the file.

- [ ] **Step 8: Typecheck**

```bash
cd apps/web && pnpm typecheck
```

- [ ] **Step 9: Commit**

```bash
git add apps/web/src/locales/ apps/web/src/lib/i18n.ts apps/web/src/hooks/usePermissions.ts apps/web/src/routes/index.tsx apps/web/src/components/organisms/Sidebar/Sidebar.tsx
git commit -m "feat(enrichment): add routing, sidebar, permissions, and i18n

Lazy-loaded route at /inventory/enrichment-results with ModuleGuard.
Sidebar item with Sparkles icon. enrichment.view/review permissions
registered. English and French translations for queue and review UI."
```

---

## Task 6: Final Verification

- [ ] **Step 1: Run full typecheck**

```bash
cd apps/web && pnpm typecheck
```

- [ ] **Step 2: Run ESLint**

```bash
cd apps/web && pnpm lint
```

- [ ] **Step 3: Run all enrichment tests**

```bash
cd apps/web && pnpm test -- --run src/features/enrichment/__tests__/
```

- [ ] **Step 4: Verify route exists**

```bash
# Check the route is registered by searching for enrichment-results in the route file
grep -n "enrichment-results" apps/web/src/routes/index.tsx
```

- [ ] **Step 5: Commit any fixes**

```bash
git add -A
git commit -m "chore: fix lint and typecheck issues in enrichment feature"
```
