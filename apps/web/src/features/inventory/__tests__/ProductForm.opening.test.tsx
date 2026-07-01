import React from 'react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { defaultCompanyConfig } from '@/test/fixtures/companyConfig'
import { ProductForm } from '../ProductForm'

/**
 * Locks the opening-stock section gate and state-machine in ProductForm:
 *
 * (a) Section hidden without inventory.adjust permission.
 * (b) Section hidden for a non-physical (service) product.
 * (c) Locked state: inputs disabled + Reset button when has_active_opening &&
 *     !has_downstream_movements.
 * (d) Entry state: inputs enabled when can_enter_opening is true.
 *
 * Uses a mutable route-params object (vi.hoisted) to control the route id,
 * and a per-test `currentProductData` variable in the api.get mock so the
 * product query resolves with fixture data regardless of cache state.
 */

// ---------------------------------------------------------------------------
// Mocks (all hoisted so they are available in factory closures)
// ---------------------------------------------------------------------------

/** Mutable object captured by react-router-dom factory. */
const routeParams = vi.hoisted<{ id: string }>(() => ({ id: '' }))

/** Controllable hasPermission. */
const { mockHasPermission } = vi.hoisted(() => ({
  mockHasPermission: vi.fn<(p: string) => boolean>(),
}))

/**
 * Per-test product fixture stored in a module-level variable that the
 * api mock reads on each call. null = no product query (create mode).
 */
let currentProductData: ReturnType<typeof makeProduct> | null = null

// i18n: echo the key so assertions remain stable regardless of catalog state.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { language: 'en' },
  }),
}))

// Controllable useParams: factory captures `routeParams` by reference.
vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return {
    ...actual,
    useParams: () => ({ id: routeParams.id }),
    useNavigate: () => vi.fn(),
    Link: ({
      to,
      children,
      ...props
    }: {
      to: string
      children: React.ReactNode
      className?: string
    }) => <a href={to} {...props}>{children}</a>,
  }
})

// Controllable hasPermission.
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

/**
 * Mock api.get:
 *   - For product routes → resolve with the per-test fixture.
 *   - For every other route → reject (same as a network failure).
 *
 * Rejecting non-product calls means:
 *   - TanStack Query keeps any pre-seeded stale data (e.g. company-config).
 *   - `units` is undefined (not an array), so `units?.reduce` is safe.
 *   - This mirrors how the WAC edit-mode test works without mocking api.get
 *     at all — network calls just fail and TQ handles errors gracefully.
 */
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: new Proxy(actual.api, {
      get(target, prop, receiver) {
        if (prop === 'get') {
          return (url: string) => {
            if (typeof url === 'string' && url.startsWith('/products/') && currentProductData !== null) {
              return Promise.resolve({ data: { data: currentProductData } })
            }
            // Reject → TanStack Query error, stale cache data preserved.
            return Promise.reject(new Error(`[test] no mock for GET ${url}`))
          }
        }
        return Reflect.get(target, prop, receiver)
      },
    }),
  }
})

// Stub heavy platform queries — not under test here.
vi.mock('../api/platformQueries', async () => {
  const actual = await vi.importActual<typeof import('../api/platformQueries')>(
    '../api/platformQueries',
  )
  return {
    ...actual,
    useProductSubmission: () => ({ mutateAsync: vi.fn(), isPending: false }),
  }
})

vi.mock('@/features/catalog/hooks/useVariants', () => ({
  useVariantsForProduct: () => ({ data: undefined, isLoading: false }),
}))

vi.mock('@/contexts/ProductConfigContext', async () => {
  const actual = await vi.importActual<typeof import('@/contexts/ProductConfigContext')>(
    '@/contexts/ProductConfigContext',
  )
  return {
    ...actual,
    useProductConfig: () => ({ isOtospex: false, product: 'izipos' }),
  }
})

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'EUR', locale: 'en', decimals: 2 }),
  getDecimals: () => 2,
}))

vi.mock('@/hooks/useTaxConfigName', () => ({
  useTaxConfigName: () => null,
}))

// Avoid pulling tax-config network queries into these gate-focused tests.
vi.mock('@/components/molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: () => null,
}))

// ---------------------------------------------------------------------------
// Shared test fixtures
// ---------------------------------------------------------------------------

const PRODUCT_ID = 'product-opening-test-abc'

