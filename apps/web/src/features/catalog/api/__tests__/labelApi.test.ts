import { describe, it, expect, vi, beforeEach } from 'vitest'
import {
  getLabelFormats,
  prepareVariantLabels,
  downloadVariantLabelsPdf,
} from '../labelApi'

vi.mock('@/lib/api', () => ({
  api: {
    post: vi.fn(),
  },
  apiGet: vi.fn(),
}))

import { api, apiGet } from '@/lib/api'

const mockApi = api as unknown as { post: ReturnType<typeof vi.fn> }
const mockApiGet = apiGet as unknown as ReturnType<typeof vi.fn>

describe('labelApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('getLabelFormats', () => {
    it('GETs /labels/formats and returns the unwrapped array', async () => {
      const formats = [
        {
          key: 'avery-l7160',
          label: 'Avery L7160',
          label_width_mm: 63.5,
          label_height_mm: 38.1,
          rows: 7,
          cols: 3,
        },
      ]
      mockApiGet.mockResolvedValue(formats)

      const result = await getLabelFormats()

      expect(mockApiGet).toHaveBeenCalledWith('/labels/formats')
      expect(result).toEqual(formats)
    })
  })

  describe('prepareVariantLabels', () => {
    it('posts {items} and returns the data+meta envelope', async () => {
      mockApi.post.mockResolvedValue({
        data: {
          data: {
            ready: [
              {
                variant_id: 'v1',
                quantity: 2,
                barcode_value: 'SKU-1',
                symbology: 'CODE128',
              },
            ],
          },
          meta: { skipped: [{ variant_id: 'v2', reason: 'not_found' }] },
        },
      })

      const result = await prepareVariantLabels([
        { variant_id: 'v1', quantity: 2 },
        { variant_id: 'v2', quantity: 1 },
      ])

      expect(mockApi.post).toHaveBeenCalledWith('/labels/variants/prepare', {
        items: [
          { variant_id: 'v1', quantity: 2 },
          { variant_id: 'v2', quantity: 1 },
        ],
      })
      expect(result.data.ready).toHaveLength(1)
      expect(result.data.ready[0].barcode_value).toBe('SKU-1')
      expect(result.meta.skipped).toEqual([
        { variant_id: 'v2', reason: 'not_found' },
      ])
    })
  })

  describe('downloadVariantLabelsPdf', () => {
    it('posts {format,items,start_cell} with responseType blob and returns the blob', async () => {
      const blob = new Blob(['%PDF'], { type: 'application/pdf' })
      mockApi.post.mockResolvedValue({ data: blob })

      const result = await downloadVariantLabelsPdf({
        format: 'avery-l7160',
        items: [{ variant_id: 'v1', quantity: 2 }],
        start_cell: 3,
      })

      expect(mockApi.post).toHaveBeenCalledWith(
        '/labels/variants/pdf',
        {
          format: 'avery-l7160',
          items: [{ variant_id: 'v1', quantity: 2 }],
          start_cell: 3,
        },
        { responseType: 'blob' },
      )
      expect(result).toBe(blob)
    })
  })
})
