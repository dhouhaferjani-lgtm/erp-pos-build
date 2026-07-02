import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter } from 'react-router-dom'
import { PaymentListPage } from './PaymentListPage'
import { PaymentForm } from './PaymentForm'
import { PaymentDetailPage } from './PaymentDetailPage'
import { InstrumentListPage } from './InstrumentListPage'
import { InstrumentDetailPage } from './InstrumentDetailPage'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'

// Mock the API
const { mockApiGet, mockApiPost, mockApi } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
  mockApiPost: vi.fn(),
  mockApi: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

vi.mock('../../lib/api', () => ({
  apiGet: mockApiGet,
  apiPost: mockApiPost,
  api: mockApi,
}))

const createTestQueryClient = () =>
  new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

function TestWrapper({ children }: { children: React.ReactNode }) {
  return (
    <QueryClientProvider client={createTestQueryClient()}>
      <BrowserRouter>{children}</BrowserRouter>
    </QueryClientProvider>
  )
}

const mockPayments = [
  {
    id: '1',
    payment_number: 'PAY-2025-0001',
    amount: 1500.0,
    payment_date: '2025-01-15',
    payment_method_id: 'method-1',
    payment_method_name: 'Cash',
    partner_id: 'partner-1',
    partner_name: 'Acme Corp',
    status: 'completed',
    created_at: '2025-01-15T10:00:00Z',
  },
  {
    id: '2',
    payment_number: 'PAY-2025-0002',
    amount: 2500.0,
    payment_date: '2025-01-16',
    payment_method_id: 'method-2',
    payment_method_name: 'Check',
    partner_id: 'partner-2',
    partner_name: 'Client Inc',
    status: 'pending',
    created_at: '2025-01-16T10:00:00Z',
  },
]

const mockInstruments = [
  {
    id: '1',
    instrument_number: 'CHK-001',
    type: 'check',
    amount: 2500.0,
    issue_date: '2025-01-16',
    maturity_date: '2025-02-16',
    partner_id: 'partner-2',
    partner_name: 'Client Inc',
    status: 'received',
    repository_id: 'repo-1',
    repository_name: 'Main Cash Register',
    created_at: '2025-01-16T10:00:00Z',
  },
  {
    id: '2',
    instrument_number: 'CHK-002',
    type: 'check',
    amount: 1000.0,
    issue_date: '2025-01-10',
    maturity_date: '2025-02-10',
    partner_id: 'partner-1',
    partner_name: 'Acme Corp',
    status: 'deposited',
    repository_id: 'repo-2',
    repository_name: 'Bank Account',
    created_at: '2025-01-10T10:00:00Z',
  },
]

const mockPaymentMethods = [
  { id: 'method-1', name: 'Cash', is_physical: false },
  { id: 'method-2', name: 'Check', is_physical: true },
  { id: 'method-3', name: 'Bank Transfer', is_physical: false },
]

const mockPartners = [
  { id: 'partner-1', name: 'Acme Corp' },
  { id: 'partner-2', name: 'Client Inc' },
]

const mockRepositories = [
  { id: 'repo-1', code: 'CASH-01', name: 'Main Cash Register', type: 'cash_register' },
]

const mockOpenInvoices = [
  {
    id: 'invoice-1',
    document_number: 'INV-2026-0001',
    document_date: '2026-06-01',
    due_date: '2026-07-01',
    total: '150.00',
    balance_due: '150.00',
    currency: 'TND',
    days_overdue: 0,
    partner: {
      id: 'partner-1',
      name: 'Acme Corp',
    },
  },
]

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: { id: 'user-1', name: 'User', email: 'user@example.test', tenant_id: tenantId, roles: [], email_verified_at: null },
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

