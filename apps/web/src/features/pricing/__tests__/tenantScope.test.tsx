import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { priceListsInvalidationPredicate } from '../_invalidation'
import { PriceListDetailPage } from '../PriceListDetailPage'
import { PriceListForm } from '../PriceListForm'
import { PriceListListPage } from '../PriceListListPage'

// ─── api mock — module-level boundary ───────────────────────────────────────

const mockFetchPriceLists = vi.hoisted(() => vi.fn())
const mockFetchPriceList = vi.hoisted(() => vi.fn())
const mockCreatePriceList = vi.hoisted(() => vi.fn())
const mockUpdatePriceList = vi.hoisted(() => vi.fn())
const mockDeletePriceList = vi.hoisted(() => vi.fn())
const mockRemovePriceListItem = vi.hoisted(() => vi.fn())
const mockRemovePriceListFromPartner = vi.hoisted(() => vi.fn())

vi.mock('../api', () => ({
  fetchPriceLists: mockFetchPriceLists,
  fetchPriceList: mockFetchPriceList,
  createPriceList: mockCreatePriceList,
  updatePriceList: mockUpdatePriceList,
  deletePriceList: mockDeletePriceList,
  removePriceListItem: mockRemovePriceListItem,
  removePriceListFromPartner: mockRemovePriceListFromPartner,
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: 'pl-123' }),
    useNavigate: () => vi.fn(),
    Link: ({ children }: { children: React.ReactNode }) => children,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string, fallback?: string) => fallback ?? k }),
}))

// ─── Helpers ──────────────────────────────────────────────────────────────────

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 't@t',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'tok',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

function pricingKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && (k[0] === 'price-lists' || k[0] === 'price-list'))
}

beforeEach(() => {
  mockFetchPriceLists.mockReset()
  mockFetchPriceLists.mockResolvedValue({ data: [] })
  mockFetchPriceList.mockReset()
  mockFetchPriceList.mockResolvedValue({
    data: {
      id: 'pl-123',
      code: 'X',
      name: 'X',
      description: null,
      currency: 'TND',
      is_active: true,
      is_default: false,
      valid_from: null,
      valid_until: null,
      created_at: null,
      items: [],
      partners: [],
    },
  })
  mockCreatePriceList.mockReset()
  // createPriceList/updatePriceList resolve the unwrapped PriceList (id at top level).
  mockCreatePriceList.mockResolvedValue({ id: 'pl-new' })
  mockUpdatePriceList.mockReset()
  mockUpdatePriceList.mockResolvedValue({ id: 'pl-123' })
  mockDeletePriceList.mockReset()
  mockDeletePriceList.mockResolvedValue(undefined)
  mockRemovePriceListItem.mockReset()
  mockRemovePriceListItem.mockResolvedValue(undefined)
  mockRemovePriceListFromPartner.mockReset()
  mockRemovePriceListFromPartner.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('priceListsInvalidationPredicate (plural namespace)', () => {
  it('matches list useQuery leaf keys for the given t/c', () => {
    const pred = priceListsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['price-lists', 'all', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['price-lists', 'active', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['price-lists', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects singular price-list namespace', () => {
    const pred = priceListsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['price-list', 'pl-1', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects wrong tenant/company', () => {
    const pred = priceListsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['price-lists', 'all', 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['price-lists', 'all', 'tenant-A', 'company-2'] })).toBe(false)
  })

  it('rejects degenerate keys with fewer than 3 elements', () => {
    const pred = priceListsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['price-lists'] })).toBe(false)
    expect(pred({ queryKey: ['price-lists', 'tenant-A'] })).toBe(false)
  })
})

// ─── useQuery shape probes (callsites .544, .548, .552) ──────────────────────

describe('pricing page queryKey shapes', () => {
  it('PriceListDetailPage useQuery carries tenant + company at the suffix (.544)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<PriceListDetailPage />, { queryClient })
    await waitFor(() => {
      expect(mockFetchPriceList).toHaveBeenCalled()
    })
    const keys = pricingKeysFromCache(queryClient)
    const detail = keys.find((k) => k[0] === 'price-list')
    expect(detail).toEqual(['price-list', 'pl-123', 'tenant-A', 'company-1'])
  })

  it('PriceListForm (edit mode) useQuery carries tenant + company at the suffix (.548)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<PriceListForm />, { queryClient })
    await waitFor(() => {
      expect(mockFetchPriceList).toHaveBeenCalled()
    })
    const keys = pricingKeysFromCache(queryClient)
    const detail = keys.find((k) => k[0] === 'price-list')
    expect(detail).toEqual(['price-list', 'pl-123', 'tenant-A', 'company-1'])
  })

  it('PriceListListPage useQuery carries tenant + company at the suffix (.552)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<PriceListListPage />, { queryClient })
    await waitFor(() => {
      expect(mockFetchPriceLists).toHaveBeenCalled()
    })
    const keys = pricingKeysFromCache(queryClient)
    const list = keys.find((k) => k[0] === 'price-lists')
    // Key shape: ['price-lists', statusFilter, searchQuery, tenant, company].
    // Tenant + company remain at the suffix for tenant-scoped invalidation.
    expect(list).toEqual(['price-lists', 'all', '', 'tenant-A', 'company-1'])
  })

  it('queryKeys differ across tenants', async () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    renderWithProviders(<PriceListListPage />, { queryClient: cA })
    await waitFor(() => {
      expect(mockFetchPriceLists).toHaveBeenCalled()
    })
    const kA = JSON.stringify(pricingKeysFromCache(cA))

    mockFetchPriceLists.mockClear()
    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    renderWithProviders(<PriceListListPage />, { queryClient: cB })
    await waitFor(() => {
      expect(mockFetchPriceLists).toHaveBeenCalled()
    })
    const kB = JSON.stringify(pricingKeysFromCache(cB))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})

// ─── Cross-tenant isolation seed test ─────────────────────────────────────────
// Plural-namespace cascade: predicate should reject tenant-B [price-lists, ...].

describe('cross-tenant isolation (predicate-based plural cascade)', () => {
  it('predicate-based invalidate rejects tenant-B price-lists cache entry', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    const tenantBKey = ['price-lists', 'all', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, { data: [{ id: 'pl-tenant-b' }] })

    const pred = priceListsInvalidationPredicate('tenant-A', 'company-1')
    await queryClient.invalidateQueries({ predicate: pred })

    const tBQuery = queryClient.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual({ data: [{ id: 'pl-tenant-b' }] })
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })
})

// userEvent unused for now but kept imported in case future review wants
// real-form-submit driven cascade tests.
void userEvent
