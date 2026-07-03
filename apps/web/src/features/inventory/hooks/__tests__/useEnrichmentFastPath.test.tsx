import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useEnrichmentFastPath } from '../useEnrichmentFastPath'
import { refreshEnrichment } from '../../api/platformApi'
import { getEnrichmentResults } from '@/features/enrichment/api/enrichmentApi'
import type { EnrichmentResult } from '@/features/enrichment/types/enrichment'

vi.mock('../../api/platformApi', () => ({
  refreshEnrichment: vi.fn(),
}))

vi.mock('@/features/enrichment/api/enrichmentApi', () => ({
  getEnrichmentResults: vi.fn(),
}))

const mockRefreshEnrichment = vi.mocked(refreshEnrichment)
const mockGetEnrichmentResults = vi.mocked(getEnrichmentResults)

describe('useEnrichmentFastPath', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('polls on chained 3/5/8/13/21 second delays and fetches pending review result when completed', async () => {
    const result = makeResult()
    mockRefreshEnrichment.mockResolvedValueOnce({ enrichment_status: 'completed' })
    mockGetEnrichmentResults.mockResolvedValueOnce(makePage([result]))

    const { result: hook } = renderHook(() => useEnrichmentFastPath({
      productId: 'product-1',
      enabled: true,
    }))

    expect(hook.current.phase).toBe('polling')
    expect(mockRefreshEnrichment).not.toHaveBeenCalled()

    await advance(2_999)
    expect(mockRefreshEnrichment).not.toHaveBeenCalled()

    await advance(1)
    expect(mockRefreshEnrichment).toHaveBeenCalledWith('product-1')

    expect(hook.current).toEqual({ phase: 'ready', result })
    expect(mockGetEnrichmentResults).toHaveBeenCalledWith({
      product_id: 'product-1',
      status: 'pending_review',
    })
  })

  it('latches the ready card when enabled flips false after ready (product refetch must not dismiss it)', async () => {
    const result = makeResult()
    mockRefreshEnrichment.mockResolvedValueOnce({ enrichment_status: 'completed' })
    mockGetEnrichmentResults.mockResolvedValueOnce(makePage([result]))

    const { result: hook, rerender } = renderHook(
      ({ enabled }: { enabled: boolean }) => useEnrichmentFastPath({ productId: 'product-1', enabled }),
      { initialProps: { enabled: true } },
    )

    await advance(3_000)
    expect(hook.current.phase).toBe('ready')

    // The successful poll flips enrichment_status to completed server-side, so
    // any product refetch turns the gate off — the card must survive that.
    rerender({ enabled: false })
    expect(hook.current.phase).toBe('ready')
  })

  it('times out after all pending polls', async () => {
    mockRefreshEnrichment.mockResolvedValue({ enrichment_status: 'pending' })

    const { result: hook } = renderHook(() => useEnrichmentFastPath({
      productId: 'product-1',
      enabled: true,
    }))

    for (const delay of [3_000, 5_000, 8_000, 13_000, 21_000]) {
      await advance(delay)
    }

    expect(mockRefreshEnrichment).toHaveBeenCalledTimes(5)
    expect(hook.current.phase).toBe('timeout')
  })

  it('stops on terminal failed status or rejected refresh', async () => {
    mockRefreshEnrichment.mockResolvedValueOnce({ enrichment_status: 'failed' })

    const { result: hook, rerender } = renderHook(
      ({ enabled }) => useEnrichmentFastPath({ productId: 'product-1', enabled }),
      { initialProps: { enabled: true } },
    )

    await advance(3_000)
    expect(hook.current.phase).toBe('timeout')
    expect(mockRefreshEnrichment).toHaveBeenCalledTimes(1)

    mockRefreshEnrichment.mockReset()
    mockRefreshEnrichment.mockRejectedValueOnce(new Error('403'))
    rerender({ enabled: false })
    rerender({ enabled: true })

    await advance(3_000)
    expect(hook.current.phase).toBe('timeout')
    expect(mockRefreshEnrichment).toHaveBeenCalledTimes(1)
  })

  it('does not poll when disabled and cancels pending timers on unmount', async () => {
    mockRefreshEnrichment.mockResolvedValue({ enrichment_status: 'pending' })

    const disabled = renderHook(() => useEnrichmentFastPath({
      productId: 'product-1',
      enabled: false,
    }))
    await advance(50_000)
    expect(disabled.result.current.phase).toBe('idle')
    expect(mockRefreshEnrichment).not.toHaveBeenCalled()

    const enabled = renderHook(() => useEnrichmentFastPath({
      productId: 'product-1',
      enabled: true,
    }))
    enabled.unmount()
    await advance(50_000)
    expect(mockRefreshEnrichment).not.toHaveBeenCalled()
  })
})

async function advance(ms: number): Promise<void> {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms)
  })
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

function makePage(data: EnrichmentResult[]) {
  return {
    data,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: data.length,
      timestamp: '2026-07-03T00:00:00Z',
      request_id: 'req-1',
    },
  }
}
