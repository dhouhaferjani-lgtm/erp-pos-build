import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CreateStockTransferPage } from '../pages/CreateStockTransferPage'
import type { ProductPickerValue } from '@/components/molecules/pickers/ProductPicker'
import type { LocationApiResponse } from '@/features/locations/api'

const mockFetchLocations = vi.fn<() => Promise<LocationApiResponse[]>>()
const mockMutateAsync = vi.fn()

vi.mock('@/features/locations/api', async () => {
  const actual = await vi.importActual<typeof import('@/features/locations/api')>('@/features/locations/api')
  return {
    ...actual,
    fetchLocations: () => mockFetchLocations(),
    fetchTransactionLocations: () => mockFetchLocations(),
  }
})

vi.mock('@/hooks/useCurrency', () => ({
  getDecimals: () => 2,
  useCurrency: () => ({ currency: 'EUR' }),
}))

vi.mock('../api/queries', () => ({
  useCreateStockTransfer: () => ({
    mutateAsync: mockMutateAsync,
    isPending: false,
  }),
}))

const pickerProducts: ProductPickerValue[] = [
  {
    id: 'prod-piece',
    sku: 'PCS-001',
    name: 'Piece item',
    quantity_decimals: 0,
  },
  {
    id: 'prod-weight',
    sku: 'KG-001',
    name: 'Weighted item',
    quantity_decimals: 3,
  },
]

vi.mock('@/components/molecules/pickers/ProductPicker', () => ({
  ProductPicker: ({
    value,
    onChange,
  }: {
    value: ProductPickerValue | null
    onChange: (next: ProductPickerValue | null) => void
  }) => (
    <div>
      {value ? (
        <span>{`${value.sku} ${value.name}`}</span>
      ) : (
        pickerProducts.map((product) => (
          <button
            key={product.id}
            type="button"
            onClick={() => {
              onChange(product)
            }}
          >
            {`Select ${product.name}`}
          </button>
        ))
      )}
    </div>
  ),
}))

const locations: LocationApiResponse[] = [
  {
    id: 'loc-source',
    company_id: 'company-1',
    name: 'Main Warehouse',
    code: 'MAIN',
    type: 'warehouse',
    phone: null,
    email: null,
    address_street: null,
    address_city: null,
    address_postal_code: null,
    address_country: null,
    tax_id: null,
    vat_number: null,
    legal_identifiers: null,
    is_default: true,
    is_active: true,
    pos_enabled: false,
    onboarding_mode: false,
    pos_stock_policy_override: null,
    created_at: '2026-06-05T00:00:00Z',
    updated_at: '2026-06-05T00:00:00Z',
  },
]

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <CreateStockTransferPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CreateStockTransferPage quantity scale', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchLocations.mockResolvedValue(locations)
  })

  it('uses the selected product quantity decimals to drive the quantity step', async () => {
    const user = userEvent.setup()
    renderPage()

    await waitFor(() => {
      expect(screen.getAllByRole('option', { name: 'Main Warehouse' }).length).toBeGreaterThan(0)
    })
    const quantityInput = screen.getByRole('spinbutton', { name: 'Quantity' })
    expect(quantityInput).toHaveAttribute('step', '0.0001')

    await user.click(screen.getByRole('button', { name: 'Select Piece item' }))

    expect(quantityInput).toHaveAttribute('step', '1')
  })
})
