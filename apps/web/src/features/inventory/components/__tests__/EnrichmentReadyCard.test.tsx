import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { EnrichmentReadyCard } from '../EnrichmentReadyCard'
import { acceptEnrichmentResult } from '@/features/enrichment/api/enrichmentApi'
import type { EnrichmentResult } from '@/features/enrichment/types/enrichment'

vi.mock('@/features/enrichment/api/enrichmentApi', () => ({
  acceptEnrichmentResult: vi.fn(),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

const mockAcceptEnrichmentResult = vi.mocked(acceptEnrichmentResult)

describe('EnrichmentReadyCard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('accepts the ready result with blanket fields and invalidates the product query', async () => {
    mockAcceptEnrichmentResult.mockResolvedValue()
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    // Seed a tenant-suffixed product entry (the shape tenantScopedKey builds)
    // to prove the bare-prefix invalidation filter actually reaches it.
    const scopedProductKey = ['product', 'product-1', 'tenant-A', 'company-1']
    queryClient.setQueryData(scopedProductKey, { id: 'product-1' })
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')

    render(
      <EnrichmentReadyCard
        state={{ phase: 'ready', result: makeResult() }}
        canReview
        productId="product-1"
      />,
      { wrapper: wrapper(queryClient) },
    )

    expect(screen.getByText('barcodeLookup.fastPathReadyTitle')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'barcodeLookup.fastPathApplyNow' }))

    await waitFor(() => {
      expect(mockAcceptEnrichmentResult).toHaveBeenCalledWith('result-1', [
        'name',
        'brand',
        'description',
        'barcode',
      ])
    })
    // Bare literal prefix (NOT tenantScopedKey-wrapped): filters match as
    // positional prefixes, so the bare prefix hits the suffixed query key.
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['product', 'product-1'] })
    expect(queryClient.getQueryState(scopedProductKey)?.isInvalidated).toBe(true)
  })

  it('renders timeout copy without an apply button', () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    render(
      <EnrichmentReadyCard
        state={{ phase: 'timeout' }}
        canReview
        productId="product-1"
      />,
      { wrapper: wrapper(queryClient) },
    )

    expect(screen.getByText('barcodeLookup.fastPathTimeout')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'barcodeLookup.fastPathApplyNow' })).not.toBeInTheDocument()
  })
})

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function makeResult(): EnrichmentResult {
  return {
    id: 'result-1',
    product_id: 'product-1',
    product_name: 'Brake Pad',
    product_barcode: '12345',
    product_sku: 'BP-001',
    tracking_id: 'tracking-1',
    version: 1,
    origin: 'initial',
    rejection_notes: null,
    status: 'pending_review',
    enriched_data: {
      name: 'Enriched Brake Pad',
      brand: null,
      description: null,
      classification: {},
      ingredients: [],
      images: [],
      confidence_score: 95,
      enrichment_tier: 'high',
      field_confidence: null,
      enrichment_sources: null,
      assigned_barcode: null,
      assigned_barcode_type: null,
    },
    enrichment_quality: 'high',
    assigned_barcode: null,
    reviewed_at: null,
    reviewed_by: null,
    accepted_fields: null,
    rejection_reason: null,
    created_at: '2026-07-03T00:00:00Z',
  }
}
