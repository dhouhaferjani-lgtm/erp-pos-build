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

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      post: mockApiPost,
      delete: mockApiDelete,
    },
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

vi.mock('@/components/organisms', () => ({
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
  mockApiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/products')) {
      return { data: { data: [{ id: 'product-1', name: 'Product 1', sku: 'P1', sale_price: 10, tax_rate: 0 }] } }
    }
    if (url.startsWith('/services')) {
      return { data: { data: [{ id: 'service-1', name: 'Service 1', code: 'S1', base_price: 20, tax_rate: 0 }] } }
    }
    if (url.startsWith('/documents/doc-1/additional-costs')) {
      return { data: { data: [{ id: 'cost-1', cost_type: 'shipping', amount: 5 }] } }
    }
    return { data: { data: [] } }
  })
  mockApiPost.mockResolvedValue({ data: { ok: true } })
  mockApiDelete.mockResolvedValue({ data: { ok: true } })
})

afterEach(() => {
  vi.unstubAllGlobals()
  resetTenant()
})

describe('document component tenant scope', () => {
  it('scopes document line product/service reads and product invalidation (.147-.149)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    queryClient.setQueryData(['products', 'tenant-A', 'company-1'], ['tenant-A-products'])
    queryClient.setQueryData(['products', 'tenant-B', 'company-2'], ['tenant-B-products'])
    const onChange = vi.fn<(lines: DocumentLine[]) => void>()

    render(<DocumentLineEditor lines={[]} onChange={onChange} />, { wrapper: wrapper(queryClient) })

    await user.click(screen.getByRole('button', { name: 'sales:lineItems.actions.searchProducts' }))

    await waitFor(() => {
      expect(queryClient.getQueryData(['products', '', 'tenant-A', 'company-1'])).toEqual({
        data: [{ id: 'product-1', name: 'Product 1', sku: 'P1', sale_price: 10, tax_rate: 0 }],
      })
    })

    await user.click(screen.getByRole('button', { name: 'sales:lineItems.tabs.service' }))

    await waitFor(() => {
      expect(queryClient.getQueryData(['services', '', 'tenant-A', 'company-1'])).toEqual({
        data: [{ id: 'service-1', name: 'Service 1', code: 'S1', base_price: 20, tax_rate: 0 }],
      })
    })

    await user.click(screen.getByRole('button', { name: 'sales:lineItems.tabs.product' }))
    await user.click(screen.getByRole('button', { name: 'sales:lineItems.actions.createNewProduct' }))
    await user.click(screen.getByRole('button', { name: 'create-product-success' }))

    await waitFor(() => {
      expect(queryClient.getQueryState(['products', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    })
    expect(queryClient.getQueryState(['products', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
  })

  it('scopes additional cost reads and exact invalidations (.150-.152)', async () => {
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
    await user.click(screen.getByRole('button', { name: /Add Cost/ }))

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
    const queryClient = createClient()

    render(<DocumentLineEditor lines={[]} onChange={vi.fn()} />, { wrapper: wrapper(queryClient) })
    await userEvent.click(screen.getByRole('button', { name: 'sales:lineItems.actions.searchProducts' }))
    render(<AdditionalCostsForm documentId="doc-1" />, { wrapper: wrapper(createClient()) })

    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
