import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  menuKeys,
  menusInvalidationPredicate,
  useAddMenuCategoryItem,
  useCreateMenu,
  useCreateMenuCategory,
  useDeleteMenu,
  useDeleteMenuCategory,
  useMenu,
  useMenus,
  useRemoveMenuCategoryItem,
  useSyncMenuCategoryItems,
  useUpdateMenu,
  useUpdateMenuCategory,
} from '../hooks/useMenus'

// ─── api mock — module-level boundary ───────────────────────────────────────

const mockGetMenus = vi.hoisted(() => vi.fn())
const mockGetMenu = vi.hoisted(() => vi.fn())
const mockCreateMenu = vi.hoisted(() => vi.fn())
const mockUpdateMenu = vi.hoisted(() => vi.fn())
const mockDeleteMenu = vi.hoisted(() => vi.fn())
const mockCreateMenuCategory = vi.hoisted(() => vi.fn())
const mockUpdateMenuCategory = vi.hoisted(() => vi.fn())
const mockDeleteMenuCategory = vi.hoisted(() => vi.fn())
const mockSyncMenuCategoryItems = vi.hoisted(() => vi.fn())
const mockAddMenuCategoryItem = vi.hoisted(() => vi.fn())
const mockRemoveMenuCategoryItem = vi.hoisted(() => vi.fn())

