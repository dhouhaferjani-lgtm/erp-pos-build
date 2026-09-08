/* eslint-disable @typescript-eslint/unbound-method */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter } from 'react-router-dom'
import { ProductMovementsTab } from '../ProductMovementsTab'

// Mock the api module
vi.mock('../../../../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}))

vi.mock('../../../../stores/authStore', () => {
  const authState = { user: { tenant_id: 'tenant-A' } }
  const useAuthStore = Object.assign(
    (selector: (state: typeof authState) => unknown) => selector(authState),
    { getState: () => authState },
  )
  return { useAuthStore }
})

vi.mock('../../../../stores/companyStore', () => {
  const companyState = { currentCompanyId: 'company-1' }
  const useCompanyStore = Object.assign(
    (selector: (state: typeof companyState) => unknown) => selector(companyState),
    { getState: () => companyState },
  )
  return { useCompanyStore }
})

// Mock LocationSelectorMulti to simplify testing
vi.mock('../../../locations/components/LocationSelectorMulti', () => ({
  LocationSelectorMulti: ({
    value,
    onChange,
    label,
  }: {
    value: string[]
    onChange: (ids: string[]) => void
    label: string
  }) => (
    <div data-testid="location-selector">
      <label>{label}</label>
      <button
        data-testid="select-location"
        onClick={() => { onChange(['loc-1']); }}
      >
        Select Location
      </button>
      <button
        data-testid="clear-location"
        onClick={() => { onChange([]); }}
      >
        Clear
      </button>
      <span data-testid="selected-count">{value.length}</span>
    </div>
  ),
}))

import { api } from '../../../../lib/api'

const mockMovements = [
  {
    id: 'mov-1',
    product_id: 'prod-1',
    product_name: 'Test Product',
    location_id: 'loc-1',
    location_name: 'Warehouse A',
    movement_type: 'receipt',
    quantity: '10',
    quantity_decimals: 3,
    quantity_before: '0',
    quantity_after: '10',
    reference: 'PO-2024-001',
    source_document_id: 'po-1',
    source_document_type: 'purchase_order',
    notes: null,
    user_id: 'user-1',
    user_name: 'John Doe',
    created_at: '2024-01-15T10:00:00Z',
  },
  {
    id: 'mov-2',
    product_id: 'prod-1',
    product_name: 'Test Product',
    location_id: 'loc-2',
    location_name: 'Warehouse B',
    movement_type: 'issue',
    quantity: '-5',
    quantity_decimals: 3,
    quantity_before: '10',
    quantity_after: '5',
    reference: 'INV-2024-001',
    source_document_id: 'inv-1',
    source_document_type: 'invoice',
    notes: 'Sale',
    user_id: 'user-1',
    user_name: 'John Doe',
    created_at: '2024-01-16T14:30:00Z',
  },
  {
    id: 'mov-3',
    product_id: 'prod-1',
    product_name: 'Test Product',
    location_id: 'loc-1',
    location_name: 'Warehouse A',
    movement_type: 'adjustment',
    quantity: '2',
    quantity_decimals: 3,
    quantity_before: '5',
    quantity_after: '7',
    reference: 'ADJ-001',
    source_document_id: null,
    source_document_type: null,
    notes: 'Inventory correction',
    user_id: 'user-1',
    user_name: 'John Doe',
    created_at: '2024-01-17T09:00:00Z',
  },
]

function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        gcTime: 0,
      },
    },
  })
}

function renderWithProviders(ui: React.ReactElement) {
  const queryClient = createTestQueryClient()
  return render(
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>{ui}</BrowserRouter>
    </QueryClientProvider>
  )
}

