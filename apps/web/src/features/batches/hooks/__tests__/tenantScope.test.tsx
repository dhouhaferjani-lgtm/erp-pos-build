import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import type {
  Batch,
  BatchStockByLocation,
  ExpiringProduct,
  FEFOResult,
  GetBatchesParams,
  PaginatedBatchesResponse,
} from '../../types'
import {
  useBatch,
  useBatches,
  useBatchStock,
  useCreateBatch,
  useDeleteBatch,
  useExpiringProducts,
  useFEFOSuggestions,
  useProductBatches,
  useRecallBatch,
  useUpdateBatch,
} from '../useBatches'

const mockGetBatches = vi.hoisted(() => vi.fn())
const mockGetBatch = vi.hoisted(() => vi.fn())
const mockCreateBatch = vi.hoisted(() => vi.fn())
const mockUpdateBatch = vi.hoisted(() => vi.fn())
const mockDeleteBatch = vi.hoisted(() => vi.fn())
const mockRecallBatch = vi.hoisted(() => vi.fn())
const mockGetExpiringProducts = vi.hoisted(() => vi.fn())
const mockGetBatchStock = vi.hoisted(() => vi.fn())
const mockGetFEFOSuggestions = vi.hoisted(() => vi.fn())
const mockGetProductBatches = vi.hoisted(() => vi.fn())

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
    success: vi.fn(),
    warning: vi.fn(),
  },
}))

vi.mock('../../api/batches', () => ({
  createBatch: mockCreateBatch,
  deleteBatch: mockDeleteBatch,
  getBatch: mockGetBatch,
  getBatches: mockGetBatches,
  getBatchStock: mockGetBatchStock,
  getExpiringProducts: mockGetExpiringProducts,
  getFEFOSuggestions: mockGetFEFOSuggestions,
  getProductBatches: mockGetProductBatches,
  recallBatch: mockRecallBatch,
  updateBatch: mockUpdateBatch,
}))

const batchParams: GetBatchesParams = { product_id: 'product-1', per_page: 15 }

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.test',
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
    companies: [],
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

function makeWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function cacheKeys(queryClient: QueryClient): unknown[][] {
  return queryClient
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

function batchFixture(uuid: string): Batch {
  return {
    id: 1,
    uuid,
    product_id: 'product-1',
    product_variant_id: null,
    batch_number: 'BATCH-1',
    manufacturing_date: null,
    expiry_date: '2027-05-11',
    expiry_status: 'OK',
    is_active: true,
    is_recalled: false,
    is_expired: false,
    recall_reason: null,
    recalled_at: null,
    notes: null,
    can_be_sold: true,
    days_until_expiry: 365,
    total_quantity: 10,
    available_quantity: 10,
    created_at: '2026-05-11T09:00:00Z',
    updated_at: '2026-05-11T09:00:00Z',
    product: {
      id: 'product-1',
      name: 'Product',
      sku: 'SKU-1',
    },
    batch_stock: [
      {
        location_id: 1,
        quantity: 10,
        reserved_quantity: 0,
        available_quantity: 10,
      },
    ],
  }
}

function batchesResponseFixture(uuid: string): PaginatedBatchesResponse {
  return {
    data: [batchFixture(uuid)],
    meta: { per_page: 15, has_more: false, total: 1 },
    links: { next: null, prev: null },
  }
}

function expiringProductFixture(): ExpiringProduct {
  return {
    product_id: 'product-1',
    product_name: 'Product',
    product_sku: 'SKU-1',
    total_batches: 1,
    total_quantity: '10',
    earliest_expiry_date: '2027-05-11',
    critical_count: 0,
    warning_count: 0,
    batches: [
      {
        batch_id: 1,
        batch_number: 'BATCH-1',
        expiry_date: '2027-05-11',
        expiry_status: 'OK',
        quantity: '10',
        location_name: 'Main',
      },
    ],
  }
}

function batchStockFixture(): BatchStockByLocation {
  return {
    location_id: 1,
    location_name: 'Main',
    quantity: '10',
    reserved_quantity: '0',
    available_quantity: '10',
  }
}

function fefoFixture(): FEFOResult {
  return {
    suggestions: [
      {
        batch: batchFixture('batch-1'),
        quantity: 1,
        available_quantity: 10,
        expiry_status: 'OK',
        days_until_expiry: 365,
      },
    ],
    fully_fulfilled: true,
    total_allocated: 1,
    requested_quantity: 1,
    shortfall: 0,
  }
}

function useProductDetailProbe(queryFn: () => Promise<unknown>) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['products', 'detail', 'product-1']),
    queryFn,
    enabled: tenantId !== null && companyId !== null,
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockGetBatches.mockResolvedValue(batchesResponseFixture('batch-1'))
  mockGetBatch.mockResolvedValue(batchFixture('batch-1'))
  mockCreateBatch.mockResolvedValue(batchFixture('batch-1'))
  mockUpdateBatch.mockResolvedValue(batchFixture('batch-1'))
  mockDeleteBatch.mockResolvedValue(undefined)
  mockRecallBatch.mockResolvedValue(batchFixture('batch-1'))
  mockGetExpiringProducts.mockResolvedValue([expiringProductFixture()])
  mockGetBatchStock.mockResolvedValue([batchStockFixture()])
  mockGetFEFOSuggestions.mockResolvedValue(fefoFixture())
  mockGetProductBatches.mockResolvedValue([batchFixture('batch-1')])
})

