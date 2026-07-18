import { beforeEach, describe, expect, it, vi } from 'vitest'

import { expenseApi } from './expenseApi'

const mockGet = vi.hoisted(() => vi.fn())
vi.mock('@/lib/api', () => ({
  api: { get: mockGet },
}))

describe('expense analytics and export API', () => {
  beforeEach(() => { vi.clearAllMocks() })

  it('requests analytics with only the provided analytics filters', async () => {
    mockGet.mockResolvedValue({ data: { data: { tiles: {} } } })

    await expenseApi.getAnalytics({ status: 'posted', date_to: '2026-03-31' })

    expect(mockGet).toHaveBeenCalledWith('/expenses/analytics', {
      params: { status: 'posted', date_to: '2026-03-31' },
    })
  })

  it('uses the authenticated client with blob response mode for CSV export', async () => {
    const response = { data: new Blob(['csv']), headers: {} }
    mockGet.mockResolvedValue(response)

    const result = await expenseApi.exportCsv({ search: 'paper', date_from: '2026-01-01' })

    expect(mockGet).toHaveBeenCalledWith('/expenses/export', {
      params: { search: 'paper', date_from: '2026-01-01' },
      responseType: 'blob',
    })
    expect(result).toBe(response)
  })
})