describe('ProductMovementsTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders loading state while fetching', () => {
    vi.mocked(api.get).mockReturnValue(new Promise(() => {}) as never) // Never resolves

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('renders empty state when no movements', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [] } })

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    await waitFor(() => {
      expect(screen.getByText(/no movements/i)).toBeInTheDocument()
    })
  })

  it('renders movement list with correct data', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockMovements } })

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    await waitFor(() => {
      // Check that movement data is rendered (locations appear multiple times so use getAllByText)
      const warehouseAElements = screen.getAllByText('Warehouse A')
      expect(warehouseAElements.length).toBeGreaterThan(0)
      expect(screen.getByText('Warehouse B')).toBeInTheDocument()
    })

    const issueRow = screen.getByText('INV-2024-001').closest('tr')
    expect(issueRow).not.toBeNull()
    if (issueRow === null) throw new Error('Expected issue movement row')

    expect(within(issueRow).getByText('-5.000')).toBeInTheDocument()
    expect(within(issueRow).getByText('10.000')).toBeInTheDocument()
    expect(within(issueRow).getByText('5.000')).toBeInTheDocument()
  })

  it('renders movement type badges correctly', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockMovements } })

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    await waitFor(() => {
      expect(screen.getByText('Receipt')).toBeInTheDocument()
      expect(screen.getByText('Issue')).toBeInTheDocument()
      expect(screen.getByText('Adjustment')).toBeInTheDocument()
    })
  })

  it('renders reference links for known document types', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockMovements } })

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    await waitFor(() => {
      // PO reference should be a link
      const poLink = screen.getByRole('link', { name: 'PO-2024-001' })
      expect(poLink).toHaveAttribute(
        'href',
        '/purchases/orders/po-1'
      )

      // INV reference should be a link
      const invLink = screen.getByRole('link', { name: 'INV-2024-001' })
      expect(invLink).toHaveAttribute(
        'href',
        '/sales/invoices/inv-1'
      )
    })
  })

  it('requests the selected server page when pagination is used', async () => {
    vi.mocked(api.get)
      .mockResolvedValueOnce({
        data: {
          data: mockMovements.slice(0, 1),
          meta: { current_page: 1, last_page: 2, per_page: 1, total: 2, from: 1, to: 1 },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: mockMovements.slice(1, 2),
          meta: { current_page: 2, last_page: 2, per_page: 1, total: 2, from: 2, to: 2 },
        },
      })
    const user = userEvent.setup()

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    await waitFor(() => {
      expect(screen.getByText('Warehouse A')).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /next/i }))

    await waitFor(() => {
      expect(api.get).toHaveBeenLastCalledWith(expect.stringContaining('page=2'))
    })
  })

  it('renders non-link reference for unknown document types', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockMovements } })

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    await waitFor(() => {
      // ADJ reference should not be a link
      expect(screen.getByText('ADJ-001')).toBeInTheDocument()
      expect(screen.queryByRole('link', { name: 'ADJ-001' })).not.toBeInTheDocument()
    })
  })

  it('shows location filter button', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockMovements } })

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    await waitFor(() => {
      expect(
        screen.getByRole('button', { name: /filter by location/i })
      ).toBeInTheDocument()
    })
  })

  it('toggles location filter panel when button clicked', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockMovements } })
    const user = userEvent.setup()

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /filter by location/i })).toBeInTheDocument()
    })

    // Location selector should not be visible initially
    expect(screen.queryByTestId('location-selector')).not.toBeInTheDocument()

    // Click filter button
    await user.click(screen.getByRole('button', { name: /filter by location/i }))

    // Location selector should now be visible
    expect(screen.getByTestId('location-selector')).toBeInTheDocument()
  })

  it('renders error state on API failure', async () => {
    vi.mocked(api.get).mockRejectedValue(new Error('API Error'))

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    await waitFor(() => {
      // Match the actual error text from translation
      expect(screen.getByText(/failed to load data/i)).toBeInTheDocument()
    })
  })

  it('displays correct movement count in subtitle', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: mockMovements } })

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    await waitFor(() => {
      // Should show count of 3 movements in subtitle
      expect(
        screen.getByText((content) => content.includes('3') && content.toLowerCase().includes('movement'))
      ).toBeInTheDocument()
    })
  })

  // QA-BUG-09 / DEV-QA-077: the second movements surface must link a count
  // movement to its counting, exactly like StockMovementsPage does.
  it('links a count movement to its counting, labelled with the CNT number', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        data: [
          {
            id: 'mov-count',
            product_id: 'prod-1',
            product_name: 'Test Product',
            location_id: 'loc-1',
            location_name: 'Warehouse A',
            movement_type: 'adjustment',
            reason: 'count_correction',
            quantity: '-2',
            quantity_decimals: 3,
            quantity_before: '70',
            quantity_after: '68',
            reference: 'CNT-2026-0010',
            reference_type: 'inventory_counting',
            reference_id: 'counting-1',
            source_document_id: 'counting-1',
            source_document_type: 'inventory_counting',
            notes: null,
            user_id: 'user-1',
            user_name: 'John Doe',
            reverses_movement_id: null,
            is_reversed: false,
            created_at: '2026-09-01T10:00:00Z',
          },
        ],
      },
    })

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    const link = await screen.findByRole('link', { name: 'CNT-2026-0010' })
    expect(link).toHaveAttribute('href', '/inventory/counting/counting-1')
  })

  // Mirrors StockMovementsPage.countingLink.test.tsx's "renders the reference as
  // plain text when no source document is resolved" — the two movements surfaces
  // drifted before this fix, so both must carry the same negative (gate r1 F-5).
  it('renders a counting movement as plain text when no source document is resolved', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        data: [
          {
            id: 'mov-count-unlinked',
            product_id: 'prod-1',
            product_name: 'Test Product',
            location_id: 'loc-1',
            location_name: 'Warehouse A',
            movement_type: 'adjustment',
            reason: 'count_correction',
            quantity: '-2',
            quantity_decimals: 3,
            quantity_before: '70',
            quantity_after: '68',
            reference: 'COUNT_REPLAY',
            reference_type: 'inventory_counting',
            reference_id: null,
            source_document_id: null,
            source_document_type: null,
            notes: null,
            user_id: 'user-1',
            user_name: 'John Doe',
            reverses_movement_id: null,
            is_reversed: false,
            created_at: '2026-09-01T10:00:00Z',
          },
        ],
      },
    })

    renderWithProviders(<ProductMovementsTab productId="prod-1" />)

    expect(await screen.findByText('COUNT_REPLAY')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'COUNT_REPLAY' })).not.toBeInTheDocument()
  })

  it('passes correct product_id to API', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [] } })

    renderWithProviders(<ProductMovementsTab productId="test-product-123" />)

    await waitFor(() => {
      expect(api.get).toHaveBeenCalledWith(
        expect.stringContaining('product_id=test-product-123')
      )
    })
  })
})
