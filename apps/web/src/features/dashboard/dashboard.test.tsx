import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { Dashboard } from './Dashboard'

// Mock the API
const { mockApiGet } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
}))

vi.mock('../../lib/api', () => ({
  apiGet: mockApiGet,
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

const mockDashboardStats = {
  revenue: {
    current: 15000,
    previous: 12000,
    change: 25,
  },
  invoices: {
    total: 45,
    pending: 12,
    overdue: 3,
  },
  partners: {
    total: 28,
    newThisMonth: 5,
  },
  payments: {
    received: 35000,
    pending: 8000,
  },
}

const mockRecentDocuments = [
  {
    id: '1',
    document_number: 'INV-2025-0001',
    type: 'invoice',
    partner_name: 'Acme Corp',
    total_amount: 1500,
    status: 'posted',
    created_at: '2025-01-15T10:00:00Z',
  },
  {
    id: '2',
    document_number: 'QUO-2025-0001',
    type: 'quote',
    partner_name: 'Client Inc',
    total_amount: 2500,
    status: 'draft',
    created_at: '2025-01-14T10:00:00Z',
  },
]

const mockRecentPayments = [
  {
    id: '1',
    payment_number: 'PAY-2025-0001',
    partner_name: 'Acme Corp',
    amount: 1500,
    payment_method_name: 'Cash',
    created_at: '2025-01-15T10:00:00Z',
  },
  {
    id: '2',
    payment_number: 'PAY-2025-0002',
    partner_name: 'Client Inc',
    amount: 2500,
    payment_method_name: 'Bank Transfer',
    created_at: '2025-01-14T10:00:00Z',
  },
]

describe('Dashboard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the dashboard with title', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/dashboard/stats')) {
        return Promise.resolve(mockDashboardStats)
      }
      if (url.includes('/documents')) {
        return Promise.resolve({ data: mockRecentDocuments })
      }
      if (url.includes('/payments')) {
        return Promise.resolve({ data: mockRecentPayments })
      }
      return Promise.resolve({ data: [] })
    })

    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /dashboard/i })).toBeInTheDocument()
    })
  })

  it('displays KPI cards', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/dashboard/stats')) {
        return Promise.resolve(mockDashboardStats)
      }
      if (url.includes('/documents')) {
        return Promise.resolve({ data: mockRecentDocuments })
      }
      if (url.includes('/payments')) {
        return Promise.resolve({ data: mockRecentPayments })
      }
      return Promise.resolve({ data: [] })
    })

    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByText(/revenue/i)).toBeInTheDocument()
      expect(screen.getByText(/invoices/i)).toBeInTheDocument()
    })
  })

  it('displays loading state initially', () => {
    mockApiGet.mockImplementation(
      () => new Promise((resolve) => setTimeout(resolve, 1000))
    )

    renderWithProviders(<Dashboard />)

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('displays recent documents section', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/dashboard/stats')) {
        return Promise.resolve(mockDashboardStats)
      }
      if (url.includes('/documents')) {
        return Promise.resolve({ data: mockRecentDocuments })
      }
      if (url.includes('/payments')) {
        return Promise.resolve({ data: mockRecentPayments })
      }
      return Promise.resolve({ data: [] })
    })

    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByText(/recent documents/i)).toBeInTheDocument()
      expect(screen.getByText('INV-2025-0001')).toBeInTheDocument()
    })
  })

  it('displays recent payments section', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/dashboard/stats')) {
        return Promise.resolve(mockDashboardStats)
      }
      if (url.includes('/documents')) {
        return Promise.resolve({ data: mockRecentDocuments })
      }
      if (url.includes('/payments')) {
        return Promise.resolve({ data: mockRecentPayments })
      }
      return Promise.resolve({ data: [] })
    })

    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByText(/recent payments/i)).toBeInTheDocument()
      expect(screen.getByText('PAY-2025-0001')).toBeInTheDocument()
    })
  })

  it('has quick action buttons', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/dashboard/stats')) {
        return Promise.resolve(mockDashboardStats)
      }
      if (url.includes('/documents')) {
        return Promise.resolve({ data: mockRecentDocuments })
      }
      if (url.includes('/payments')) {
        return Promise.resolve({ data: mockRecentPayments })
      }
      return Promise.resolve({ data: [] })
    })

    renderWithProviders(<Dashboard />)

    await waitFor(() => {
      expect(screen.getByRole('link', { name: /new invoice/i })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: /new quote/i })).toBeInTheDocument()
    })
  })
})
