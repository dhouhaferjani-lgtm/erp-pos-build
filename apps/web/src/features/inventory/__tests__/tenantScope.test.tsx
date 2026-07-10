import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { QueryClient, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'
import { tenantScopedKey } from '@/lib/tenantScopedKey'

import {
  inventoryProductsInvalidationPredicate,
  stockLevelsInvalidationPredicate,
} from '../_invalidation'
import { ProductDetailPage } from '../ProductDetailPage'
import { ProductForm } from '../ProductForm'
import { ProductListPage } from '../ProductListPage'
import { StockLevelsPage } from '../StockLevelsPage'
import { StockMovementsPage } from '../StockMovementsPage'
import { useCatalogLookup } from '../api/platformQueries'
import { ProductDocumentsTab } from '../components/ProductDocumentsTab'
import { ProductMovementsTab } from '../components/ProductMovementsTab'
import { ProductStockLevels } from '../components/ProductStockLevels'
import { PriceInputWithMargin } from '../components/pricing/PriceInputWithMargin'
import { useLoyaltyEarnRate } from '../useLoyaltyEarnRate'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPostMethod = vi.hoisted(() => vi.fn())
const mockApiDeleteMethod = vi.hoisted(() => vi.fn())
const mockApiGetHelper = vi.hoisted(() => vi.fn())
const mockApiPostHelper = vi.hoisted(() => vi.fn())
const mockApiPatchHelper = vi.hoisted(() => vi.fn())
const mockApiDeleteHelper = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      post: mockApiPostMethod,
      delete: mockApiDeleteMethod,
    },
    apiGet: mockApiGetHelper,
    apiPost: mockApiPostHelper,
    apiPatch: mockApiPatchHelper,
    apiDelete: mockApiDeleteHelper,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@/hooks/usePageTitle', () => ({
  usePageTitle: vi.fn(),
}))

vi.mock('@/hooks/useTaxConfigName', () => ({
  useTaxConfigName: () => null,
}))

vi.mock('@/features/products/hooks/useProductRealtime', () => ({
  useProductRealtime: vi.fn(),
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 't@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [
      {
        id: companyId,
        name: 'Company',
        legalName: 'Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'EUR',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

function inventoryKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  const namespaces = new Set([
    'platform',
    'product',
    'products',
    'product-documents',
    'product-movements',
    'product-stock',
    'margin-check',
    'locations',
    'stock-levels',
    'stock-movements',
  ])
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && namespaces.has(String(k[0])))
}

function expectScoped(key: unknown[] | undefined, prefix: readonly unknown[]) {
  expect(key).toEqual([...prefix, 'tenant-A', 'company-1'])
}

beforeEach(() => {
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockReset()
  mockApiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/products?')) {
      return {
        data: {
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
          aggregates: { total_products: 0, total_active: 0, average_price: null },
        },
      }
    }
    if (url.startsWith('/products/prod-1')) {
      return {
        data: {
          data: {
            id: 'prod-1',
            name: 'Product',
            sku: 'SKU',
            is_physical: true,
            category_id: null,
            description: null,
            sale_price: '10.00',
            purchase_price: null,
            cost_price: null,
            tax_rate: null,
            default_tax_configuration_id: null,
            unit: null,
            barcode: null,
            is_active: true,
            oem_numbers: null,
            cross_references: null,
            parapharmacy_metadata: null,
            created_at: '2026-01-01',
            updated_at: null,
          },
        },
      }
    }
    if (url.startsWith('/stock-levels')) {
      return { data: { data: [], meta: { total: 0 } } }
    }
    if (url.startsWith('/stock-movements')) {
      return { data: { data: [] } }
    }
    if (url.startsWith('/documents')) {
      return { data: { data: [] } }
    }
    return { data: { data: [] } }
  })
  mockApiPostMethod.mockReset()
  mockApiPostMethod.mockResolvedValue({ data: { data: {} } })
  mockApiDeleteMethod.mockReset()
  mockApiDeleteMethod.mockResolvedValue({ data: {} })
  mockApiGetHelper.mockReset()
  mockApiGetHelper.mockResolvedValue([])
  mockApiPostHelper.mockReset()
  mockApiPostHelper.mockResolvedValue({})
  mockApiPatchHelper.mockReset()
  mockApiPatchHelper.mockResolvedValue({})
  mockApiDeleteHelper.mockReset()
  mockApiDeleteHelper.mockResolvedValue({})
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('inventory invalidation predicates', () => {
  it('matches product list keys for the active tenant/company and rejects singular product keys', () => {
    const pred = inventoryProductsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['products', { page: 1 }, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['product', 'prod-1', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['products', { page: 1 }, 'tenant-B', 'company-1'] })).toBe(false)
  })

  it('matches stock-level list keys and rejects sibling stock namespaces', () => {
    const pred = stockLevelsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['stock-levels', '', 'loc-1', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['stock-movements', '', 'all', 'loc-1', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['product-stock', 'prod-1', 'tenant-A', 'company-1'] })).toBe(false)
  })
})

