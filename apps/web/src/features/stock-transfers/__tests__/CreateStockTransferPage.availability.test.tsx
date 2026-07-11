import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CreateStockTransferPage } from '../pages/CreateStockTransferPage'
import type { ProductPickerValue } from '@/components/molecules/pickers/ProductPicker'
import type { LocationApiResponse } from '@/features/locations/api'

const mockFetchLocations = vi.fn<() => Promise<LocationApiResponse[]>>()
const mockApiGet = vi.fn()

vi.mock('@/features/locations/api', async () => {
  const actual = await vi.importActual<typeof import('@/features/locations/api')>('@/features/locations/api')
  return { ...actual, fetchLocations: () => mockFetchLocations() }
})

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, apiGet: (...args: unknown[]) => mockApiGet(...args) }
})

vi.mock('@/hooks/useCurrency', () => ({
  getDecimals: () => 2,
  useCurrency: () => ({ currency: 'EUR' }),
}))

vi.mock('../api/queries', () => ({
  useCreateStockTransfer: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

const product: ProductPickerValue = { id: 'prod-1', sku: 'SKU-1', name: 'Test item', quantity_decimals: 0 }

vi.mock('@/components/molecules/pickers/ProductPicker', () => ({
  ProductPicker: ({ value, onChange }: { value: ProductPickerValue | null; onChange: (v: ProductPickerValue | null) => void }) =>
    value ? <span>{`${value.sku} ${value.name}`}</span> : (
      <button type="button" onClick={() => { onChange(product) }}>Select Test item</button>
    ),
}))

function loc(id: string, name: string): LocationApiResponse {
  return {
    id, company_id: 'c1', name, code: id.toUpperCase(), type: 'warehouse', phone: null, email: null,
    address_street: null, address_city: null, address_postal_code: null, address_country: null,
    tax_id: null, vat_number: null, legal_identifiers: null,
    is_default: false, is_active: true, pos_enabled: false,
    onboarding_mode: false, pos_stock_policy_override: null,
    created_at: '2026-06-08T00:00:00Z', updated_at: '2026-06-08T00:00:00Z',
  }
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter><CreateStockTransferPage /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CreateStockTransferPage available-at-source column', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchLocations.mockResolvedValue([loc('loc-source', 'Source WH'), loc('loc-dest', 'Dest WH')])
    mockApiGet.mockResolvedValue({ locations: [{ location_id: 'loc-source', available: '5.0000' }] })
  })

  it('shows available stock at the source and warns when the quantity exceeds it', async () => {
    const user = userEvent.setup()
    renderPage()

    await waitFor(() => {
      expect(screen.getAllByRole('option', { name: 'Source WH' }).length).toBeGreaterThan(0)
    })
    await user.selectOptions(screen.getByRole('combobox', { name: /source location/i }), 'loc-source')
    await user.click(screen.getByRole('button', { name: 'Select Test item' }))

    await waitFor(() => {
      expect(screen.getByText('5.0000')).toBeInTheDocument()
    })

    const quantity = screen.getByRole('spinbutton', { name: 'Quantity' })
    await user.clear(quantity)
    await user.type(quantity, '9')

    await waitFor(() => {
      expect(screen.getByText('Exceeds source availability')).toBeInTheDocument()
    })
  })
})
