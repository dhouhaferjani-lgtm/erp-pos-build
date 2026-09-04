import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CreateStockTransferPage } from '../pages/CreateStockTransferPage'
import type { ProductPickerValue } from '@/components/molecules/pickers/ProductPicker'
import type { LocationApiResponse } from '@/features/locations/api'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const mockFetchLocations = vi.fn<() => Promise<LocationApiResponse[]>>()
const mockApiGet = vi.fn()

vi.mock('@/features/locations/api', async () => {
  const actual = await vi.importActual<typeof import('@/features/locations/api')>('@/features/locations/api')
  return {
    ...actual,
    fetchLocations: () => mockFetchLocations(),
    fetchTransactionLocations: () => mockFetchLocations(),
  }
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
// A SECOND DISTINCT product: Task 7 only dedupes consumers of the SAME
// product/variant. The distinct-product fan-out must survive (one request each)
// or the shared hook has over-deduplicated.
const secondProduct: ProductPickerValue = { id: 'prod-2', sku: 'SKU-2', name: 'Second item', quantity_decimals: 0 }

vi.mock('@/components/molecules/pickers/ProductPicker', () => ({
  ProductPicker: ({ value, onChange }: { value: ProductPickerValue | null; onChange: (v: ProductPickerValue | null) => void }) =>
    value ? <span>{`${value.sku} ${value.name}`}</span> : (
      <>
        <button type="button" onClick={() => { onChange(product) }}>Select Test item</button>
        <button type="button" onClick={() => { onChange(secondProduct) }}>Select Second item</button>
      </>
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

/**
 * Only stock-level URLs. Selecting a product also hits /products/:id/variants and
 * /products/:id/batch-stock, so counting all apiGet traffic would be meaningless —
 * and a bare count could pass by SUPPRESSING traffic, hence the exact URL list.
 */
function stockLevelUrls(): string[] {
  const urls: string[] = []
  for (const call of mockApiGet.mock.calls) {
    const url: unknown = call[0]
    if (typeof url === 'string' && /^\/products\/[^/]+\/stock-levels$/.test(url)) urls.push(url)
  }
  return urls
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
    // The shared stock-level hook stays disabled until both scopes exist.
    useAuthStore.setState({
      user: {
        id: 'user-1', name: 'Test User', email: 'test@example.com',
        tenant_id: 'tenant-1', roles: [], email_verified_at: null,
      },
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1' })
    mockFetchLocations.mockResolvedValue([loc('loc-source', 'Source WH'), loc('loc-dest', 'Dest WH')])
    mockApiGet.mockResolvedValue({ locations: [{ location_id: 'loc-source', available: '5.0000' }] })
  })

  afterEach(() => {
    act(() => {
      useAuthStore.setState({ user: null })
      useCompanyStore.setState({ currentCompanyId: null })
    })
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

    // S-6 partial (Task 7): AvailabilityCell and TransferSourceSuggestion request the
    // same product/variant and must share ONE stock-level query.
    expect(stockLevelUrls()).toEqual(['/products/prod-1/stock-levels'])

    const quantity = screen.getByRole('spinbutton', { name: 'Quantity' })
    await user.clear(quantity)
    await user.type(quantity, '9')

    await waitFor(() => {
      expect(screen.getByText('Exceeds source availability')).toBeInTheDocument()
    })
  })

  it('still issues one stock-level request per DISTINCT product (fan-out is B-8, not Task 7)', async () => {
    const user = userEvent.setup()
    renderPage()

    await waitFor(() => {
      expect(screen.getAllByRole('option', { name: 'Source WH' }).length).toBeGreaterThan(0)
    })
    await user.selectOptions(screen.getByRole('combobox', { name: /source location/i }), 'loc-source')

    await user.click(screen.getByRole('button', { name: 'Select Test item' }))
    await waitFor(() => {
      expect(stockLevelUrls()).toEqual(['/products/prod-1/stock-levels'])
    })

    await user.click(screen.getByRole('button', { name: 'Add line' }))
    await user.click(screen.getByRole('button', { name: 'Select Second item' }))

    // Two rows, two distinct products, two AvailabilityCell + two
    // TransferSourceSuggestion mounts => exactly TWO requests, not one and not four.
    // One request short would mean the hook over-deduplicated across products.
    await waitFor(() => {
      expect(stockLevelUrls()).toEqual([
        '/products/prod-1/stock-levels',
        '/products/prod-2/stock-levels',
      ])
    })

    // Plan Step 5 arm (a): a THIRD row repeating the FIRST product must add no
    // request at all — same product/variant, so it reuses row 1's cache entry.
    await user.click(screen.getByRole('button', { name: 'Add line' }))
    await user.click(screen.getByRole('button', { name: 'Select Test item' }))
    await waitFor(() => {
      expect(screen.getAllByText('SKU-1 Test item')).toHaveLength(2)
    })
    expect(stockLevelUrls()).toEqual([
      '/products/prod-1/stock-levels',
      '/products/prod-2/stock-levels',
    ])
  })
})