describe('inventory queryKey shapes', () => {
  function CatalogLookupProbe() {
    useCatalogLookup('5901234123457')
    return null
  }

  function LoyaltyEarnRateProbe() {
    useLoyaltyEarnRate()
    return null
  }

  it('wraps product page keys (.300, .302, .306)', async () => {
    const queryClient = createTestQueryClient()
    renderWithProviders(
      <>
        <ProductListPage />
        <Routes>
          <Route path="/inventory/products/:id" element={<ProductDetailPage />} />
          <Route path="/inventory/products/:id/edit" element={<ProductForm />} />
        </Routes>
      </>,
      { queryClient, route: '/inventory/products/prod-1/edit' },
    )

    await waitFor(() => {
      const keys = inventoryKeysFromCache(queryClient)
      expect(keys.some((k) => k[0] === 'products')).toBe(true)
      expect(keys.some((k) => k[0] === 'product')).toBe(true)
    })

    const keys = inventoryKeysFromCache(queryClient)
    const productsKey = keys.find((k) => k[0] === 'products')
    const productKey = keys.find((k) => k[0] === 'product')
    expect(productsKey?.slice(-2)).toEqual(['tenant-A', 'company-1'])
    expectScoped(productKey, ['product', 'prod-1'])
  })

  it('wraps stock page keys (.307, .308, .313)', async () => {
    const queryClient = createTestQueryClient()
    renderWithProviders(
      <>
        <StockLevelsPage />
        <StockMovementsPage />
      </>,
      { queryClient },
    )

    await waitFor(() => {
      const keys = inventoryKeysFromCache(queryClient)
      expect(keys.some((k) => k[0] === 'locations')).toBe(true)
      expect(keys.some((k) => k[0] === 'stock-levels')).toBe(true)
      expect(keys.some((k) => k[0] === 'stock-movements')).toBe(true)
    })

    const keys = inventoryKeysFromCache(queryClient)
    expectScoped(keys.find((k) => k[0] === 'locations'), ['locations'])
    expectScoped(keys.find((k) => k[0] === 'stock-levels'), ['stock-levels', '', null])
    expectScoped(keys.find((k) => k[0] === 'stock-movements'), ['stock-movements', '', 'all', null])
  })

  it('wraps platform and product subcomponent keys (.314-.318)', async () => {
    const queryClient = createTestQueryClient()
    renderWithProviders(
      <>
        <CatalogLookupProbe />
        <ProductDocumentsTab productId="prod-1" />
        <ProductMovementsTab productId="prod-1" />
        <ProductStockLevels productId="prod-1" costPrice="10.00" canViewCostPrices />
        <PriceInputWithMargin productId="prod-1" value={20} onChange={() => {}} />
      </>,
      { queryClient },
    )

    await waitFor(() => {
      const keys = inventoryKeysFromCache(queryClient)
      expect(keys.some((k) => k[0] === 'platform')).toBe(true)
      expect(keys.some((k) => k[0] === 'product-documents')).toBe(true)
      expect(keys.some((k) => k[0] === 'product-movements')).toBe(true)
      expect(keys.some((k) => k[0] === 'product-stock')).toBe(true)
      expect(keys.some((k) => k[0] === 'margin-check')).toBe(true)
    })

    const keys = inventoryKeysFromCache(queryClient)
    expectScoped(keys.find((k) => k[0] === 'platform'), ['platform', 'catalog-lookup', '5901234123457'])
    expectScoped(keys.find((k) => k[0] === 'product-documents'), ['product-documents', 'prod-1', 1, 25])
    expectScoped(keys.find((k) => k[0] === 'product-movements'), ['product-movements', 'prod-1', [], 1, 25])
    expectScoped(keys.find((k) => k[0] === 'product-stock'), ['product-stock', 'prod-1'])
    expectScoped(keys.find((k) => k[0] === 'margin-check'), ['margin-check', 'prod-1', '20'])
  })

  it('wraps loyalty earn-rate key and gates missing tenant/company', async () => {
    mockApiGetHelper.mockReset()
    mockApiGetHelper.mockResolvedValue({ rate: '0.1000' })
    const queryClient = createTestQueryClient()

    renderWithProviders(<LoyaltyEarnRateProbe />, { queryClient })

    await waitFor(() => {
      expect(queryClient.getQueryData(['loyalty', 'earn-rate', 'tenant-A', 'company-1'])).toEqual({ rate: '0.1000' })
    })

    const earnRateCalls = mockApiGetHelper.mock.calls.length
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
    renderWithProviders(<LoyaltyEarnRateProbe />, { queryClient: createTestQueryClient() })

    expect(mockApiGetHelper).toHaveBeenCalledTimes(earnRateCalls)
  })
})

