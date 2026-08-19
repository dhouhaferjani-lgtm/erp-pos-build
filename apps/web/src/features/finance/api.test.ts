import { describe, expect, it, vi, beforeEach } from 'vitest'
import { apiGet } from '@/lib/api'
import { getUpcomingPayments } from './api'

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
}))

const mockApiGet = vi.mocked(apiGet)

describe('finance api', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
  })

  it('fetches upcoming payments from the C3 endpoint without double-unwrapping', async () => {
    const response: App.Modules.Accounting.Application.DTOs.Reports.UpcomingPaymentsData = {
      in: [],
      out: [],
      total_in: '0.000',
      total_out: '0.000',
      net: '0.000',
      days: 45,
      as_of_date: '2026-07-03',
      buckets_by_location: [],
    }
    mockApiGet.mockResolvedValueOnce(response)

    await expect(getUpcomingPayments(45)).resolves.toBe(response)

    // Promoted L3 locationScopedKey lane: location-aware reports request server grouping.
    expect(mockApiGet).toHaveBeenCalledWith('/reports/upcoming-payments?days=45&group_by=location')
  })
})
