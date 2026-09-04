import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
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
const mockResetIdempotencyKey = vi.hoisted(() => vi.fn())
// How many keys the fake hook below has minted in this test. Reset per test.
const mintedKeys = vi.hoisted(() => ({ count: 0 }))

const BATCH_PRODUCT_ID = '11111111-1111-4111-8111-111111111111'
const PLAIN_PRODUCT_ID = '22222222-2222-4222-8222-222222222222'
const VARIANT_PRODUCT_ID = '33333333-3333-4333-8333-333333333333'
const VARIANT_ID = '44444444-4444-4444-8444-444444444444'

vi.mock('@/features/locations/api', () => ({
  fetchLocations: mockFetchLocations,
  fetchTransactionLocations: mockFetchLocations,
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

/**
 * A STATEFUL fake of the real hook, not a frozen literal.
 *
 * A constant key cannot tell "the failed submit kept its key" apart from "the
 * page rotated it", which is the one invariant ID-3 exists for. This fake mints
 * `transfer-key-<n>` per mounted instance and rotates it only when the page
 * calls `reset()` — exactly the real hook's contract (`useIdempotencyKey.ts`),
 * with deterministic values instead of UUIDs so payloads stay exact-matchable.
 */
vi.mock('@/hooks/useIdempotencyKey', async () => {
  const { useCallback, useState } = await vi.importActual<typeof import('react')>('react')

  return {
    useIdempotencyKey: (): { key: string; reset: () => void } => {
      const mint = (): string => {
        mintedKeys.count += 1
        return `transfer-key-${String(mintedKeys.count)}`
      }
      const [key, setKey] = useState(mint)
      const reset = useCallback(() => {
        mockResetIdempotencyKey()
        setKey(mint())
      }, [])

      return { key, reset }
    },
  }
})

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

function batch(id: number, availableQuantity: string, expiryDate: string | null) {
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
    mintedKeys.count = 0
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

  /**
   * W4-1 gate r1 finding D — the client's FEFO order must match the SERVER's
   * exactly: `(expiry_date IS NULL) ASC, expiry_date ASC, id ASC`.
   *
   * `assertAllocationsFollowFefo()` on the server compares per-batch QUANTITIES,
   * so on a PARTIAL draw across equal-rank lots a client that returned 0 for the
   * tie and leaned on JS sort stability could build a split the endpoint refuses
   * with "Batch allocations must follow FEFO" — and the operator would have no
   * way to satisfy it. Undated ties are the common shape on the launch tenant.
   */
  it('ranks undated lots last and breaks the tie on id, matching the server', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue({
      kind: 'product',
      matched_code_type: 'product_barcode',
      product: product(BATCH_PRODUCT_ID),
    })
    // Deliberately supplied in an order NO rule would produce: the higher-id
    // undated lot first, then a dated lot, then the lower-id undated lot.
    mockUseProductBatches.mockReturnValue({
      data: [batch(305, '2.0000', null), batch(101, '1.0000', '2026-08-31'), batch(204, '2.0000', null)],
      isLoading: false,
      isFetching: false,
    })

    renderPage()
    await selectLocations(user)

    scan('123456')

    expect(await screen.findByText('PARA-LOT Batch tracked product')).toBeInTheDocument()
    await screen.findByText('LOT-101')

    // Two scans -> quantity 2 against 1 + 2 + 2 available, so the draw stops after
    // the SECOND lot. A full draw would take every lot and the ordering could not
    // be observed; stopping early is what proves both rules at once — the dated lot
    // came first, and of the two UNDATED lots the lower id won.
    scan('123456')

    await waitFor(() => {
      expect(screen.getByRole('spinbutton', { name: 'Quantity' })).toHaveValue(2)
    })

    await user.click(screen.getByRole('button', { name: /create transfer/i }))

    await waitFor(() => {
      expect(mockCreate).toHaveBeenCalled()
    })

    const payload = mockCreate.mock.calls.at(-1)?.[0] as {
      lines: { batch_allocations: { batch_id: number, quantity: string }[] }[]
    }

    expect(payload.lines[0]?.batch_allocations.map((a) => a.batch_id)).toEqual([101, 204])
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

  it('submits the complete header and line payload with decimal strings intact', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue({
      kind: 'product',
      matched_code_type: 'product_barcode',
      product: product(BATCH_PRODUCT_ID),
    })

    renderPage()
    await selectLocations(user)

    await user.type(screen.getByLabelText(/notes/i), '  Cold chain handoff  ')
    await user.type(screen.getByLabelText(/transfer cost \(freight, handling\)/i), '8.250')
    await user.type(screen.getByLabelText(/cost label/i), 'Temperature control')
    await user.selectOptions(screen.getByLabelText(/allocate cost by/i), 'equal_per_line')
    scan('123456')

    expect(await screen.findByText('PARA-LOT Batch tracked product')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /create transfer/i }))

    await waitFor(() => {
      expect(mockCreate).toHaveBeenCalledWith({
        idempotency_key: 'transfer-key-1',
        source_location_id: 'source-location',
        destination_location_id: 'destination-location',
        notes: 'Cold chain handoff',
        transfer_cost: '8.25',
        transfer_cost_label: 'Temperature control',
        transfer_cost_distribution: 'equal_per_line',
        lines: [
          {
            product_id: BATCH_PRODUCT_ID,
            quantity: '1',
            batch_allocations: [{ batch_id: 101, quantity: '1.0000' }],
          },
        ],
      })
    })

    // ID-3: the key rotates only after the awaited success resolves.
    await waitFor(() => {
      expect(mockResetIdempotencyKey).toHaveBeenCalledTimes(1)
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

  /**
   * FE gate r1 MAJOR-1 — the invariant ID-3 exists for.
   *
   * The key must SURVIVE a failed submit so the retry is deduplicated
   * server-side, and rotate ONLY after an awaited success. A success-path
   * `toHaveBeenCalledTimes(1)` cannot see `reset()` moving into a `finally`,
   * into the `catch`, or above the `await`; this test can.
   */
  it('keeps the SAME idempotency key after a failed submit and rotates it only after a success', async () => {
    const user = userEvent.setup()
    mockCreate.mockRejectedValueOnce(new Error('network'))

    renderPage()
    await selectLocations(user)

    // A plain (non batch-tracked) line: this test is about the KEY, and the
    // FEFO allocation dance would only add timing noise.
    await user.click(screen.getByRole('button', { name: /select manual product/i }))
    expect(screen.getByText('MANUAL-1 Manual add product')).toBeInTheDocument()

    const submitButton = screen.getByRole('button', { name: /create transfer/i })

    await user.click(submitButton)

    // The failure reaches the operator...
    await waitFor(() => {
      expect(toast.error).toHaveBeenCalledWith('Could not create the transfer.')
    })
    // ...and the attempt is NOT over, so the key must not have rotated.
    expect(mockResetIdempotencyKey).not.toHaveBeenCalled()

    await user.click(submitButton)

    await waitFor(() => {
      expect(mockCreate).toHaveBeenCalledTimes(2)
    })
    const firstKey = mockCreate.mock.calls[0]?.[0].idempotency_key
    expect(firstKey).toBe('transfer-key-1')
    expect(mockCreate.mock.calls[1]?.[0].idempotency_key).toBe(firstKey)

    // The retry succeeded: this logical attempt is done, so the key rotates once.
    await waitFor(() => {
      expect(mockResetIdempotencyKey).toHaveBeenCalledTimes(1)
    })

    await user.click(submitButton)

    await waitFor(() => {
      expect(mockCreate).toHaveBeenCalledTimes(3)
    })
    expect(mockCreate.mock.calls[2]?.[0].idempotency_key).not.toBe(firstKey)
  })

  /**
   * FE gate r1 MAJOR-2 — `disabled={createMutation.isPending}` is async state:
   * it flips on a render that happens AFTER the click handler returns, so two
   * clicks dispatched in one task both get through. The synchronous ref latch
   * is what makes the second one a no-op.
   */
  it('issues exactly ONE create request when the submit button is double-clicked', async () => {
    const user = userEvent.setup()
    // Held open: the first request is still in flight when the second click lands.
    mockCreate.mockReturnValue(new Promise<{ id: string }>(() => undefined))

    renderPage()
    await selectLocations(user)

    await user.click(screen.getByRole('button', { name: /select manual product/i }))
    expect(screen.getByText('MANUAL-1 Manual add product')).toBeInTheDocument()

    const submitButton = screen.getByRole('button', { name: /create transfer/i })

    await act(async () => {
      fireEvent.click(submitButton)
      fireEvent.click(submitButton)
      // Let both submit handlers run up to their awaited request.
      await Promise.resolve()
    })
    // Give a second submit that slipped past the latch every chance to land.
    await act(async () => {
      await Promise.resolve()
    })

    expect(mockCreate).toHaveBeenCalledTimes(1)
  })

  it('keeps the existing manual add-line flow working', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole('button', { name: /select manual product/i }))

    expect(screen.getByText('MANUAL-1 Manual add product')).toBeInTheDocument()
  })
})
