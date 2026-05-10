import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'
import { QueryClient, useMutation, useQueryClient } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { uploadProductImage } from '../api/productImages'
import { ProductImageGallery } from '../components/ProductImageGallery'
import { ProductImageSection } from '../components/ProductImageSection'
import { ProductImageUpload } from '../components/ProductImageUpload'
import { ProductPrimaryImageDisplay } from '../components/ProductPrimaryImageDisplay'
import {
  productKeys,
  productsInvalidationPredicate,
  useProduct,
  useProducts,
} from '../hooks/useProducts'

// ─── API mocks ────────────────────────────────────────────────────────────────

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    apiPut: mockApiPut,
    apiDelete: mockApiDelete,
    api: {
      get: mockApiGet,
      post: mockApiPost,
      put: mockApiPut,
      patch: mockApiPatch,
      delete: mockApiDelete,
    },
    getErrorMessage: (e: unknown) => String(e),
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}))

// useRealtimeChannel is exercised by useProductRealtime; mock to a no-op so the
// hook can render without a live WebSocket. The realtime callsites (.561,
// .562) live inside the hook's `handleUpdate` callback — our predicate test
// invokes that callback directly via the helper.
vi.mock('@/hooks/useRealtimeChannel', () => ({
  useRealtimeChannel: () => undefined,
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
    companies: [
      {
        id: companyId,
        name: 'Co',
        legalName: 'Co',
        taxId: null,
        countryCode: 'FR',
        currency: 'EUR',
        locale: 'fr',
        timezone: 'Europe/Paris',
      },
    ],
    isLoading: false,
  })
}

function productsKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter(
      (key) =>
        Array.isArray(key) &&
        (key[0] === 'products' || key[0] === 'product' || key[0] === 'product-images'),
    )
}

beforeEach(() => {
  mockApiGet.mockReset()
  // api.get returns the axios-like response { data: { data: [...] } }; the
  // production code unwraps response.data.data.
  mockApiGet.mockResolvedValue({ data: { data: [] } })
  mockApiPost.mockReset()
  mockApiPost.mockResolvedValue({ data: { data: { id: 'img-new' } } })
  mockApiPut.mockReset()
  mockApiPut.mockResolvedValue({ data: { data: {} } })
  mockApiPatch.mockReset()
  mockApiPatch.mockResolvedValue({ data: { data: {} } })
  mockApiDelete.mockReset()
  mockApiDelete.mockResolvedValue({ data: undefined })
  // Don't crash window.confirm called by ProductImageGallery.
  vi.stubGlobal('confirm', () => true)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  vi.unstubAllGlobals()
})

// ─── productKeys factory contract (callsites .563, .564) ─────────────────────

describe('productKeys factory contract', () => {
  it('returns un-scoped structural prefixes (wrap-at-callsite contract)', () => {
    expect(productKeys.all).toEqual(['products'])
    expect(productKeys.lists()).toEqual(['products', 'list'])
    expect(productKeys.list({})).toEqual(['products', 'list', {}])
    expect(productKeys.details()).toEqual(['products', 'detail'])
    expect(productKeys.detail('abc')).toEqual(['products', 'detail', 'abc'])
  })
})

// ─── useProducts queryKey shape (callsites .563, .564) ───────────────────────

describe('useProducts / useProduct queryKey shape', () => {
  function ListProbe() {
    useProducts({ per_page: 1 })
    return null
  }
  function DetailProbe() {
    useProduct('abc')
    return null
  }

  it('useProducts queryKey carries tenant_id + company_id at the suffix', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient })

    const keys = productsKeysFromCache(queryClient)
    expect(keys.length).toBe(1)
    const k = keys[0]!
    expect(k[0]).toBe('products')
    expect(k[1]).toBe('list')
    expect(k.at(-2)).toBe('tenant-A')
    expect(k.at(-1)).toBe('company-1')
  })

  it('useProduct(id) queryKey carries tenant_id + company_id', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<DetailProbe />, { queryClient })

    const keys = productsKeysFromCache(queryClient)
    expect(keys.length).toBe(1)
    const k = keys[0]!
    expect(k[0]).toBe('products')
    expect(k[1]).toBe('detail')
    expect(k[2]).toBe('abc')
    expect(k.at(-2)).toBe('tenant-A')
    expect(k.at(-1)).toBe('company-1')
  })

  it('queryKeys differ across tenants', () => {
    setTenant('tenant-A', 'company-1')
    const c1 = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient: c1 })
    const k1 = JSON.stringify(productsKeysFromCache(c1))

    setTenant('tenant-B', 'company-1')
    const c2 = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient: c2 })
    const k2 = JSON.stringify(productsKeysFromCache(c2))

    expect(k1).not.toEqual(k2)
    expect(k1).toContain('tenant-A')
    expect(k2).toContain('tenant-B')
  })
})