function makeProduct(
  overrides: {
    is_physical?: boolean
    opening?: {
      has_active_opening: boolean
      has_downstream_movements: boolean
      can_enter_opening: boolean
    } | null
  } = {},
) {
  return {
    id: PRODUCT_ID,
    name: 'Opening Test Product',
    sku: 'SKU-OPENING-001',
    is_physical: overrides.is_physical ?? true,
    is_active_for_ecommerce: false,
    unit_id: null,
    category_id: null,
    description: null,
    sale_price: '10.000',
    purchase_price: null,
    cost_price: null,
    tax_rate: null,
    default_tax_configuration_id: null,
    unit: 'pcs',
    barcode: '',
    is_active: true,
    oem_numbers: [],
    cross_references: [],
    parapharmacy_metadata: null,
    requires_batch_tracking: false,
    default_shelf_life_days: null,
    units_per_pack: null,
    shelf_location: null,
    reorder_point: null,
    reorder_quantity: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
    opening: overrides.opening !== undefined ? overrides.opening : null,
  }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('ProductForm opening-stock section gate', () => {
  beforeEach(() => {
    seedAuth()
    routeParams.id = '' // create mode by default
    currentProductData = null
  })

  afterEach(() => {
    resetAuth()
    vi.clearAllMocks()
  })

  // (a) Permission gate — create mode, no inventory.adjust
  it('hides the opening section when inventory.adjust permission is absent', async () => {
    // routeParams.id = '' → isEditing = false (create mode, product query disabled)
    mockHasPermission.mockReturnValue(false)

    renderWithProviders(<ProductForm />, {
      route: '/inventory/products/new',
      companyConfig: defaultCompanyConfig,
    })

    // Wait for the BarcodeHero barcode input (always present in the form).
    // The i18n mock echoes keys, so aria-label = 'editor.hero.barcodePlaceholder'.
    await waitFor(() => {
      expect(
        screen.getByRole('textbox', { name: 'editor.hero.barcodePlaceholder' }),
      ).toBeInTheDocument()
    })

    expect(screen.queryByTestId('opening-section')).not.toBeInTheDocument()
  })

  // (b) Physical-product gate — edit mode, product is non-physical
  it('hides the opening section for a non-physical product even with permission', async () => {
    routeParams.id = PRODUCT_ID
    currentProductData = makeProduct({ is_physical: false })
    mockHasPermission.mockImplementation((p: string) => p === 'inventory.adjust')

    renderWithProviders(<ProductForm />, {
      route: `/inventory/products/${PRODUCT_ID}`,
      companyConfig: defaultCompanyConfig,
    })

    // Wait for the form to populate with product data.
    // ProductForm renders the product name in two places (BarcodeHero hero input
    // and the main form name field), so use getAllByDisplayValue.
    await waitFor(() => {
      expect(screen.getAllByDisplayValue('Opening Test Product').length).toBeGreaterThan(0)
    })

    // is_physical = false → watchIsPhysical = false → section hidden
    expect(screen.queryByTestId('opening-section')).not.toBeInTheDocument()
  })

  // (c) Locked state: active opening, no downstream movements → Reset button
  it('locks opening inputs and shows Reset button when has_active_opening=true and no downstream', async () => {
    routeParams.id = PRODUCT_ID
    currentProductData = makeProduct({
      opening: {
        has_active_opening: true,
        has_downstream_movements: false,
        can_enter_opening: false,
      },
    })
    mockHasPermission.mockImplementation((p: string) => p === 'inventory.adjust')

    renderWithProviders(<ProductForm />, {
      route: `/inventory/products/${PRODUCT_ID}`,
      companyConfig: defaultCompanyConfig,
    })

    // Wait for form to populate — this also means opening state has been applied.
    await waitFor(() => {
      expect(screen.getAllByDisplayValue('Opening Test Product').length).toBeGreaterThan(0)
    })

    // Section must be visible (product is physical)
    expect(screen.getByTestId('opening-section')).toBeInTheDocument()

    // Inputs must be disabled (locked: can_enter_opening = false)
    expect(screen.getByTestId('opening-qty-input')).toBeDisabled()
    expect(screen.getByTestId('opening-cost-input')).toBeDisabled()

    // Reset button must be present (has_active_opening && !has_downstream_movements)
    expect(screen.getByTestId('opening-reset-btn')).toBeInTheDocument()
  })

  // (d) Entry state: can_enter_opening === true → inputs enabled
  it('renders opening inputs enabled when can_enter_opening is true', async () => {
    routeParams.id = PRODUCT_ID
    currentProductData = makeProduct({
      opening: {
        has_active_opening: false,
        has_downstream_movements: false,
        can_enter_opening: true,
      },
    })
    mockHasPermission.mockImplementation((p: string) => p === 'inventory.adjust')

    renderWithProviders(<ProductForm />, {
      route: `/inventory/products/${PRODUCT_ID}`,
      companyConfig: defaultCompanyConfig,
    })

    await waitFor(() => {
      expect(screen.getAllByDisplayValue('Opening Test Product').length).toBeGreaterThan(0)
    })

    // Section must be visible
    expect(screen.getByTestId('opening-section')).toBeInTheDocument()

    // Qty input must be enabled (can_enter_opening = true)
    expect(screen.getByTestId('opening-qty-input')).not.toBeDisabled()

    // Reset button must NOT be present (not locked)
    expect(screen.queryByTestId('opening-reset-btn')).not.toBeInTheDocument()
  })
})
