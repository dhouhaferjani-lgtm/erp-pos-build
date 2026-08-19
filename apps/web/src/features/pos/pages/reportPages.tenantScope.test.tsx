import { screen, waitFor } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { ShiftHistoryPage } from './ShiftHistoryPage/ShiftHistoryPage'
import { ZReportDetailPage } from './ZReportDetailPage/ZReportDetailPage'
import { ZReportListPage } from './ZReportListPage/ZReportListPage'
import type { ZReportItem, PaginatedZReports } from '../api/reportApi'
import type { PaginatedShifts } from '../api/shiftHistoryApi'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockReportApi = vi.hoisted(() => ({
  fetchZReport: vi.fn(),
  fetchZReports: vi.fn(),
  verifyZReportChain: vi.fn(),
  downloadZReportPdf: vi.fn(),
}))
const mockShiftHistoryApi = vi.hoisted(() => ({
  fetchShiftHistory: vi.fn(),
}))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
  }
})

vi.mock('../api/reportApi', async () => {
  const actual = await vi.importActual<typeof import('../api/reportApi')>('../api/reportApi')
  return {
    ...actual,
    ...mockReportApi,
  }
})

vi.mock('../api/shiftHistoryApi', async () => {
  const actual = await vi.importActual<typeof import('../api/shiftHistoryApi')>('../api/shiftHistoryApi')
  return {
    ...actual,
    ...mockShiftHistoryApi,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    format: (value: number | string) => String(value),
  }),
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  },
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [
      {
        id: companyId,
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function cacheKeys(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

const terminals = [{ id: 'terminal-1', code: 'TERM-1', name: 'Terminal 1' }]

const shifts: PaginatedShifts = {
  data: [],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 0,
    from: null,
    to: null,
  },
}

const zReport: ZReportItem = {
  id: 'z-1',
  z_number: 7,
  terminal_id: 'terminal-1',
  shift_id: 'shift-1',
  fiscal_hash: 'hash',
  previous_z_hash: null,
  generated_by: 'user-1',
  generated_at: '2026-05-11T10:00:00Z',
  is_first_z_report: true,
  formatted_z_number: 'Z0007',
  sales_count: 0,
  gross_sales: '0.000',
  opening_cash: '0.000',
  expected_cash: '0.000',
  actual_cash: '0.000',
  variance: '0.000',
  has_variance: false,
  report_data: {
    sales_count: 0,
    gross_sales: '0.000',
    net_sales: '0.000',
    tax_amount: '0.000',
    refunds_count: 0,
    refunds_amount: '0.000',
    voided_count: 0,
    voided_amount: '0.000',
    opening_cash: '0.000',
    expected_cash: '0.000',
    actual_cash: '0.000',
    variance: '0.000',
    vat_breakdown: [],
    payment_methods: [],
  },
}

const zReports: PaginatedZReports = {
  data: [zReport],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 1,
    from: 1,
    to: 1,
  },
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockResolvedValue(terminals)
  mockShiftHistoryApi.fetchShiftHistory.mockResolvedValue(shifts)
  mockReportApi.fetchZReport.mockResolvedValue(zReport)
  mockReportApi.fetchZReports.mockResolvedValue(zReports)
  mockReportApi.verifyZReportChain.mockResolvedValue({ is_valid: true, broken_at_z_number: null, broken_at_id: null })
  mockReportApi.downloadZReportPdf.mockResolvedValue(undefined)
})

afterEach(() => {
  resetTenant()
})

describe('POS report pages tenant scope', () => {
  it('scopes shift-history terminal and list query keys (.536-.537)', () => {
    const queryClient = createTestQueryClient()

    renderWithProviders(<ShiftHistoryPage />, { queryClient })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['pos', 'terminals', 'tenant-A', 'company-1'],
      ['pos', 'shift-history', { page: 1, per_page: 20 }, 'tenant-A', 'company-1'],
    ]))
  })

  it('scopes z-report detail query keys (.538)', () => {
    const queryClient = createTestQueryClient()

    renderWithProviders(
      <Routes>
        <Route path="/pos/z-reports/:zNumber" element={<ZReportDetailPage />} />
      </Routes>,
      {
      queryClient,
      route: '/pos/z-reports/7?terminal_id=terminal-1',
      },
    )

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['pos', 'z-report', '7', 'terminal-1', 'tenant-A', 'company-1'],
    ]))
  })

  it('scopes z-report list terminal and report query keys (.539-.540)', async () => {
    const queryClient = createTestQueryClient()

    renderWithProviders(<ZReportListPage />, { queryClient })

    await waitFor(() => {
      expect(screen.getByRole('combobox')).toBeInTheDocument()
    })

    // Promoted L3 locationScopedKey lane: Z-report lists key effective location scope.
    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['pos', 'terminals', { locScope: 'all' }, 'tenant-A', 'company-1'],
      ['pos', 'z-reports', { page: 1, per_page: 20, location_ids: [] }, { locScope: 'all' }, 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch report page data without tenant/company state', () => {
    resetTenant()

    renderWithProviders(<ShiftHistoryPage />)
    renderWithProviders(
      <Routes>
        <Route path="/pos/z-reports/:zNumber" element={<ZReportDetailPage />} />
      </Routes>,
      { route: '/pos/z-reports/7?terminal_id=terminal-1' },
    )

    expect(mockApiGet).not.toHaveBeenCalled()
    expect(mockShiftHistoryApi.fetchShiftHistory).not.toHaveBeenCalled()
    expect(mockReportApi.fetchZReport).not.toHaveBeenCalled()
    expect(mockReportApi.fetchZReports).not.toHaveBeenCalled()
  })
})
