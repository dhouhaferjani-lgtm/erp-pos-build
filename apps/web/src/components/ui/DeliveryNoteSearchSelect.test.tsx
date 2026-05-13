import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { DeliveryNoteSearchSelect } from './DeliveryNoteSearchSelect'
import * as api from '../../lib/api'
import { makeDeliveryNote } from '@/features/documents/__fixtures__/deliveryNote'

// Mock the API
vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}))

/**
 * Translation mock.
 *
 * The underlying component uses `t('common:actions.select') || 'Select'`
 * as a self-fallback — so when the mock returns `key`, the `||` falls
 * through to the literal key (truthy) and never to the component fallback.
 * This map pins a small subset of keys to their expected English values.
 */
const i18nMap: Record<string, string> = {
  'common:actions.select': 'Select',
  'common:unknown': 'Unknown',
  'common:status.loading': 'Loading...',
  'common:loading': 'Loading...',
  'sales:lineItems.title': 'Line Items',
  'sales:lineItems.quantity': 'Items',
  'sales:deliveryNotes.searchPlaceholder':
    'Search by delivery note number or partner...',
  'sales:deliveryNotes.noResults': 'No delivery notes found',
  'sales:deliveryNotes.noConfirmed': 'No confirmed delivery notes available',
}

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => i18nMap[key] ?? fallback ?? key,
  }),
}))

const mockDeliveryNotes = [
  makeDeliveryNote({
    id: '1',
    document_number: 'DN-001',
    document_date: '2024-01-15',
    partner: { id: 'p1', name: 'ACME Corp' },
    total: '1500.00',
    status: 'confirmed',
    lines: [
      { id: 'l1', product_code: 'PROD-1', quantity: 5 },
      { id: 'l2', product_code: 'PROD-2', quantity: 3 },
    ],
  }),
  makeDeliveryNote({
    id: '2',
    document_number: 'DN-002',
    document_date: '2024-01-20',
    partner: { id: 'p2', name: 'Tech Solutions' },
    total: '2500.50',
    status: 'confirmed',
    lines: [
      { id: 'l3', product_code: 'PROD-3', quantity: 10 },
    ],
  }),
]

