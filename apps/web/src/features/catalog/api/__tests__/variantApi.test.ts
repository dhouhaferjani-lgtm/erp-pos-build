import { describe, it, expect, vi, beforeEach } from 'vitest'
import { generateVariantMatrix } from '../variantApi'

vi.mock('@/lib/api', () => ({
  api: {
    post: vi.fn(),
  },
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPatch: vi.fn(),
  apiDelete: vi.fn(),
}))

import { api } from '@/lib/api'

const mockApi = api as unknown as { post: ReturnType<typeof vi.fn> }

describe('variantApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('generateVariantMatrix', () => {
    it('posts the axes payload and returns the data+meta envelope', async () => {
      mockApi.post.mockResolvedValue({
        data: {
          data: [],
          meta: { created_count: 2, skipped_count: 0, restored_count: 0 },
        },
      })

      const result = await generateVariantMatrix('p1', [
        { attribute_id: 'a1', value_ids: ['v1', 'v2'] },
      ])

      expect(mockApi.post).toHaveBeenCalledWith(
        '/products/p1/variants/generate-matrix',
        { axes: [{ attribute_id: 'a1', value_ids: ['v1', 'v2'] }] },
      )
      expect(result.meta.created_count).toBe(2)
      expect(result.data).toEqual([])
    })
  })
})
