import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { EnrichmentReviewPanel } from '../components/EnrichmentReviewPanel'
import type { EnrichmentResult } from '../types/enrichment'

interface EnrichmentResultQuery {
  data: EnrichmentResult
  isError: boolean
  isLoading: boolean
}

const mockRejectMutate = vi.hoisted(() => vi.fn())
const mockAcceptMutate = vi.hoisted(() => vi.fn())
const mockUseEnrichmentResult = vi.hoisted(() =>
  vi.fn<(id: string) => EnrichmentResultQuery>(),
)

vi.mock('../api/enrichmentQueries', () => ({
  useAcceptEnrichment: () => ({ isPending: false, mutate: mockAcceptMutate }),
  useEnrichmentResult: (id: string) => mockUseEnrichmentResult(id),
  useRejectEnrichment: () => ({ isPending: false, mutate: mockRejectMutate }),
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: () => true }),
}))

vi.mock('sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn() },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) =>
      ({
        'quality.high': 'High',
        'review.accept': 'Accept selected fields',
        'review.accepting': 'Accepting...',
        'review.assignedBarcodeNotice': 'Syneriva barcode assigned: 1234567890123',
        'review.checkboxHint': 'Toggle any field',
        'review.close': 'Close',
        'review.confirmReject': 'Confirm reject',
        'review.curatedUpdateBadge': 'Updated by curation',
        'review.enrichedData': 'Enriched Data',
        'review.fields.name': 'Name',
        'review.reject': 'Reject',
        'review.rejectNoteLabel': 'Optional note',
        'review.rejectNotePlaceholder': 'Add details for the enrichment team',
        'review.rejectReasonLabel': 'Rejection reason',
        'review.rejectReasons.badData': 'Bad data',
        'review.rejectReasons.wrongProduct': 'Wrong product',
        'review.rejecting': 'Rejecting...',
        'review.yourData': 'Your Data',
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
      field_confidence: { name: 95 },
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

describe('EnrichmentReviewPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUseEnrichmentResult.mockReturnValue({
      data: buildResult(),
      isError: false,
      isLoading: false,
    })
  })

  it('requires a reject reason before confirming and submits optional note', async () => {
    const user = userEvent.setup()

    render(<EnrichmentReviewPanel resultId="result-1" onClose={vi.fn()} />)

    await user.click(screen.getByRole('button', { name: 'Reject' }))

    expect(screen.getByText('Rejection reason')).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Wrong product' })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Bad data' })).toBeInTheDocument()

    const confirmButton = screen.getByRole('button', { name: 'Confirm reject' })
    expect(confirmButton).toBeDisabled()

    await user.click(screen.getByRole('radio', { name: 'Bad data' }))
    await user.type(
      screen.getByRole('textbox', { name: 'Optional note' }),
      'The specifications are for another catalog item.',
    )

    expect(confirmButton).toBeEnabled()
    await user.click(confirmButton)

    expect(mockRejectMutate).toHaveBeenCalledWith(
      {
        id: 'result-1',
        notes: 'The specifications are for another catalog item.',
        reason: 'bad_data',
      },
      expect.any(Object),
    )
  })

  it('renders curated-update badge in the header only for curated results', () => {
    const { rerender } = render(<EnrichmentReviewPanel resultId="result-1" onClose={vi.fn()} />)

    expect(screen.queryByText('Updated by curation')).not.toBeInTheDocument()

    mockUseEnrichmentResult.mockReturnValue({
      data: buildResult({ origin: 'curated_update', version: 2 }),
      isError: false,
      isLoading: false,
    })

    rerender(<EnrichmentReviewPanel resultId="result-1" onClose={vi.fn()} />)

    expect(screen.getByText('Updated by curation')).toBeInTheDocument()
  })
})
