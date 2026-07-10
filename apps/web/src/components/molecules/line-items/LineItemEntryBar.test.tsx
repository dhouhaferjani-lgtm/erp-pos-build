import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { type ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiGet } from '../../../lib/api'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { LineItemEntryBar } from './LineItemEntryBar'

const apiClientGetMock = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const code = typeof params?.['code'] === 'string' ? params['code'] : ''
      const map: Record<string, string> = {
        'sales:lineItems.entry.placeholder': 'Search or scan a product',
        'sales:lineItems.entry.browseCatalog': 'Browse catalog',
        'sales:lineItems.entry.productNotFound': `Product not found: ${code}`,
        'sales:lineItems.entry.requiresVariant': 'Choose a variant before adding this product',
        'sales:lineItems.loading': 'Loading',
        'sales:lineItems.noProductsFound': 'No products found',
        'sales:lineItems.productImagePlaceholder': 'No product image',
      }
      return map[key] ?? key
    },
  }),
}))

vi.mock('../../../lib/api', () => ({
  api: { get: apiClientGetMock },
  apiGet: vi.fn(),
}))

const apiGetMock = vi.mocked(apiGet)

const product = {
  id: 'product-1',
  name: 'Crème solaire SPF50',
  sku: 'CS-050',
  barcode: '6194000123456',
  sale_price: '14.280',
  tax_rate: '19.00',
  default_tax_configuration_id: null,
  quantity_decimals: 0,
  primary_image_url: null,
  has_variants: false,
  requires_batch_tracking: false,
}

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [
      {
        id: companyId,
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
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function wrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  })

  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

