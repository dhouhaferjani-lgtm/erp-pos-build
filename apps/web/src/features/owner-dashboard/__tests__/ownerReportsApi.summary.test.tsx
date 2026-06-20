import { describe, expect, it, vi } from 'vitest'
import { fetchSalesSummary } from '../api/ownerReportsApi'
import { apiGet } from '@/lib/api'

vi.mock('@/lib/api', () => ({ apiGet: vi.fn().mockResolvedValue({ grossSales: '120.00' }) }))

describe('fetchSalesSummary', () => {
  it('requests the summary endpoint with location params', async () => {
    await fetchSalesSummary({ from: '2026-06-01', to: '2026-06-30', location_ids: ['loc-1'] })
    expect(apiGet).toHaveBeenCalledWith(expect.stringContaining('/reports/sales/summary?'))
    expect(apiGet).toHaveBeenCalledWith(expect.stringContaining('location_ids%5B%5D=loc-1'))
  })
})
