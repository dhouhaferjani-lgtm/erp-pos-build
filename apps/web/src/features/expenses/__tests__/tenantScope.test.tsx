import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  expenseCategoriesInvalidationPredicate,
  expensesInvalidationPredicate,
} from '../_invalidation'
import {
  useCreateExpense,
  useDeleteExpense,
  useExpense,
  useExpenseAnalytics,
  useExpenses,
  usePayExpense,
  usePostExpense,
  useUpdateExpense,
} from '../hooks/useExpenses'

import {
  useCreateExpenseCategory,
  useDeleteExpenseCategory,
  useExpenseCategories,
  useExpenseCategory,
  useUpdateExpenseCategory,
} from '../hooks/useExpenseCategories'

// ─── api mock — module-level boundary ────────────────────────────────────────

const mockExpenseList = vi.hoisted(() => vi.fn())
const mockExpenseGet = vi.hoisted(() => vi.fn())
const mockExpenseCreate = vi.hoisted(() => vi.fn())
const mockExpenseUpdate = vi.hoisted(() => vi.fn())
const mockExpenseDelete = vi.hoisted(() => vi.fn())
const mockExpensePost = vi.hoisted(() => vi.fn())
const mockExpensePay = vi.hoisted(() => vi.fn())
const mockExpenseAnalytics = vi.hoisted(() => vi.fn())
const mockCategoryList = vi.hoisted(() => vi.fn())
const mockCategoryGet = vi.hoisted(() => vi.fn())
const mockCategoryCreate = vi.hoisted(() => vi.fn())
const mockCategoryUpdate = vi.hoisted(() => vi.fn())
const mockCategoryDelete = vi.hoisted(() => vi.fn())

