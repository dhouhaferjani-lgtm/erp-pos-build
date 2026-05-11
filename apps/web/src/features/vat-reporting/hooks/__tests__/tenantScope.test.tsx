import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { useVatPeriodActions } from '../useVatPeriodActions'
import { useVatExportFormats, useVatReport } from '../useVatReport'

const mockGetVatReportSummary = vi.hoisted(() => vi.fn())
const mockGetVatExportFormats = vi.hoisted(() => vi.fn())
const mockGenerateVatPeriods = vi.hoisted(() => vi.fn())
const mockCloseVatPeriod = vi.hoisted(() => vi.fn())
const mockReopenVatPeriod = vi.hoisted(() => vi.fn())
const mockFileVatPeriod = vi.hoisted(() => vi.fn())

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('../../api', () => ({
  closeVatPeriod: mockCloseVatPeriod,
  fileVatPeriod: mockFileVatPeriod,
  generateVatPeriods: mockGenerateVatPeriods,
  getVatExportFormats: mockGetVatExportFormats,
  getVatReportSummary: mockGetVatReportSummary,
  reopenVatPeriod: mockReopenVatPeriod,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: { id: 'user-1', name: 'User', email: 'u@example.test', tenant_id: tenantId, roles: [], email_verified_at: null },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function useVatPeriodsProbe(queryFn: () => Promise<unknown>) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  return useQuery({ queryKey: tenantScopedKey(['vat-periods']), queryFn, enabled: tenantId !== null && companyId !== null })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockGetVatReportSummary.mockResolvedValue({ id: 'period-1' })
  mockGetVatExportFormats.mockResolvedValue(['xml'])
  mockGenerateVatPeriods.mockResolvedValue([])
  mockCloseVatPeriod.mockResolvedValue({})
  mockReopenVatPeriod.mockResolvedValue({})
  mockFileVatPeriod.mockResolvedValue({})
})

afterEach(() => {
  resetTenant()
})

describe('VAT hooks tenant scope', () => {
  it('wraps VAT report keys and gates missing tenant/company (.753-.754)', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => ({
      formats: useVatExportFormats('period-1'),
      report: useVatReport('period-1'),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.formats.isSuccess).toBe(true)
      expect(result.current.report.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['vat-report', 'period-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['vat-export-formats', 'period-1', 'tenant-A', 'company-1'])).toBeDefined()

    resetTenant()
    const gatedClient = createClient()
    renderHook(() => useVatReport('period-2'), { wrapper: wrapper(gatedClient) })
    expect(mockGetVatReportSummary).toHaveBeenCalledTimes(1)
  })

  it('bounds VAT action invalidation to active tenant (.750-.751)', async () => {
    const queryClient = createClient()
    let periodsCalls = 0
    let reportCalls = 0
    const periodsQuery = vi.fn(async () => [{ id: `periods-${++periodsCalls}` }])
    mockGetVatReportSummary.mockImplementation(async () => ({ id: `report-${++reportCalls}` }))

    queryClient.setQueryData(['vat-periods', 'tenant-B', 'company-1'], { marker: 'tenant-B-periods' })
    queryClient.setQueryData(['vat-report', 'period-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-report' })

    const { result: reads } = renderHook(() => ({
      periods: useVatPeriodsProbe(periodsQuery),
      report: useVatReport('period-1'),
    }), { wrapper: wrapper(queryClient) })
    await waitFor(() => {
      expect(reads.current.periods.isSuccess).toBe(true)
      expect(reads.current.report.isSuccess).toBe(true)
      expect(periodsCalls).toBe(1)
      expect(reportCalls).toBe(1)
    })

    const { result: actions } = renderHook(() => useVatPeriodActions(), { wrapper: wrapper(queryClient) })
    await act(async () => {
      await actions.current.closeMutation.mutateAsync({ id: 'period-1' })
    })

    await waitFor(() => {
      expect(periodsCalls).toBe(2)
      expect(reportCalls).toBe(2)
    })

    expect(queryClient.getQueryData(['vat-periods', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-periods' })
    expect(queryClient.getQueryData(['vat-report', 'period-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-report' })
  })
})
