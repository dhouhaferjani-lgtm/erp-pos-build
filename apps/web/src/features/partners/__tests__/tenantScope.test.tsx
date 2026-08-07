import { Routes, Route } from 'react-router-dom'
import { QueryClient, useQuery } from '@tanstack/react-query'
import { waitFor, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { PartnerDetailPage } from '../PartnerDetailPage'
import { PartnerForm } from '../PartnerForm'
import { PartnerListPage } from '../PartnerListPage'
import {
  partnerAccountBalanceInvalidationPredicate,
  partnersInvalidationPredicate,
  partnerVehiclesInvalidationPredicate,
} from '../_invalidation'
import { usePartnerContacts } from '../hooks/usePartnerContacts'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiInstance = vi.hoisted(() => ({
  get: vi.fn(),
}))
const mockGetCountries = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: mockApiInstance,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    apiPatch: mockApiPatch,
  }
})

vi.mock('../hooks/usePartnerBalanceRealtime', () => ({
  usePartnerBalanceRealtime: vi.fn(),
}))

vi.mock('../../settings/api/country', () => ({
  getCountries: mockGetCountries,
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
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

const fullPartner = {
  id: 'partner-1',
  name: 'Acme Corp',
  type: 'customer' as const,
  customer_category: 'business' as const,
  company_legal_name: 'Acme Corporation',
  business_registration_number: '123456',
  payment_terms: 'net_30',
  payment_terms_days: null,
  credit_limit: '1000.00',
  discount_percentage: '0.00',
  invoice_consolidation: false,
  consolidation_frequency: null,
  email: 'contact@example.com',
  phone: null,
  street_address: null,
  city: null,
  state: null,
  postal_code: null,
  country: null,
  country_code: null,
  vat_number: null,
  tax_status: 'REGISTERED' as const,
  exemption_reason: null,
  exemption_certificate_path: null,
  exemption_valid_until: null,
  notes: null,
}

function partnersKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockResolvedValue([])
  mockApiPost.mockResolvedValue({ id: 'partner-new', name: 'New Partner' })
  mockApiPatch.mockResolvedValue({ id: 'partner-1', name: 'Acme Corp' })
  mockGetCountries.mockResolvedValue([])
  mockApiInstance.get.mockImplementation((url: string) => {
    if (url.startsWith('/partners/') && !url.includes('/account-balance')) {
      return Promise.resolve({ data: { data: fullPartner } })
    }
    if (url.includes('/account-balance')) {
      return Promise.resolve({
        data: {
          data: {
            partner_id: 'partner-1',
            currency: 'TND',
            unallocated_balance: '0.00',
            deposit_count: 0,
          },
        },
      })
    }
    if (url.startsWith('/partners?')) {
      return Promise.resolve({
        data: {
          data: [],
          meta: {
            total: 0,
            current_page: 1,
            per_page: 25,
            last_page: 1,
            from: null,
            to: null,
          },
        },
      })
    }
    return Promise.resolve({ data: { data: [] } })
  })
})

afterEach(() => {
  resetTenant()
})

describe('partner queryKey tenant scope', () => {
  it('scopes PartnerListPage list query (.437)', () => {
    const queryClient = createTestQueryClient()

    renderWithProviders(<PartnerListPage />, { queryClient })

    // The partner-type segment ('all' when the page is mounted outside the
    // Clients/Fournisseurs routes) was added by the BUG-006 fix so the two
    // typed lists can never share a cache entry. Tenant + company stay the
    // trailing suffix, which is what this test locks.
    expect(partnersKeysFromCache(queryClient)).toContainEqual([
      'partners',
      'all',
      { sort_by: 'name', sort_dir: 'asc', page: '1', per_page: '25' },
      'tenant-A',
      'company-1',
    ])
  })

  it('scopes PartnerDetailPage detail, documents, payments, and balance queries (.427-.430)', () => {
    const queryClient = createPersistentQueryClient()

    renderWithProviders(
      <Routes>
        <Route path="/sales/customers/:id" element={<PartnerDetailPage />} />
      </Routes>,
      { route: '/sales/customers/partner-1', queryClient },
    )

    expect(partnersKeysFromCache(queryClient)).toEqual(expect.arrayContaining([
      ['partner', 'partner-1', 'tenant-A', 'company-1'],
      ['partner-documents', 'partner-1', false, 1, 10, 'tenant-A', 'company-1'],
      ['partner-payments', 'partner-1', 1, 10, 'tenant-A', 'company-1'],
      ['partner-account-balance', 'partner-1', 'tenant-A', 'company-1'],
    ]))
  })

  it('scopes PartnerForm country and edit detail queries (.432, .433)', () => {
    const queryClient = createTestQueryClient()

    renderWithProviders(
      <Routes>
        <Route path="/partners/:id/edit" element={<PartnerForm />} />
      </Routes>,
      { route: '/partners/partner-1/edit', queryClient },
    )

    expect(partnersKeysFromCache(queryClient)).toEqual(expect.arrayContaining([
      ['countries', 'active', 'tenant-A', 'company-1'],
      ['partner', 'partner-1', 'tenant-A', 'company-1'],
    ]))
  })

  it('scopes usePartnerContacts query (.441)', () => {
    function ContactsProbe() {
      usePartnerContacts('partner-1')
      return null
    }
    const queryClient = createTestQueryClient()

    renderWithProviders(<ContactsProbe />, { queryClient })

    expect(partnersKeysFromCache(queryClient)).toContainEqual([
      'partner-contacts',
      'partner-1',
      'tenant-A',
      'company-1',
    ])
  })
})

describe('partner invalidation predicates', () => {
  it('match tenant-scoped partner list-like caches and reject cross-tenant entries', () => {
    const pred = partnersInvalidationPredicate('tenant-A', 'company-1')

    expect(pred({ queryKey: ['partners', { page: 1 }, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['partners', 'search', 'acme', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['partner', 'partner-1', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['partners', { page: 1 }, 'tenant-B', 'company-1'] })).toBe(false)
  })

  it('matches tenant-scoped partner vehicle caches for one partner only (.431)', () => {
    const pred = partnerVehiclesInvalidationPredicate('partner-1', 'tenant-A', 'company-1')

    expect(pred({ queryKey: ['partner-vehicles', 'partner-1', 15, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['partner-vehicles', 'partner-2', 15, 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['partner-vehicles', 'partner-1', 15, 'tenant-B', 'company-1'] })).toBe(false)
  })

  it('matches tenant-scoped account-balance caches and rejects cross-tenant entries (.440)', () => {
    const pred = partnerAccountBalanceInvalidationPredicate('tenant-A', 'company-1')

    expect(pred({ queryKey: ['partner-account-balance', 'partner-1', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['partner-account-balance', 'partner-1', 'tenant-B', 'company-1'] })).toBe(false)
  })
})

describe('PartnerForm mutation cascades', () => {
  function PartnersListProbe({ onFetch }: { onFetch: () => void }) {
    const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
    const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
    useQuery({
      queryKey: tenantScopedKey(['partners', { page: 1 }]),
      queryFn: async () => {
        onFetch()
        return []
      },
      enabled: tenantId !== null && companyId !== null,
    })
    return null
  }

  it('create invalidates only current-tenant partner lists (.434)', async () => {
    const user = userEvent.setup()
    let listFetches = 0
    const queryClient = createPersistentQueryClient()

    renderWithProviders(
      <>
        <PartnersListProbe onFetch={() => { listFetches += 1 }} />
        <PartnerForm />
      </>,
      { queryClient },
    )

    await waitFor(() => {
      expect(listFetches).toBe(1)
    })
    queryClient.setQueryData(['partners', { page: 1 }, 'tenant-B', 'company-1'], { marker: 'tenant-B' })

    await user.type(screen.getByLabelText(/^name\s*\*?$/i), 'New Partner')
    await user.selectOptions(screen.getByLabelText(/^type/i), 'customer')
    await user.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalled()
      expect(listFetches).toBe(2)
    })
    expect(queryClient.getQueryData(['partners', { page: 1 }, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B' })
  })

  it('update invalidates current-tenant lists and exact detail only (.435, .436)', async () => {
    const user = userEvent.setup()
    let listFetches = 0
    let detailFetches = 0
    mockApiInstance.get.mockImplementation((url: string) => {
      if (url === '/partners/partner-1') {
        detailFetches += 1
        return Promise.resolve({ data: { data: fullPartner } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    const queryClient = createPersistentQueryClient()
    queryClient.setQueryData(['partners', { page: 1 }, 'tenant-B', 'company-1'], { marker: 'tenant-B' })
    queryClient.setQueryData(['partner', 'partner-1', 'tenant-B', 'company-1'], { marker: 'tenant-B' })

    renderWithProviders(
      <>
        <PartnersListProbe onFetch={() => { listFetches += 1 }} />
        <Routes>
          <Route path="/partners/:id/edit" element={<PartnerForm />} />
        </Routes>
      </>,
      { route: '/partners/partner-1/edit', queryClient },
    )

    await waitFor(() => {
      expect(screen.getByLabelText(/^name\s*\*?$/i)).toHaveValue('Acme Corp')
      expect(listFetches).toBe(1)
      expect(detailFetches).toBe(1)
    })

    await user.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalled()
      expect(listFetches).toBe(2)
      expect(detailFetches).toBe(2)
    })
    expect(queryClient.getQueryData(['partners', { page: 1 }, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B' })
    expect(queryClient.getQueryData(['partner', 'partner-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B' })
  })
})
