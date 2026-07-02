import { describe, expect, it, vi } from 'vitest'
import { apiGet } from '@/lib/api'
import { listReconciliations, listRepositories } from './reconciliation'

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}))

const mockApiGet = vi.mocked(apiGet)

describe('treasury reconciliation API', () => {
  it('returns an empty reconciliation list from an unwrapped empty response', async () => {
    mockApiGet.mockResolvedValueOnce([])

    await expect(listReconciliations()).resolves.toEqual([])
    expect(mockApiGet).toHaveBeenCalledWith('/bank-reconciliations')
  })

  it('returns an empty repository list from an unwrapped empty response', async () => {
    mockApiGet.mockResolvedValueOnce([])

    await expect(listRepositories()).resolves.toEqual([])
    expect(mockApiGet).toHaveBeenCalledWith('/payment-repositories')
  })
})
