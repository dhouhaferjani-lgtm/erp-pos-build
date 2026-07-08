import { describe, it, expect, vi, beforeEach } from 'vitest'
import { getDocumentIngestion } from '../api'

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  apiPost: vi.fn(),
}))

import { api } from '@/lib/api'

const mockApi = api as unknown as { get: ReturnType<typeof vi.fn> }

describe('getDocumentIngestion suggestions mapping', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('maps the snake_case suggestions envelope the server actually serves to the camelCase the review page reads', async () => {
    // Real /document-ingestions/{id} payload: MatchSuggestionService::toArray() emits
    // snake_case keys (supplier_candidates, product_candidates, ...). Captured live 2026-07-08.
    mockApi.get.mockResolvedValue({
      data: {
        data: {
          id: 'i-1',
          kind: 'supplier_invoice',
          status: 'needs_review',
          extraction: { header: { currency: { value: 'TND' } }, lines: [] },
          confidence_summary: {
            average_confidence: 0.9,
            low_confidence_fields: [],
            reconciliation: { consistent: true, flags: [] },
          },
          suggestions: {
            supplier_candidates: [{ id: 's-1', name: 'ACME', score: '0.9' }],
            product_candidates: [[{ id: 'p-1', name: 'Widget', requires_batch_tracking: false }]],
            receipt_line_candidates: [{ po_line_id: 'pol-1', label: 'L1' }],
            purchase_order_candidates: [],
          },
        },
      },
    })

    const detail = await getDocumentIngestion('i-1')

    // The review page reads detail.suggestions.supplierCandidates[0] in a mount effect;
    // an undefined array here is the production crash this test guards against.
    expect(detail.suggestions?.supplierCandidates[0]?.id).toBe('s-1')
    expect(detail.suggestions?.productCandidates[0]?.[0]?.id).toBe('p-1')
    expect(detail.suggestions?.receiptLineCandidates[0]?.po_line_id).toBe('pol-1')
    expect(detail.suggestions?.purchaseOrderCandidates).toEqual([])
  })
})
