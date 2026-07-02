import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/lib/api', () => ({
  apiPost: vi.fn(),
}))

import { apiPost } from '@/lib/api'
import { lookupBarcode, refreshEnrichment, submitForEnrichment } from '../api/platformApi'

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
    it('calls correct endpoint with payload including product_id', async () => {
      mockApiPost.mockResolvedValue({ trackingId: 'track-1', status: 'submitted' })
      await submitForEnrichment({
        product_id: '11111111-1111-4111-8111-111111111111',
        barcode: '5901234123457',
        name: 'Test Product',
        brand: 'TestBrand',
      })
      expect(mockApiPost).toHaveBeenCalledWith('/platform/submit-for-enrichment', {
        product_id: '11111111-1111-4111-8111-111111111111',
        barcode: '5901234123457',
        name: 'Test Product',
        brand: 'TestBrand',
      })
    })
  })

  describe('refreshEnrichment', () => {
    it('calls the product-scoped refresh endpoint', async () => {
      mockApiPost.mockResolvedValue({ enrichment_status: 'completed' })
      await refreshEnrichment('11111111-1111-4111-8111-111111111111')
      expect(mockApiPost).toHaveBeenCalledWith(
        '/products/11111111-1111-4111-8111-111111111111/enrichment/refresh',
        {},
      )
    })
  })
})
