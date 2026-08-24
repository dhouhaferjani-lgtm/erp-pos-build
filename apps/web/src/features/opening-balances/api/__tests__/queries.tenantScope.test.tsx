import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  openingBalanceKeys,
  useCreateOpeningBatch,
  useDeleteOpeningBatch,
  useImportOpeningRows,
  useLockOpeningBatch,
  useOpeningBatch,
  useOpeningBatches,
  useOpeningBatchPreview,
  useOpeningBatchRows,
  useOpeningBatchStatus,
  useOpeningBatchTypes,
  usePostOpeningBatch,
  useValidateOpeningBatch,
} from '../queries'
import type {
  OpeningBalanceBatch,
  OpeningBalanceImportRow,
  OpeningBatchStatusResponse,
  OpeningBatchTypeInfo,
  PostPreview,
  PostResult,
  ValidationResult,
} from '../../types'

const mockGetTypes = vi.hoisted(() => vi.fn())
const mockGetStatus = vi.hoisted(() => vi.fn())
const mockList = vi.hoisted(() => vi.fn())
const mockGet = vi.hoisted(() => vi.fn())
const mockCreate = vi.hoisted(() => vi.fn())
const mockDelete = vi.hoisted(() => vi.fn())
const mockGetRows = vi.hoisted(() => vi.fn())
const mockImportRows = vi.hoisted(() => vi.fn())
const mockValidate = vi.hoisted(() => vi.fn())
const mockPreview = vi.hoisted(() => vi.fn())
const mockPost = vi.hoisted(() => vi.fn())
const mockLock = vi.hoisted(() => vi.fn())

vi.mock('../openingBalancesApi', () => ({
  openingBalancesApi: {
    getTypes: mockGetTypes,
    getStatus: mockGetStatus,
    list: mockList,
    get: mockGet,
    create: mockCreate,
    delete: mockDelete,
    getRows: mockGetRows,
    importRows: mockImportRows,
    validate: mockValidate,
    preview: mockPreview,
    post: mockPost,
    lock: mockLock,
  },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, vars?: Record<string, unknown>) => vars ? `${key}:${JSON.stringify(vars)}` : key }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 'test@example.test',
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
    companies: [],
    isLoading: false,
  })
}

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>
  }
}

function persistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function batchFixture(id: string): OpeningBalanceBatch {
  return {
    id,
    tenant_id: 'tenant-A',
    company_id: 'company-1',
    type: 'ACCOUNTING',
    name: `Batch ${id}`,
    cutover_date: '2026-01-01',
    status: 'DRAFT',
    source_system: null,
    import_file_reference: null,
    hash: null,
    previous_hash: null,
    validated_at: null,
    validated_by: null,
    locked_at: null,
    locked_by: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    created_by: 'user-1',
  }
}

function rowFixture(id: string): OpeningBalanceImportRow {
  return {
    id,
    batch_id: 'batch-1',
    row_number: 1,
    row_type: 'account',
    raw_data: {},
    mapped_data: null,
    status: 'PENDING',
    validation_errors: null,
    mapped_entity_id: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  }
}

const typesFixture: OpeningBatchTypeInfo[] = [{
  type: 'ACCOUNTING',
  label: 'Accounting',
  description: 'Accounting balances',
  icon: 'book',
}]

const statusFixture: OpeningBatchStatusResponse = {
  types: {
    ACCOUNTING: {
      type: 'ACCOUNTING',
      label: 'Accounting',
      has_batch: false,
      batch: null,
      is_locked: false,
      is_ready: false,
    },
    INVENTORY: {
      type: 'INVENTORY',
      label: 'Inventory',
      has_batch: false,
      batch: null,
      is_locked: false,
      is_ready: false,
    },
    AR_OPEN_ITEMS: {
      type: 'AR_OPEN_ITEMS',
      label: 'AR',
      has_batch: false,
      batch: null,
      is_locked: false,
      is_ready: false,
    },
    AP_OPEN_ITEMS: {
      type: 'AP_OPEN_ITEMS',
      label: 'AP',
      has_batch: false,
      batch: null,
      is_locked: false,
      is_ready: false,
    },
  },
  all_ready: false,
  inventory_ready: false,
}

const validationFixture: ValidationResult = {
  valid: true,
  total_rows: 1,
  valid_rows: 1,
  invalid_rows: 0,
  errors: {},
}

const previewFixture: PostPreview = {
  batch_type: 'ACCOUNTING',
  entry: {
    entry_date: '2026-01-01',
    description: 'GL Opening Balance - Preview',
    is_historical: true,
    source_type: 'opening_balance',
  },
  lines: [],
  totals: { debit: '0.000', credit: '0.000', is_balanced: true },
}

const postFixture: PostResult = { success: true }