vi.mock('../api/menuApi', () => ({
  getMenus: mockGetMenus,
  getMenu: mockGetMenu,
  createMenu: mockCreateMenu,
  updateMenu: mockUpdateMenu,
  deleteMenu: mockDeleteMenu,
  createMenuCategory: mockCreateMenuCategory,
  updateMenuCategory: mockUpdateMenuCategory,
  deleteMenuCategory: mockDeleteMenuCategory,
  syncMenuCategoryItems: mockSyncMenuCategoryItems,
  addMenuCategoryItem: mockAddMenuCategoryItem,
  removeMenuCategoryItem: mockRemoveMenuCategoryItem,
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

function menuKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && k[0] === 'menus')
}

beforeEach(() => {
  mockGetMenus.mockReset()
  mockGetMenus.mockResolvedValue([])
  mockGetMenu.mockReset()
  mockGetMenu.mockResolvedValue({ id: 'm-1' })
  mockCreateMenu.mockReset()
  mockCreateMenu.mockResolvedValue({ id: 'm-1' })
  mockUpdateMenu.mockReset()
  mockUpdateMenu.mockResolvedValue({ id: 'm-1' })
  mockDeleteMenu.mockReset()
  mockDeleteMenu.mockResolvedValue(undefined)
  mockCreateMenuCategory.mockReset()
  mockCreateMenuCategory.mockResolvedValue({ id: 'mc-1' })
  mockUpdateMenuCategory.mockReset()
  mockUpdateMenuCategory.mockResolvedValue({ id: 'mc-1' })
  mockDeleteMenuCategory.mockReset()
  mockDeleteMenuCategory.mockResolvedValue(undefined)
  mockSyncMenuCategoryItems.mockReset()
  mockSyncMenuCategoryItems.mockResolvedValue(undefined)
  mockAddMenuCategoryItem.mockReset()
  mockAddMenuCategoryItem.mockResolvedValue(undefined)
  mockRemoveMenuCategoryItem.mockReset()
  mockRemoveMenuCategoryItem.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── menuKeys factory contract ────────────────────────────────────────────────

describe('menuKeys factory contract (wrap-at-callsite invariant)', () => {
  it('returns un-scoped structural prefixes', () => {
    expect(menuKeys.all).toEqual(['menus'])
    expect(menuKeys.lists()).toEqual(['menus', 'list'])
    expect(menuKeys.details()).toEqual(['menus', 'detail'])
    expect(menuKeys.detail('m-1')).toEqual(['menus', 'detail', 'm-1'])
    expect(menuKeys.list({ active: true })).toEqual(['menus', 'list', { active: true }])
  })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('menusInvalidationPredicate', () => {
  it('matches list + detail leaf keys for the given t/c', () => {
    const pred = menusInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['menus', 'list', undefined, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['menus', 'detail', 'm-1', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects sibling search namespaces and wrong tenant/company', () => {
    const pred = menusInvalidationPredicate('tenant-A', 'company-1')
    // MenuCategoryItemManager search useQuery uses `composite_item-search` /
    // `product-search` namespace; predicate must NOT cascade into them.
    expect(pred({ queryKey: ['composite_item-search', '', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['product-search', '', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['menus', 'list', undefined, 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['menus', 'detail', 'm-1', 'tenant-A', 'company-2'] })).toBe(false)
  })

  it('rejects degenerate keys with fewer than 3 elements', () => {
    const pred = menusInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['menus'] })).toBe(false)
    expect(pred({ queryKey: ['menus', 'tenant-A'] })).toBe(false)
  })
})

// ─── useQuery shape probes (callsites .377, .378) ────────────────────────────

describe('menu useQuery queryKey shapes', () => {
  function ListProbe() {
    useMenus()
    return null
  }
  function DetailProbe() {
    useMenu('m-1')
    return null
  }

  it('useMenus carries tenant + company at the suffix (.377)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ListProbe />, { queryClient })
    const keys = menuKeysFromCache(queryClient)
    const list = keys.find((k) => k[1] === 'list')
    expect(list).toEqual(['menus', 'list', undefined, 'tenant-A', 'company-1'])
  })

  it('useMenu(id) carries tenant + company at the suffix (.378)', () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<DetailProbe />, { queryClient })
    const keys = menuKeysFromCache(queryClient)
    const detail = keys.find((k) => k[1] === 'detail')
    expect(detail).toEqual(['menus', 'detail', 'm-1', 'tenant-A', 'company-1'])
  })
})

// ─── Cascade tests for the 9 mutations (callsites .379-.387) ─────────────────
// Per-call counters; menusInvalidationPredicate is the single cascade target.

describe('menu mutation cascades — fetch-count signals', () => {
  function CascadeProbe() {
    let listCalls = 0
    let detailCalls = 0
    ;(globalThis as Record<string, unknown>)['__menuCounters'] = {
      list: () => listCalls,
      detail: () => detailCalls,
    }
    mockGetMenus.mockImplementation(async () => {
      listCalls += 1
      return [{ id: `m-${listCalls}` }]
    })
    mockGetMenu.mockImplementation(async () => {
      detailCalls += 1
      return { id: `m-detail-${detailCalls}` }
    })
    useMenus()
    useMenu('m-1')
    const create = useCreateMenu()
    const update = useUpdateMenu()
    const del = useDeleteMenu()
    const createCat = useCreateMenuCategory()
    const updateCat = useUpdateMenuCategory()
    const deleteCat = useDeleteMenuCategory()
    const sync = useSyncMenuCategoryItems()
    const addItem = useAddMenuCategoryItem()
    const removeItem = useRemoveMenuCategoryItem()
    ;(globalThis as Record<string, unknown>)['__menuMutations'] = {
      create,
      update,
      del,
      createCat,
      updateCat,
      deleteCat,
      sync,
      addItem,
      removeItem,
    }
    return null
  }

  function getCounters() {
    return (globalThis as Record<string, unknown>)['__menuCounters'] as {
      list: () => number
      detail: () => number
    }
  }
  function getMutations() {
    return (globalThis as Record<string, unknown>)['__menuMutations'] as Record<
      string,
      { mutateAsync: (input: unknown) => Promise<unknown> }
    >
  }

  // Single representative cascade test per mutation type. The remaining 6
  // mutations share the same predicate and onSuccess shape, so a single
  // assertion per mutation is sufficient evidence that the predicate fires.
  // Without per-mutation tests, an always-false predicate would still leave
  // the counters at 1, deterministically failing each assertion.

  it.each([
    ['useCreateMenu (.379)', 'create', { name: 'X' }],
    ['useUpdateMenu (.380)', 'update', { id: 'm-1', data: { name: 'X' } }],
    ['useDeleteMenu (.381)', 'del', 'm-1'],
    ['useCreateMenuCategory (.382)', 'createCat', { menuId: 'm-1', data: { name: 'C' } }],
    ['useUpdateMenuCategory (.383)', 'updateCat', { id: 'mc-1', data: { name: 'C' } }],
    ['useDeleteMenuCategory (.384)', 'deleteCat', 'mc-1'],
    ['useSyncMenuCategoryItems (.385)', 'sync', { categoryId: 'mc-1', data: { items: [] } }],
    ['useAddMenuCategoryItem (.386)', 'addItem', { categoryId: 'mc-1', data: { sellable_id: 's-1', sellable_type: 'product', sort_order: 0 } }],
    ['useRemoveMenuCategoryItem (.387)', 'removeItem', { categoryId: 'mc-1', itemId: 's-1' }],
  ])('%s refetches BOTH list + detail', async (_label, mutation, payload) => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    await getMutations()[mutation].mutateAsync(payload)

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
  })

  it('cross-tenant isolation: tenant-A mutation does not refetch tenant-B menu queries', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<CascadeProbe />, { queryClient })

    await waitFor(() => {
      expect(getCounters().list()).toBe(1)
      expect(getCounters().detail()).toBe(1)
    })

    const tenantBKey = ['menus', 'detail', 'm-other', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBKey, { id: 'm-tenant-b' })

    await getMutations()['create'].mutateAsync({ name: 'X' })

    expect(getCounters().list()).toBe(2)
    expect(getCounters().detail()).toBe(2)
    const tBQuery = queryClient.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual({ id: 'm-tenant-b' })
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })
})
