import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { InstrumentDetailPage } from './InstrumentDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet, post: mockApiPost },
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
    useNavigate: () => vi.fn(),
    useParams: () => ({ id: 'instrument-1' }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

function setTenant() {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: 'tenant-A',
      roles: [],
      permissions: ['instruments.view', 'instruments.remit'],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
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
    status: 'received',
    repository_id: 'repo-cash',
    repository: { id: 'repo-cash', code: 'CASH', name: 'Cash Desk', type: 'cash' },
    bank_name: null,
    bank_branch: null,
    bank_account: null,
    deposited_at: null,
    deposited_to_id: null,
    deposited_to: null,
    cleared_at: null,
    bounced_at: null,
    bounce_reason: null,
    created_at: '2026-05-11T09:00:00Z',
  }
}

function createClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity } },
  })
}

function renderPage() {
  const queryClient = createClient()
  return render(<InstrumentDetailPage />, {
    wrapper: ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    ),
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant()
  mockApiGet.mockImplementation((url: string) => {
    if (url === '/payment-instruments/instrument-1') {
      return Promise.resolve({ data: { data: instrumentFixture() } })
    }
    if (url === '/payment-repositories') {
      return Promise.resolve({ data: { data: [{ id: 'repo-bank', code: 'BANK', name: 'Bank Account', type: 'bank_account' }] } })
    }
    return Promise.resolve({ data: { data: [] } })
  })
  mockApiPost.mockResolvedValue({ data: {} })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('InstrumentDetailPage canonicalization', () => {
  it('renders exactly one h1 (PageHeader) with the instrument reference', async () => {
    renderPage()

    const heading = await screen.findByRole('heading', { level: 1 })
    expect(heading).toHaveTextContent('CHK-1')
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders the status via a StatusBadge pill (rounded-full span)', async () => {
    renderPage()

    await screen.findByRole('heading', { level: 1 })
    const matches = screen.getAllByText('treasury:instruments.statuses.received')
    const pill = matches.find(
      (el) => el.tagName === 'SPAN' && el.className.includes('rounded-full'),
    )
    expect(pill).toBeDefined()
  })

  it('exposes the remittance action button for a received instrument', async () => {
    renderPage()

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /treasury:instruments.remit/ })).toBeInTheDocument()
    })
  })

  it('uses issuance and payment-repository labels for outbound instruments', async () => {
    useAuthStore.setState((state) => ({
      user: state.user ? {
        ...state.user,
        permissions: [
          'instruments.remit',
          'instruments.transfer',
          'instruments.cancel',
          'instruments.clear',
          'instruments.bounce',
        ],
      } : null,
    }))
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/payment-instruments/instrument-1') {
        return Promise.resolve({
          data: {
            data: {
              ...instrumentFixture(),
              direction: 'outbound',
              kind: 'cheque',
            },
          },
        })
      }
      if (url === '/payment-repositories') {
        return Promise.resolve({ data: { data: [] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })

    renderPage()

    expect(await screen.findByText('treasury:instruments.issuedDate')).toBeInTheDocument()
    expect(screen.getByText('treasury:instruments.repository')).toBeInTheDocument()
    expect(screen.queryByText('treasury:instruments.receivedDate')).not.toBeInTheDocument()
    expect(screen.queryByText('treasury:instruments.location')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /treasury:instruments.remit/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /treasury:instruments.transfer/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /treasury:instruments.cancel/ })).not.toBeInTheDocument()
  })

  it('hides inbound clear and bounce actions for an outbound clearing instrument', async () => {
    useAuthStore.setState((state) => ({
      user: state.user ? {
        ...state.user,
        permissions: ['instruments.clear', 'instruments.bounce'],
      } : null,
    }))
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/payment-instruments/instrument-1') {
        return Promise.resolve({
          data: {
            data: {
              ...instrumentFixture(),
              direction: 'outbound',
              status: 'clearing',
            },
          },
        })
      }
      if (url === '/payment-repositories') {
        return Promise.resolve({ data: { data: [] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })

    renderPage()

    await screen.findByRole('heading', { level: 1 })
    expect(screen.queryByRole('button', { name: /treasury:instruments.clear/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /treasury:instruments.bounce/ })).not.toBeInTheDocument()
  })
})
