import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { DocumentLineEditor, type DocumentLine } from '../DocumentLineEditor'
import { AdditionalCostsForm } from '../costing/AdditionalCostsForm'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
const mockResolveCode = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      post: mockApiPost,
      delete: mockApiDelete,
    },
    // The redesigned LineItemEntryBar resolves scanned/typed codes through the
    // apiGet('/line-entry/resolve-code') wrapper (useProductLineLookup). Stub it so
    // the "code not found -> open the create-product modal" path is deterministic.
    apiGet: mockResolveCode,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@/hooks/useCurrency', async () => {
  const actual = await vi.importActual<typeof import('@/hooks/useCurrency')>('@/hooks/useCurrency')
  return {
    ...actual,
    useCurrency: () => ({ decimals: 3 }),
  }
})

// DocumentLineEditor imports AddQuickProductModal via its deep module path (not the
// barrel), so the mock must target that exact module to intercept it.
vi.mock('@/components/organisms/AddQuickProductModal/AddQuickProductModal', () => ({
  AddQuickProductModal: ({
    isOpen,
    onSuccess,
  }: {
    isOpen: boolean
    onSuccess: (product: { id: string; name: string; sku: string; sale_price: number; tax_rate: number }) => void
  }) => isOpen ? (
    <button
      type="button"
      onClick={() => {
        onSuccess({ id: 'product-new', name: 'New Product', sku: 'NP', sale_price: 12, tax_rate: 0 })
      }}
    >
      create-product-success
    </button>
  ) : null,
}))

vi.mock('@/components/atoms/TaxConfigurationSelect', () => ({
  TaxConfigurationSelect: () => <select aria-label="tax-configuration" />,
}))

vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({
    config: null,
    isLoading: false,
    error: null,
    hasModule: (moduleName: string) => moduleName === 'Workshop',
  }),
}))

vi.mock('../DesignationCell', () => ({
  DesignationCell: () => <span>designation</span>,
}))

vi.mock('../NotesCell', () => ({
  NotesCell: () => <span>notes</span>,
}))

vi.mock('../../hooks/useLineDesignationFeature', () => ({
  useLineDesignationFeature: () => false,
}))

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

function createClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  vi.stubGlobal('confirm', vi.fn(() => true))
  mockApiGet.mockImplementation(async (url: string, config?: { params?: { search?: string } }) => {
    if (url.startsWith('/products')) {
      // The entry bar sends the typed text as params.search. Returning an empty
      // result set for the sentinel code drives the "not found" scan path below.
      if (config?.params?.search === 'UNKNOWN-CODE') {
        return { data: { data: [] } }
      }
      return { data: { data: [{ id: 'product-1', name: 'Product 1', sku: 'P1', sale_price: 10, tax_rate: 0 }] } }
    }
    // NOTE: the standalone /services line search was removed from DocumentLineEditor
    // in the entry-bar redesign, so no /services branch is exercised any more.
    if (url.startsWith('/documents/doc-1/additional-costs')) {
      return { data: { data: [{ id: 'cost-1', cost_type: 'shipping', amount: 5 }] } }
    }
    return { data: { data: [] } }
  })
  mockResolveCode.mockResolvedValue({ kind: 'not_found', code: 'UNKNOWN-CODE' })
  mockApiPost.mockResolvedValue({ data: { ok: true } })
  mockApiDelete.mockResolvedValue({ data: { ok: true } })
})

afterEach(() => {
  vi.unstubAllGlobals()
  resetTenant()
})

