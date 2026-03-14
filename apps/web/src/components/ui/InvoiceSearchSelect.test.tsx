import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { InvoiceSearchSelect } from './InvoiceSearchSelect'
import type { Invoice } from './InvoiceSearchSelect'
import { api } from '../../lib/api'

// Mock the API
vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}))

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback || key,
  }),
}))

const mockInvoices: Invoice[] = [
  {
    id: '1',
    number: 'INV-00001',
    document_date: '2024-12-20',
    total: '1000.00',
    balance: '500.00',
    currency: 'EUR',
    status: 'posted',
    partner: {
      id: 'p1',
      name: 'ACME Corp',
    },
  } as Invoice,
  {
    id: '2',
    number: 'INV-00002',
    document_date: '2024-12-21',
    total: '2000.00',
    balance: '2000.00',
    currency: 'EUR',
    status: 'posted',
    partner: {
      id: 'p2',
      name: 'TechStart Inc',
    },
  } as Invoice,
]

describe('InvoiceSearchSelect', () => {
  let queryClient: QueryClient
  const user = userEvent.setup()
  const onChangeMock = vi.fn()

  beforeEach(() => {
    queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
        },
      },
    })
    vi.clearAllMocks()
  })

  const renderComponent = (props = {}) => {
    return render(
      <QueryClientProvider client={queryClient}>
        <InvoiceSearchSelect value={null} onChange={onChangeMock} {...props} />
      </QueryClientProvider>
    )
  }

  it('renders with label when provided', () => {
    renderComponent({ label: 'Select Invoice' })
    expect(screen.getByText('Select Invoice')).toBeInTheDocument()
  })

  it('shows required asterisk when required prop is true', () => {
    renderComponent({ label: 'Invoice', required: true })
    expect(screen.getByText('*')).toBeInTheDocument()
  })

  it('displays placeholder text when no value selected', () => {
    renderComponent({ label: 'Invoice' })
    expect(screen.getByRole('button')).toHaveTextContent('Select')
  })

  it('opens dropdown when button is clicked', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockInvoices } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByPlaceholderText(/Search by invoice number or partner/i)).toBeInTheDocument()
    })
  })

  it('fetches and displays invoices when dropdown opens', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockInvoices } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByText('INV-00001')).toBeInTheDocument()
      expect(screen.getByText('INV-00002')).toBeInTheDocument()
      expect(screen.getByText('ACME Corp')).toBeInTheDocument()
      expect(screen.getByText('TechStart Inc')).toBeInTheDocument()
    })
  })

  it('filters invoices by posted status', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockInvoices } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(api.get).toHaveBeenCalledWith(
        expect.stringContaining('status=posted')
      )
    })
  })

  it('filters by partner ID when provided', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockInvoices } })

    renderComponent({ partnerId: 'partner-123' })

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(api.get).toHaveBeenCalledWith(
        expect.stringContaining('partner_id=partner-123')
      )
    })
  })

  it('calls onChange when an invoice is selected', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockInvoices } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByText('INV-00001')).toBeInTheDocument()
    })

    await user.click(screen.getByText('INV-00001'))

    expect(onChangeMock).toHaveBeenCalledWith(mockInvoices[0])
  })

  it('displays selected invoice correctly', () => {
    const selectedInvoice = mockInvoices[0]
    renderComponent({ value: selectedInvoice })

    expect(screen.getByRole('button')).toHaveTextContent('INV-00001')
    expect(screen.getByRole('button')).toHaveTextContent('ACME Corp')
    expect(screen.getByRole('button')).toHaveTextContent('Balance')
  })

  it('clears selection when clear button is clicked', async () => {
    const selectedInvoice = mockInvoices[0]
    renderComponent({ value: selectedInvoice })

    const clearButton = screen.getByRole('button', { name: '' }).querySelector('svg')
    expect(clearButton).toBeInTheDocument()

    await user.click(clearButton!.parentElement!)

    expect(onChangeMock).toHaveBeenCalledWith(null)
  })

  it('disables the component when disabled prop is true', () => {
    renderComponent({ disabled: true })

    const button = screen.getByRole('button')
    expect(button).toBeDisabled()
  })

  it('shows error message when error prop is provided', () => {
    renderComponent({ error: 'Invoice is required' })

    expect(screen.getByText('Invoice is required')).toBeInTheDocument()
  })

  it('shows loading state while fetching invoices', async () => {
    vi.mocked(api.get).mockImplementation(() => new Promise(() => {})) // Never resolves

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByText('Loading...')).toBeInTheDocument()
    })
  })

  it('shows empty state when no invoices found', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [] } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByText('No posted invoices available')).toBeInTheDocument()
    })
  })

  it('shows no results message when search returns empty', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [] } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByPlaceholderText(/Search by invoice number or partner/i)).toBeInTheDocument()
    })

    const searchInput = screen.getByPlaceholderText(/Search by invoice number or partner/i)
    await user.type(searchInput, 'NONEXISTENT')

    await waitFor(() => {
      expect(screen.getByText('No invoices found')).toBeInTheDocument()
    })
  })

  it('updates search query as user types', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockInvoices } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByPlaceholderText(/Search by invoice number or partner/i)).toBeInTheDocument()
    })

    const searchInput = screen.getByPlaceholderText(/Search by invoice number or partner/i)
    await user.type(searchInput, 'INV-00001')

    expect((searchInput as HTMLInputElement).value).toBe('INV-00001')
  })

  it('clears search when clear search button is clicked', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockInvoices } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByPlaceholderText(/Search by invoice number or partner/i)).toBeInTheDocument()
    })

    const searchInput = screen.getByPlaceholderText(/Search by invoice number or partner/i)
    await user.type(searchInput, 'test')

    expect((searchInput as HTMLInputElement).value).toBe('test')

    // Find and click the clear button in the search input
    const clearButtons = screen.getAllByRole('button')
    const searchClearButton = clearButtons.find(btn =>
      btn.querySelector('svg') && btn.className.includes('absolute')
    )

    await user.click(searchClearButton!)

    expect((searchInput as HTMLInputElement).value).toBe('')
  })
})