describe('LineItemEntryBar', () => {
  beforeEach(() => {
    apiGetMock.mockReset()
    apiClientGetMock.mockReset()
    setTenant('tenant-A', 'company-1')
  })

  afterEach(() => {
    resetTenant()
  })

  it('adds the highlighted search result with Enter and keeps the input focused', async () => {
    const user = userEvent.setup()
    const onAddProduct = vi.fn()
    apiClientGetMock.mockResolvedValue({ data: { data: [product] } })

    render(<LineItemEntryBar onAddProduct={onAddProduct} />, { wrapper: wrapper() })

    const input = screen.getByRole('combobox', { name: 'Search or scan a product' })
    await user.type(input, 'creme')

    await waitFor(() => {
      expect(screen.getByText('Crème solaire SPF50')).toBeInTheDocument()
    })

    await user.keyboard('{Enter}')

    expect(onAddProduct).toHaveBeenCalledWith(product, expect.objectContaining({ source: 'search', incrementBy: 1 }))
    expect(input).toHaveFocus()
    expect(input).toHaveValue('')
  })

  it('scopes the product search read key with the active tenant + company', async () => {
    const user = userEvent.setup()
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: Infinity } },
    })
    apiClientGetMock.mockResolvedValue({ data: { data: [product] } })

    render(<LineItemEntryBar onAddProduct={vi.fn()} />, {
      wrapper: ({ children }: { children: ReactNode }) => (
        <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
      ),
    })

    const input = screen.getByRole('combobox', { name: 'Search or scan a product' })
    await user.type(input, 'creme')

    // The read key carries the tenant + company suffix so cache entries invalidate
    // on a tenant/company switch and never leak across tenants.
    await waitFor(() => {
      expect(queryClient.getQueryData(['line-entry-products', 'creme', 'tenant-A', 'company-1'])).toEqual({
        data: [product],
      })
    })
  })

  it('shows first-page product suggestions when focused with an empty query', async () => {
    const user = userEvent.setup()
    apiClientGetMock.mockResolvedValue({ data: { data: [product] } })

    render(<LineItemEntryBar onAddProduct={vi.fn()} />, { wrapper: wrapper() })

    const input = screen.getByRole('combobox', { name: 'Search or scan a product' })
    await user.click(input)

    expect(await screen.findByRole('option', { name: /CS-050 Crème solaire SPF50/i })).toBeInTheDocument()
    expect(apiClientGetMock).toHaveBeenCalledWith('/products', { params: undefined })
  })

  it('does not fire the product search without tenant/company state', async () => {
    resetTenant()
    const user = userEvent.setup()
    apiClientGetMock.mockResolvedValue({ data: { data: [product] } })

    render(<LineItemEntryBar onAddProduct={vi.fn()} />, { wrapper: wrapper() })

    const input = screen.getByRole('combobox', { name: 'Search or scan a product' })
    await user.type(input, 'creme')

    // Let any (incorrectly) eager query flush before asserting silence.
    await waitFor(() => {
      expect(apiClientGetMock).not.toHaveBeenCalled()
    })
  })

  it('resolves scanner-like Enter through the code resolver and never submits the parent form', async () => {
    const user = userEvent.setup()
    const onAddProduct = vi.fn()
    const onSubmit = vi.fn((event: React.FormEvent<HTMLFormElement>) => {
      event.preventDefault()
    })
    apiClientGetMock.mockResolvedValue({ data: { data: [] } })
    apiGetMock.mockResolvedValue({
      kind: 'product',
      matched_code_type: 'product_barcode',
      product,
    })

    render(
      <form onSubmit={onSubmit}>
        <LineItemEntryBar onAddProduct={onAddProduct} />
      </form>,
      { wrapper: wrapper() },
    )

    const input = screen.getByRole('combobox', { name: 'Search or scan a product' })
    await user.type(input, '6194000123456')
    await user.keyboard('{Enter}')

    await waitFor(() => {
      expect(onAddProduct).toHaveBeenCalledWith(product, expect.objectContaining({ source: 'scan', incrementBy: 1 }))
    })
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('highlights the first search result and emits it on Enter with an explicit accessible name', async () => {
    const user = userEvent.setup()
    const onAddProduct = vi.fn()
    const paracetamol = {
      id: 'product-2',
      name: 'Paracetamol 500',
      sku: 'PARA-1',
      barcode: null,
      sale_price: '2.500',
      tax_rate: '19.00',
      default_tax_configuration_id: null,
      quantity_decimals: 0,
      primary_image_url: null,
      has_variants: false,
      requires_batch_tracking: false,
    }
    apiClientGetMock.mockResolvedValue({ data: { data: [paracetamol] } })

    render(<LineItemEntryBar onAddProduct={onAddProduct} />, { wrapper: wrapper() })

    const input = screen.getByRole('combobox', { name: 'Search or scan a product' })
    await user.type(input, 'para')

    expect(await screen.findByRole('option', { name: /PARA-1 Paracetamol 500/i })).toHaveAttribute('aria-selected', 'true')

    await user.keyboard('{Enter}')

    expect(onAddProduct).toHaveBeenCalledTimes(1)
    expect(onAddProduct).toHaveBeenCalledWith(
      paracetamol,
      expect.objectContaining({ source: 'search', variantId: null }),
    )
    expect(input).toHaveValue('')
    expect(input).toHaveFocus()
  })

  it('resolves a variant scanner code and emits a direct variant add', async () => {
    const onAddProduct = vi.fn()
    const tshirt = {
      id: 'product-1',
      name: 'T-Shirt',
      sku: 'TSHIRT',
      barcode: null,
      sale_price: '10.000',
      tax_rate: '19.00',
      default_tax_configuration_id: null,
      quantity_decimals: 0,
      primary_image_url: null,
      has_variants: false,
      requires_batch_tracking: false,
    }
    apiClientGetMock.mockResolvedValue({ data: { data: [] } })
    apiGetMock.mockResolvedValue({
      kind: 'variant',
      matched_code_type: 'variant_barcode',
      product: tshirt,
      variant: {
        id: 'variant-red',
        product_id: 'product-1',
        sku: 'TSHIRT-RED',
        variant_code: 'RED',
        barcode: '999',
        name_suffix: 'Red',
        is_default: false,
        price_override: null,
        cost_override: null,
        image_url: null,
      },
    })

    render(<LineItemEntryBar onAddProduct={onAddProduct} />, { wrapper: wrapper() })

    // Scanner wedge: rapid keystrokes on window followed by Enter.
    window.dispatchEvent(new KeyboardEvent('keydown', { key: '9' }))
    window.dispatchEvent(new KeyboardEvent('keydown', { key: '9' }))
    window.dispatchEvent(new KeyboardEvent('keydown', { key: '9' }))
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))

    await waitFor(() => {
      expect(onAddProduct).toHaveBeenCalledTimes(1)
    })
    expect(onAddProduct).toHaveBeenCalledWith(
      tshirt,
      expect.objectContaining({
        source: 'scan',
        variantId: 'variant-red',
        code: '999',
        matchedCodeType: 'variant_barcode',
      }),
    )
    expect(apiGetMock).toHaveBeenCalledWith('/line-entry/resolve-code', expect.objectContaining({ code: '999' }))
  })
})
