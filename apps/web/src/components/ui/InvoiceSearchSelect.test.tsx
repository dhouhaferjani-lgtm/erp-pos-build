import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth } from '@/test/seedAuth'
import { InvoiceSearchSelect } from './InvoiceSearchSelect'
import type { Invoice } from './InvoiceSearchSelect'
import { api } from '../../lib/api'
import { makeInvoice } from '@/features/documents/__fixtures__/invoice'

// Mock the API
vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}))

/**
 * Translation mock.
 *
 * `DocumentSearchSelect` calls `t('common:actions.select') || 'Select'`.
 * If the mock returns the key, the `||` fallback never fires because the
 * key is truthy. Pin the small subset of keys to their expected values.
 */
const i18nMap: Record<string, string> = {
  'common:actions.select': 'Select',
  'common:unknown': 'Unknown',
  'common:status.loading': 'Loading...',
  'common:loading': 'Loading...',
  'sales:invoices.searchPlaceholder':
    'Search by invoice number or partner name...',
  'sales:invoices.noResults': 'No invoices found',
  'sales:invoices.noPosted': 'No posted invoices available',
  'sales:balance': 'Balance',
}

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => i18nMap[key] ?? fallback ?? key,
  }),
}))

const mockInvoices: Invoice[] = [
  makeInvoice({
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
  }),
  makeInvoice({
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
  }),
]

describe('InvoiceSearchSelect', () => {
  const user = userEvent.setup()
  const onChangeMock = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
  })

  const renderComponent = (props = {}) => {
    return renderWithProviders(
      <InvoiceSearchSelect value={null} onChange={onChangeMock} {...props} />
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

    // The trigger is now a `div role="button"`; when a value is selected
    // the inner `<button aria-label="Clear selection">` is also present,
    // so `getByRole('button')` would be ambiguous. Narrow by name.
    const trigger = screen.getAllByRole('button').find(
      (el) => el.getAttribute('aria-label') !== 'Clear selection',
    )!
    expect(trigger).toHaveTextContent('INV-00001')
    expect(trigger).toHaveTextContent('ACME Corp')
    expect(trigger).toHaveTextContent('Balance')
  })

  it('clears selection when clear button is clicked', async () => {
    const selectedInvoice = mockInvoices[0]
    renderComponent({ value: selectedInvoice })

    const clearButton = screen.getByRole('button', { name: /clear selection/i })
    await user.click(clearButton)

    expect(onChangeMock).toHaveBeenCalledWith(null)
  })

  it('disables the component when disabled prop is true', () => {
    renderComponent({ disabled: true })

    // Trigger is a `div role="button"`; disabled is exposed as
    // `aria-disabled` + `tabindex="-1"`.
    const button = screen.getByRole('button')
    expect(button).toHaveAttribute('aria-disabled', 'true')
    expect(button).toHaveAttribute('tabindex', '-1')
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
