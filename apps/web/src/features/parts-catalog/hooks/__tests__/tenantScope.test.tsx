import { QueryClient, useQuery } from '@tanstack/react-query'
import { Routes, Route } from 'react-router-dom'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { ArticleDetailPage } from '../../pages/ArticleDetailPage'
import { partsCatalogKeys } from '../usePartsCatalog'
import { useArticleDetail, useArticleDetailParallel, useArticleLinkages } from '../useArticleDetail'
import { useCategoryArticles, useVehicleArticles } from '../useArticles'
import { useCriteriaMetadata } from '../useCriteriaMetadata'
import { useCriteriaSearch } from '../useCriteriaSearch'
import { useManufacturers } from '../useManufacturers'
import { useModelSeries } from '../useModelSeries'
import { useMultiSearch } from '../useMultiSearch'
import { useSearchTreeChildren, useSearchTreeRoots } from '../useSearchTree'
import { useSupplierBrands } from '../useSupplierBrands'
import { useVehicles } from '../useVehicles'
import type { EnrichedArticle } from '../../types/catalog'

const mockPartsCatalogApi = vi.hoisted(() => ({
  getArticle: vi.fn(),
  getArticleLinkages: vi.fn(),
  getVehicleArticles: vi.fn(),
  getSearchTreeArticles: vi.fn(),
  getCriteriaMetadata: vi.fn(),
  searchByCriteria: vi.fn(),
  getManufacturers: vi.fn(),
  getModelSeries: vi.fn(),
  multiSearch: vi.fn(),
  getSearchTreeRoots: vi.fn(),
  getSearchTreeChildren: vi.fn(),
  getSupplierBrands: vi.fn(),
  getVehicles: vi.fn(),
}))

vi.mock('../../api/partsCatalog', () => mockPartsCatalogApi)

const articleFixture: EnrichedArticle = {
  id: 'article-1',
  article_number: 'A-1',
  status: 'active',
  supplier: { id: 'supplier-1', brand: 'Supplier', slug: 'supplier' },
  cross_references: [],
  criteria: [],
  compatible_vehicles: [],
  local_inventory: {
    product_id: null,
    in_stock: false,
    total_quantity: 0,
    available_quantity: 0,
    sale_price: null,
    purchase_price: null,
  },
  prices: [],
}

vi.mock('../../components/organisms/ArticleDetailPanel', () => ({
  ArticleDetailPanel: ({ onAddToInventory }: { onAddToInventory: (article: EnrichedArticle) => void }) => (
    <button type="button" onClick={() => { onAddToInventory(articleFixture) }}>
      Add to inventory
    </button>
  ),
}))

vi.mock('../../components/organisms/AddToInventoryModal', () => ({
  AddToInventoryModal: ({
    isOpen,
    onSuccess,
  }: {
    isOpen: boolean
    onSuccess: () => void
  }) => (
    isOpen ? (
      <button type="button" onClick={onSuccess}>
        Confirm add
      </button>
    ) : null
  ),
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

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function partsCatalogKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && k[0] === 'parts-catalog')
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')

  mockPartsCatalogApi.getArticle.mockResolvedValue(articleFixture)
  mockPartsCatalogApi.getArticleLinkages.mockResolvedValue({ vehicles: [] })
  mockPartsCatalogApi.getVehicleArticles.mockResolvedValue({
    data: [],
    meta: { cursor: null, has_more: false, per_page: 20 },
  })
  mockPartsCatalogApi.getSearchTreeArticles.mockResolvedValue({
    data: [],
    meta: { cursor: null, has_more: false, per_page: 20 },
  })
  mockPartsCatalogApi.getCriteriaMetadata.mockResolvedValue([])
  mockPartsCatalogApi.searchByCriteria.mockResolvedValue({
    data: [],
    meta: { cursor: null, has_more: false, per_page: 20 },
  })
  mockPartsCatalogApi.getManufacturers.mockResolvedValue([])
  mockPartsCatalogApi.getModelSeries.mockResolvedValue([])
  mockPartsCatalogApi.multiSearch.mockResolvedValue({
    articles: [],
    detected_brand: null,
    search_methods_used: ['article_number'],
  })
  mockPartsCatalogApi.getSearchTreeRoots.mockResolvedValue([])
  mockPartsCatalogApi.getSearchTreeChildren.mockResolvedValue([])
  mockPartsCatalogApi.getSupplierBrands.mockResolvedValue([])
  mockPartsCatalogApi.getVehicles.mockResolvedValue([])
})

afterEach(() => {
  resetTenant()
})

