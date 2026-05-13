import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Search } from 'lucide-react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { CompanyConfigProvider } from '@/contexts/CompanyConfigContext'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { AddPartnerModal } from '../organisms/AddPartnerModal/AddPartnerModal'
import { AddQuickProductModal } from '../organisms/AddQuickProductModal/AddQuickProductModal'
import { AddRepositoryModal } from '../organisms/AddRepositoryModal/AddRepositoryModal'
import { DocumentSearchSelect } from '../ui/DocumentSearchSelect'
import { LocationBadge } from '../ui/LocationBadge'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiGetHelper = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGet,
    },
    apiGet: mockApiGetHelper,
    apiPost: mockApiPost,
  }
})

vi.mock('@/features/finance/hooks/useAccounts', () => ({
  useAccounts: () => ({ data: [] }),
}))

vi.mock('../molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: ({ onChange }: { onChange: (configId: string, taxRate: string) => void }) => (
    <button type="button" onClick={() => { onChange('tax-1', '19') }}>
      select-tax
    </button>
  ),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback ?? key,
  }),
}))

interface TestDocument {
  id: string
  document_number: string
  document_date: string
  partner: { id: string; name: string } | null
  total: string
  status: string
  currency: string
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
    return (
      <MemoryRouter initialEntries={['/sales/invoices/new']}>
        <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
      </MemoryRouter>
    )
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiPost.mockImplementation((url: string) => {
    if (url === '/partners') return Promise.resolve({ id: 'partner-1', name: 'Partner A', type: 'customer' })
    if (url === '/products') return Promise.resolve({ id: 'product-1', name: 'Product A', sku: null })
    if (url === '/payment-repositories') return Promise.resolve({ data: { id: 'repo-1', name: 'Cash Register' } })
    return Promise.resolve({ data: {} })
  })
  mockApiGet.mockImplementation((url: string) => {
    if (url === '/locations/loc-1') {
      return Promise.resolve({ data: { data: { id: 'loc-1', name: 'Shop', code: 'S1', is_default: true, is_active: true } } })
    }
    if (url.startsWith('/invoices')) {
      return Promise.resolve({
        data: {
          data: [
            {
              id: 'doc-1',
              document_number: 'INV-001',
              document_date: '2026-05-11',
              partner: { id: 'partner-1', name: 'Partner A' },
              total: '100',
              status: 'posted',
              currency: 'EUR',
            },
          ],
        },
      })
    }
    return Promise.resolve({ data: { data: [] } })
  })
  mockApiGetHelper.mockResolvedValue({
    vertical: 'generic',
    default_modules: [],
    enabled_extras: [],
    all_enabled_modules: ['Inventory'],
    currency: 'EUR',
    locale: 'en_US',
    country_code: 'TN',
    smart_prompts_enabled: false,
    smart_prompts_variant: 'off',
    line_designation_override_enabled: false,
  })
})

afterEach(() => {
  resetTenant()
})

describe('shared singleton tenant scope', () => {
  it('scopes modal invalidations (.001-.003)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    queryClient.setQueryData(['partners', 'tenant-A', 'company-1'], ['tenant-A-partners'])
    queryClient.setQueryData(['partners', 'tenant-B', 'company-2'], ['tenant-B-partners'])
    render(<AddPartnerModal isOpen={true} onClose={vi.fn()} partnerType="customer" />, { wrapper: wrapper(queryClient) })
    await user.type(screen.getByLabelText(/sales:partners.name/), 'Partner A')
    await user.click(screen.getByRole('button', { name: 'common:actions.create' }))
    await waitFor(() => {
      expect(queryClient.getQueryState(['partners', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    })
    expect(queryClient.getQueryState(['partners', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
    cleanup()

    queryClient.setQueryData(['products', 'tenant-A', 'company-1'], ['tenant-A-products'])
    queryClient.setQueryData(['products', 'tenant-B', 'company-2'], ['tenant-B-products'])
    render(<AddQuickProductModal isOpen={true} onClose={vi.fn()} />, { wrapper: wrapper(queryClient) })
    await user.type(screen.getByLabelText(/inventory:products.name/), 'Product A')
    await user.type(screen.getByLabelText(/inventory:products.salePrice/), '10')
    await user.click(screen.getByRole('button', { name: 'select-tax' }))
    await user.click(screen.getByRole('button', { name: 'common:actions.create' }))
    await waitFor(() => {
      expect(queryClient.getQueryState(['products', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    })
    expect(queryClient.getQueryState(['products', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
    cleanup()

    queryClient.setQueryData(['payment-repositories', 'tenant-A', 'company-1'], ['tenant-A-repos'])
    queryClient.setQueryData(['payment-repositories', 'tenant-B', 'company-2'], ['tenant-B-repos'])
    render(<AddRepositoryModal isOpen={true} onClose={vi.fn()} />, { wrapper: wrapper(queryClient) })
    await user.type(screen.getByLabelText(/Code/), 'CASH-01')
    await user.type(screen.getByLabelText(/Name/), 'Cash Register')
    await user.click(screen.getByRole('button', { name: 'common:actions.save' }))
    await waitFor(() => {
      expect(queryClient.getQueryState(['payment-repositories', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    })
    expect(queryClient.getQueryState(['payment-repositories', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
  })

  it('scopes shared reads (.018-.019, .026-.029, .094)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()

    render(
      <>
        <DocumentSearchSelect<TestDocument>
          onChange={vi.fn()}
          config={{
            endpoint: '/invoices',
            queryKey: 'invoices-search',
            icon: Search,
            searchPlaceholder: 'Search invoices',
            noResultsMessage: 'No invoices',
            noDataMessage: 'No invoices',
          }}
        />
        <LocationBadge locationId="loc-1" />
        <CompanyConfigProvider>
          <div>company-config-child</div>
        </CompanyConfigProvider>
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await user.click(screen.getByRole('button', { name: 'common:actions.select' }))

    await waitFor(() => {
      expect(queryClient.getQueryData(['location', 'loc-1', 'tenant-A', 'company-1'])).toEqual({
        id: 'loc-1',
        name: 'Shop',
        code: 'S1',
        is_default: true,
        is_active: true,
      })
      expect(queryClient.getQueryData(['company-config', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['invoices-search', '', undefined, undefined, 'tenant-A', 'company-1'])).toBeDefined()
    })
  })

  it('does not fetch shared singleton reads without tenant/company state', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    resetTenant()

    render(
      <>
        <DocumentSearchSelect<TestDocument>
          onChange={vi.fn()}
          config={{
            endpoint: '/invoices',
            queryKey: 'invoices-search',
            icon: Search,
            searchPlaceholder: 'Search invoices',
            noResultsMessage: 'No invoices',
            noDataMessage: 'No invoices',
          }}
        />
        <LocationBadge locationId="loc-1" />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await user.click(screen.getByRole('button', { name: 'common:actions.select' }))

    expect(mockApiGet).not.toHaveBeenCalled()
    expect(queryClient.getQueryData(['location', 'loc-1', null, null])).toBeUndefined()
    expect(queryClient.getQueryData(['invoices-search', '', undefined, undefined, null, null])).toBeUndefined()
  })
})