vi.mock('../api/expenseApi', () => ({
  expenseApi: {
    list: mockExpenseList,
    get: mockExpenseGet,
    create: mockExpenseCreate,
    update: mockExpenseUpdate,
    delete: mockExpenseDelete,
    post: mockExpensePost,
    pay: mockExpensePay,
    getAnalytics: mockExpenseAnalytics,
  },
  expenseCategoryApi: {
    list: mockCategoryList,
    get: mockCategoryGet,
    create: mockCategoryCreate,
    update: mockCategoryUpdate,
    delete: mockCategoryDelete,
  },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string, fallback?: string) => fallback ?? k }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// ─── Helpers ─────────────────────────────────────────────────────────────────

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

function makeWrapper(client: ReturnType<typeof createTestQueryClient>) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  mockExpenseList.mockReset(); mockExpenseList.mockResolvedValue([])
  mockExpenseGet.mockReset(); mockExpenseGet.mockResolvedValue({ id: 'e-1' })
  mockExpenseCreate.mockReset(); mockExpenseCreate.mockResolvedValue({ id: 'e-new' })
  mockExpenseUpdate.mockReset(); mockExpenseUpdate.mockResolvedValue({ id: 'e-1' })
  mockExpenseDelete.mockReset(); mockExpenseDelete.mockResolvedValue(undefined)
  mockExpensePost.mockReset(); mockExpensePost.mockResolvedValue({ id: 'e-1' })
  mockExpensePay.mockReset(); mockExpensePay.mockResolvedValue({ id: 'e-1' })
  mockExpenseAnalytics.mockReset(); mockExpenseAnalytics.mockResolvedValue({ tiles: {} })
  mockCategoryList.mockReset(); mockCategoryList.mockResolvedValue([])
  mockCategoryGet.mockReset(); mockCategoryGet.mockResolvedValue({ id: 'c-1' })
  mockCategoryCreate.mockReset(); mockCategoryCreate.mockResolvedValue({ id: 'c-new' })
  mockCategoryUpdate.mockReset(); mockCategoryUpdate.mockResolvedValue({ id: 'c-1' })
  mockCategoryDelete.mockReset(); mockCategoryDelete.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('expensesInvalidationPredicate', () => {
  it('matches expenses list keys (plural) for the given t/c', () => {
    const pred = expensesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['expenses', 'list', undefined, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['expenses', 'list', { status: 'open' }, 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects expenses singular detail keys (handled by exact-match, not predicate)', () => {
    const pred = expensesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['expenses', 'detail', 'e-1', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects sibling expense-categories namespace', () => {
    const pred = expensesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['expense-categories', 'list', undefined, 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects wrong tenant/company', () => {
    const pred = expensesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['expenses', 'list', undefined, 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['expenses', 'list', undefined, 'tenant-A', 'company-2'] })).toBe(false)
  })
})

describe('expenseCategoriesInvalidationPredicate', () => {
  it('matches expense-categories list keys (plural) for the given t/c', () => {
    const pred = expenseCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['expense-categories', 'list', undefined, 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects expense-categories singular detail keys (handled by exact-match)', () => {
    const pred = expenseCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['expense-categories', 'detail', 'c-1', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects sibling expenses namespace', () => {
    const pred = expenseCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['expenses', 'list', undefined, 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects wrong tenant/company', () => {
    const pred = expenseCategoriesInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['expense-categories', 'list', undefined, 'tenant-B', 'company-1'] })).toBe(false)
  })
})

// ─── useQuery shape probes ───────────────────────────────────────────────────

describe('expenses hook queryKey shapes', () => {
  it('useExpenseAnalytics queryKey carries filters, tenant, and company', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const filters = { status: 'posted' as const, date_to: '2026-03-31' }
    const { result } = renderHook(() => useExpenseAnalytics(filters), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const key = client.getQueryCache().getAll()
      .map((query) => query.queryKey as unknown[])
      .find((candidate) => candidate[0] === 'expenses' && candidate[1] === 'analytics')
    // Promoted L3 locationScopedKey lane: expense analytics carries effective locations and scope.
    const scopedFilters = { ...filters, location_ids: [] }
    expect(key).toEqual(['expenses', 'analytics', scopedFilters, { locScope: 'all' }, 'tenant-A', 'company-1'])
    expect(mockExpenseAnalytics).toHaveBeenCalledWith(scopedFilters)
  })

  it('useExpenses queryKey carries tenant + company at the suffix (.261)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result } = renderHook(() => useExpenses(), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    const expensesKey = keys.find((k) => Array.isArray(k) && k[0] === 'expenses')
    expect(expensesKey?.[expensesKey.length - 2]).toBe('tenant-A')
    expect(expensesKey?.[expensesKey.length - 1]).toBe('company-1')
  })

  it('useExpense(id) queryKey is ["expenses","detail",id,t,c] (.262)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result } = renderHook(() => useExpense('e-1'), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    const detail = keys.find((k) => Array.isArray(k) && k[0] === 'expenses' && k[1] === 'detail')
    expect(detail).toEqual(['expenses', 'detail', 'e-1', 'tenant-A', 'company-1'])
  })

  it('useExpenseCategories queryKey carries tenant + company at the suffix (.255)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result } = renderHook(() => useExpenseCategories(), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    const cat = keys.find((k) => Array.isArray(k) && k[0] === 'expense-categories')
    expect(cat?.[cat.length - 2]).toBe('tenant-A')
    expect(cat?.[cat.length - 1]).toBe('company-1')
  })

  it('useExpenseCategory(id) queryKey is ["expense-categories","detail",id,t,c] (.256)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result } = renderHook(() => useExpenseCategory('c-1'), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    const detail = keys.find((k) => Array.isArray(k) && k[0] === 'expense-categories' && k[1] === 'detail')
    expect(detail).toEqual(['expense-categories', 'detail', 'c-1', 'tenant-A', 'company-1'])
  })

  it('queryKeys differ across tenants', async () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    const wrapperA = makeWrapper(cA)
    const { result: rA } = renderHook(() => useExpenses(), { wrapper: wrapperA })
    await waitFor(() => { expect(rA.current.isSuccess).toBe(true) })
    const kA = JSON.stringify(cA.getQueryCache().getAll().map((q) => q.queryKey))

    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    const wrapperB = makeWrapper(cB)
    const { result: rB } = renderHook(() => useExpenses(), { wrapper: wrapperB })
    await waitFor(() => { expect(rB.current.isSuccess).toBe(true) })
    const kB = JSON.stringify(cB.getQueryCache().getAll().map((q) => q.queryKey))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})

// ─── Cascade tests — drive production mutations via mutateAsync (B11 pattern) ───