afterEach(() => {
  resetTenant()
})

describe('batch hooks tenant scope', () => {
  it('wraps batch read query keys with the active tenant and company (.030-.035)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => ({
      batch: useBatch('batch-1'),
      batches: useBatches(batchParams),
      batchStock: useBatchStock('batch-1'),
      expiring: useExpiringProducts(30),
      fefo: useFEFOSuggestions('product-1', 1, 1),
      productBatches: useProductBatches('product-1'),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.batch.isSuccess).toBe(true)
      expect(result.current.batches.isSuccess).toBe(true)
      expect(result.current.batchStock.isSuccess).toBe(true)
      expect(result.current.expiring.isSuccess).toBe(true)
      expect(result.current.fefo.isSuccess).toBe(true)
      expect(result.current.productBatches.isSuccess).toBe(true)
    })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['batches', 'detail', 'batch-1', 'tenant-A', 'company-1'],
      ['batches', 'list', batchParams, 'tenant-A', 'company-1'],
      ['batches', 'stock', 'batch-1', 'tenant-A', 'company-1'],
      ['batches', 'expiring', 30, 'tenant-A', 'company-1'],
      ['batches', 'fefo', 'product-1', 1, 1, 'tenant-A', 'company-1'],
      ['batches', 'product', 'product-1', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch batch reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      batch: useBatch('batch-1'),
      batches: useBatches(batchParams),
      batchStock: useBatchStock('batch-1'),
      expiring: useExpiringProducts(30),
      fefo: useFEFOSuggestions('product-1', 1, 1),
      productBatches: useProductBatches('product-1'),
    }), { wrapper })

    expect(mockGetBatch).not.toHaveBeenCalled()
    expect(mockGetBatches).not.toHaveBeenCalled()
    expect(mockGetBatchStock).not.toHaveBeenCalled()
    expect(mockGetExpiringProducts).not.toHaveBeenCalled()
    expect(mockGetFEFOSuggestions).not.toHaveBeenCalled()
    expect(mockGetProductBatches).not.toHaveBeenCalled()
  })

  it('bounds batch mutation invalidation to the active tenant cache (.036-.044)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let batchListCalls = 0
    let batchDetailCalls = 0
    let batchStockCalls = 0
    let expiringCalls = 0
    let fefoCalls = 0
    let productBatchesCalls = 0
    let productDetailCalls = 0

    mockGetBatches.mockImplementation(async () => {
      batchListCalls += 1
      return batchesResponseFixture(`batch-list-${batchListCalls}`)
    })
    mockGetBatch.mockImplementation(async () => {
      batchDetailCalls += 1
      return batchFixture(`batch-detail-${batchDetailCalls}`)
    })
    mockGetBatchStock.mockImplementation(async () => {
      batchStockCalls += 1
      return [batchStockFixture()]
    })
    mockGetExpiringProducts.mockImplementation(async () => {
      expiringCalls += 1
      return [expiringProductFixture()]
    })
    mockGetFEFOSuggestions.mockImplementation(async () => {
      fefoCalls += 1
      return fefoFixture()
    })
    mockGetProductBatches.mockImplementation(async () => {
      productBatchesCalls += 1
      return [batchFixture(`product-batch-${productBatchesCalls}`)]
    })
    const productDetailQueryFn = vi.fn(async () => {
      productDetailCalls += 1
      return { id: `product-${productDetailCalls}` }
    })

    queryClient.setQueryData(
      ['batches', 'list', batchParams, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-batches-preserved' },
    )
    queryClient.setQueryData(
      ['batches', 'detail', 'batch-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-batch-preserved' },
    )
    queryClient.setQueryData(
      ['batches', 'stock', 'batch-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-stock-preserved' },
    )
    queryClient.setQueryData(
      ['batches', 'expiring', 30, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-expiring-preserved' },
    )
    queryClient.setQueryData(
      ['batches', 'fefo', 'product-1', 1, 1, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-fefo-preserved' },
    )
    queryClient.setQueryData(
      ['batches', 'product', 'product-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-product-batches-preserved' },
    )
    queryClient.setQueryData(
      ['products', 'detail', 'product-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-product-detail-preserved' },
    )

    const { result: reads } = renderHook(() => ({
      batch: useBatch('batch-1'),
      batches: useBatches(batchParams),
      batchStock: useBatchStock('batch-1'),
      expiring: useExpiringProducts(30),
      fefo: useFEFOSuggestions('product-1', 1, 1),
      productBatches: useProductBatches('product-1'),
      productDetail: useProductDetailProbe(productDetailQueryFn),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.batch.isSuccess).toBe(true)
      expect(reads.current.batches.isSuccess).toBe(true)
      expect(reads.current.batchStock.isSuccess).toBe(true)
      expect(reads.current.expiring.isSuccess).toBe(true)
      expect(reads.current.fefo.isSuccess).toBe(true)
      expect(reads.current.productBatches.isSuccess).toBe(true)
      expect(reads.current.productDetail.isSuccess).toBe(true)
      expect(batchListCalls).toBe(1)
      expect(batchDetailCalls).toBe(1)
      expect(batchStockCalls).toBe(1)
      expect(expiringCalls).toBe(1)
      expect(fefoCalls).toBe(1)
      expect(productBatchesCalls).toBe(1)
      expect(productDetailCalls).toBe(1)
    })

    const { result: mutations } = renderHook(() => ({
      createBatch: useCreateBatch(),
      deleteBatch: useDeleteBatch(),
      recallBatch: useRecallBatch(),
      updateBatch: useUpdateBatch(),
    }), { wrapper })

    await act(async () => {
      await mutations.current.createBatch.mutateAsync({
        product_id: 'product-1',
        batch_number: 'BATCH-1',
        expiry_date: '2027-05-11',
      })
    })
    await waitFor(() => {
      expect(batchListCalls).toBe(2)
      expect(batchDetailCalls).toBe(1)
      expect(batchStockCalls).toBe(2)
      expect(expiringCalls).toBe(2)
      expect(fefoCalls).toBe(2)
      expect(productBatchesCalls).toBe(2)
      expect(productDetailCalls).toBe(2)
    })

    await act(async () => {
      await mutations.current.updateBatch.mutateAsync({
        uuid: 'batch-1',
        input: { notes: 'Updated' },
      })
    })
    await waitFor(() => {
      expect(batchListCalls).toBe(3)
      expect(batchDetailCalls).toBe(2)
      expect(batchStockCalls).toBe(3)
      expect(expiringCalls).toBe(3)
      expect(fefoCalls).toBe(3)
      expect(productBatchesCalls).toBe(3)
      expect(productDetailCalls).toBe(3)
    })

    await act(async () => {
      await mutations.current.deleteBatch.mutateAsync('batch-1')
    })
    await waitFor(() => {
      expect(batchListCalls).toBe(4)
      expect(batchDetailCalls).toBe(3)
      expect(batchStockCalls).toBe(4)
      expect(expiringCalls).toBe(4)
      expect(fefoCalls).toBe(4)
      expect(productBatchesCalls).toBe(4)
      expect(productDetailCalls).toBe(3)
    })

    await act(async () => {
      await mutations.current.recallBatch.mutateAsync({
        uuid: 'batch-1',
        input: { recall_reason: 'quality' },
      })
    })
    await waitFor(() => {
      expect(batchListCalls).toBe(5)
      expect(batchDetailCalls).toBe(4)
      expect(batchStockCalls).toBe(5)
      expect(expiringCalls).toBe(5)
      expect(fefoCalls).toBe(5)
      expect(productBatchesCalls).toBe(5)
      expect(productDetailCalls).toBe(4)
    })

    expect(queryClient.getQueryData([
      'batches',
      'list',
      batchParams,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-batches-preserved' })
    expect(queryClient.getQueryData([
      'batches',
      'detail',
      'batch-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-batch-preserved' })
    expect(queryClient.getQueryData([
      'batches',
      'stock',
      'batch-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-stock-preserved' })
    expect(queryClient.getQueryData([
      'batches',
      'expiring',
      30,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-expiring-preserved' })
    expect(queryClient.getQueryData([
      'batches',
      'fefo',
      'product-1',
      1,
      1,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-fefo-preserved' })
    expect(queryClient.getQueryData([
      'batches',
      'product',
      'product-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-product-batches-preserved' })
    expect(queryClient.getQueryData([
      'products',
      'detail',
      'product-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-product-detail-preserved' })
  })
})
