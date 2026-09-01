import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { Dashboard } from './Dashboard'
import {
  makeDashboardStats,
  makeRecentDocument,
  makeRecentPayment,
} from './__fixtures__/dashboard'

// Mock the API. The dashboard uses `api.get` directly (not `apiGet`), so
// mocking the axios-like instance is what matters. We also mock `apiGet`
// because the onboarding query under the hood calls it.
const { mockApi, mockApiGet } = vi.hoisted(() => ({
  mockApi: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  mockApiGet: vi.fn(),
}))

vi.mock('../../lib/api', () => ({
  apiGet: mockApiGet,
  api: mockApi,
}))

vi.mock('@/features/treasury/components/CashPositionWidget', () => ({
  CashPositionWidget: () => <div data-testid="cash-position-widget" />,
}))

const mockRecentDocuments = [
  makeRecentDocument({
    id: '1',
    document_number: 'INV-2025-0001',
    type: 'invoice',
    partner_name: 'Acme Corp',
    total: 4321,
    status: 'posted',
    created_at: '2025-01-15T10:00:00Z',
  }),
  makeRecentDocument({
    id: '2',
    document_number: 'QUO-2025-0001',
    type: 'quote',
    partner_name: 'Client Inc',
    total: 8765,
    status: 'draft',
    created_at: '2025-01-14T10:00:00Z',
  }),
]

const mockRecentPayments = [
  makeRecentPayment({
    id: '1',
    payment_number: 'PAY-2025-0001',
    partner_name: 'Acme Corp',
    amount: 1500,
    payment_method_name: 'Cash',
    created_at: '2025-01-15T10:00:00Z',
  }),
  makeRecentPayment({
    id: '2',
    payment_number: 'PAY-2025-0002',
    partner_name: 'Client Inc',
    amount: 2500,
    payment_method_name: 'Bank Transfer',
    created_at: '2025-01-14T10:00:00Z',
  }),
]

/**
 * Route `api.get` calls by URL so each query resolves to the correct wire
 * shape. `/dashboard/stats` uses the axios `{data:{data: ...}}` envelope;
 * `/documents` and `/payments` use the flat `{data: {data: [...]}}` shape
 * whose outer `.data` is consumed by the hook.
 */
function configureApiMocks() {
  mockApi.get.mockImplementation((url: string) => {
    if (url.includes('/dashboard/stats')) {
      return Promise.resolve({ data: { data: makeDashboardStats() } })
    }
    if (url.includes('/documents')) {
      return Promise.resolve({ data: { data: mockRecentDocuments } })
    }
    if (url.includes('/payments')) {
      return Promise.resolve({ data: { data: mockRecentPayments } })
    }
    return Promise.resolve({ data: { data: [] } })
  })
  mockApiGet.mockImplementation((url: string) => {
    if (url.includes('/onboarding/status')) {
      return Promise.resolve([])
    }
    return Promise.resolve([])
  })
}

describe('Dashboard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth({ roles: ['admin'] })
    configureApiMocks()
  })

  afterEach(() => {
    resetAuth()
  })

  it('renders the dashboard with title', async () => {
    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /dashboard/i })).toBeInTheDocument()
    })
  })

  it('displays KPI cards', async () => {
    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByText(/revenue/i)).toBeInTheDocument()
      expect(screen.getByText(/invoices/i)).toBeInTheDocument()
    })
  })

  it('displays loading state initially', () => {
    mockApi.get.mockImplementation(
      () => new Promise((resolve) => setTimeout(resolve, 1000))
    )

    renderWithProviders(<Dashboard />)

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('displays recent documents section', async () => {
    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByText(/recent documents/i)).toBeInTheDocument()
      expect(screen.getByText('INV-2025-0001')).toBeInTheDocument()
    })

    // Regression guard: the widget must render each document's real `total`
    // (the API emits `total`, not `total_amount`). Reading the wrong field
    // formatted every PO/invoice as 0 on the dashboard.
    expect(screen.getByText(/4[\s,.]?321/)).toBeInTheDocument()
    expect(screen.getByText(/8[\s,.]?765/)).toBeInTheDocument()
  })

  it('displays recent payments section', async () => {
    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByText(/recent payments/i)).toBeInTheDocument()
      expect(screen.getByText('PAY-2025-0001')).toBeInTheDocument()
      expect(screen.getByTestId('cash-position-widget')).toBeInTheDocument()
    })
  })

  it('does not request or render permission-gated activity for a synthetic minimal principal', async () => {
    seedAuth({ permissions: ['purchase-orders.receive'] })

    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /dashboard/i })).toBeInTheDocument()
    })

    const requestedUrls = mockApi.get.mock.calls.map(([url]) => String(url))
    expect(requestedUrls.filter((url) => url.startsWith('/documents'))).toHaveLength(0)
    expect(requestedUrls.filter((url) => url.startsWith('/payments'))).toHaveLength(0)
    expect(screen.queryByText(/recent documents/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/recent payments/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/loading/i)).not.toBeInTheDocument()
  })

  it('requests and renders only documents for a documents-only principal', async () => {
    seedAuth({ permissions: ['documents.view'] })

    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByText(/recent documents/i)).toBeInTheDocument()
    })

    const requestedUrls = mockApi.get.mock.calls.map(([url]) => String(url))
    expect(requestedUrls.filter((url) => url.startsWith('/documents'))).toHaveLength(1)
    expect(requestedUrls.filter((url) => url.startsWith('/payments'))).toHaveLength(0)
    expect(screen.queryByText(/recent payments/i)).not.toBeInTheDocument()
  })

  it('requests and renders both activity cards for an admin principal', async () => {
    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByText(/recent documents/i)).toBeInTheDocument()
      expect(screen.getByText(/recent payments/i)).toBeInTheDocument()
    })

    const requestedUrls = mockApi.get.mock.calls.map(([url]) => String(url))
    expect(requestedUrls.filter((url) => url.startsWith('/documents'))).toHaveLength(1)
    expect(requestedUrls.filter((url) => url.startsWith('/payments'))).toHaveLength(1)
  })

  it('has quick action buttons', async () => {
    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByRole('link', { name: /new invoice/i })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: /new quote/i })).toBeInTheDocument()
    })
  })

  // AMENDED ruling M2 (2026-08-03 gate): revenue.change is null when there is
  // no meaningful previous-period baseline (e.g. a tenant's first month).
  // Render no arrow icon, just an em-dash — mirrors ExpenseAnalyticsPage's
  // mom_delta_percent tile convention.
  it('renders no arrow and an em-dash when revenue.change is null', async () => {
    mockApi.get.mockImplementation((url: string) => {
      if (url.includes('/dashboard/stats')) {
        return Promise.resolve({
          data: {
            data: makeDashboardStats({
              revenue: { current: '5000.000', previous: '0.000', change: null },
            }),
          },
        })
      }
      if (url.includes('/documents') || url.includes('/payments')) {
        return Promise.resolve({ data: { data: [] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })

    const { container } = renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByText('—')).toBeInTheDocument()
    })

    expect(container.querySelector('.lucide-trending-up')).not.toBeInTheDocument()
    expect(container.querySelector('.lucide-trending-down')).not.toBeInTheDocument()
  })
})