describe('expenses mutation cascades — fetch-count signals', () => {
  it.each([
    ['useCreateExpense (.263)', 'create', undefined as unknown],
    ['useUpdateExpense (.264, .265)', 'update', { id: 'e-1', data: {} }],
    ['useDeleteExpense (.266)', 'delete', 'e-1'],
    ['usePostExpense (.267, .268)', 'post', 'e-1'],
    ['usePayExpense', 'pay', {
      id: 'e-1',
      data: { payment_repository_id: 'repo-1', payment_date: '2026-07-13' },
    }],
  ])('%s cascades scoped expense list, analytics, and detail when applicable; does NOT touch expense-categories', async (_label, kind, payload) => {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    let detailCalls = 0
    let catListCalls = 0
    let analyticsCalls = 0
    mockExpenseList.mockImplementation(async () => { listCalls += 1; return [{ id: `e-${listCalls}` }] })
    mockExpenseGet.mockImplementation(async () => { detailCalls += 1; return { id: `e-detail-${detailCalls}` } })
    mockCategoryList.mockImplementation(async () => { catListCalls += 1; return [{ id: `c-${catListCalls}` }] })
    mockExpenseAnalytics.mockImplementation(async () => { analyticsCalls += 1; return { tiles: {} } })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)

    // Mount production hooks — no inline duplicate probes.
    const { result: list } = renderHook(() => useExpenses(), { wrapper })
    const { result: detail } = renderHook(() => useExpense('e-1'), { wrapper })
    const { result: analytics } = renderHook(
      () => useExpenseAnalytics({ status: 'posted' }),
      { wrapper },
    )
    const { result: cats } = renderHook(() => useExpenseCategories(), { wrapper })
    const { result: create } = renderHook(() => useCreateExpense(), { wrapper })
    const { result: update } = renderHook(() => useUpdateExpense(), { wrapper })
    const { result: del } = renderHook(() => useDeleteExpense(), { wrapper })
    const { result: post } = renderHook(() => usePostExpense(), { wrapper })
    const { result: pay } = renderHook(() => usePayExpense(), { wrapper })

    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(detail.current.isSuccess).toBe(true)
      expect(analytics.current.isSuccess).toBe(true)
      expect(cats.current.isSuccess).toBe(true)
    })
    expect(listCalls).toBe(1)
    expect(detailCalls).toBe(1)
    expect(catListCalls).toBe(1)
    expect(analyticsCalls).toBe(1)

    const siblingAnalyticsKey = [
      'expenses',
      'analytics',
      { status: 'posted' },
      'tenant-A',
      'company-2',
    ]
    client.setQueryDefaults(siblingAnalyticsKey, { gcTime: Infinity })
    client.setQueryData(siblingAnalyticsKey, { tiles: { total: '999.00' } })
    const foreignTenantAnalyticsKey = [
      'expenses',
      'analytics',
      { status: 'posted' },
      'tenant-B',
      'company-1',
    ]
    client.setQueryDefaults(foreignTenantAnalyticsKey, { gcTime: Infinity })
    client.setQueryData(foreignTenantAnalyticsKey, { tiles: { total: '888.00' } })

    const mutations: Record<string, () => Promise<unknown>> = {
      create: () => create.current.mutateAsync({} as never),
      update: () => update.current.mutateAsync(payload as { id: string; data: Record<string, unknown> }),
      delete: () => del.current.mutateAsync(payload as string),
      post: () => post.current.mutateAsync(payload as string),
      pay: () => pay.current.mutateAsync(payload as {
        id: string
        data: { payment_repository_id: string; payment_date: string }
      }),
    }
    await mutations[kind]()

    // Plural list always cascaded.
    await waitFor(() => { expect(listCalls).toBe(2) })
    await waitFor(() => { expect(analyticsCalls).toBe(2) })
    // Detail only cascaded by update/post (which mutate the singular).
    if (kind === 'update' || kind === 'post' || kind === 'pay') {
      await waitFor(() => { expect(detailCalls).toBe(2) })
    } else {
      expect(detailCalls).toBe(1)
    }
    // expense-categories sibling untouched by ANY expense mutation.
    expect(catListCalls).toBe(1)
    expect(client.getQueryCache().find({
      queryKey: siblingAnalyticsKey,
      exact: true,
    })?.state.isInvalidated).toBe(false)
    expect(client.getQueryCache().find({
      queryKey: foreignTenantAnalyticsKey,
      exact: true,
    })?.state.isInvalidated).toBe(false)
  })

  it.each([
    ['useCreateExpenseCategory (.257)', 'create', undefined as unknown],
    ['useUpdateExpenseCategory (.258, .259)', 'update', {
      id: 'c-1',
      data: { name: 'Renamed category' },
    }],
    ['useDeleteExpenseCategory (.260)', 'delete', 'c-1'],
  ])('%s cascades expense-categories plus scoped analytics labels, but not the expense list', async (_label, kind, payload) => {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    let detailCalls = 0
    let expListCalls = 0
    let analyticsCalls = 0
    mockCategoryList.mockImplementation(async () => { listCalls += 1; return [{ id: `c-${listCalls}` }] })
    mockCategoryGet.mockImplementation(async () => { detailCalls += 1; return { id: `c-detail-${detailCalls}` } })
    mockExpenseList.mockImplementation(async () => { expListCalls += 1; return [{ id: `e-${expListCalls}` }] })
    mockExpenseAnalytics.mockImplementation(async () => { analyticsCalls += 1; return { tiles: {} } })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)

    const { result: list } = renderHook(() => useExpenseCategories(), { wrapper })
    const { result: detail } = renderHook(() => useExpenseCategory('c-1'), { wrapper })
    const { result: exp } = renderHook(() => useExpenses(), { wrapper })
    const { result: analytics } = renderHook(
      () => useExpenseAnalytics({ status: 'posted' }),
      { wrapper },
    )
    const { result: create } = renderHook(() => useCreateExpenseCategory(), { wrapper })
    const { result: update } = renderHook(() => useUpdateExpenseCategory(), { wrapper })
    const { result: del } = renderHook(() => useDeleteExpenseCategory(), { wrapper })

    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(detail.current.isSuccess).toBe(true)
      expect(exp.current.isSuccess).toBe(true)
      expect(analytics.current.isSuccess).toBe(true)
    })
    expect(listCalls).toBe(1)
    expect(detailCalls).toBe(1)
    expect(expListCalls).toBe(1)
    expect(analyticsCalls).toBe(1)

    const mutations: Record<string, () => Promise<unknown>> = {
      create: () => create.current.mutateAsync({} as never),
      update: () => update.current.mutateAsync(payload as { id: string; data: Record<string, unknown> }),
      delete: () => del.current.mutateAsync(payload as string),
    }
    await mutations[kind]()

    await waitFor(() => { expect(listCalls).toBe(2) })
    await waitFor(() => { expect(analyticsCalls).toBe(2) })
    if (kind === 'update') {
      await waitFor(() => { expect(detailCalls).toBe(2) })
    } else {
      expect(detailCalls).toBe(1)
    }
    expect(expListCalls).toBe(1)
  })
})

