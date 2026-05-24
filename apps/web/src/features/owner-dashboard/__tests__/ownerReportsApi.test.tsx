import { describe, expect, it, vi } from 'vitest'
import {
  fetchCashRegisterReconciliation,
  fetchLowStockAlerts,
  fetchPaymentMethodBreakdown,
  fetchRevenueByCategory,
  fetchSalesByLocation,
  fetchTopSkus,
} from '../api/ownerReportsApi'
import { ownerReportKeys } from '../hooks/useOwnerReports'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  apiGet: mockApiGet,
}))

describe('owner reports API', () => {
  it('builds sales by location query params without double-unwrapping', async () => {
    mockApiGet.mockResolvedValueOnce([])

    await fetchSalesByLocation({
      from: '2026-05-01',
      to: '2026-05-31',
      location_ids: ['loc-1', 'loc-2'],
      company_ids: ['company-1'],
      granularity: 'day',
    })

    expect(mockApiGet).toHaveBeenCalledWith(
      '/reports/sales/by-location?from=2026-05-01&to=2026-05-31&location_ids%5B%5D=loc-1&location_ids%5B%5D=loc-2&company_ids%5B%5D=company-1&granularity=day'
    )
  })

  it('maps every owner report endpoint', async () => {
    mockApiGet.mockResolvedValue([])

    await fetchTopSkus({ from: '2026-05-01', to: '2026-05-31', limit: 20, sort_by: 'quantity' })
    await fetchRevenueByCategory({ from: '2026-05-01', to: '2026-05-31' })
    await fetchPaymentMethodBreakdown({ from: '2026-05-01', to: '2026-05-31' })
    await fetchLowStockAlerts({ threshold_pct: 100 })
    await fetchCashRegisterReconciliation({ from: '2026-05-01', to: '2026-05-31' })

    expect(mockApiGet).toHaveBeenCalledWith('/reports/sales/top-skus?from=2026-05-01&to=2026-05-31&limit=20&sort_by=quantity')
    expect(mockApiGet).toHaveBeenCalledWith('/reports/sales/revenue-by-category?from=2026-05-01&to=2026-05-31')
    expect(mockApiGet).toHaveBeenCalledWith('/reports/sales/payment-method-breakdown?from=2026-05-01&to=2026-05-31')
    expect(mockApiGet).toHaveBeenCalledWith('/reports/stock/alerts?threshold_pct=100')
    expect(mockApiGet).toHaveBeenCalledWith('/reports/cash-register/reconciliation?from=2026-05-01&to=2026-05-31')
  })

  it('exposes stable query key factories for tenantScopedKey wrapping', () => {
    expect(ownerReportKeys.salesByLocation({ from: '2026-05-01', to: '2026-05-31' })).toEqual([
      'owner-reports',
      'sales-by-location',
      { from: '2026-05-01', to: '2026-05-31' },
    ])
    expect(ownerReportKeys.stockAlerts({ threshold_pct: 100 })).toEqual(['owner-reports', 'stock-alerts', { threshold_pct: 100 }])
  })
})