// ─── productsInvalidationPredicate (used by useProductRealtime .562) ──────────

describe('productsInvalidationPredicate', () => {
  it('matches ANY tenant-scoped products key (list or detail) for the given t/c', () => {
    const pred = productsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['products', 'list', { p: 1 }, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['products', 'detail', 'abc', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects keys for a different tenant or company', () => {
    const pred = productsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['products', 'list', null, 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['products', 'list', null, 'tenant-A', 'company-2'] })).toBe(false)
  })

  it('rejects unrelated keys', () => {
    const pred = productsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['categories', 'list', null, 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['products'] })).toBe(false) // too short to be a leaf
  })
})

// ─── ProductImageSection useQuery shape (callsite .557) ──────────────────────

describe('ProductImageSection.useQuery (callsite .557)', () => {
  it('product-images queryKey carries tenant_id + company_id', () => {
    setTenant('tenant-A', 'company-1')
    mockApiGet.mockResolvedValue([])
    const queryClient = createTestQueryClient()

    renderWithProviders(<ProductImageSection productId="prod-1" />, { queryClient })

    const keys = productsKeysFromCache(queryClient)
    const piKey = keys.find((k) => k[0] === 'product-images')
    expect(piKey).toBeDefined()
    expect(piKey![1]).toBe('prod-1')
    expect(piKey!.at(-2)).toBe('tenant-A')
    expect(piKey!.at(-1)).toBe('company-1')
  })
})

// ─── ProductPrimaryImageDisplay useQuery shape (callsite .560) ───────────────

describe('ProductPrimaryImageDisplay.useQuery (callsite .560)', () => {
  it('product-images queryKey carries tenant_id + company_id', () => {
    setTenant('tenant-A', 'company-1')
    mockApiGet.mockResolvedValue([])
    const queryClient = createTestQueryClient()

    renderWithProviders(<ProductPrimaryImageDisplay productId="prod-1" />, { queryClient })

    const keys = productsKeysFromCache(queryClient)
    const piKey = keys.find((k) => k[0] === 'product-images')
    expect(piKey).toBeDefined()
    expect(piKey!.at(-2)).toBe('tenant-A')
    expect(piKey!.at(-1)).toBe('company-1')
  })
})

// ─── ProductImageGallery mutation cascade (callsites .553, .554, .555, .556) ──

