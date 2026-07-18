import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { InstrumentListPage } from '../InstrumentListPage'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: { ...actual.api, get: mockApiGet } }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: unknown) => (typeof fallback === 'string' ? fallback : key),
  }),
}))

function setScope() {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: 'tenant-A',
      roles: ['admin'],
      permissions: ['instruments.view'],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: 'company-A',
    companies: [{ id: 'company-A', currency: 'TND', locale: 'fr_TN' } as never],
    isLoading: false,
  })
}

function wrapper(client: QueryClient) {
  return ({ children }: { children: ReactNode }) => (
    <MemoryRouter><QueryClientProvider client={client}>{children}</QueryClientProvider></MemoryRouter>
  )
}

describe('InstrumentListPage filters', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setScope()
    mockApiGet.mockImplementation(async (url: string) => {
      if (url.startsWith('/treasury/maturing-instruments')) {
        return {
          data: {
            data: [
              { id: 'in-1', direction: 'inbound', bucket: 'overdue' },
              { id: 'in-2', direction: 'inbound', bucket: 'overdue' },
              { id: 'out-1', direction: 'outbound', bucket: 'd0_7' },
            ],
            meta: {
              buckets: {
                overdue: { count: 2, total_in: '25.000', total_out: '0.000' },
                d0_7: { count: 1, total_in: '10.000', total_out: '0.000' },
                d8_30: { count: 0, total_in: '0.000', total_out: '0.000' },
                d31_60: { count: 0, total_in: '0.000', total_out: '0.000' },
                d61_90: { count: 0, total_in: '0.000', total_out: '0.000' },
                d90_plus: { count: 0, total_in: '0.000', total_out: '0.000' },
              },
              grand_total: { count: 3, total_in: '35.000', total_out: '0.000' },
            },
          },
        }
      }

      return {
        data: {
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
        },
      }
    })
  })

  afterEach(() => {
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  })

  it('drives API params from canonical filters and keeps every query tenant scoped', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<InstrumentListPage />, { wrapper: wrapper(client) })

    expect(await screen.findByText(/2.*instruments\.buckets\.overdue/)).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('treasury:instruments.filters.kind'), 'effet')
    await userEvent.selectOptions(screen.getByLabelText('treasury:instruments.filters.direction'), 'inbound')
    await userEvent.selectOptions(screen.getByLabelText('treasury:instruments.filters.needsDetails'), 'true')
    await userEvent.type(screen.getByLabelText('treasury:instruments.filters.maturityFrom'), '2026-07-01')
    await userEvent.type(screen.getByLabelText('treasury:instruments.filters.maturityTo'), '2026-07-31')

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith(expect.stringMatching(
        /^\/payment-instruments\?.*kind=effet.*direction=inbound.*needs_details=true.*maturity_from=2026-07-01.*maturity_to=2026-07-31/,
      ))
    })

    const queryKeys = client.getQueryCache().getAll().map((query) => query.queryKey)
    expect(queryKeys.length).toBeGreaterThanOrEqual(2)
    for (const key of queryKeys) {
      expect(key.slice(-2)).toEqual(['tenant-A', 'company-A'])
    }
  })

  it('groups maturity exposure into receivables and payables sections', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<InstrumentListPage />, { wrapper: wrapper(client) })

    expect(await screen.findByRole('region', {
      name: 'treasury:instruments.schedule.receivables',
    })).toBeInTheDocument()
    expect(screen.getByRole('region', {
      name: 'treasury:instruments.schedule.payables',
    })).toBeInTheDocument()
  })
})
