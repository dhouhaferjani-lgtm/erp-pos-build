import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/lib/api', () => ({ api: { get: vi.fn(), post: vi.fn() } }))

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

  it('getEnrichmentResults preserves meta (uses api.get not apiGet)', async () => {
    mockGet.mockResolvedValue({ data: { data: [{ id: '1' }], meta: { total: 10 } } })
    const result = await getEnrichmentResults({ quality: 'high' })
    expect(mockGet).toHaveBeenCalledWith('/enrichment-results', { params: { quality: 'high' } })
    expect(result.meta.total).toBe(10)
  })

  it('getEnrichmentResult unwraps data wrapper', async () => {
    mockGet.mockResolvedValue({ data: { data: { id: '1', product_name: 'Test' } } })
    const result = await getEnrichmentResult('1')
    expect(result.id).toBe('1')
  })

  it('acceptEnrichmentResult posts accepted_fields', async () => {
    mockPost.mockResolvedValue({})
    await acceptEnrichmentResult('abc', ['name', 'description'])
    expect(mockPost).toHaveBeenCalledWith('/enrichment-results/abc/accept', {
      accepted_fields: ['name', 'description'],
    })
  })

  it('rejectEnrichmentResult posts reason', async () => {
    mockPost.mockResolvedValue({})
    await rejectEnrichmentResult('abc', 'Wrong data')
    expect(mockPost).toHaveBeenCalledWith('/enrichment-results/abc/reject', {
      reason: 'Wrong data',
    })
  })
})