describe('parts catalog queryKey tenant scope', () => {
  function PartsCatalogHooksProbe() {
    useArticleDetail('article-1')
    useArticleLinkages('article-1')
    useArticleDetailParallel('article-2')
    useVehicleArticles('pc', 'vehicle-1', 'group-1')
    useCategoryArticles('node-1')
    useCriteriaMetadata()
    useCriteriaSearch([{ criteria_id: 'criterion-1', value: 'v' }], 'group-1')
    useManufacturers()
    useModelSeries('manufacturer-1')
    useMultiSearch(' brake pad ')
    useSearchTreeRoots('pc')
    useSearchTreeChildren('node-1')
    useSupplierBrands()
    useVehicles('series-1', 'pc')
    return null
  }

  it('scopes every B28 parts catalog hook key at the tenant/company suffix (.442-.456)', () => {
    const queryClient = createTestQueryClient()

    renderWithProviders(<PartsCatalogHooksProbe />, { queryClient })

    expect(partsCatalogKeysFromCache(queryClient)).toEqual(expect.arrayContaining([
      ['parts-catalog', 'article', 'article-1', 'tenant-A', 'company-1'],
      ['parts-catalog', 'article', 'article-1', 'linkages', 'tenant-A', 'company-1'],
      ['parts-catalog', 'article', 'article-2', 'tenant-A', 'company-1'],
      ['parts-catalog', 'article', 'article-2', 'linkages', 'tenant-A', 'company-1'],
      [
        'parts-catalog',
        'articles',
        { vehicleType: 'pc', vehicleId: 'vehicle-1', productGroupId: 'group-1' },
        'tenant-A',
        'company-1',
      ],
      ['parts-catalog', 'search-tree', 'node-1', 'articles', 'tenant-A', 'company-1'],
      ['parts-catalog', 'criteria-metadata', 'tenant-A', 'company-1'],
      [
        'parts-catalog',
        'criteria-search',
        { criteriaFilters: [{ criteria_id: 'criterion-1', value: 'v' }], productGroupId: 'group-1' },
        'tenant-A',
        'company-1',
      ],
      ['parts-catalog', 'manufacturers', 'tenant-A', 'company-1'],
      ['parts-catalog', 'model-series', 'manufacturer-1', 'tenant-A', 'company-1'],
      ['parts-catalog', 'search', 'brake pad', 'tenant-A', 'company-1'],
      ['parts-catalog', 'search-tree', 'roots', 'pc', 'tenant-A', 'company-1'],
      ['parts-catalog', 'search-tree', 'node-1', 'children', 'tenant-A', 'company-1'],
      ['parts-catalog', 'supplier-brands', 'tenant-A', 'company-1'],
      ['parts-catalog', 'vehicles', 'series-1', 'pc', 'tenant-A', 'company-1'],
    ]))
  })

  it('uses different cache slots across tenants', () => {
    setTenant('tenant-A', 'company-1')
    const clientA = createTestQueryClient()
    renderWithProviders(<PartsCatalogHooksProbe />, { queryClient: clientA })

    setTenant('tenant-B', 'company-1')
    const clientB = createTestQueryClient()
    renderWithProviders(<PartsCatalogHooksProbe />, { queryClient: clientB })

    expect(JSON.stringify(partsCatalogKeysFromCache(clientA))).toContain('tenant-A')
    expect(JSON.stringify(partsCatalogKeysFromCache(clientB))).toContain('tenant-B')
    expect(JSON.stringify(partsCatalogKeysFromCache(clientA))).not.toEqual(
      JSON.stringify(partsCatalogKeysFromCache(clientB)),
    )
  })
})

describe('ArticleDetailPage tenant-scoped invalidation', () => {
  function ArticleDetailProbe({ onFetch }: { onFetch: () => void }) {
    const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
    const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
    useQuery({
      queryKey: tenantScopedKey([...partsCatalogKeys.articleDetail('article-1')]),
      queryFn: async () => {
        onFetch()
        return articleFixture
      },
      enabled: tenantId !== null && companyId !== null,
    })
    return null
  }

  it('refetches current-tenant article detail after inventory add and leaves tenant-B cache untouched (.457)', async () => {
    const user = userEvent.setup()
    let articleFetches = 0
    const queryClient = createPersistentQueryClient()

    renderWithProviders(
      <>
        <ArticleDetailProbe onFetch={() => { articleFetches += 1 }} />
        <Routes>
          <Route path="/parts/:articleId" element={<ArticleDetailPage />} />
        </Routes>
      </>,
      { route: '/parts/article-1', queryClient },
    )

    await waitFor(() => {
      expect(articleFetches).toBe(1)
    })
    queryClient.setQueryData(
      ['parts-catalog', 'article', 'article-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B' },
    )

    await user.click(screen.getByRole('button', { name: /add to inventory/i }))
    await user.click(screen.getByRole('button', { name: /confirm add/i }))

    await waitFor(() => {
      expect(articleFetches).toBe(2)
    })
    expect(queryClient.getQueryData(['parts-catalog', 'article', 'article-1', 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B',
    })
  })
})
