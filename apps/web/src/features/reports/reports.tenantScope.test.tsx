import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { ReportsPage } from './ReportsPage'
import { AgedReceivablesPage } from './pages/AgedReceivablesPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockReportsApi = vi.hoisted(() => ({
  fetchAgedReceivables: vi.fn(),
}))

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGet,
    },
  }
})

vi.mock('./api/reportsApi', async () => {
  const actual = await vi.importActual<typeof import('./api/reportsApi')>('./api/reportsApi')
  return {
    ...actual,
    ...mockReportsApi,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
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

const emptyListResponse = {
  data: [],
  meta: {
    total: 0,
  },
}

const agedReceivablesReport = {
  as_of_date: '2026-05-11',
  total_outstanding: '0.000',
  summary: {
    current: '0.000',
    days_1_30: '0.000',
    days_31_60: '0.000',
    days_61_90: '0.000',
    days_over_90: '0.000',
  },
  by_partner: [],
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockResolvedValue({ data: emptyListResponse })
  mockReportsApi.fetchAgedReceivables.mockResolvedValue(agedReceivablesReport)
})

afterEach(() => {
  resetTenant()
})

describe('reports tenant scope', () => {
  it('scopes report dashboard query keys (.586-.589)', () => {
    const queryClient = createTestQueryClient()

    renderWithProviders(<ReportsPage />, { queryClient })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['documents', 'tenant-A', 'company-1'],
      ['partners', 'tenant-A', 'company-1'],
      ['products', 'tenant-A', 'company-1'],
      ['payments', 'tenant-A', 'company-1'],
    ]))
  })

  it('scopes aged receivables query key (.590)', () => {
    const queryClient = createTestQueryClient()
    const today = new Date().toISOString().split('T')[0]

    renderWithProviders(<AgedReceivablesPage />, { queryClient })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['aged-receivables', today, 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch reports without tenant/company state', () => {
    resetTenant()

    renderWithProviders(<ReportsPage />)
    renderWithProviders(<AgedReceivablesPage />)

    expect(mockApiGet).not.toHaveBeenCalled()
    expect(mockReportsApi.fetchAgedReceivables).not.toHaveBeenCalled()
  })
})
