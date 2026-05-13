import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter } from 'react-router-dom'
import { seedAuth } from '@/test/seedAuth'
import { PriceListListPage } from './PriceListListPage'
import { PriceListForm } from './PriceListForm'
import { PriceListDetailPage } from './PriceListDetailPage'

// Mock the API
const { mockApiGet, mockApiPost, mockApiPatch, mockApiDelete } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
  mockApiPost: vi.fn(),
  mockApiPatch: vi.fn(),
  mockApiDelete: vi.fn(),
}))

vi.mock('../../lib/api', () => ({
  apiGet: mockApiGet,
  apiPost: mockApiPost,
  apiPatch: mockApiPatch,
  apiDelete: mockApiDelete,
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

// Mock useParams for detail page
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: 'price-list-1' }),
  }
})

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

const mockPriceLists = [
  {
    id: 'price-list-1',
    code: 'RETAIL',
    name: 'Retail Prices',
    description: 'Standard retail pricing',
    currency: 'TND',
    is_active: true,
    is_default: true,
    valid_from: '2025-01-01',
    valid_until: null,
    items_count: 25,
    created_at: '2025-01-01T10:00:00Z',
  },
  {
    id: 'price-list-2',
    code: 'WHOLESALE',
    name: 'Wholesale Prices',
    description: 'Pricing for wholesalers',
    currency: 'TND',
    is_active: true,
    is_default: false,
    valid_from: '2025-01-01',
    valid_until: '2025-12-31',
    items_count: 50,
    created_at: '2025-01-02T10:00:00Z',
  },
  {
    id: 'price-list-3',
    code: 'VIP',
    name: 'VIP Prices',
    description: 'Special pricing for VIP customers',
    currency: 'TND',
    is_active: false,
    is_default: false,
    valid_from: null,
    valid_until: null,
    items_count: 10,
    created_at: '2025-01-03T10:00:00Z',
  },
]

const mockPriceListDetail = {
  id: 'price-list-1',
  code: 'RETAIL',
  name: 'Retail Prices',
  description: 'Standard retail pricing',
  currency: 'TND',
  is_active: true,
  is_default: true,
  valid_from: '2025-01-01',
  valid_until: null,
  items: [
    {
      id: 'item-1',
      product_id: 'prod-1',
      product_name: 'Brake Pads',
      product_sku: 'BP-001',
      price: '45.00',
      min_quantity: 1,
      max_quantity: 9,
    },
    {
      id: 'item-2',
      product_id: 'prod-1',
      product_name: 'Brake Pads',
      product_sku: 'BP-001',
      price: '40.00',
      min_quantity: 10,
      max_quantity: null,
    },
    {
      id: 'item-3',
      product_id: 'prod-2',
      product_name: 'Oil Filter',
      product_sku: 'OF-001',
      price: '12.50',
      min_quantity: 1,
      max_quantity: null,
    },
  ],
  partners: [
    { id: 'partner-1', name: 'ACME Corp' },
    { id: 'partner-2', name: 'Best Auto' },
  ],
  created_at: '2025-01-01T10:00:00Z',
  updated_at: '2025-01-15T10:00:00Z',
}