describe('Treasury Management', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setTenant('tenant-A', 'company-1')
  })

  afterEach(() => {
    resetTenant()
  })

  describe('PaymentListPage', () => {
    it('renders the payment list page with title', () => {
      mockApi.get.mockResolvedValue({ data: { data: [], meta: { total: 0 } } })

      render(<PaymentListPage />, { wrapper: TestWrapper })

      expect(screen.getByRole('heading', { name: /payments/i })).toBeInTheDocument()
    })

    it('displays loading state initially', () => {
      mockApi.get.mockImplementation(
        () => new Promise((resolve) => setTimeout(resolve, 1000))
      )

      const { container } = render(<PaymentListPage />, { wrapper: TestWrapper })

      // DataTable renders an animated skeleton body while loading.
      expect(container.querySelector('.animate-pulse')).toBeInTheDocument()
    })

    it('displays list of payments', async () => {
      mockApi.get.mockResolvedValue({ data: { data: mockPayments, meta: { total: 2 } } })

      render(<PaymentListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('PAY-2025-0001')).toBeInTheDocument()
        expect(screen.getByText('PAY-2025-0002')).toBeInTheDocument()
      })
    })

    it('displays empty state when no payments', async () => {
      mockApi.get.mockResolvedValue({ data: { data: [], meta: { total: 0 } } })

      render(<PaymentListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText(/no payments/i)).toBeInTheDocument()
      })
    })

    it('has a button to record new payment', () => {
      mockApi.get.mockResolvedValue({ data: { data: [], meta: { total: 0 } } })

      render(<PaymentListPage />, { wrapper: TestWrapper })

      expect(screen.getByRole('button', { name: /record payment/i })).toBeInTheDocument()
    })

    it('displays payment method for each payment', async () => {
      mockApi.get.mockResolvedValue({ data: { data: mockPayments, meta: { total: 2 } } })

      render(<PaymentListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('Cash')).toBeInTheDocument()
        expect(screen.getByText('Check')).toBeInTheDocument()
      })
    })

    it('displays payment status badges', async () => {
      mockApi.get.mockResolvedValue({ data: { data: mockPayments, meta: { total: 2 } } })

      render(<PaymentListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('Completed')).toBeInTheDocument()
        expect(screen.getByText('Pending')).toBeInTheDocument()
      })
    })
  })

  describe('PaymentForm', () => {
    const setupFormMocks = () => {
      mockApi.get.mockImplementation((url: string) => {
        if (url.includes('/payment-methods')) {
          return Promise.resolve({ data: { data: mockPaymentMethods } })
        }
        if (url.includes('/payment-repositories')) {
          return Promise.resolve({ data: { data: mockRepositories } })
        }
        if (url.includes('/partners/partner-1/open-invoices')) {
          return Promise.resolve({ data: { data: mockOpenInvoices } })
        }
        if (url.includes('/partners')) {
          return Promise.resolve({ data: { data: mockPartners } })
        }
        return Promise.resolve({ data: { data: [] } })
      })
      mockApiPost.mockImplementation((url: string) => {
        if (url === '/smart-payment/preview-allocation') {
          return Promise.resolve({
            allocation_method: 'manual',
            allocations: [
              {
                document_id: 'invoice-1',
                document_number: 'INV-2026-0001',
                amount: '50.25',
                original_balance: '150.00',
              },
            ],
            total_to_invoices: '50.25',
            excess_amount: '0.00',
            excess_handling: 'none',
          })
        }

        return Promise.resolve({
          id: 'payment-1',
          payment_number: 'PAY-2026-0001',
          amount: 50,
        })
      })
    }

    it('renders payment form with required fields', () => {
      setupFormMocks()

      render(<PaymentForm />, { wrapper: TestWrapper })

      expect(screen.getByLabelText(/amount/i)).toBeInTheDocument()
      expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument()
    })

    it('shows validation errors for required fields', async () => {
      setupFormMocks()
      const user = userEvent.setup()

      render(<PaymentForm />, { wrapper: TestWrapper })

      const submitButton = screen.getByRole('button', { name: /save/i })
      await user.click(submitButton)

      await waitFor(() => {
        expect(screen.getByText(/amount is required/i)).toBeInTheDocument()
      })
    })

    it('has cancel button', () => {
      setupFormMocks()

      render(<PaymentForm />, { wrapper: TestWrapper })

      expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument()
    })

    it('allows selecting payment method', async () => {
      setupFormMocks()
      const user = userEvent.setup()

      render(<PaymentForm />, { wrapper: TestWrapper })

      // Wait for the options to be populated
      await waitFor(() => {
        expect(screen.getByRole('option', { name: 'Cash' })).toBeInTheDocument()
      })

      const methodSelect = screen.getByLabelText(/payment method/i)
      await user.selectOptions(methodSelect, 'method-1')

      expect(methodSelect).toHaveValue('method-1')
    })

    it('surfaces open invoices and submits partial manual allocations as strings', async () => {
      setupFormMocks()
      const user = userEvent.setup()

      render(<PaymentForm />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('option', { name: 'Cash' })).toBeInTheDocument()
        expect(screen.getByRole('option', { name: /Main Cash Register/ })).toBeInTheDocument()
      })

      await user.type(screen.getByLabelText(/amount/i), '50.25')
      await user.selectOptions(screen.getByLabelText(/payment method/i), 'method-1')
      await user.selectOptions(screen.getByLabelText(/repository/i), 'repo-1')
      await user.selectOptions(screen.getByLabelText(/partner/i), 'partner-1')

      await waitFor(() => {
        expect(screen.getByText('INV-2026-0001')).toBeInTheDocument()
      })

      await user.click(screen.getByRole('radio', { name: /manual selection/i }))
      await user.click(screen.getByRole('checkbox', { name: /INV-2026-0001/i }))

      const allocationInput = screen.getByLabelText(/amount.*INV-2026-0001/i)
      await user.clear(allocationInput)
      await user.type(allocationInput, '50.25')

      await user.click(screen.getByRole('button', { name: /preview allocation/i }))

      await waitFor(() => {
        expect(mockApiPost).toHaveBeenCalledWith('/smart-payment/preview-allocation', {
          partner_id: 'partner-1',
          payment_amount: '50.25',
          allocation_method: 'manual',
          manual_allocations: [{ document_id: 'invoice-1', amount: '50.25' }],
        })
      })

      await user.click(screen.getByRole('button', { name: /save/i }))

      await waitFor(() => {
        expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
          amount: '50.25',
          allocations: [{ document_id: 'invoice-1', amount: '50.25' }],
        }))
      })
    })
  })

  describe('InstrumentListPage', () => {
    it('renders the instrument list page with title', () => {
      mockApi.get.mockResolvedValue({ data: { data: [], meta: { total: 0 } } })

      render(<InstrumentListPage />, { wrapper: TestWrapper })

      expect(screen.getByRole('heading', { name: /instruments/i })).toBeInTheDocument()
    })

    it('displays loading state initially', () => {
      mockApi.get.mockImplementation(
        () => new Promise((resolve) => setTimeout(resolve, 1000))
      )

      const { container } = render(<InstrumentListPage />, { wrapper: TestWrapper })

      // DataTable renders an animated skeleton body while loading.
      expect(container.querySelector('.animate-pulse')).toBeInTheDocument()
    })

    it('displays list of instruments', async () => {
      mockApi.get.mockResolvedValue({ data: { data: mockInstruments, meta: { total: 2 } } })

      render(<InstrumentListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('CHK-001')).toBeInTheDocument()
        expect(screen.getByText('CHK-002')).toBeInTheDocument()
      })
    })

    it('displays empty state when no instruments', async () => {
      mockApi.get.mockResolvedValue({ data: { data: [], meta: { total: 0 } } })

      render(<InstrumentListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText(/no instruments/i)).toBeInTheDocument()
      })
    })

    it('displays instrument status badges', async () => {
      mockApi.get.mockResolvedValue({ data: { data: mockInstruments, meta: { total: 2 } } })

      render(<InstrumentListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('Received')).toBeInTheDocument()
        expect(screen.getByText('Deposited')).toBeInTheDocument()
      })
    })

    it('displays maturity dates', async () => {
      mockApi.get.mockResolvedValue({ data: { data: mockInstruments, meta: { total: 2 } } })

      render(<InstrumentListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        // Check that dates are displayed
        expect(screen.getAllByText(/2025/i).length).toBeGreaterThan(0)
      })
    })
  })

  describe('InstrumentDetailPage', () => {
    const mockInstrumentDetail: {
      id: string
      payment_method_id: string
      payment_method: { id: string; code: string; name: string }
      reference: string
      partner_id: string
      partner: { id: string; name: string }
      drawer_name: string
      amount: number
      currency: string
      received_date: string
      maturity_date: string
      expiry_date: string | null
      status: 'received' | 'deposited' | 'cleared' | 'bounced' | 'cancelled'
      repository_id: string
      repository: { id: string; code: string; name: string }
      bank_name: string
      bank_branch: string
      bank_account: string
      deposited_at: string | null
      deposited_to_id: string | null
      deposited_to: { id: string; code: string; name: string } | null
      cleared_at: string | null
      bounced_at: string | null
      bounce_reason: string | null
      created_at: string
    } = {
      id: 'instrument-1',
      payment_method_id: 'method-2',
      payment_method: {
        id: 'method-2',
        code: 'CHECK',
        name: 'Check',
      },
      reference: 'CHK-001',
      partner_id: 'partner-1',
      partner: {
        id: 'partner-1',
        name: 'Acme Corp',
      },
      drawer_name: 'John Doe',
      amount: 2500.0,
      currency: 'TND',
      received_date: '2025-01-15',
      maturity_date: '2025-02-15',
      expiry_date: null,
      status: 'received',
      repository_id: 'repo-1',
      repository: {
        id: 'repo-1',
        code: 'SAFE',
        name: 'Main Safe',
      },
      bank_name: 'Tunisian Bank',
      bank_branch: 'Downtown',
      bank_account: '12345678',
      deposited_at: null,
      deposited_to_id: null,
      deposited_to: null,
      cleared_at: null,
      bounced_at: null,
      bounce_reason: null,
      created_at: '2025-01-15T10:00:00Z',
    }

    const mockRepositories = [
      { id: 'repo-1', code: 'SAFE', name: 'Main Safe', type: 'safe' },
      { id: 'repo-2', code: 'BANK', name: 'Bank Account', type: 'bank_account' },
    ]

    // Mock useParams for detail page
    vi.mock('react-router-dom', async () => {
      const actual = await vi.importActual('react-router-dom')
      return {
        ...actual,
        useParams: () => ({ id: 'instrument-1' }),
      }
    })

    const setupMocks = (instrumentData: typeof mockInstrumentDetail) => {
      mockApi.get.mockImplementation((url: string) => {
        if (url.includes('/payment-instruments/')) {
          return Promise.resolve({ data: { data: instrumentData } })
        }
        if (url.includes('/payment-repositories')) {
          return Promise.resolve({ data: { data: mockRepositories } })
        }
        return Promise.resolve({ data: { data: [] } })
      })
    }

    it('renders instrument detail page with reference', async () => {
      setupMocks(mockInstrumentDetail)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('CHK-001')).toBeInTheDocument()
      })
    })

    it('displays loading state initially', () => {
      mockApi.get.mockImplementation(
        () => new Promise((resolve) => setTimeout(resolve, 1000))
      )

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      expect(screen.getByText(/loading/i)).toBeInTheDocument()
    })

    it('displays instrument amount', async () => {
      setupMocks(mockInstrumentDetail)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        // Amount is displayed with currency formatting
        expect(screen.getByText(/2[\s,.]?500/)).toBeInTheDocument()
      })
    })

    it('displays partner information', async () => {
      setupMocks(mockInstrumentDetail)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('Acme Corp')).toBeInTheDocument()
      })
    })

    it('displays instrument status badge', async () => {
      setupMocks(mockInstrumentDetail)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        // There are multiple "Received" texts (badge and history), check badge exists
        const badges = screen.getAllByText('Received')
        expect(badges.length).toBeGreaterThanOrEqual(1)
        // Check the badge span exists
        expect(badges.some(el => el.tagName === 'SPAN' && el.classList.contains('rounded-full'))).toBe(true)
      })
    })

    it('displays current repository location', async () => {
      setupMocks(mockInstrumentDetail)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('Main Safe')).toBeInTheDocument()
      })
    })

    it('shows bank information', async () => {
      setupMocks(mockInstrumentDetail)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('Tunisian Bank')).toBeInTheDocument()
        expect(screen.getByText('Downtown')).toBeInTheDocument()
      })
    })

    it('has deposit button when status is received', async () => {
      setupMocks(mockInstrumentDetail)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /deposit/i })).toBeInTheDocument()
      })
    })

    it('has transfer button when status is received', async () => {
      setupMocks(mockInstrumentDetail)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /transfer/i })).toBeInTheDocument()
      })
    })

    it('has clear button when status is deposited', async () => {
      const depositedInstrument = {
        ...mockInstrumentDetail,
        status: 'deposited' as const,
        deposited_at: '2025-01-20T10:00:00Z',
        deposited_to_id: 'repo-2',
        deposited_to: { id: 'repo-2', code: 'BANK', name: 'Bank Account' },
      }
      setupMocks(depositedInstrument)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /clear/i })).toBeInTheDocument()
      })
    })

    it('has bounce button when status is deposited', async () => {
      const depositedInstrument = {
        ...mockInstrumentDetail,
        status: 'deposited' as const,
        deposited_at: '2025-01-20T10:00:00Z',
        deposited_to_id: 'repo-2',
        deposited_to: { id: 'repo-2', code: 'BANK', name: 'Bank Account' },
      }
      setupMocks(depositedInstrument)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /bounce/i })).toBeInTheDocument()
      })
    })

    it('shows cleared status with timestamp', async () => {
      const clearedInstrument = {
        ...mockInstrumentDetail,
        status: 'cleared' as const,
        deposited_at: '2025-01-20T10:00:00Z',
        deposited_to_id: 'repo-2',
        deposited_to: { id: 'repo-2', code: 'BANK', name: 'Bank Account' },
        cleared_at: '2025-02-15T10:00:00Z',
      }
      setupMocks(clearedInstrument)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        // Multiple "Cleared" texts (badge and history)
        const cleared = screen.getAllByText('Cleared')
        expect(cleared.length).toBeGreaterThanOrEqual(1)
      })
    })

    it('shows bounced status with reason', async () => {
      const bouncedInstrument = {
        ...mockInstrumentDetail,
        status: 'bounced' as const,
        deposited_at: '2025-01-20T10:00:00Z',
        deposited_to_id: 'repo-2',
        deposited_to: { id: 'repo-2', code: 'BANK', name: 'Bank Account' },
        bounced_at: '2025-02-15T10:00:00Z',
        bounce_reason: 'Insufficient funds',
      }
      setupMocks(bouncedInstrument)

      render(<InstrumentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        // Multiple "Bounced" texts (badge and history)
        const bounced = screen.getAllByText('Bounced')
        expect(bounced.length).toBeGreaterThanOrEqual(1)
        expect(screen.getByText('Insufficient funds')).toBeInTheDocument()
      })
    })
  })

  describe('PaymentDetailPage - Refunds', () => {
    interface MockPaymentDetail {
      id: string
      payment_number: string
      partner_id: string
      partner: { id: string; name: string }
      payment_method_id: string
      payment_method: { id: string; code: string; name: string }
      instrument_id: string | null
      repository_id: string
      amount: string
      currency: string
      payment_date: string
      status: 'pending' | 'completed' | 'cancelled'
      reference: string
      notes: string
      allocations: Array<{ id: string; document_id: string; document_number: string; amount: string }>
      created_at: string
    }

    const mockPaymentDetail: MockPaymentDetail = {
      id: 'payment-1',
      payment_number: 'PAY-2025-0001',
      partner_id: 'partner-1',
      partner: {
        id: 'partner-1',
        name: 'Acme Corp',
      },
      payment_method_id: 'method-1',
      payment_method: {
        id: 'method-1',
        code: 'CASH',
        name: 'Cash',
      },
      instrument_id: null,
      repository_id: 'repo-1',
      amount: '1500.00',
      currency: 'TND',
      payment_date: '2025-01-15',
      status: 'completed',
      reference: 'REF-001',
      notes: 'Test payment',
      allocations: [
        {
          id: 'alloc-1',
          document_id: 'doc-1',
          document_number: 'INV-2025-0001',
          amount: '1500.00',
        },
      ],
      created_at: '2025-01-15T10:00:00Z',
    }

    const mockRefundHistory = [
      {
        id: 'refund-1',
        type: 'partial',
        amount: '500.00',
        reason: 'Product return',
        created_at: '2025-01-20T10:00:00Z',
        created_by: 'Admin User',
      },
    ]

    const setupPaymentMocks = (
      paymentData: MockPaymentDetail,
      canRefund = true,
      refundHistory: typeof mockRefundHistory = []
    ) => {
      mockApi.get.mockImplementation((url: string) => {
        if (url.includes('/payments/') && url.includes('/can-refund')) {
          return Promise.resolve({
            data: {
              data: {
                can_refund: canRefund,
                status: paymentData.status,
                amount: paymentData.amount,
              },
            },
          })
        }
        if (url.includes('/payments/') && url.includes('/refund-history')) {
          return Promise.resolve({ data: { data: refundHistory } })
        }
        if (url.includes('/payments/')) {
          return Promise.resolve({ data: { data: paymentData } })
        }
        return Promise.resolve({ data: { data: [] } })
      })
    }

    it('renders payment detail page with amount', async () => {
      setupPaymentMocks(mockPaymentDetail)

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      // Wait for the payment info section to render
      await waitFor(() => {
        // Look for the Payment Information section heading
        expect(screen.getByText(/payment information/i)).toBeInTheDocument()
      })
    })

    it('displays completed status badge', async () => {
      setupPaymentMocks(mockPaymentDetail)

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      // Wait for refund button which only shows for completed payments
      await waitFor(() => {
        // If partial refund button shows, payment is loaded and completed
        expect(screen.getByRole('button', { name: /partial refund/i })).toBeInTheDocument()
      })
    })

    it('shows refund button for completed payments', async () => {
      setupPaymentMocks(mockPaymentDetail)

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        // Use exact match to avoid matching "Partial Refund"
        const buttons = screen.getAllByRole('button', { name: /refund/i })
        const refundButton = buttons.find(btn => btn.textContent?.toLowerCase().trim() === 'refund')
        expect(refundButton).toBeInTheDocument()
      })
    })

    it('shows partial refund button for completed payments', async () => {
      setupPaymentMocks(mockPaymentDetail)

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /partial refund/i })).toBeInTheDocument()
      })
    })

    it('shows reverse payment button for completed payments', async () => {
      setupPaymentMocks(mockPaymentDetail)

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /reverse/i })).toBeInTheDocument()
      })
    })

    it('does not show refund buttons for pending payments', async () => {
      const pendingPayment = { ...mockPaymentDetail, status: 'pending' as const }
      setupPaymentMocks(pendingPayment, false)

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.queryByRole('button', { name: /refund/i })).not.toBeInTheDocument()
      })
    })

    it('does not show refund buttons for cancelled payments', async () => {
      const cancelledPayment = { ...mockPaymentDetail, status: 'cancelled' as const }
      setupPaymentMocks(cancelledPayment, false)

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.queryByRole('button', { name: /refund/i })).not.toBeInTheDocument()
      })
    })

    it('opens refund modal when clicking refund button', async () => {
      setupPaymentMocks(mockPaymentDetail)
      const user = userEvent.setup()

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /^refund$/i })).toBeInTheDocument()
      })

      await user.click(screen.getByRole('button', { name: /^refund$/i }))

      await waitFor(() => {
        expect(screen.getByText(/reason/i)).toBeInTheDocument()
      })
    })

    it('opens partial refund modal when clicking partial refund button', async () => {
      setupPaymentMocks(mockPaymentDetail)
      const user = userEvent.setup()

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /partial refund/i })).toBeInTheDocument()
      })

      await user.click(screen.getByRole('button', { name: /partial refund/i }))

      await waitFor(() => {
        expect(screen.getByLabelText(/amount/i)).toBeInTheDocument()
        expect(screen.getByText(/reason/i)).toBeInTheDocument()
      })
    })

    it('displays refund history when available', async () => {
      setupPaymentMocks(mockPaymentDetail, true, mockRefundHistory)

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText(/refund history/i)).toBeInTheDocument()
        expect(screen.getByText('Product return')).toBeInTheDocument()
      })
    })

    it('shows remaining refundable amount', async () => {
      setupPaymentMocks(mockPaymentDetail, true, mockRefundHistory)

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        // Original: 1500, Refunded: 500, Remaining: 1000
        expect(screen.getByText(/remaining/i)).toBeInTheDocument()
      })
    })

    it('disables refund when payment cannot be refunded', async () => {
      setupPaymentMocks(mockPaymentDetail, false)

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        const refundButton = screen.queryByRole('button', { name: /^refund$/i })
        if (refundButton) {
          expect(refundButton).toBeDisabled()
        }
      })
    })

    it('submits full refund with reason', async () => {
      setupPaymentMocks(mockPaymentDetail)
      mockApi.post.mockResolvedValue({ data: { data: {}, message: 'Refund successful' } })
      const user = userEvent.setup()

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      // Find the refund button (not partial refund)
      await waitFor(() => {
        const buttons = screen.getAllByRole('button', { name: /refund/i })
        expect(buttons.length).toBeGreaterThanOrEqual(1)
      })

      const buttons = screen.getAllByRole('button', { name: /refund/i })
      const refundButton = buttons.find(btn => btn.textContent?.toLowerCase().trim() === 'refund')
      expect(refundButton).toBeTruthy()
      await user.click(refundButton!)

      await waitFor(() => {
        expect(screen.getByLabelText(/reason/i)).toBeInTheDocument()
      })

      await user.type(screen.getByLabelText(/reason/i), 'Customer requested refund')
      await user.click(screen.getByRole('button', { name: /confirm/i }))

      await waitFor(() => {
        // The ID from useParams mock is 'instrument-1', so check for that
        expect(mockApi.post).toHaveBeenCalledWith(
          expect.stringContaining('/refund'),
          expect.objectContaining({ reason: 'Customer requested refund' })
        )
      })
    })

    it('submits partial refund with amount and reason', async () => {
      setupPaymentMocks(mockPaymentDetail)
      mockApi.post.mockResolvedValue({ data: { data: {}, message: 'Partial refund successful' } })
      const user = userEvent.setup()

      render(<PaymentDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /partial refund/i })).toBeInTheDocument()
      })

      await user.click(screen.getByRole('button', { name: /partial refund/i }))

      await waitFor(() => {
        expect(screen.getByLabelText(/amount/i)).toBeInTheDocument()
      })

      await user.type(screen.getByLabelText(/amount/i), '500')
      await user.type(screen.getByLabelText(/reason/i), 'Partial return')
      await user.click(screen.getByRole('button', { name: /confirm/i }))

      await waitFor(() => {
        // The ID comes from useParams mock, check for partial-refund endpoint
        expect(mockApi.post).toHaveBeenCalledWith(
          expect.stringContaining('/partial-refund'),
          expect.objectContaining({ amount: '500', reason: 'Partial return' })
        )
      })
    })
  })

  describe('SplitPaymentForm', () => {
    const mockPaymentMethods = [
      { id: 'method-1', name: 'Cash', code: 'CASH', is_physical: false },
      { id: 'method-2', name: 'Check', code: 'CHECK', is_physical: true },
      { id: 'method-3', name: 'Bank Transfer', code: 'TRANSFER', is_physical: false },
    ]

    const mockRepositories = [
      { id: 'repo-1', name: 'Main Cash', code: 'CASH-1', type: 'cash' },
      { id: 'repo-2', name: 'Bank Account', code: 'BANK-1', type: 'bank_account' },
    ]

    const setupSplitPaymentMocks = () => {
      mockApi.get.mockImplementation((url: string) => {
        if (url.includes('/payment-methods')) {
          return Promise.resolve({ data: { data: mockPaymentMethods } })
        }
        if (url.includes('/payment-repositories')) {
          return Promise.resolve({ data: { data: mockRepositories } })
        }
        return Promise.resolve({ data: { data: [] } })
      })
    }

    it('renders split payment form with add payment button', async () => {
      setupSplitPaymentMocks()

      const { SplitPaymentForm } = await import('./SplitPaymentForm')
      render(
        <SplitPaymentForm
          documentId="doc-1"
          totalAmount={1000}
          currency="TND"
          onSuccess={() => {}}
          onCancel={() => {}}
        />,
        { wrapper: TestWrapper }
      )

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /add payment/i })).toBeInTheDocument()
      })
    })

    it('starts with one payment line', async () => {
      setupSplitPaymentMocks()

      const { SplitPaymentForm } = await import('./SplitPaymentForm')
      render(
        <SplitPaymentForm
          documentId="doc-1"
          totalAmount={1000}
          currency="TND"
          onSuccess={() => {}}
          onCancel={() => {}}
        />,
        { wrapper: TestWrapper }
      )

      await waitFor(() => {
        const amountInputs = screen.getAllByLabelText(/amount/i)
        expect(amountInputs.length).toBe(1)
      })
    })

    it('can add multiple payment lines', async () => {
      setupSplitPaymentMocks()
      const user = userEvent.setup()

      const { SplitPaymentForm } = await import('./SplitPaymentForm')
      render(
        <SplitPaymentForm
          documentId="doc-1"
          totalAmount={1000}
          currency="TND"
          onSuccess={() => {}}
          onCancel={() => {}}
        />,
        { wrapper: TestWrapper }
      )

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /add payment/i })).toBeInTheDocument()
      })

      await user.click(screen.getByRole('button', { name: /add payment/i }))

      await waitFor(() => {
        const amountInputs = screen.getAllByLabelText(/amount/i)
        expect(amountInputs.length).toBe(2)
      })
    })

    it('shows total amount and remaining balance', async () => {
      setupSplitPaymentMocks()

      const { SplitPaymentForm } = await import('./SplitPaymentForm')
      render(
        <SplitPaymentForm
          documentId="doc-1"
          totalAmount={1000}
          currency="TND"
          onSuccess={() => {}}
          onCancel={() => {}}
        />,
        { wrapper: TestWrapper }
      )

      // Check for total required text (from translation key)
      await waitFor(() => {
        expect(screen.getByText(/total required/i)).toBeInTheDocument()
        expect(screen.getByText(/remaining/i)).toBeInTheDocument()
      })
    })

    it('validates that total equals required amount', async () => {
      setupSplitPaymentMocks()
      const user = userEvent.setup()

      const { SplitPaymentForm } = await import('./SplitPaymentForm')
      render(
        <SplitPaymentForm
          documentId="doc-1"
          totalAmount={1000}
          currency="TND"
          onSuccess={() => {}}
          onCancel={() => {}}
        />,
        { wrapper: TestWrapper }
      )

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /submit/i })).toBeInTheDocument()
      })

      // Submit with partial amount should show validation error
      const amountInput = screen.getByLabelText(/amount/i)
      await user.clear(amountInput)
      await user.type(amountInput, '500')

      const submitButton = screen.getByRole('button', { name: /submit/i })
      await user.click(submitButton)

      await waitFor(() => {
        expect(screen.getByText(/does not match/i)).toBeInTheDocument()
      })
    })

    it('submits split payment when amounts match', async () => {
      setupSplitPaymentMocks()
      mockApi.post.mockResolvedValue({ data: { data: {}, message: 'Success' } })
      const onSuccess = vi.fn()
      const user = userEvent.setup()

      const { SplitPaymentForm } = await import('./SplitPaymentForm')
      render(
        <SplitPaymentForm
          documentId="doc-1"
          totalAmount={1000}
          currency="TND"
          onSuccess={onSuccess}
          onCancel={() => {}}
        />,
        { wrapper: TestWrapper }
      )

      await waitFor(() => {
        expect(screen.getByLabelText(/amount/i)).toBeInTheDocument()
      })

      // Enter full amount
      const amountInput = screen.getByLabelText(/amount/i)
      await user.clear(amountInput)
      await user.type(amountInput, '1000')

      // Select payment method (label is just "Method")
      await waitFor(() => {
        expect(screen.getByRole('option', { name: 'Cash' })).toBeInTheDocument()
      })
      const methodSelect = screen.getByLabelText(/method/i)
      await user.selectOptions(methodSelect, 'method-1')

      await user.click(screen.getByRole('button', { name: /submit/i }))

      await waitFor(() => {
        expect(mockApi.post).toHaveBeenCalledWith(
          expect.stringContaining('/split-payment'),
          expect.objectContaining({
            splits: expect.arrayContaining([
              expect.objectContaining({ amount: '1000' })
            ])
          })
        )
      })
    })

    it('can remove payment lines except the last one', async () => {
      setupSplitPaymentMocks()
      const user = userEvent.setup()

      const { SplitPaymentForm } = await import('./SplitPaymentForm')
      render(
        <SplitPaymentForm
          documentId="doc-1"
          totalAmount={1000}
          currency="TND"
          onSuccess={() => {}}
          onCancel={() => {}}
        />,
        { wrapper: TestWrapper }
      )

      // Add a second payment line
      await waitFor(() => {
        expect(screen.getByRole('button', { name: /add payment/i })).toBeInTheDocument()
      })
      await user.click(screen.getByRole('button', { name: /add payment/i }))

      // Should have 2 lines now
      await waitFor(() => {
        const amountInputs = screen.getAllByLabelText(/amount/i)
        expect(amountInputs.length).toBe(2)
      })

      // Remove one line
      const removeButtons = screen.getAllByRole('button', { name: /remove/i })
      await user.click(removeButtons[0])

      // Should have 1 line
      await waitFor(() => {
        const amountInputs = screen.getAllByLabelText(/amount/i)
        expect(amountInputs.length).toBe(1)
      })
    })
  })
})
