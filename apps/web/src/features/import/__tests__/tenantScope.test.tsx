import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import { importsListInvalidationPredicate } from '../_invalidation'
import {
  importKeys,
  useCreateImport,
  useDeleteImport,
  useDependencyCheck,
  useExecuteImport,
  useImportErrors,
  useImportJob,
  useImportJobs,
  useImportPreview,
  useMigrationStatus,
  useWizardOrder,
} from '../api/queries'

// ─── api mock — module-level boundary ────────────────────────────────────────

const mockList = vi.hoisted(() => vi.fn())
const mockGetJob = vi.hoisted(() => vi.fn())
const mockGetErrors = vi.hoisted(() => vi.fn())
const mockGetPreview = vi.hoisted(() => vi.fn())
const mockCreateJob = vi.hoisted(() => vi.fn())
const mockExecuteImport = vi.hoisted(() => vi.fn())
const mockDeleteJob = vi.hoisted(() => vi.fn())
const mockGetWizardOrder = vi.hoisted(() => vi.fn())
const mockGetMigrationStatus = vi.hoisted(() => vi.fn())
const mockCheckDependencies = vi.hoisted(() => vi.fn())

vi.mock('../api/importApi', () => ({
  importApi: {
    list: mockList,
    getJob: mockGetJob,
    getErrors: mockGetErrors,
    getPreview: mockGetPreview,
    createJob: mockCreateJob,
    executeImport: mockExecuteImport,
    deleteJob: mockDeleteJob,
    getWizardOrder: mockGetWizardOrder,
    getMigrationStatus: mockGetMigrationStatus,
    checkDependencies: mockCheckDependencies,
    suggestMapping: vi.fn(),
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
  mockList.mockReset(); mockList.mockResolvedValue({ data: [] })
  mockGetJob.mockReset(); mockGetJob.mockResolvedValue({ id: 'j-1' })
  mockGetErrors.mockReset(); mockGetErrors.mockResolvedValue({ data: [] })
  mockGetPreview.mockReset(); mockGetPreview.mockResolvedValue({ data: [] })
  mockCreateJob.mockReset(); mockCreateJob.mockResolvedValue({ id: 'j-new' })
  mockExecuteImport.mockReset(); mockExecuteImport.mockResolvedValue({ id: 'j-1' })
  mockDeleteJob.mockReset(); mockDeleteJob.mockResolvedValue(undefined)
  mockGetWizardOrder.mockReset(); mockGetWizardOrder.mockResolvedValue({ data: [] })
  mockGetMigrationStatus.mockReset(); mockGetMigrationStatus.mockResolvedValue({ data: {} })
  mockCheckDependencies.mockReset(); mockCheckDependencies.mockResolvedValue({ data: { ok: true } })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('importsListInvalidationPredicate', () => {
  it('matches imports.list keys (plural) for the given t/c', () => {
    const pred = importsListInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['imports', 'list', 'all', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['imports', 'list', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects singular imports.detail / .errors / .preview keys (handled by exact-match)', () => {
    const pred = importsListInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['imports', 'detail', 'j-1', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['imports', 'errors', 'j-1', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['imports', 'preview', 'j-1', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects sibling migration-wizard namespace', () => {
    const pred = importsListInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['migration-wizard', 'status', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['migration-wizard', 'order', 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects sibling import-error-summary / import-errors namespaces', () => {
    const pred = importsListInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['import-error-summary', 'j-1', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['import-errors', 'j-1', 1, 50, 'tenant-A', 'company-1'] })).toBe(false)
  })

  it('rejects wrong tenant/company', () => {
    const pred = importsListInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['imports', 'list', 'all', 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['imports', 'list', 'all', 'tenant-A', 'company-2'] })).toBe(false)
  })
})

// ─── useQuery shape probes ───────────────────────────────────────────────────

describe('import hook queryKey shapes', () => {
  it('useImportJobs queryKey is [imports, list, t, c] (.286)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const { result } = renderHook(() => useImportJobs(), { wrapper: makeWrapper(client) })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    const list = keys.find((k) => Array.isArray(k) && k[0] === 'imports' && k[1] === 'list')
    expect(list).toEqual(['imports', 'list', 'tenant-A', 'company-1'])
  })

  it('useImportJob(id) queryKey is [imports, detail, id, t, c] (.287)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const { result } = renderHook(() => useImportJob('j-1'), { wrapper: makeWrapper(client) })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    const detail = keys.find((k) => Array.isArray(k) && k[0] === 'imports' && k[1] === 'detail')
    expect(detail).toEqual(['imports', 'detail', 'j-1', 'tenant-A', 'company-1'])
  })

  it('useImportErrors / useImportPreview / useWizardOrder / useMigrationStatus / useDependencyCheck carry t/c at suffix (.288, .289, .290, .291, .292)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result: r1 } = renderHook(() => useImportErrors('j-1'), { wrapper })
    const { result: r2 } = renderHook(() => useImportPreview('j-1'), { wrapper })
    const { result: r3 } = renderHook(() => useWizardOrder(), { wrapper })
    const { result: r4 } = renderHook(() => useMigrationStatus(), { wrapper })
    const { result: r5 } = renderHook(() => useDependencyCheck('products'), { wrapper })
    await waitFor(() => {
      expect(r1.current.isSuccess).toBe(true)
      expect(r2.current.isSuccess).toBe(true)
      expect(r3.current.isSuccess).toBe(true)
      expect(r4.current.isSuccess).toBe(true)
      expect(r5.current.isSuccess).toBe(true)
    })
    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    expect(keys.find((k) => k[0] === 'imports' && k[1] === 'errors')).toEqual(['imports', 'errors', 'j-1', 'tenant-A', 'company-1'])
    expect(keys.find((k) => k[0] === 'imports' && k[1] === 'preview')).toEqual(['imports', 'preview', 'j-1', 'tenant-A', 'company-1'])
    expect(keys.find((k) => k[0] === 'migration-wizard' && k[1] === 'order')).toEqual(['migration-wizard', 'order', 'tenant-A', 'company-1'])
    expect(keys.find((k) => k[0] === 'migration-wizard' && k[1] === 'status')).toEqual(['migration-wizard', 'status', 'tenant-A', 'company-1'])
    expect(keys.find((k) => k[0] === 'migration-wizard' && k[1] === 'dependencies')).toEqual(['migration-wizard', 'dependencies', 'products', 'tenant-A', 'company-1'])
  })

  it('queryKeys differ across tenants (useImportJobs)', async () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    const { result: rA } = renderHook(() => useImportJobs(), { wrapper: makeWrapper(cA) })
    await waitFor(() => { expect(rA.current.isSuccess).toBe(true) })
    const kA = JSON.stringify(cA.getQueryCache().getAll().map((q) => q.queryKey))

    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    const { result: rB } = renderHook(() => useImportJobs(), { wrapper: makeWrapper(cB) })
    await waitFor(() => { expect(rB.current.isSuccess).toBe(true) })
    const kB = JSON.stringify(cB.getQueryCache().getAll().map((q) => q.queryKey))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})

// ─── Cascade tests — drive production mutations via mutateAsync ──────────────

describe('import mutation cascades — fetch-count signals', () => {
  it('useCreateImport (.293) cascades imports.list only', async () => {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    let detailCalls = 0
    let statusCalls = 0
    mockList.mockImplementation(async () => { listCalls += 1; return { data: [{ id: `j-${listCalls}` }] } })
    mockGetJob.mockImplementation(async () => { detailCalls += 1; return { id: `j-detail-${detailCalls}` } })
    mockGetMigrationStatus.mockImplementation(async () => { statusCalls += 1; return { data: { calls: statusCalls } } })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result: list } = renderHook(() => useImportJobs(), { wrapper })
    const { result: detail } = renderHook(() => useImportJob('j-1'), { wrapper })
    const { result: status } = renderHook(() => useMigrationStatus(), { wrapper })
    const { result: create } = renderHook(() => useCreateImport(), { wrapper })

    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(detail.current.isSuccess).toBe(true)
      expect(status.current.isSuccess).toBe(true)
    })
    expect(listCalls).toBe(1)
    expect(detailCalls).toBe(1)
    expect(statusCalls).toBe(1)

    await create.current.mutateAsync({ type: 'products', file: new File([''], 'x.csv') })

    await waitFor(() => { expect(listCalls).toBe(2) })
    // Detail + wizardStatus untouched.
    expect(detailCalls).toBe(1)
    expect(statusCalls).toBe(1)
  })

  it('useExecuteImport (.294, .295, .296) cascades imports.list + imports.detail + migration-wizard.status', async () => {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    let detailCalls = 0
    let statusCalls = 0
    let orderCalls = 0
    mockList.mockImplementation(async () => { listCalls += 1; return { data: [{ id: `j-${listCalls}` }] } })
    mockGetJob.mockImplementation(async () => { detailCalls += 1; return { id: `j-detail-${detailCalls}` } })
    mockGetMigrationStatus.mockImplementation(async () => { statusCalls += 1; return { data: { calls: statusCalls } } })
    mockGetWizardOrder.mockImplementation(async () => { orderCalls += 1; return { data: [`o-${orderCalls}`] } })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result: list } = renderHook(() => useImportJobs(), { wrapper })
    const { result: detail } = renderHook(() => useImportJob('j-1'), { wrapper })
    const { result: status } = renderHook(() => useMigrationStatus(), { wrapper })
    const { result: order } = renderHook(() => useWizardOrder(), { wrapper })
    const { result: execute } = renderHook(() => useExecuteImport(), { wrapper })

    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(detail.current.isSuccess).toBe(true)
      expect(status.current.isSuccess).toBe(true)
      expect(order.current.isSuccess).toBe(true)
    })
    expect(listCalls).toBe(1)
    expect(detailCalls).toBe(1)
    expect(statusCalls).toBe(1)
    expect(orderCalls).toBe(1)

    await execute.current.mutateAsync('j-1')

    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(detailCalls).toBe(2)
      expect(statusCalls).toBe(2)
    })
    // wizardOrder is sibling — untouched by useExecuteImport.
    expect(orderCalls).toBe(1)
  })

  it('useDeleteImport (.297) cascades imports.list only', async () => {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    let detailCalls = 0
    let statusCalls = 0
    mockList.mockImplementation(async () => { listCalls += 1; return { data: [] } })
    mockGetJob.mockImplementation(async () => { detailCalls += 1; return { id: 'j-1' } })
    mockGetMigrationStatus.mockImplementation(async () => { statusCalls += 1; return { data: {} } })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result: list } = renderHook(() => useImportJobs(), { wrapper })
    const { result: detail } = renderHook(() => useImportJob('j-1'), { wrapper })
    const { result: status } = renderHook(() => useMigrationStatus(), { wrapper })
    const { result: del } = renderHook(() => useDeleteImport(), { wrapper })

    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(detail.current.isSuccess).toBe(true)
      expect(status.current.isSuccess).toBe(true)
    })
    expect(listCalls).toBe(1)
    expect(detailCalls).toBe(1)
    expect(statusCalls).toBe(1)

    await del.current.mutateAsync('j-1')

    await waitFor(() => { expect(listCalls).toBe(2) })
    expect(detailCalls).toBe(1)
    expect(statusCalls).toBe(1)
  })
})