describe('Pricing Module', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
  })

  describe('PriceListListPage', () => {
    it('renders the price list page with title', () => {
      mockApiGet.mockResolvedValue({ data: [], meta: { total: 0 } })

      render(<PriceListListPage />, { wrapper: TestWrapper })

      expect(screen.getByRole('heading', { name: /price lists/i })).toBeInTheDocument()
    })

    it('displays loading state initially', () => {
      mockApiGet.mockImplementation(
        () => new Promise((resolve) => setTimeout(resolve, 1000))
      )

      render(<PriceListListPage />, { wrapper: TestWrapper })

      expect(screen.getByText(/loading/i)).toBeInTheDocument()
    })

    it('displays list of price lists', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceLists, meta: { total: 3 } })

      render(<PriceListListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('RETAIL')).toBeInTheDocument()
        expect(screen.getByText('WHOLESALE')).toBeInTheDocument()
        expect(screen.getByText('VIP')).toBeInTheDocument()
      })
    })

    it('displays empty state when no price lists', async () => {
      mockApiGet.mockResolvedValue({ data: [], meta: { total: 0 } })

      render(<PriceListListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText(/no price lists/i)).toBeInTheDocument()
      })
    })

    it('has a button to create new price list', () => {
      mockApiGet.mockResolvedValue({ data: [], meta: { total: 0 } })

      render(<PriceListListPage />, { wrapper: TestWrapper })

      expect(screen.getByRole('link', { name: /create price list/i })).toBeInTheDocument()
    })

    it('displays price list status badges', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceLists, meta: { total: 3 } })

      render(<PriceListListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        // Active lists should show "Active", inactive should show "Inactive"
        expect(screen.getAllByText('Active').length).toBeGreaterThan(0)
        expect(screen.getByText('Inactive')).toBeInTheDocument()
      })
    })

    it('displays items count for each price list', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceLists, meta: { total: 3 } })

      render(<PriceListListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText(/25 items/i)).toBeInTheDocument()
        expect(screen.getByText(/50 items/i)).toBeInTheDocument()
      })
    })

    it('shows default badge for default price list', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceLists, meta: { total: 3 } })

      render(<PriceListListPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('Default')).toBeInTheDocument()
      })
    })
  })

  describe('PriceListForm', () => {
    // Note: useParams is mocked to return { id: 'price-list-1' } so we're testing edit mode
    // We need to mock the API to return existing data for the form to render
    const mockExistingPriceList = {
      id: 'price-list-1',
      code: 'RETAIL',
      name: 'Retail Prices',
      description: 'Standard retail pricing',
      currency: 'TND',
      is_active: true,
      is_default: true,
      valid_from: '2025-01-01',
      valid_until: null,
      items: [],
      partners: [],
      created_at: '2025-01-01T10:00:00Z',
      updated_at: '2025-01-15T10:00:00Z',
    }

    it('renders price list form with required fields', async () => {
      mockApiGet.mockResolvedValue({ data: mockExistingPriceList })

      render(<PriceListForm />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByLabelText(/code/i)).toBeInTheDocument()
        expect(screen.getByLabelText(/name/i)).toBeInTheDocument()
        expect(screen.getByLabelText(/currency/i)).toBeInTheDocument()
        expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument()
      })
    })

    it('shows validation errors for required fields', async () => {
      mockApiGet.mockResolvedValue({ data: mockExistingPriceList })
      const user = userEvent.setup()

      render(<PriceListForm />, { wrapper: TestWrapper })

      // Wait for form to load
      await waitFor(() => {
        expect(screen.getByLabelText(/code/i)).toBeInTheDocument()
      })

      // Clear the required fields and submit
      const codeInput = screen.getByLabelText(/code/i)
      const nameInput = screen.getByLabelText(/name/i)
      await user.clear(codeInput)
      await user.clear(nameInput)

      const submitButton = screen.getByRole('button', { name: /save/i })
      await user.click(submitButton)

      await waitFor(() => {
        expect(screen.getByText(/code is required/i)).toBeInTheDocument()
        expect(screen.getByText(/name is required/i)).toBeInTheDocument()
      })
    })

    it('has cancel button that navigates back', async () => {
      mockApiGet.mockResolvedValue({ data: mockExistingPriceList })

      render(<PriceListForm />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('link', { name: /cancel/i })).toBeInTheDocument()
      })
    })

    it('allows entering description', async () => {
      mockApiGet.mockResolvedValue({ data: mockExistingPriceList })
      const user = userEvent.setup()

      render(<PriceListForm />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByLabelText(/description/i)).toBeInTheDocument()
      })

      const descriptionInput = screen.getByLabelText(/description/i)
      await user.clear(descriptionInput)
      await user.type(descriptionInput, 'New test description')

      expect(descriptionInput).toHaveValue('New test description')
    })

    it('has validity date fields', async () => {
      mockApiGet.mockResolvedValue({ data: mockExistingPriceList })

      render(<PriceListForm />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByLabelText(/valid from/i)).toBeInTheDocument()
        expect(screen.getByLabelText(/valid until/i)).toBeInTheDocument()
      })
    })

    it('has active and default toggles', async () => {
      mockApiGet.mockResolvedValue({ data: mockExistingPriceList })

      render(<PriceListForm />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByLabelText(/active/i)).toBeInTheDocument()
        expect(screen.getByLabelText(/default/i)).toBeInTheDocument()
      })
    })
  })

  describe('PriceListDetailPage', () => {
    it('renders the price list detail page', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceListDetail })

      render(<PriceListDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('Retail Prices')).toBeInTheDocument()
      })
    })

    it('displays loading state initially', () => {
      mockApiGet.mockImplementation(
        () => new Promise((resolve) => setTimeout(resolve, 1000))
      )

      render(<PriceListDetailPage />, { wrapper: TestWrapper })

      expect(screen.getByText(/loading/i)).toBeInTheDocument()
    })

    it('displays price list items table', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceListDetail })

      render(<PriceListDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText('Brake Pads')).toBeInTheDocument()
        expect(screen.getByText('Oil Filter')).toBeInTheDocument()
      })
    })

    it('shows quantity breaks for products', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceListDetail })

      render(<PriceListDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        // Brake Pads has two price tiers - prices are formatted with currency
        expect(screen.getByText(/45\.00/)).toBeInTheDocument()
        expect(screen.getByText(/40\.00/)).toBeInTheDocument()
      })
    })

    it('has button to add item', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceListDetail })

      render(<PriceListDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /add item/i })).toBeInTheDocument()
      })
    })

    it('displays assigned partners section', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceListDetail })

      render(<PriceListDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByText(/assigned partners/i)).toBeInTheDocument()
        expect(screen.getByText('ACME Corp')).toBeInTheDocument()
        expect(screen.getByText('Best Auto')).toBeInTheDocument()
      })
    })

    it('has button to assign partner', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceListDetail })

      render(<PriceListDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('button', { name: /assign partner/i })).toBeInTheDocument()
      })
    })

    it('has edit button', async () => {
      mockApiGet.mockResolvedValue({ data: mockPriceListDetail })

      render(<PriceListDetailPage />, { wrapper: TestWrapper })

      await waitFor(() => {
        expect(screen.getByRole('link', { name: /edit/i })).toBeInTheDocument()
      })
    })
  })
})