describe('inventory cascades and cross-tenant isolation', () => {
  function ProductsCascadeProbe() {
    const queryClient = useQueryClient()
    const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
    const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
    useQuery({
      queryKey: tenantScopedKey(['products', { page: 1 }]),
      queryFn: async () => {
        productListCalls += 1
        return [{ id: `prod-${productListCalls}` }]
      },
      enabled: !!tenantId && !!companyId,
    })
    const mutation = useMutation({
      mutationFn: async () => ({}),
      onSuccess: async () => {
        await queryClient.invalidateQueries({
          predicate: inventoryProductsInvalidationPredicate(tenantId, companyId),
        })
      },
    })
    productMutate = () => mutation.mutateAsync()
    return null
  }

  function StockLevelsCascadeProbe() {
    const queryClient = useQueryClient()
    const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
    const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
    useQuery({
      queryKey: tenantScopedKey(['stock-levels', '', null]),
      queryFn: async () => {
        stockLevelCalls += 1
        return [{ id: `stock-${stockLevelCalls}` }]
      },
      enabled: !!tenantId && !!companyId,
    })
    const mutation = useMutation({
      mutationFn: async () => ({}),
      onSuccess: async () => {
        await queryClient.invalidateQueries({
          predicate: stockLevelsInvalidationPredicate(tenantId, companyId),
        })
      },
    })
    stockMutate = () => mutation.mutateAsync()
    return null
  }

  let productListCalls = 0
  let stockLevelCalls = 0
  let productMutate: (() => Promise<unknown>) | null = null
  let stockMutate: (() => Promise<unknown>) | null = null

  beforeEach(() => {
    productListCalls = 0
    stockLevelCalls = 0
    productMutate = null
    stockMutate = null
  })

  it('predicate-based mutation cascades refetch active products and stock-levels lists (L7)', async () => {
    const queryClient = createTestQueryClient()
    renderWithProviders(
      <>
        <ProductsCascadeProbe />
        <StockLevelsCascadeProbe />
      </>,
      { queryClient },
    )

    await waitFor(() => {
      expect(productListCalls).toBe(1)
      expect(stockLevelCalls).toBe(1)
    })

    await productMutate?.()
    await stockMutate?.()

    await waitFor(() => {
      expect(productListCalls).toBe(2)
      expect(stockLevelCalls).toBe(2)
    })
  })

  it('tenant-A product list data does not contain tenant-B entries (L18)', async () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false, gcTime: Infinity },
        mutations: { retry: false },
      },
    })
    queryClient.setQueryData(['products', { page: 1 }, 'tenant-B', 'company-1'], {
      data: [{ id: 'leaked-tenant-b-product' }],
      meta: { total: 1 },
      aggregates: { total_products: 1, total_active: 1, average_price: null },
    })
    mockApiGet.mockImplementation(async (url: string) => {
      if (url.startsWith('/products?')) {
        return {
          data: {
            data: [],
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
            aggregates: { total_products: 0, total_active: 0, average_price: null },
          },
        }
      }
      return { data: { data: [] } }
    })

    renderWithProviders(<ProductListPage />, { queryClient })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith(expect.stringMatching(/^\/products/))
    })

    const tenantAQuery = queryClient
      .getQueryCache()
      .getAll()
      .find((q) => {
        const k = q.queryKey as unknown[]
        return Array.isArray(k) && k[0] === 'products' && k[k.length - 2] === 'tenant-A'
      })
    const tenantAData = tenantAQuery?.state.data as { data?: Array<{ id: string }> } | undefined
    expect(tenantAData?.data ?? []).toEqual([])
    expect((tenantAData?.data ?? []).map((row) => row.id)).not.toContain('leaked-tenant-b-product')

    const tenantBQuery = queryClient
      .getQueryCache()
      .getAll()
      .find((q) => {
        const k = q.queryKey as unknown[]
        return Array.isArray(k) && k[0] === 'products' && k[k.length - 2] === 'tenant-B'
      })
    expect(tenantBQuery?.state.data).toEqual({
      data: [{ id: 'leaked-tenant-b-product' }],
      meta: { total: 1 },
      aggregates: { total_products: 1, total_active: 1, average_price: null },
    })
  })
})