// ─── Cross-tenant isolation: cache + DATA (L18 applied upfront) ──────────────

describe('cross-tenant isolation', () => {
  it('expensesInvalidationPredicate rejects tenant-B expenses cache entry', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const tenantBKey = ['expenses', 'list', undefined, 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, [{ id: 'e-tenant-b' }])

    const pred = expensesInvalidationPredicate('tenant-A', 'company-1')
    await client.invalidateQueries({ predicate: pred })

    const tBQuery = client.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual([{ id: 'e-tenant-b' }])
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })

  it('cross-tenant DATA isolation: tenant-A useExpenses results are free of tenant-B entries', async () => {
    // L18: prove tenant-A query results don't contain tenant-B data, not just
    // that the tenant-B cache entry survives. Pre-seed tenant-B cache, render
    // useExpenses under tenant-A, assert tenant-A's slot is the EMPTY mock
    // response — NOT the seeded tenant-B payload.
    const client = createTestQueryClient()

    const tenantBKey = ['expenses', 'list', undefined, 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, [{ id: 'leaked-tenant-b-expense' }])
    const tenantBCatKey = ['expense-categories', 'list', undefined, 'tenant-B', 'company-1']
    client.setQueryData(tenantBCatKey, [{ id: 'leaked-tenant-b-category' }])

    setTenant('tenant-A', 'company-1')
    const wrapper = makeWrapper(client)
    const { result: exp } = renderHook(() => useExpenses(), { wrapper })
    const { result: cat } = renderHook(() => useExpenseCategories(), { wrapper })

    await waitFor(() => {
      expect(exp.current.isSuccess).toBe(true)
      expect(cat.current.isSuccess).toBe(true)
    })

    // Promoted L3 locationScopedKey lane: expense lists isolate location-scoped tenant slots.
    const tenantAExpensesKey = ['expenses', 'list', { location_ids: [] }, { locScope: 'all' }, 'tenant-A', 'company-1']
    const tAExp = client.getQueryCache().find({ queryKey: tenantAExpensesKey, exact: true })
    expect(tAExp?.state.data).toEqual([])
    const tAExpData = (tAExp?.state.data ?? []) as Array<{ id: string }>
    expect(tAExpData.map((e) => e.id)).not.toContain('leaked-tenant-b-expense')

    const tenantACatKey = ['expense-categories', 'list', undefined, 'tenant-A', 'company-1']
    const tACat = client.getQueryCache().find({ queryKey: tenantACatKey, exact: true })
    expect(tACat?.state.data).toEqual([])
    const tACatData = (tACat?.state.data ?? []) as Array<{ id: string }>
    expect(tACatData.map((c) => c.id)).not.toContain('leaked-tenant-b-category')
  })
})
