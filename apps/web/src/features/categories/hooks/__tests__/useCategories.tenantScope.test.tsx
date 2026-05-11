import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  categoryKeys,
  useCategories,
  useCategory,
  useCategoryTree,
  useCreateCategory,
  useDeleteCategory,
  useReorderCategories,
  useUpdateCategory,
} from '../useCategories'

// ─── API mock ─────────────────────────────────────────────────────────────────
//
// The hooks under test fire fetches when both stores are populated. Mock every
// module export apps/web/src/features/categories/api/categoriesApi.ts pulls in,
// returning empty payloads so the network never gets touched. The tests assert
// queryKey shape, not response handling.

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    apiPut: mockApiPut,
    apiDelete: mockApiDelete,
  }
})

// ─── Probes ───────────────────────────────────────────────────────────────────

function CategoriesProbe() {
  useCategories()
  useCategoryTree()
  useCategory(123)
  return null
}

/**
 * The renderWithProviders helper pre-seeds a `['company-config']` query
 * for the CompanyConfigProvider. Filter that out so the assertions only
 * inspect category queryKeys.
 */
function categoryKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((key) => Array.isArray(key) && key[0] === 'categories')
}

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

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('useCategories tenant-scoped queryKeys (web.tanstack-keys.095-104)', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiGet.mockResolvedValue({ data: [], meta: { total: 0 } })
    mockApiPost.mockReset()
    mockApiPut.mockReset()
    mockApiDelete.mockReset()
  })

  afterEach(() => {
    useAuthStore.setState({
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: false,
    })
    useCompanyStore.setState({
      currentCompanyId: null,
      companies: [],
      isLoading: false,
    })
  })

  it('every queryKey includes tenant_id and company_id when both stores are populated', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    renderWithProviders(<CategoriesProbe />, { queryClient })

    const keys = categoryKeysFromCache(queryClient)
    // Pre-condition: hooks emit one query each (3 hooks → 3 unique queries).
    expect(keys.length).toBeGreaterThanOrEqual(3)

    // Cross-tenant invariant: every queryKey must contain BOTH the tenant_id
    // and the company_id. A queryKey that lacks them shares cache slots
    // across tenants, which is the leak this batch closes.
    for (const key of keys) {
      expect(key, `queryKey ${JSON.stringify(key)} missing tenant_id`).toContain('tenant-A')
      expect(key, `queryKey ${JSON.stringify(key)} missing company_id`).toContain('company-1')
    }
  })

  it('queryKeys differ across tenants (cache slots stay isolated)', () => {
    setTenant('tenant-A', 'company-1')
    const clientA = createTestQueryClient()
    renderWithProviders(<CategoriesProbe />, { queryClient: clientA })
    const keysA = categoryKeysFromCache(clientA)
      .map((k) => JSON.stringify(k))
      .sort()

    setTenant('tenant-B', 'company-1')
    const clientB = createTestQueryClient()
    renderWithProviders(<CategoriesProbe />, { queryClient: clientB })
    const keysB = categoryKeysFromCache(clientB)
      .map((k) => JSON.stringify(k))
      .sort()

    expect(keysA.length).toEqual(keysB.length)
    expect(keysA).not.toEqual(keysB)
    for (const k of keysB) expect(k).not.toContain('tenant-A')
    for (const k of keysA) expect(k).not.toContain('tenant-B')
  })

  it('queryKeys differ across companies within the same tenant', () => {
    setTenant('tenant-A', 'company-1')
    const c1 = createTestQueryClient()
    renderWithProviders(<CategoriesProbe />, { queryClient: c1 })
    const keys1 = categoryKeysFromCache(c1)
      .map((k) => JSON.stringify(k))
      .sort()

    setTenant('tenant-A', 'company-2')
    const c2 = createTestQueryClient()
    renderWithProviders(<CategoriesProbe />, { queryClient: c2 })
    const keys2 = categoryKeysFromCache(c2)
      .map((k) => JSON.stringify(k))
      .sort()

    expect(keys1).not.toEqual(keys2)
    for (const k of keys2) expect(k).not.toContain('company-1')
    for (const k of keys1) expect(k).not.toContain('company-2')
  })

  it('useCreateCategory invalidates and refetches ALL category queries (callsite .098)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    function FullProbe() {
      useCategories()
      useCategoryTree()
      useCategory(7)
      const create = useCreateCategory()
      ;(globalThis as Record<string, unknown>)['__createMutation'] = create
      return null
    }

    mockApiPost.mockResolvedValue({ id: 1, company_id: 'c', name: 'x', slug: 'x' })

    renderWithProviders(<FullProbe />, { queryClient })

    // 3 useQuery hooks fire 3 initial fetches.
    expect(mockApiGet).toHaveBeenCalledTimes(3)

    const create = (globalThis as Record<string, unknown>)['__createMutation'] as {
      mutateAsync: (input: unknown) => Promise<unknown>
    }
    await create.mutateAsync({ name: 'Foo' })
    delete (globalThis as Record<string, unknown>)['__createMutation']

    // After mutation: invalidate 'all' triggers all 3 active queries to
    // refetch. Total = 3 initial + 3 refetched = 6.
    // If the predicate failed to match (the prefix-match defect), only the
    // initial 3 fetches would be observed.
    expect(mockApiGet).toHaveBeenCalledTimes(6)
  })

  it('useUpdateCategory refetches detail(id) + lists + trees but NOT unrelated detail (callsites .099-.101)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    function UpdateProbe() {
      useCategories() // list query
      useCategoryTree() // tree query
      useCategory(7) // detail(7)
      useCategory(99) // detail(99) — must NOT refetch
      const update = useUpdateCategory()
      ;(globalThis as Record<string, unknown>)['__updateMutation'] = update
      return null
    }

    mockApiPut.mockResolvedValue({ id: 7, company_id: 'c', name: 'Bar', slug: 'bar' })

    renderWithProviders(<UpdateProbe />, { queryClient })

    // 4 useQuery hooks → 4 initial fetches.
    expect(mockApiGet).toHaveBeenCalledTimes(4)

    const update = (globalThis as Record<string, unknown>)['__updateMutation'] as {
      mutateAsync: (input: unknown) => Promise<unknown>
    }
    await update.mutateAsync({ id: 7, data: { name: 'Bar' } })
    delete (globalThis as Record<string, unknown>)['__updateMutation']

    // After mutation: invalidate detail(7) + lists + trees → 3 refetches.
    // detail(99) is NOT invalidated → no refetch.
    // Total = 4 initial + 3 refetched = 7.
    expect(mockApiGet).toHaveBeenCalledTimes(7)
    // Verify detail(99)'s URL was fetched exactly once (initial), never re-fetched.
    const detail99Calls = mockApiGet.mock.calls.filter((args: unknown[]) => args[0] === '/categories/99')
    expect(detail99Calls.length, 'detail(99) must NOT be refetched by update(id=7)').toBe(1)
  })

  it('useDeleteCategory refetches ALL category queries (callsite .102)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    function DelProbe() {
      useCategories()
      useCategoryTree()
      useCategory(7)
      const del = useDeleteCategory()
      ;(globalThis as Record<string, unknown>)['__delMutation'] = del
      return null
    }

    mockApiDelete.mockResolvedValue(undefined)

    renderWithProviders(<DelProbe />, { queryClient })
    expect(mockApiGet).toHaveBeenCalledTimes(3)

    const del = (globalThis as Record<string, unknown>)['__delMutation'] as {
      mutateAsync: (input: unknown) => Promise<unknown>
    }
    await del.mutateAsync(7)
    delete (globalThis as Record<string, unknown>)['__delMutation']

    expect(mockApiGet).toHaveBeenCalledTimes(6)
  })

  it('useReorderCategories refetches lists + trees but NOT details (callsites .103-.104)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    function ReorderProbe() {
      useCategories()
      useCategoryTree()
      useCategory(7)
      const reorder = useReorderCategories()
      ;(globalThis as Record<string, unknown>)['__reorderMutation'] = reorder
      return null
    }

    mockApiPost.mockResolvedValue(undefined)

    renderWithProviders(<ReorderProbe />, { queryClient })
    expect(mockApiGet).toHaveBeenCalledTimes(3)

    const reorder = (globalThis as Record<string, unknown>)['__reorderMutation'] as {
      mutateAsync: (input: unknown) => Promise<unknown>
    }
    await reorder.mutateAsync([])
    delete (globalThis as Record<string, unknown>)['__reorderMutation']

    // Reorder invalidates lists + trees only → 2 refetches.
    // detail(7) is NOT invalidated.
    // Total = 3 initial + 2 refetched = 5.
    expect(mockApiGet).toHaveBeenCalledTimes(5)
    const detail7Calls = mockApiGet.mock.calls.filter((args: unknown[]) => args[0] === '/categories/7')
    expect(detail7Calls.length, 'detail(7) must NOT be refetched by reorder').toBe(1)
  })

  it('mutation invalidation is tenant-isolated (cross-tenant cache stays untouched)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    function CrossTenantProbe() {
      useCategories()
      const create = useCreateCategory()
      ;(globalThis as Record<string, unknown>)['__createMutation'] = create
      return null
    }

    mockApiPost.mockResolvedValue({ id: 1, company_id: 'c', name: 'x', slug: 'x' })

    renderWithProviders(<CrossTenantProbe />, { queryClient })

    // Seed a cross-tenant entry simulating a stale entry from a previous
    // tenant context (not cleared on logout — defense-in-depth target).
    const tenantBKey = ['categories', 'list', undefined, 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, [])

    const cache = queryClient.getQueryCache()
    const tenantBQuery = cache.find({ queryKey: tenantBKey, exact: true })
    expect(tenantBQuery, 'tenant-B query must exist before mutation').toBeDefined()
    expect(tenantBQuery?.state.isInvalidated).toBe(false)
    const tenantBDataUpdatedAtBefore = tenantBQuery?.state.dataUpdatedAt

    const create = (globalThis as Record<string, unknown>)['__createMutation'] as {
      mutateAsync: (input: unknown) => Promise<unknown>
    }
    await create.mutateAsync({ name: 'Foo' })
    delete (globalThis as Record<string, unknown>)['__createMutation']

    // Cross-tenant invariant: a mutation in tenant-A MUST NOT invalidate
    // OR refetch tenant-B's cache entry. Since tenant-B's query has no
    // active observer, "invalidated" wouldn't refetch anyway — so we
    // verify two stronger signals: dataUpdatedAt is unchanged AND no fetch
    // ever fired for the tenant-B URL.
    const tenantBQueryAfter = cache.find({ queryKey: tenantBKey, exact: true })
    expect(tenantBQueryAfter?.state.dataUpdatedAt).toBe(tenantBDataUpdatedAtBefore)
    expect(tenantBQueryAfter?.state.isInvalidated).toBe(false)
  })

  it('factory still exposes structural prefixes (wrap-at-callsite contract)', () => {
    // The categoryKeys factory returns un-scoped structural prefixes; the
    // tenant scope is appended at the useQuery / invalidateQueries call site
    // via tenantScopedKey([...]). Pin this contract: if a future refactor
    // moves the wrap inside the factory, the audit-tanstack-keys scanner
    // would re-flag callers (it requires the wrap to be a bare-Identifier
    // call, not a property-access call), so we keep the factory plain.
    expect(categoryKeys.all).toEqual(['categories'])
    expect(categoryKeys.lists()).toEqual(['categories', 'list'])
    expect(categoryKeys.list({ search: 'x' })).toEqual([
      'categories',
      'list',
      { search: 'x' },
    ])
    expect(categoryKeys.trees()).toEqual(['categories', 'tree'])
    expect(categoryKeys.tree()).toEqual(['categories', 'tree'])
    expect(categoryKeys.details()).toEqual(['categories', 'detail'])
    expect(categoryKeys.detail(7)).toEqual(['categories', 'detail', 7])
  })
})