describe('document component tenant scope', () => {
  it('scopes document line product/service reads and product invalidation (.159-.161)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    queryClient.setQueryData(['products', 'tenant-A', 'company-1'], ['tenant-A-products'])
    queryClient.setQueryData(['products', 'tenant-B', 'company-2'], ['tenant-B-products'])
    const onChange = vi.fn<(lines: DocumentLine[]) => void>()

    render(<DocumentLineEditor lines={[]} onChange={onChange} />, { wrapper: wrapper(queryClient) })

    // The old "search products" toggle + product/service dropdown was replaced by the
    // persistent LineItemEntryBar combobox. Typing into it fires the product read.
    const searchInput = screen.getByRole('combobox', { name: 'sales:lineItems.entry.placeholder' })
    await user.type(searchInput, 'Product')

    // A real product fetch fires from the typed query, and the redesigned entry bar
    // now tenant-scopes its read key: ['line-entry-products', <query>, tenant, company].
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/products', expect.objectContaining({ params: { per_page: 20, search: 'Product' } }))
    })
    await waitFor(() => {
      expect(queryClient.getQueryData(['line-entry-products', 'Product', 'tenant-A', 'company-1'])).toEqual({
        data: [{ id: 'product-1', name: 'Product 1', sku: 'P1', sale_price: 10, tax_rate: 0 }],
      })
    })

    // SEMANTIC CHANGE: standalone service line search was removed from
    // DocumentLineEditor in the entry-bar redesign (no service tab / no /services
    // read), so the former tenant-scoped ['services', '', tenant, company] read
    // assertion can no longer be exercised through the new UI.

    // Create-new-product now opens via the entry bar's "code not found" path: type an
    // unknown code, get an empty result set, press Enter to resolve the code, which
    // reports not_found and opens the AddQuickProductModal.
    await user.clear(searchInput)
    await user.type(searchInput, 'UNKNOWN-CODE')
    await waitFor(() => {
      expect(screen.getByText('sales:lineItems.noProductsFound')).toBeInTheDocument()
    })
    await user.keyboard('{Enter}')

    const createButton = await screen.findByRole('button', { name: 'create-product-success' })
    await user.click(createButton)

    // Product invalidation is still EXACTLY tenant/company scoped (unchanged in the
    // redesign): only tenant-A/company-1 keys are invalidated, tenant-B/company-2 are not.
    await waitFor(() => {
      expect(queryClient.getQueryState(['products', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    })
    expect(queryClient.getQueryState(['products', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
  })

  it('scopes additional cost reads and exact invalidations (.165-.167)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    render(<AdditionalCostsForm documentId="doc-1" />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['document-additional-costs', 'doc-1', 'tenant-A', 'company-1'])).toEqual({
        data: [{ id: 'cost-1', cost_type: 'shipping', amount: 5 }],
      })
    })

    await user.clear(screen.getByPlaceholderText('0.00'))
    await user.type(screen.getByPlaceholderText('0.00'), '7')
    // The previously-hardcoded "Add Cost" label is now a t() key (identity-mocked).
    await user.click(screen.getByRole('button', { name: /additionalCosts\.addButton/ }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/additional-costs', expect.objectContaining({ amount: 7 }))
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/documents/doc-1/additional-costs')).toHaveLength(2)
    })

    await user.click(screen.getByRole('button', { name: '' }))

    await waitFor(() => {
      expect(mockApiDelete).toHaveBeenCalledWith('/documents/doc-1/additional-costs/cost-1')
      expect(mockApiGet.mock.calls.filter(([url]) => url === '/documents/doc-1/additional-costs')).toHaveLength(3)
    })
  })

  it('does not fetch document component data without tenant/company state', async () => {
    resetTenant()
    const user = userEvent.setup()
    const queryClient = createClient()

    // AdditionalCostsForm still tenant-gates its read (enabled: tenant && company).
    // Its query fires on mount if the gate is broken, so mounting it without
    // tenant/company is the meaningful exercise of "no fetch without tenant/company".
    render(<AdditionalCostsForm documentId="doc-1" />, { wrapper: wrapper(queryClient) })

    // The redesigned LineItemEntryBar product read is now tenant-gated again
    // (enabled requires tenant !== null && company !== null), so typing into it with
    // no tenant/company must NOT fire /products.
    render(<DocumentLineEditor lines={[]} onChange={vi.fn()} />, { wrapper: wrapper(createClient()) })

    const searchInput = screen.getByRole('combobox', { name: 'sales:lineItems.entry.placeholder' })
    await user.type(searchInput, 'Product')

    // Let any (incorrectly) eager query flush before asserting silence.
    await waitFor(() => {
      expect(mockApiGet).not.toHaveBeenCalled()
    })
  })
})
