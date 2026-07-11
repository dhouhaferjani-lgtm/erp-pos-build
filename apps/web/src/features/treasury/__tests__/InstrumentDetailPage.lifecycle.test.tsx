import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { InstrumentDetailPage } from '../InstrumentDetailPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const permissionState = vi.hoisted(() => ({ allowed: new Set<string>() }))
const instrumentState = vi.hoisted(() => ({ status: 'deposited' }))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: { ...actual.api, get: mockApiGet, post: mockApiPost } }
})

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: (permission: string) => permissionState.allowed.has(permission) }),
}))

vi.mock('../hooks/useInstrumentEvents', () => ({
  useInstrumentEvents: () => ({
    data: [
      {
        id: 'event-1',
        event_type: 'remitted',
        from_status: 'received',
        to_status: 'deposited',
        occurred_at: '2026-07-10T09:30:00Z',
        payload: { reason: null },
      },
    ],
    isLoading: false,
    error: null,
  }),
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children, to }: { children: ReactNode; to: string }) => <a href={to}>{children}</a>,
    useNavigate: () => vi.fn(),
    useParams: () => ({ id: 'instrument-1' }),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, fallback?: unknown) => (typeof fallback === 'string' ? fallback : key) }),
}))

function fixture() {
  return {
    id: 'instrument-1',
    payment_method_id: 'method-1',
    payment_method: { id: 'method-1', code: 'CHK', name: 'Cheque' },
    reference: 'CHK-1',
    partner_id: null,
    partner: null,
    drawer_name: 'Drawer',
    amount: '100.000',
    currency: 'TND',
    received_date: '2026-07-01',
    maturity_date: '2026-07-15',
    expiry_date: null,
    status: instrumentState.status,
    kind: 'cheque',
    direction: 'inbound',
    needs_details: false,
    repository_id: 'repo-safe',
    repository: { id: 'repo-safe', code: 'SAFE', name: 'Safe', type: 'safe' },
    bank_name: null,
    bank_branch: null,
    bank_account: null,
    deposited_at: null,
    deposited_to_id: 'repo-bank',
    deposited_to: { id: 'repo-bank', code: 'BANK', name: 'Bank', type: 'bank_account' },
    cleared_at: null,
    bounced_at: null,
    bounce_reason: null,
    created_at: '2026-07-01T09:00:00Z',
  }
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(<InstrumentDetailPage />, {
    wrapper: ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    ),
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  instrumentState.status = 'deposited'
  permissionState.allowed = new Set(['instruments.view', 'instruments.clear', 'instruments.bounce'])
  useAuthStore.setState({
    user: {
      id: 'user-1', name: 'User', email: 'user@example.test', tenant_id: 'tenant-A',
      roles: [], permissions: [...permissionState.allowed], email_verified_at: null,
    },
    token: 'token', isAuthenticated: true, isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-A', companies: [], isLoading: false })
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/payment-instruments/instrument-1') return { data: { data: fixture() } }
    if (url === '/payment-repositories') return { data: { data: [] } }
    return { data: { data: [] } }
  })
  mockApiPost.mockResolvedValue({ data: { data: fixture() } })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('InstrumentDetailPage lifecycle', () => {
  it('renders immutable event rows from the instrument-events hook', async () => {
    renderPage()

    expect(await screen.findByText('treasury:instruments.events.remitted')).toBeInTheDocument()
    expect(screen.getByText('treasury:instruments.statuses.received → treasury:instruments.statuses.deposited')).toBeInTheDocument()
  })

  it('requires dishonor routing before submitting a bounce', async () => {
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'treasury:instruments.bounce' }))
    const submit = screen.getAllByRole('button', { name: 'treasury:instruments.bounce' }).at(-1)
    expect(submit).toBeDisabled()

    await userEvent.selectOptions(screen.getByLabelText('treasury:instruments.bounceRouting'), 'receivable')
    expect(submit).toBeEnabled()
  })

  it('hides every lifecycle action when its granular permission is absent', async () => {
    permissionState.allowed = new Set(['instruments.view'])
    renderPage()

    await screen.findByRole('heading', { name: 'CHK-1' })
    expect(screen.queryByRole('button', { name: 'treasury:instruments.clear' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'treasury:instruments.bounce' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'treasury:instruments.transfer' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'treasury:instruments.cancel' })).not.toBeInTheDocument()
  })
})