describe('DeliveryNoteSearchSelect', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  const renderComponent = (props = {}) => {
    return renderWithProviders(<DeliveryNoteSearchSelect onChange={vi.fn()} {...props} />)
  }

  it('renders with label when provided', () => {
    renderComponent({ label: 'Select Delivery Note' })
    expect(screen.getByText('Select Delivery Note')).toBeInTheDocument()
  })

  it('shows required indicator when required', () => {
    renderComponent({ label: 'Delivery Note', required: true })
    expect(screen.getByText('*')).toBeInTheDocument()
  })

  it('displays placeholder when no value selected', () => {
    renderComponent()
    expect(screen.getByText('Select')).toBeInTheDocument()
  })

  it('opens dropdown on click', async () => {
    const user = userEvent.setup()
    vi.mocked(api.api.get).mockResolvedValue({ data: { data: mockDeliveryNotes } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByPlaceholderText(/search by delivery note/i)).toBeInTheDocument()
    })
  })

  it('fetches and displays delivery notes when dropdown opens', async () => {
    const user = userEvent.setup()
    vi.mocked(api.api.get).mockResolvedValue({ data: { data: mockDeliveryNotes } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(api.api.get).toHaveBeenCalledWith(expect.stringContaining('/delivery-notes'))
      expect(screen.getByText('DN-001')).toBeInTheDocument()
      expect(screen.getByText('DN-002')).toBeInTheDocument()
    })
  })

  it('filters delivery notes by search query', async () => {
    const user = userEvent.setup()
    vi.mocked(api.api.get).mockResolvedValue({ data: { data: mockDeliveryNotes } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByText('DN-001')).toBeInTheDocument()
    })

    const searchInput = screen.getByPlaceholderText(/search by delivery note/i)
    await user.type(searchInput, 'ACME')

    await waitFor(() => {
      expect(screen.getByText('ACME Corp')).toBeInTheDocument()
      expect(screen.queryByText('Tech Solutions')).not.toBeInTheDocument()
    })
  })

  it('calls onChange when delivery note is selected', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    vi.mocked(api.api.get).mockResolvedValue({ data: { data: mockDeliveryNotes } })

    renderComponent({ onChange })

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByText('DN-001')).toBeInTheDocument()
    })

    const option = screen.getByText('DN-001')
    await user.click(option)

    expect(onChange).toHaveBeenCalledWith(mockDeliveryNotes[0])
  })

  it('displays selected delivery note', () => {
    renderComponent({ value: mockDeliveryNotes[0] })

    // Trigger renders a formatted summary string combining the doc number,
    // partner name, and counts: "DN-001 - ACME Corp - 2024-01-15 (…)"
    expect(screen.getByText(/DN-001/)).toBeInTheDocument()
    expect(screen.getByText(/ACME Corp/)).toBeInTheDocument()
  })

  it('shows clear button when value is selected', () => {
    renderComponent({ value: mockDeliveryNotes[0] })

    // The trigger is now a `<div role="button">`; the clear affordance is
    // the only real `<button>` in the DOM when a value is selected.
    const clearButton = screen.getByRole('button', { name: /clear selection/i })
    expect(clearButton).toBeInTheDocument()
  })

  it('clears selection when clear button is clicked', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()

    renderComponent({ value: mockDeliveryNotes[0], onChange })

    // Find the X button (last button that's not the main button)
    const buttons = screen.getAllByRole('button')
    const clearButton = buttons[buttons.length - 1]

    await user.click(clearButton)

    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('can be disabled', () => {
    renderComponent({ disabled: true })

    // The trigger is a div with `role="button"`; the disabled affordance is
    // `aria-disabled="true"` plus `tabIndex=-1`, since div elements cannot
    // carry the native disabled attribute.
    const button = screen.getByRole('button')
    expect(button).toHaveAttribute('aria-disabled', 'true')
    expect(button).toHaveAttribute('tabindex', '-1')
  })

  it('shows loading state', async () => {
    const user = userEvent.setup()
    vi.mocked(api.api.get).mockImplementation(
      () => new Promise((resolve) => setTimeout(() => { resolve({ data: { data: [] } }); }, 100))
    )

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByText('Loading...')).toBeInTheDocument()
    })
  })

  it('shows empty state when no results', async () => {
    const user = userEvent.setup()
    vi.mocked(api.api.get).mockResolvedValue({ data: { data: [] } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByText('No confirmed delivery notes available')).toBeInTheDocument()
    })
  })

  it('shows no results message when search has no matches', async () => {
    const user = userEvent.setup()
    vi.mocked(api.api.get).mockResolvedValue({ data: { data: mockDeliveryNotes } })

    renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    const searchInput = await screen.findByPlaceholderText(/search by delivery note/i)
    await user.type(searchInput, 'NONEXISTENT')

    await waitFor(() => {
      expect(screen.getByText('No delivery notes found')).toBeInTheDocument()
    })
  })

  it('filters by partner_id when provided', async () => {
    const user = userEvent.setup()
    vi.mocked(api.api.get).mockResolvedValue({ data: { data: mockDeliveryNotes } })

    renderComponent({ partnerId: 'p1' })

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(api.api.get).toHaveBeenCalledWith(expect.stringContaining('partner_id=p1'))
    })
  })

  it('displays error message when provided', () => {
    renderComponent({ error: 'Delivery note is required' })

    expect(screen.getByText('Delivery note is required')).toBeInTheDocument()
  })

  it('applies error styling when error is present', () => {
    renderComponent({ error: 'Error' })

    const button = screen.getByRole('button')
    expect(button.className).toContain('border-red-300')
  })

  it('shows item count in selected delivery note', () => {
    renderComponent({ value: mockDeliveryNotes[0] })

    // 2 line items with total quantity of 8 (5 + 3)
    expect(screen.getByText(/2.*Line Items.*8.*Items/i)).toBeInTheDocument()
  })

  it('closes dropdown on click outside', async () => {
    const user = userEvent.setup()
    vi.mocked(api.api.get).mockResolvedValue({ data: { data: mockDeliveryNotes } })

    const { container } = renderComponent()

    const button = screen.getByRole('button')
    await user.click(button)

    await waitFor(() => {
      expect(screen.getByPlaceholderText(/search by delivery note/i)).toBeInTheDocument()
    })

    // Click outside
    await user.click(container)

    await waitFor(() => {
      expect(screen.queryByPlaceholderText(/search by delivery note/i)).not.toBeInTheDocument()
    })
  })
})