// ─── Cross-tenant isolation: cache + DATA (L18 applied upfront) ──────────────

describe('cross-tenant isolation', () => {
  it('importsListInvalidationPredicate rejects tenant-B imports.list cache entry', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const tenantBKey = ['imports', 'list', 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, { data: [{ id: 'j-tenant-b' }] })

    const pred = importsListInvalidationPredicate('tenant-A', 'company-1')
    await client.invalidateQueries({ predicate: pred })

    const tBQuery = client.getQueryCache().find({ queryKey: tenantBKey, exact: true })
    expect(tBQuery?.state.data).toEqual({ data: [{ id: 'j-tenant-b' }] })
    expect(tBQuery?.state.isInvalidated).toBe(false)
  })

  it('cross-tenant DATA isolation: tenant-A useImportJobs result is the empty mock — NOT seeded tenant-B data', async () => {
    // L18: prove tenant-A query results don't contain tenant-B data, not just
    // that the tenant-B cache entry survives. Pre-seed tenant-B cache, render
    // useImportJobs under tenant-A, assert tenant-A's slot equals the empty
    // mock response.
    const client = createTestQueryClient()

    const tenantBKey = ['imports', 'list', 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, { data: [{ id: 'leaked-tenant-b-import' }] })

    setTenant('tenant-A', 'company-1')
    const { result } = renderHook(() => useImportJobs(), { wrapper: makeWrapper(client) })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const tenantAKey = ['imports', 'list', 'tenant-A', 'company-1']
    const tA = client.getQueryCache().find({ queryKey: tenantAKey, exact: true })
    expect(tA?.state.data).toEqual({ data: [] })
    const tAData = (tA?.state.data as { data: Array<{ id: string }> }).data
    expect(tAData.map((j) => j.id)).not.toContain('leaked-tenant-b-import')
  })
})

// importKeys factory smoke test (used by callsites; ensures shape is stable)
describe('importKeys factory shape', () => {
  it('exposes the expected key shapes', () => {
    expect(importKeys.all).toEqual(['imports'])
    expect(importKeys.lists()).toEqual(['imports', 'list'])
    expect(importKeys.list('all')).toEqual(['imports', 'list', 'all'])
    expect(importKeys.detail('j-1')).toEqual(['imports', 'detail', 'j-1'])
    expect(importKeys.errors('j-1')).toEqual(['imports', 'errors', 'j-1'])
    expect(importKeys.preview('j-1')).toEqual(['imports', 'preview', 'j-1'])
    expect(importKeys.wizard).toEqual(['migration-wizard'])
    expect(importKeys.wizardOrder()).toEqual(['migration-wizard', 'order'])
    expect(importKeys.wizardStatus()).toEqual(['migration-wizard', 'status'])
    expect(importKeys.dependencies('products')).toEqual(['migration-wizard', 'dependencies', 'products'])
  })
})
