import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { DeliveryNoteSearchSelect } from './DeliveryNoteSearchSelect'
import * as api from '../../lib/api'

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

const mockDeliveryNotes = [
  {
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
  },
  {
    id: '2',
    document_number: 'DN-002',
    document_date: '2024-01-20',
    partner: { id: 'p2', name: 'Tech Solutions' },
    total: '2500.50',
    status: 'confirmed',
    lines: [
      { id: 'l3', product_code: 'PROD-3', quantity: 10 },
    ],
  },
]

describe('DeliveryNoteSearchSelect', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
      },
    })
    vi.clearAllMocks()
  })

  const renderComponent = (props = {}) => {
    return render(
      <QueryClientProvider client={queryClient}>
        <DeliveryNoteSearchSelect onChange={vi.fn()} {...props} />
      </QueryClientProvider>
    )
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

    expect(screen.getByText('DN-001')).toBeInTheDocument()
    expect(screen.getByText(/ACME Corp/)).toBeInTheDocument()
  })

  it('shows clear button when value is selected', () => {
    const { container } = renderComponent({ value: mockDeliveryNotes[0] })

    // Clear button is an X icon
    const clearButtons = container.querySelectorAll('button')
    expect(clearButtons.length).toBeGreaterThan(1) // Main button + clear button
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

    const button = screen.getByRole('button')
    expect(button).toBeDisabled()
  })

  it('shows loading state', async () => {
    const user = userEvent.setup()
    vi.mocked(api.api.get).mockImplementation(
      () => new Promise((resolve) => setTimeout(() => resolve({ data: { data: [] } }), 100))
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
