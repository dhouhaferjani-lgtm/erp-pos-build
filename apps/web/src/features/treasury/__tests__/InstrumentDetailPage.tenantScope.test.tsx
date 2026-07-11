import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { InstrumentDetailPage } from '../InstrumentDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockRouteId = vi.hoisted(() => ({ current: 'instrument-1' }))
const mockStatus = vi.hoisted(() => ({ current: 'received' as 'received' | 'deposited' }))
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      post: mockApiPost,
    },
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
    useNavigate: () => vi.fn(),
    useParams: () => ({ id: mockRouteId.current }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: mockTranslate,
  }),
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: tenantId,
      roles: [],
      permissions: [
        'instruments.view',
        'instruments.clear',
        'instruments.bounce',
        'instruments.remit',
        'instruments.transfer',
        'instruments.update',
      ],
      email_verified_at: null,
    },
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
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function instrumentFixture() {
  return {
    id: 'instrument-1',
    payment_method_id: 'method-1',
    payment_method: { id: 'method-1', code: 'CHK', name: 'Cheque' },
    reference: 'CHK-1',
    partner_id: 'partner-1',
    partner: { id: 'partner-1', name: 'Partner A' },
    drawer_name: null,
    amount: '100.000',
    currency: 'TND',
    received_date: '2026-05-11',
    maturity_date: '2026-05-20',
    expiry_date: null,
    status: mockStatus.current,
    repository_id: 'repo-cash',
    repository: { id: 'repo-cash', code: 'CASH', name: 'Cash Desk', type: 'cash' },
    bank_name: null,
    bank_branch: null,
    bank_account: null,
    deposited_at: mockStatus.current === 'deposited' ? '2026-05-12T10:00:00Z' : null,
    deposited_to_id: mockStatus.current === 'deposited' ? 'repo-bank' : null,
    deposited_to: mockStatus.current === 'deposited'
      ? { id: 'repo-bank', code: 'BANK', name: 'Bank Account', type: 'bank_account' }
      : null,
    cleared_at: null,
    bounced_at: null,
    bounce_reason: null,
    created_at: '2026-05-11T09:00:00Z',
  }
}

function repositoriesFixture() {
  return [
    { id: 'repo-cash', code: 'CASH', name: 'Cash Desk', type: 'cash' },
    { id: 'repo-bank', code: 'BANK', name: 'Bank Account', type: 'bank_account' },
  ]
}

function mockInstrumentResponses() {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/payment-instruments/instrument-1') return { data: { data: instrumentFixture() } }
    if (url === '/payment-repositories') return { data: { data: repositoriesFixture() } }
    return { data: { data: [] } }
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  mockRouteId.current = 'instrument-1'
  mockStatus.current = 'received'
  setTenant('tenant-A', 'company-1')
  mockInstrumentResponses()
  mockApiPost.mockResolvedValue({ data: {} })
})

afterEach(() => {
  resetTenant()
})

describe('InstrumentDetailPage tenant scope', () => {
  it('wraps instrument and repository read keys and gates missing tenant/company (.670-.671)', async () => {
    const queryClient = createClient()
    render(<InstrumentDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['instrument', 'instrument-1', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['repositories', 'tenant-A', 'company-1'])).toBeDefined()
    })

    resetTenant()
    const calls = mockApiGet.mock.calls.length
    render(<InstrumentDetailPage />, { wrapper: wrapper(createClient()) })
    expect(mockApiGet).toHaveBeenCalledTimes(calls)
  })

  it('invalidates transfer detail keys without touching tenant-B (.675)', async () => {
    const queryClient = createClient()
    queryClient.setQueryData(['instrument', 'instrument-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-instrument' })

    render(<InstrumentDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payment-instruments/instrument-1')).toHaveLength(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'treasury:instruments.transfer' }))
    await userEvent.selectOptions(await screen.findByLabelText('treasury:instruments.selectRepository'), 'repo-bank')
    await act(async () => {
      await userEvent.click(screen.getAllByRole('button', { name: 'treasury:instruments.transfer' })[1])
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payment-instruments/instrument-1')).toHaveLength(2)
    })
    expect(queryClient.getQueryData(['instrument', 'instrument-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-instrument' })
  })

  it('invalidates clear and bounce detail keys without touching tenant-B (.673-.674)', async () => {
    mockStatus.current = 'deposited'
    const queryClient = createClient()
    queryClient.setQueryData(['instrument', 'instrument-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-instrument' })

    render(<InstrumentDetailPage />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payment-instruments/instrument-1')).toHaveLength(1)
    })

    await userEvent.click(await screen.findByRole('button', { name: 'treasury:instruments.clear' }))
    await act(async () => {
      await userEvent.click(screen.getAllByRole('button', { name: 'treasury:instruments.clear' })[1])
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payment-instruments/instrument-1')).toHaveLength(2)
    })

    await userEvent.click(screen.getByRole('button', { name: 'treasury:instruments.bounce' }))
    await userEvent.selectOptions(await screen.findByLabelText('treasury:instruments.bounceRouting'), 'receivable')
    await userEvent.type(await screen.findByLabelText('treasury:instruments.bounceReason'), 'Insufficient funds')
    await act(async () => {
      await userEvent.click(screen.getAllByRole('button', { name: 'treasury:instruments.bounce' })[1])
    })

    await waitFor(() => {
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/payment-instruments/instrument-1')).toHaveLength(3)
    })
    expect(queryClient.getQueryData(['instrument', 'instrument-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-instrument' })
  })
})
