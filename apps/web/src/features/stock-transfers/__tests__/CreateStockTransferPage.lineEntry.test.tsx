import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { toast } from 'sonner'
import { CreateStockTransferPage } from '../pages/CreateStockTransferPage'
import type { CreateStockTransferInput } from '../types'
import type { ProductPickerValue } from '@/components/molecules/pickers/ProductPicker'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const mockCreate = vi.hoisted(() => vi.fn<(input: CreateStockTransferInput) => Promise<{ id: string }>>())
const mockFetchLocations = vi.hoisted(() => vi.fn())
// The unified LineItemEntryBar reads the product SEARCH via `api.get` (paginated,
// tenant-scoped) and resolves SCAN codes via `apiGet('/line-entry/resolve-code')`.
const mockApiGet = vi.hoisted(() => vi.fn<(...args: unknown[]) => unknown>())
const mockApiClientGet = vi.hoisted(() => vi.fn<(...args: unknown[]) => unknown>())
const mockUseProductBatches = vi.hoisted(() => vi.fn())
const mockUseProductVariants = vi.hoisted(() => vi.fn())

const BATCH_PRODUCT_ID = '11111111-1111-4111-8111-111111111111'
const PLAIN_PRODUCT_ID = '22222222-2222-4222-8222-222222222222'
const VARIANT_PRODUCT_ID = '33333333-3333-4333-8333-333333333333'
const VARIANT_ID = '44444444-4444-4444-8444-444444444444'

vi.mock('@/features/location/api', () => ({
  fetchLocations: mockFetchLocations,
}))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]): unknown => mockApiClientGet(...args) },
    apiGet: (...args: unknown[]): unknown => mockApiGet(...args),
  }
})

vi.mock('../api/queries', () => ({
  useCreateStockTransfer: () => ({
    mutateAsync: mockCreate,
    isPending: false,
  }),
}))

vi.mock('@/features/batches/hooks/useBatches', () => ({
  useProductBatches: mockUseProductBatches,
}))

vi.mock('@/features/catalog/hooks/useProductVariants', () => ({
  useProductVariants: mockUseProductVariants,
}))