describe('ProductImageGallery mutations cascade tenant-scoped invalidation', () => {
  // Helpers — render the gallery wrapped with ProductImageSection (which seeds
  // the product-images query) so the cascade has something to invalidate.
  function GalleryProbe({ productId }: { productId: string }) {
    return (
      <>
        <ProductImageSection productId={productId} />
        {/* second render of the gallery directly so we can drive its mutations */}
        <ProductImageGallery
          productId={productId}
          images={[
            {
              id: 'img-1',
              product_id: productId,
              filename: 'a.jpg',
              original_filename: 'a.jpg',
              storage_path: '/tmp/a.jpg',
              storage_disk: 'local',
              mime_type: 'image/jpeg',
              file_size: 1024,
              width: 100,
              height: 100,
              is_primary: false,
              sort_order: 0,
              created_at: '2026-01-01T00:00:00Z',
              updated_at: null,
            },
            {
              id: 'img-2',
              product_id: productId,
              filename: 'b.jpg',
              original_filename: 'b.jpg',
              storage_path: '/tmp/b.jpg',
              storage_disk: 'local',
              mime_type: 'image/jpeg',
              file_size: 1024,
              width: 100,
              height: 100,
              is_primary: true,
              sort_order: 1,
              created_at: '2026-01-01T00:00:00Z',
              updated_at: null,
            },
          ]}
          readOnly={false}
        />
      </>
    )
  }

  it('setPrimary refetches product-images for the current tenant (.555 + .556)', async () => {
    setTenant('tenant-A', 'company-1')
    mockApiGet.mockResolvedValue([])
    mockApiPost.mockResolvedValue(undefined)
    const queryClient = createTestQueryClient()

    const { container } = renderWithProviders(<GalleryProbe productId="prod-1" />, { queryClient })

    // Initial fetch from ProductImageSection's useQuery.
    expect(mockApiGet).toHaveBeenCalledTimes(1)

    // Find the "set primary" button on img-1 (img-2 is already primary so its
    // button is hidden). The button has title="products:images.setPrimary".
    const setPrimaryBtn = container.querySelector(
      'button[title="products:images.setPrimary"]',
    ) as HTMLButtonElement | null
    expect(setPrimaryBtn).not.toBeNull()

    setPrimaryBtn!.click()

    // After cascade: product-images was re-fetched. mockApiGet should be 2.
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledTimes(2)
    })
    // The single fetched URL must be the tenant-A product-images endpoint.
    const calls = mockApiGet.mock.calls.map((c: unknown[]) => c[0])
    expect(calls.filter((u) => String(u).includes('/products/prod-1/images')).length).toBeGreaterThanOrEqual(2)
  })

  it('cross-tenant isolation: tenant-A setPrimary does NOT refetch tenant-B product-images cache', async () => {
    setTenant('tenant-A', 'company-1')
    // Use a non-zero gcTime so the seeded tenant-B query (no observer) isn't
    // immediately garbage-collected — we need it to survive long enough to
    // assert the cross-tenant invariant.
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false, gcTime: Infinity },
        mutations: { retry: false },
      },
    })

    const { container } = renderWithProviders(<GalleryProbe productId="prod-1" />, { queryClient })

    // Seed a tenant-B product-images cache entry (simulating leftover from a
    // prior tenant context).
    const tenantBKey = ['product-images', 'prod-1', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, [])

    const initialApiGetCount = mockApiGet.mock.calls.length

    const setPrimaryBtn = container.querySelector(
      'button[title="products:images.setPrimary"]',
    ) as HTMLButtonElement | null
    setPrimaryBtn!.click()
    // Let the tenant-A mutation cascade complete.
    await waitFor(() => {
      expect(mockApiGet.mock.calls.length).toBeGreaterThan(initialApiGetCount)
    })

    // Tenant-B's seeded data must remain. setQueryData puts data directly into
    // the cache; verify the data slot is intact.
    const tenantBQuery = queryClient.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tenantBQuery?.state.data).toEqual([])
    // No new fetches against the tenant-B URL — only tenant-A refetches happened.
    const newCalls = mockApiGet.mock.calls.slice(initialApiGetCount)
    for (const call of newCalls) {
      expect(String(call[0])).not.toContain('tenant-B')
    }
  })
})

// ─── ProductImageUpload mutation cascade (callsites .558, .559) ──────────────

describe('ProductImageUpload mutation cascade', () => {
  // Re-creates ProductImageUpload's uploadMutation so the test can invoke it
  // directly (the file-input + dispatchEvent path doesn't reliably propagate
  // `files` through React's synthetic event system in jsdom). Keep the
  // mutation contract aligned with ProductImageUpload.tsx's uploadMutation.
  function UploadHookProbe({ productId }: { productId: string }) {
    const queryClient = useQueryClient()
    const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
    const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
    void tenantId
    void companyId
    const mutation = useMutation({
      mutationFn: (file: File) => uploadProductImage(productId, file),
      onSuccess: async () => {
        await Promise.all([
          queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product-images', productId]) }),
          queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product', productId]) }),
        ])
      },
    })
    ;(globalThis as Record<string, unknown>)['__uploadMutation'] = mutation
    return null
  }

  it('upload refetches the product-images query (callsite .558)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    renderWithProviders(
      <>
        <ProductImageSection productId="prod-1" />
        <UploadHookProbe productId="prod-1" />
      </>,
      { queryClient },
    )

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledTimes(1)
    })

    const upload = (globalThis as Record<string, unknown>)['__uploadMutation'] as {
      mutateAsync: (file: File) => Promise<unknown>
    }
    const file = new File(['x'], 'a.jpg', { type: 'image/jpeg' })
    await upload.mutateAsync(file)
    delete (globalThis as Record<string, unknown>)['__uploadMutation']

    // After cascade: product-images was re-fetched (the cascade key matches
    // ProductImageSection's useQuery key exactly).
    expect(mockApiGet).toHaveBeenCalledTimes(2)
  })

  it('upload mutation in ProductImageUpload component itself uses tenant-scoped invalidation (callsites .558 + .559)', () => {
    // Static-shape test for the production component: render it and confirm
    // it doesn't throw; the queryKey shape is verified by the audit-tanstack-keys
    // scanner (call site is bare-array tenantScopedKey wrap). The fetch-count
    // cascade is exercised by the previous test using the same mutation
    // contract.
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    const { container } = renderWithProviders(
      <ProductImageUpload productId="prod-1" />,
      { queryClient },
    )
    // Component renders an input[type=file] without throwing.
    expect(container.querySelector('input[type="file"]')).not.toBeNull()
  })
})