beforeEach(() => {
  mockGetTypes.mockReset(); mockGetTypes.mockResolvedValue(typesFixture)
  mockGetStatus.mockReset(); mockGetStatus.mockResolvedValue(statusFixture)
  mockList.mockReset(); mockList.mockResolvedValue([])
  mockGet.mockReset(); mockGet.mockResolvedValue(batchFixture('batch-1'))
  mockCreate.mockReset(); mockCreate.mockResolvedValue(batchFixture('batch-new'))
  mockDelete.mockReset(); mockDelete.mockResolvedValue(undefined)
  mockGetRows.mockReset(); mockGetRows.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } })
  mockImportRows.mockReset(); mockImportRows.mockResolvedValue({ imported: 1, skipped: 0 })
  mockValidate.mockReset(); mockValidate.mockResolvedValue(validationFixture)
  mockPreview.mockReset(); mockPreview.mockResolvedValue(previewFixture)
  mockPost.mockReset(); mockPost.mockResolvedValue(postFixture)
  mockLock.mockReset(); mockLock.mockResolvedValue(batchFixture('batch-1'))
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('opening balance query key shapes', () => {
  it('wraps all opening balance useQuery keys with tenant/company suffixes (.388-.393)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)

    const hooks = [
      renderHook(() => useOpeningBatchTypes(), { wrapper }),
      renderHook(() => useOpeningBatchStatus(), { wrapper }),
      renderHook(() => useOpeningBatches(), { wrapper }),
      renderHook(() => useOpeningBatch('batch-1'), { wrapper }),
      renderHook(() => useOpeningBatchRows('batch-1', { page: 1 }), { wrapper }),
      renderHook(() => useOpeningBatchPreview('batch-1'), { wrapper }),
    ]

    await waitFor(() => {
      for (const hook of hooks) {
        expect(hook.result.current.isSuccess).toBe(true)
      }
    })

    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    expect(keys).toEqual(expect.arrayContaining([
      ['opening-balances', 'types', 'tenant-A', 'company-1'],
      ['opening-balances', 'status', 'company-1', 'tenant-A', 'company-1'],
      ['opening-balances', 'list', 'company-1', 'tenant-A', 'company-1'],
      ['opening-balances', 'detail', 'company-1', 'batch-1', 'tenant-A', 'company-1'],
      ['opening-balances', 'preview', 'company-1', 'batch-1', 'tenant-A', 'company-1'],
    ]))
    expect(keys).toContainEqual([
      'opening-balances',
      'rows',
      'company-1',
      'batch-1',
      { page: 1 },
      'tenant-A',
      'company-1',
    ])
  })
})

describe('opening balance mutation cascades', () => {
  async function mountBatchQueries(client: QueryClient) {
    const wrapper = makeWrapper(client)
    const hooks = {
      list: renderHook(() => useOpeningBatches(), { wrapper }),
      status: renderHook(() => useOpeningBatchStatus(), { wrapper }),
      detail: renderHook(() => useOpeningBatch('batch-1'), { wrapper }),
      rows: renderHook(() => useOpeningBatchRows('batch-1'), { wrapper }),
      preview: renderHook(() => useOpeningBatchPreview('batch-1'), { wrapper }),
    }
    await waitFor(() => {
      expect(hooks.list.result.current.isSuccess).toBe(true)
      expect(hooks.status.result.current.isSuccess).toBe(true)
      expect(hooks.detail.result.current.isSuccess).toBe(true)
      expect(hooks.rows.result.current.isSuccess).toBe(true)
      expect(hooks.preview.result.current.isSuccess).toBe(true)
    })
    return { wrapper, hooks }
  }

  it('create/delete refetch only the active list and status keys (.394-.397)', async () => {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    let statusCalls = 0
    let detailCalls = 0
    mockList.mockImplementation(async () => { listCalls += 1; return [batchFixture(`batch-list-${listCalls}`)] })
    mockGetStatus.mockImplementation(async () => { statusCalls += 1; return statusFixture })
    mockGet.mockImplementation(async () => { detailCalls += 1; return batchFixture(`batch-detail-${detailCalls}`) })

    const client = createTestQueryClient()
    const { wrapper } = await mountBatchQueries(client)
    expect(listCalls).toBe(1)
    expect(statusCalls).toBe(1)
    expect(detailCalls).toBe(1)

    const { result: create } = renderHook(() => useCreateOpeningBatch(), { wrapper })
    await create.current.mutateAsync({ type: 'ACCOUNTING', name: 'Batch', cutover_date: '2026-01-01' })
    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(statusCalls).toBe(2)
    })
    expect(detailCalls).toBe(1)

    const { result: del } = renderHook(() => useDeleteOpeningBatch(), { wrapper })
    await del.current.mutateAsync('batch-1')
    await waitFor(() => {
      expect(listCalls).toBe(3)
      expect(statusCalls).toBe(3)
    })
    expect(detailCalls).toBe(1)
  })

  it('row import/validate refetch only active detail and rows keys (.398-.401)', async () => {
    setTenant('tenant-A', 'company-1')
    let detailCalls = 0
    let rowCalls = 0
    let listCalls = 0
    mockGet.mockImplementation(async () => { detailCalls += 1; return batchFixture(`batch-detail-${detailCalls}`) })
    mockGetRows.mockImplementation(async () => { rowCalls += 1; return { data: [rowFixture(`row-${rowCalls}`)], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })
    mockList.mockImplementation(async () => { listCalls += 1; return [batchFixture(`batch-list-${listCalls}`)] })

    const client = createTestQueryClient()
    const { wrapper } = await mountBatchQueries(client)
    expect(detailCalls).toBe(1)
    expect(rowCalls).toBe(1)
    expect(listCalls).toBe(1)

    const { result: importRows } = renderHook(() => useImportOpeningRows(), { wrapper })
    await importRows.current.mutateAsync({ batchId: 'batch-1', payload: { rows: [{}] } })
    await waitFor(() => {
      expect(detailCalls).toBe(2)
      expect(rowCalls).toBe(2)
    })
    expect(listCalls).toBe(1)

    const { result: validate } = renderHook(() => useValidateOpeningBatch(), { wrapper })
    await validate.current.mutateAsync('batch-1')
    await waitFor(() => {
      expect(detailCalls).toBe(3)
      expect(rowCalls).toBe(3)
    })
    expect(listCalls).toBe(1)
  })

  it('post/lock refetch the active exact keys they mutate (.402-.406)', async () => {
    setTenant('tenant-A', 'company-1')
    let detailCalls = 0
    let statusCalls = 0
    let listCalls = 0
    let rowCalls = 0
    mockGet.mockImplementation(async () => { detailCalls += 1; return batchFixture(`batch-detail-${detailCalls}`) })
    mockGetStatus.mockImplementation(async () => { statusCalls += 1; return statusFixture })
    mockList.mockImplementation(async () => { listCalls += 1; return [batchFixture(`batch-list-${listCalls}`)] })
    mockGetRows.mockImplementation(async () => { rowCalls += 1; return { data: [rowFixture(`row-${rowCalls}`)], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } })

    const client = createTestQueryClient()
    const { wrapper } = await mountBatchQueries(client)
    expect(detailCalls).toBe(1)
    expect(statusCalls).toBe(1)
    expect(listCalls).toBe(1)
    expect(rowCalls).toBe(1)

    const { result: post } = renderHook(() => usePostOpeningBatch(), { wrapper })
    await post.current.mutateAsync('batch-1')
    await waitFor(() => {
      expect(detailCalls).toBe(2)
      expect(statusCalls).toBe(2)
    })
    expect(listCalls).toBe(1)
    expect(rowCalls).toBe(1)

    const { result: lock } = renderHook(() => useLockOpeningBatch(), { wrapper })
    await lock.current.mutateAsync('batch-1')
    await waitFor(() => {
      expect(detailCalls).toBe(3)
      expect(statusCalls).toBe(3)
      expect(listCalls).toBe(2)
    })
    expect(rowCalls).toBe(1)
  })
})

