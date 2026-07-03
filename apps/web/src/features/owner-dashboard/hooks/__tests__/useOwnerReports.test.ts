import { describe, expect, it, vi } from 'vitest'
import { buildOwnerReportRefreshOptions, useLiveSales } from '../useOwnerReports'

const useQueryMock = vi.hoisted(() => vi.fn())

vi.mock('@tanstack/react-query', () => ({
  useQuery: useQueryMock,
}))

vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (state: { user: { tenant_id: string } }) => unknown) =>
    selector({ user: { tenant_id: 'tenant-1' } }),
}))

vi.mock('@/stores/companyStore', () => ({
  useCompanyStore: (selector: (state: { currentCompanyId: string }) => unknown) =>
    selector({ currentCompanyId: 'company-1' }),
}))

vi.mock('@/lib/tenantScopedKey', () => ({
  tenantScopedKey: (key: readonly unknown[]) => ['tenant-1', ...key],
}))

describe('useOwnerReports hooks', () => {
  it('configures live sales polling every fifteen seconds without background polling', () => {
    useQueryMock.mockReturnValue({ data: undefined, isLoading: false, isError: false })

    useLiveSales(true)

    expect(useQueryMock).toHaveBeenCalledWith(expect.objectContaining({
      enabled: true,
      refetchInterval: 15000,
      refetchIntervalInBackground: false,
    }))
  })

  it('adds a one-minute refetch interval only when the date range includes today', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-03T10:15:00Z'))

    expect(buildOwnerReportRefreshOptions({ from: '2026-07-01', to: '2026-07-03' })).toEqual({ refetchInterval: 60000 })
    expect(buildOwnerReportRefreshOptions({ from: '2026-06-01', to: '2026-06-30' })).toEqual({})

    vi.useRealTimers()
  })
})
