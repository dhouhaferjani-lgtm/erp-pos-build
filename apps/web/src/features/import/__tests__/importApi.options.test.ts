import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockApiPatch = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  api: {
    patch: mockApiPatch,
  },
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  authenticatedDownload: vi.fn(),
}))

import { importApi } from '../api/importApi'

describe('importApi options', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('patches import job options and returns the backend envelope', async () => {
    mockApiPatch.mockResolvedValue({
      data: {
        data: {
          id: 'job-1',
          options: { price_authority: 'margin' },
        },
      },
    })

    const result = await importApi.updateOptions('job-1', { price_authority: 'margin' })

    expect(mockApiPatch).toHaveBeenCalledWith('/imports/job-1/options', {
      options: { price_authority: 'margin' },
    })
    expect(result.data.options).toEqual({ price_authority: 'margin' })
  })

  it('threads the duplicate policy through the options patch', async () => {
    mockApiPatch.mockResolvedValue({ data: { data: { id: 'job-1', options: { duplicate_policy: 'skip' } } } })

    await importApi.updateOptions('job-1', { duplicate_policy: 'skip' })

    expect(mockApiPatch).toHaveBeenCalledWith('/imports/job-1/options', {
      options: { duplicate_policy: 'skip' },
    })
  })
})