vi.mock('@/components/molecules/pickers/ProductPicker', () => ({
  ProductPicker: ({
    value,
    onChange,
  }: {
    value: ProductPickerValue | null
    onChange: (next: ProductPickerValue | null) => void
  }) => (
    value === null ? (
      <button
        type="button"
        onClick={() => {
          onChange({
            id: PLAIN_PRODUCT_ID,
            sku: 'MANUAL-1',
            name: 'Manual add product',
            quantity_decimals: 0,
            requires_batch_tracking: false,
          })
        }}
      >
        Select manual product
      </button>
    ) : (
      <span>{`${value.sku} ${value.name}`}</span>
    )
  ),
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

function product(id: string, overrides: Partial<ProductPickerValue> = {}): ProductPickerValue {
  return {
    id,
    sku: 'PARA-LOT',
    name: 'Batch tracked product',
    quantity_decimals: 0,
    requires_batch_tracking: true,
    ...overrides,
  }
}

function batch(id: number, availableQuantity: string, expiryDate: string) {
  const idText = String(id)
  return {
    id,
    uuid: `batch-${idText}`,
    product_id: BATCH_PRODUCT_ID,
    batch_number: `LOT-${idText}`,
    expiry_date: expiryDate,
    expiry_status: 'OK',
    is_active: true,
    is_recalled: false,
    is_expired: false,
    can_be_sold: true,
    days_until_expiry: 100,
    available_quantity: 0,
    total_quantity: 0,
    batch_stock: [
      {
        location_id: 'source-location',
        quantity: availableQuantity,
        reserved_quantity: '0.0000',
        available_quantity: availableQuantity,
      },
    ],
  }
}

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

async function selectLocations(user: ReturnType<typeof userEvent.setup>) {
  await waitFor(() => {
    expect(screen.getAllByRole('option', { name: 'Main Warehouse' }).length).toBeGreaterThan(0)
  })
  await user.selectOptions(screen.getByLabelText(/source location/i), 'source-location')
  await user.selectOptions(screen.getByLabelText(/destination location/i), 'destination-location')
}

function scan(code: string): void {
  for (const char of code) {
    window.dispatchEvent(new KeyboardEvent('keydown', { key: char }))
  }
  window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))
}

describe('CreateStockTransferPage line entry bar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // The entry-bar product search is tenant/company gated (non-negotiable
    // tenant scoping); seed both stores so the search dropdown can open.
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Test User',
        email: 'test@example.com',
        tenant_id: 'tenant-A',
        roles: [],
        email_verified_at: null,
      },
      token: 'test-token',
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({
      currentCompanyId: 'company-1',
      companies: [
        {
          id: 'company-1',
          name: 'Test Company',
          legalName: 'Test Company LLC',
          taxId: null,
          countryCode: 'TN',
          currency: 'TND',
          locale: 'en_US',
          timezone: 'Africa/Tunis',
        },
      ],
      isLoading: false,
    })
    mockFetchLocations.mockResolvedValue([
      { id: 'source-location', name: 'Main Warehouse' },
      { id: 'destination-location', name: 'Downtown Shop' },
    ])
    mockCreate.mockResolvedValue({ id: 'transfer-1' })
    // Default product-search response for the entry bar (paginated envelope).
    mockApiClientGet.mockResolvedValue({ data: { data: [product(BATCH_PRODUCT_ID)] } })
    mockUseProductBatches.mockReturnValue({ data: [batch(101, '6.0000', '2026-08-31')], isLoading: false, isFetching: false })
    mockUseProductVariants.mockReturnValue({ data: [], isLoading: false })
  })

  afterEach(() => {
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  })

  it('rejects entry-bar scan/add when no source location is selected', async () => {
    const user = userEvent.setup()

    renderPage()

    const input = screen.getByRole('combobox', { name: /search or scan a product/i })
    await user.type(input, 'para')
    await screen.findByRole('option', { name: /PARA-LOT Batch tracked product/i })
    await user.keyboard('{Enter}')

    expect(toast.error).toHaveBeenCalledWith('Select a source location before adding transfer lines.')
    expect(screen.queryByText('PARA-LOT Batch tracked product')).not.toBeInTheDocument()

    scan('123456')

    expect(toast.error).toHaveBeenCalledWith('Select a source location before adding transfer lines.')
    // The source guard vetoes both the search-add and the scan BEFORE the code
    // resolver is ever hit — no /line-entry/resolve-code request fires.
    expect(mockApiGet).not.toHaveBeenCalled()
  })

  it('auto-allocates FEFO lots and opens the batch panel for a batch-tracked scan', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue({
      kind: 'product',
      matched_code_type: 'product_barcode',
      product: product(BATCH_PRODUCT_ID),
    })

    renderPage()
    await selectLocations(user)

    scan('123456')

    expect(await screen.findByText('PARA-LOT Batch tracked product')).toBeInTheDocument()
    expect(await screen.findByText('LOT-101')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /1 lot/i })).toHaveAttribute('aria-expanded', 'true')

    await user.click(screen.getByRole('button', { name: /create transfer/i }))

    await waitFor(() => {
      expect(mockCreate).toHaveBeenCalledWith(expect.objectContaining({
        lines: [
          expect.objectContaining({
            product_id: BATCH_PRODUCT_ID,
            quantity: '1',
            batch_allocations: [{ batch_id: 101, quantity: '1.0000' }],
          }),
        ],
      }))
    })
  })

  it('keeps a scanned batch-tracked line blocked when FEFO cannot cover the quantity', async () => {
    const user = userEvent.setup()
    mockUseProductBatches.mockReturnValue({ data: [], isLoading: false, isFetching: false })
    mockApiGet.mockResolvedValue({
      kind: 'product',
      matched_code_type: 'product_barcode',
      product: product(BATCH_PRODUCT_ID),
    })

    renderPage()
    await selectLocations(user)

    scan('123456')

    expect(await screen.findByText('Needs allocation')).toBeInTheDocument()
    expect(screen.getByText('FEFO could not cover the requested quantity. Allocate lots manually.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /create transfer/i }))

    expect(mockCreate).not.toHaveBeenCalled()
    expect(toast.error).toHaveBeenCalledWith('FEFO could not cover every batch-tracked line. Allocate lots manually.')
  })

  it('re-scanning the same batch-tracked product increments quantity and re-derives FEFO for the new total', async () => {
    const user = userEvent.setup()
    mockUseProductBatches.mockReturnValue({
      data: [
        batch(101, '1.0000', '2026-08-31'),
        batch(102, '5.0000', '2026-09-30'),
      ],
      isLoading: false,
      isFetching: false,
    })
    mockApiGet.mockResolvedValue({
      kind: 'product',
      matched_code_type: 'product_barcode',
      product: product(BATCH_PRODUCT_ID),
    })

    renderPage()
    await selectLocations(user)

    scan('123456')
    await screen.findByText('LOT-101')
    scan('123456')

    await waitFor(() => {
      expect(screen.getByRole('spinbutton', { name: 'Quantity' })).toHaveValue(2)
    })
    expect(screen.getByRole('spinbutton', { name: 'Quantity from LOT-101' })).toHaveValue(1)
    expect(screen.getByRole('spinbutton', { name: 'Quantity from LOT-102' })).toHaveValue(1)

    await user.click(screen.getByRole('button', { name: /create transfer/i }))

    await waitFor(() => {
      expect(mockCreate).toHaveBeenCalledWith(expect.objectContaining({
        lines: [
          expect.objectContaining({
            product_id: BATCH_PRODUCT_ID,
            quantity: '2',
            batch_allocations: [
              { batch_id: 101, quantity: '1.0000' },
              { batch_id: 102, quantity: '1.0000' },
            ],
          }),
        ],
      }))
    })
  })

  it('direct-adds variant barcode scans and requires a chooser for parent product scans', async () => {
    const user = userEvent.setup()
    mockUseProductVariants.mockReturnValue({
      data: [
        {
          id: VARIANT_ID,
          tenant_id: 'tenant-1',
          company_id: 'company-1',
          product_id: VARIANT_PRODUCT_ID,
          variant_code: 'RED',
          sku: 'TSHIRT-RED',
          barcode: '999111',
          name_suffix: 'Red',
          is_default: false,
          is_active: true,
          display_order: 0,
          price_override: null,
          cost_override: null,
          image_url: null,
        },
      ],
      isLoading: false,
    })
    mockApiGet.mockImplementation((urlArg: unknown, paramsArg?: unknown) => {
      const url = typeof urlArg === 'string' ? urlArg : ''
      const params = typeof paramsArg === 'object' && paramsArg !== null && 'code' in paramsArg
        ? paramsArg
        : undefined
      if (url !== '/line-entry/resolve-code') {
        return { locations: [] }
      }
      if (params?.code === '999111') {
        return {
          kind: 'variant',
          matched_code_type: 'variant_barcode',
          product: product(VARIANT_PRODUCT_ID, {
            sku: 'TSHIRT',
            name: 'Variant product',
            requires_batch_tracking: false,
          }),
          variant: {
            id: VARIANT_ID,
            sku: 'TSHIRT-RED',
            name_suffix: 'Red',
          },
        }
      }
      // Parent-with-variants scan: the real /line-entry/resolve-code backend
      // returns kind:'product' with has_variants — the entry bar routes it to
      // the variant chooser, whose options come from useProductVariants.
      return {
        kind: 'product',
        matched_code_type: 'product_barcode',
        product: {
          ...product(VARIANT_PRODUCT_ID, {
            sku: 'TSHIRT',
            name: 'Variant product',
            requires_batch_tracking: false,
          }),
          has_variants: true,
        },
      }
    })

    renderPage()
    await selectLocations(user)

    scan('999111')

    expect(await screen.findByText('TSHIRT Variant product')).toBeInTheDocument()
    const variantSelect = await screen.findByLabelText('Variant')
    expect(variantSelect).toHaveValue(VARIANT_ID)

    scan('999222')

    const chooser = await screen.findByRole('dialog', { name: /choose variant for Variant product/i })
    await user.click(within(chooser).getByRole('button', { name: /Red TSHIRT-RED/i }))

    await waitFor(() => {
      expect(screen.getByRole('spinbutton', { name: 'Quantity' })).toHaveValue(2)
    })
  })

  it('keeps the existing manual add-line flow working', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole('button', { name: /select manual product/i }))

    expect(screen.getByText('MANUAL-1 Manual add product')).toBeInTheDocument()
  })
})
