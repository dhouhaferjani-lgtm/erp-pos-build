import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { EnrichmentQueueTable } from '../components/EnrichmentQueueTable'
import type { EnrichmentResult } from '../types/enrichment'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) =>
      ({
        'quality.high': 'High',
        'queue.columns.assignedBarcode': 'Assigned Barcode',
        'queue.columns.barcode': 'Barcode',
        'queue.columns.productName': 'Product',
        'queue.columns.quality': 'Quality',
        'queue.columns.submitted': 'Submitted',
        'queue.curatedUpdateBadge': 'Updated by curation',
      })[key] ?? key,
  }),
}))

function buildResult(overrides: Partial<EnrichmentResult> = {}): EnrichmentResult {
  return {
    accepted_fields: null,
    assigned_barcode: null,
    created_at: '2026-07-03T10:00:00Z',
    enriched_data: {
      assigned_barcode: null,
      assigned_barcode_type: null,
      brand: null,
      classification: {},
      confidence_score: 95,
      description: null,
      enrichment_sources: null,
      enrichment_tier: 'high',
      field_confidence: null,
      images: [],
      ingredients: [],
      name: 'Enriched brake pad',
    },
    enrichment_quality: 'high',
    id: 'result-1',
    origin: 'initial',
    product_barcode: '1234567890123',
    product_id: 'product-1',
    product_name: 'Brake pad',
    product_sku: null,
    rejection_notes: null,
    rejection_reason: null,
    reviewed_at: null,
    reviewed_by: null,
    status: 'pending_review',
    tracking_id: 'tracking-1',
    version: 1,
    ...overrides,
  }
}

function renderTable(results: EnrichmentResult[]) {
  return render(
    <EnrichmentQueueTable
      results={results}
      selectedIds={new Set()}
      onRowClick={vi.fn()}
      onToggleSelect={vi.fn()}
      onToggleSelectAll={vi.fn()}
    />,
  )
}

describe('EnrichmentQueueTable', () => {
  it('renders curated-update badge for curated rows and omits it for initial rows', () => {
    const { rerender } = renderTable([
      buildResult({ id: 'initial-result', product_name: 'Initial product' }),
      buildResult({
        id: 'curated-result',
        origin: 'curated_update',
        product_name: 'Curated product',
        version: 2,
      }),
    ])

    expect(screen.getByText('Initial product')).toBeInTheDocument()
    expect(screen.getByText('Curated product')).toBeInTheDocument()
    expect(screen.getByText('Updated by curation')).toBeInTheDocument()

    rerender(
      <EnrichmentQueueTable
        results={[buildResult({ id: 'initial-only', product_name: 'Only initial' })]}
        selectedIds={new Set()}
        onRowClick={vi.fn()}
        onToggleSelect={vi.fn()}
        onToggleSelectAll={vi.fn()}
      />,
    )

    expect(screen.queryByText('Updated by curation')).not.toBeInTheDocument()
  })
})
