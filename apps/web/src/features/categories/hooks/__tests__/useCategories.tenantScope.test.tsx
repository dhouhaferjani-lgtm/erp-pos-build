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

  it('mutation invalidations target tenant-scoped queryKeys (callsites .098-.104)', async () => {
    setTenant('tenant-A', 'company-1')

    // Spy on QueryClient.invalidateQueries by intercepting at the prototype
    // level — captures every call from every mutation onSuccess in this test.
    const invalidateSpy = vi.fn()

    function MutationsProbe() {
      const create = useCreateCategory()
      const update = useUpdateCategory()
      const del = useDeleteCategory()
      const reorder = useReorderCategories()
      // Expose the mutation handles on a global so the test can fire them.
      ;(globalThis as Record<string, unknown>)['__categoryMutations'] = {
        create,
        update,
        del,
        reorder,
      }
      return null
    }

    mockApiPost.mockResolvedValue({ id: 1, company_id: 'c', name: 'x', slug: 'x' })
    mockApiPut.mockResolvedValue({ id: 1, company_id: 'c', name: 'x', slug: 'x' })
    mockApiDelete.mockResolvedValue(undefined)

    const queryClient = createTestQueryClient()
    const realInvalidate = queryClient.invalidateQueries.bind(queryClient)
    queryClient.invalidateQueries = ((arg: { queryKey: unknown[] }) => {
      invalidateSpy(arg.queryKey)
      return realInvalidate(arg)
    }) as typeof queryClient.invalidateQueries

    renderWithProviders(<MutationsProbe />, { queryClient })

    const mutations = (globalThis as Record<string, unknown>)['__categoryMutations'] as {
      create: { mutateAsync: (input: unknown) => Promise<unknown> }
      update: { mutateAsync: (input: unknown) => Promise<unknown> }
      del: { mutateAsync: (input: unknown) => Promise<unknown> }
      reorder: { mutateAsync: (input: unknown) => Promise<unknown> }
    }

    await mutations.create.mutateAsync({ name: 'Foo' })
    await mutations.update.mutateAsync({ id: 7, data: { name: 'Bar' } })
    await mutations.del.mutateAsync(7)
    await mutations.reorder.mutateAsync([])

    delete (globalThis as Record<string, unknown>)['__categoryMutations']

    // 7 invalidations expected:
    //  - create:  1 (categoryKeys.all)
    //  - update:  3 (detail, lists, trees)
    //  - delete:  1 (categoryKeys.all)
    //  - reorder: 2 (lists, trees)
    expect(invalidateSpy).toHaveBeenCalledTimes(7)

    // Every invalidation must target a tenant-scoped queryKey.
    for (const [key] of invalidateSpy.mock.calls) {
      expect(key, `invalidation queryKey ${JSON.stringify(key)} missing tenant_id`).toContain(
        'tenant-A',
      )
      expect(key, `invalidation queryKey ${JSON.stringify(key)} missing company_id`).toContain(
        'company-1',
      )
    }
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
