import { describe, it, expect, vi, beforeEach } from 'vitest'
import { countingApi } from '../countingApi'

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
  },
  apiGet: vi.fn(),
  apiPatch: vi.fn(),
  apiPost: vi.fn(),
}))

import { api } from '@/lib/api'

const mockApi = api as unknown as { get: ReturnType<typeof vi.fn> }

describe('countingApi.list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('returns the paginated envelope {data, meta} — not the bare array', async () => {
    // Real index response shape: {data: [...], meta: {...}} at the top level,
    // so axios wraps it once: response.data = {data, meta}.
    mockApi.get.mockResolvedValue({
      data: {
        data: [{ id: '019f3c39-6d21-7351-a2d9-66e8b00522b7', status: 'finalized' }],
        meta: { current_page: 1, last_page: 3, per_page: 15, total: 41 },
      },
    })

    const result = await countingApi.list({})

    expect(result.data).toHaveLength(1)
    expect(result.data[0].id).toBe('019f3c39-6d21-7351-a2d9-66e8b00522b7')
    expect(result.meta.total).toBe(41)
    expect(result.meta.last_page).toBe(3)
  })
})