describe('opening balance cross-tenant isolation', () => {
  it('tenant-A list results do not contain tenant-B seeded cache data (L18)', async () => {
    const client = persistentQueryClient()
    const tenantBListKey = ['opening-balances', 'list', 'company-1', 'tenant-B', 'company-1']
    const tenantBRowsKey = ['opening-balances', 'rows', 'company-1', 'batch-1', undefined, 'tenant-B', 'company-1']
    client.setQueryData(tenantBListKey, [batchFixture('leaked-tenant-b-batch')])
    client.setQueryData(tenantBRowsKey, {
      data: [rowFixture('leaked-tenant-b-row')],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    })

    setTenant('tenant-A', 'company-1')
    const wrapper = makeWrapper(client)
    const { result: list } = renderHook(() => useOpeningBatches(), { wrapper })
    const { result: rows } = renderHook(() => useOpeningBatchRows('batch-1'), { wrapper })

    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(rows.current.isSuccess).toBe(true)
    })

    const tenantAListKey = ['opening-balances', 'list', 'company-1', 'tenant-A', 'company-1']
    const tenantAList = client.getQueryData<OpeningBalanceBatch[]>(tenantAListKey)
    expect(tenantAList).toEqual([])
    expect(tenantAList?.map((batch) => batch.id)).not.toContain('leaked-tenant-b-batch')

    const tenantARowsKey = ['opening-balances', 'rows', 'company-1', 'batch-1', undefined, 'tenant-A', 'company-1']
    const tenantARows = client.getQueryData<{ data: OpeningBalanceImportRow[] }>(tenantARowsKey)
    expect(tenantARows?.data).toEqual([])
    expect(tenantARows?.data.map((row) => row.id)).not.toContain('leaked-tenant-b-row')

    expect(client.getQueryData(tenantBListKey)).toEqual([batchFixture('leaked-tenant-b-batch')])
    expect(client.getQueryData(tenantBRowsKey)).toEqual({
      data: [rowFixture('leaked-tenant-b-row')],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    })
  })
})

describe('openingBalanceKeys factory shape', () => {
  it('keeps the unscoped factories deterministic for callers', () => {
    expect(openingBalanceKeys.list('company-1')).toEqual(['opening-balances', 'list', 'company-1'])
    expect(openingBalanceKeys.detail('company-1', 'batch-1')).toEqual([
      'opening-balances',
      'detail',
      'company-1',
      'batch-1',
    ])
  })
})
