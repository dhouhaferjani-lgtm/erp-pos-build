import { QueryClient } from '@tanstack/react-query'
import { waitFor } from '@testing-library/react'
import { useRef } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

// §14.2 — `useCloseOrder` removed as part of the new-sale server-authoring
// disposition. The order-close → SALE_RECEIPT path is retired; the
// invalidation contract for the close mutation no longer exists.
import {
  orderKeys,
  useAddOrderLine,
  useCancelOrder,
  useCreateOrder,
  useModifyOrderLine,
  useOrder,
  useOrders,
  useRemoveOrderLine,
  useSendToKitchen,
} from '../useOrders'
import type {
  AddOrderLineRequest,
  CreateOrderRequest,
  ModifyOrderLineRequest,
  OrderData,
  OrderListResponse,
} from '../../api/orderApi'

const mockOrderApi = vi.hoisted(() => ({
  getOrders: vi.fn(),
  getOrder: vi.fn(),
  createOrder: vi.fn(),
  addOrderLine: vi.fn(),
  modifyOrderLine: vi.fn(),
  removeOrderLine: vi.fn(),
  sendToKitchen: vi.fn(),
  // §14.2 — closeOrder slot removed; the order-close → SALE_RECEIPT path
  // is retired.
  cancelOrder: vi.fn(),
}))

vi.mock('../../api/orderApi', async () => {
  const actual = await vi.importActual<typeof import('../../api/orderApi')>('../../api/orderApi')
  return {
    ...actual,
    ...mockOrderApi,
  }
})

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

function cacheKeys(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

const order: OrderData = {
  id: 'order-1',
  terminal_id: 'terminal-1',
  shift_id: 'shift-1',
  table_id: null,
  order_number: 'ORD-1',
  status: 'open',
  cashier_id: 'user-1',
  cashier_name: 'Test User',
  customer_name: null,
  customer_identifier: null,
  partner_id: null,
  subtotal: '10.000',
  tax_amount: '0.000',
  discount_amount: '0.000',
  total: '10.000',
  currency: 'TND',
  consumption_mode: null,
  notes: null,
  opened_at: '2026-05-11T10:00:00Z',
  sent_at: null,
  ready_at: null,
  served_at: null,
  closed_at: null,
  cancelled_at: null,
  receipt_id: null,
  lines: [],
}

const listResponse: OrderListResponse = {
  data: [order],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 1,
    from: 1,
    to: 1,
    timestamp: '2026-05-11T10:00:00Z',
  },
}

const createRequest: CreateOrderRequest = {
  terminal_id: 'terminal-1',
  shift_id: 'shift-1',
}

const addLineRequest: AddOrderLineRequest = {
  product_id: 'product-1',
  quantity: 1,
  unit_price: '10.000',
  tax_rate: '0.000',
}

const modifyLineRequest: ModifyOrderLineRequest = {
  quantity: 2,
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockOrderApi.getOrders.mockResolvedValue(listResponse)
  mockOrderApi.getOrder.mockResolvedValue(order)
  mockOrderApi.createOrder.mockResolvedValue(order)
  mockOrderApi.addOrderLine.mockResolvedValue({ line: order.lines[0], order })
  mockOrderApi.modifyOrderLine.mockResolvedValue({ line: order.lines[0], order })
  mockOrderApi.removeOrderLine.mockResolvedValue(order)
  mockOrderApi.sendToKitchen.mockResolvedValue(order)
  // §14.2 — closeOrder mock removed; route returns HTTP 410 server-side.
  mockOrderApi.cancelOrder.mockResolvedValue(order)
})

afterEach(() => {
  resetTenant()
})

describe('POS orders queryKey tenant scope', () => {
  function QueryProbe() {
    useOrders({ status: 'open' })
    useOrder('order-1')
    return null
  }

  it('scopes order list and detail query keys (.485-.486)', () => {
    const queryClient = createPersistentQueryClient()

    renderWithProviders(<QueryProbe />, { queryClient })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['orders', 'list', { status: 'open' }, 'tenant-A', 'company-1'],
      ['orders', 'detail', 'order-1', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch without tenant/company state', () => {
    resetTenant()

    renderWithProviders(<QueryProbe />)

    expect(mockOrderApi.getOrders).not.toHaveBeenCalled()
    expect(mockOrderApi.getOrder).not.toHaveBeenCalled()
  })
})

describe('POS order mutation invalidation', () => {
  function OrdersMutationProbe() {
    const listFetchesRef = useRef(0)
    const detailFetchesRef = useRef(0)
    ;(globalThis as Record<string, unknown>)['__orderCounters'] = {
      list: () => listFetchesRef.current,
      detail: () => detailFetchesRef.current,
    }
    mockOrderApi.getOrders.mockImplementation(async () => {
      listFetchesRef.current += 1
      return listResponse
    })
    mockOrderApi.getOrder.mockImplementation(async () => {
      detailFetchesRef.current += 1
      return order
    })
    useOrders({ status: 'open' })
    useOrder('order-1')
    const create = useCreateOrder()
    const add = useAddOrderLine()
    const modify = useModifyOrderLine()
    const remove = useRemoveOrderLine()
    const send = useSendToKitchen()
    // §14.2 — close mutation removed from the harness.
    const cancel = useCancelOrder()
    ;(globalThis as Record<string, unknown>)['__orderMutations'] = {
      create,
      add,
      modify,
      remove,
      send,
      cancel,
    }
    return null
  }

  function orderCounters() {
    return (globalThis as Record<string, unknown>)['__orderCounters'] as {
      list: () => number
      detail: () => number
    }
  }

  function orderMutations() {
    return (globalThis as Record<string, unknown>)['__orderMutations'] as {
      create: { mutateAsync: (input: CreateOrderRequest) => Promise<unknown> }
      add: { mutateAsync: (input: { orderId: string; data: AddOrderLineRequest }) => Promise<unknown> }
      modify: { mutateAsync: (input: { orderId: string; lineId: string; data: ModifyOrderLineRequest }) => Promise<unknown> }
      remove: { mutateAsync: (input: { orderId: string; lineId: string }) => Promise<unknown> }
      send: { mutateAsync: (id: string) => Promise<unknown> }
      // §14.2 — close slot removed; route returns HTTP 410.
      cancel: { mutateAsync: (input: { orderId: string; reason?: string }) => Promise<unknown> }
    }
  }

  it('refetches current-tenant order lists/details and preserves tenant-B cache (.487-.499)', async () => {
    const queryClient = createPersistentQueryClient()
    renderWithProviders(<OrdersMutationProbe />, { queryClient })

    await waitFor(() => {
      expect(orderCounters().list()).toBe(1)
      expect(orderCounters().detail()).toBe(1)
    })

    queryClient.setQueryData(['orders', 'list', { status: 'open' }, 'tenant-B', 'company-1'], { marker: 'tenant-B-list' })
    queryClient.setQueryData([...orderKeys.detail('order-1'), 'tenant-B', 'company-1'], { marker: 'tenant-B-detail' })

    await orderMutations().create.mutateAsync(createRequest)
    await waitFor(() => {
      expect(orderCounters().list()).toBe(2)
      expect(orderCounters().detail()).toBe(1)
    })

    await orderMutations().add.mutateAsync({ orderId: 'order-1', data: addLineRequest })
    await waitFor(() => {
      expect(orderCounters().list()).toBe(3)
      expect(orderCounters().detail()).toBe(2)
    })

    await orderMutations().modify.mutateAsync({ orderId: 'order-1', lineId: 'line-1', data: modifyLineRequest })
    await waitFor(() => {
      expect(orderCounters().list()).toBe(4)
      expect(orderCounters().detail()).toBe(3)
    })

    await orderMutations().remove.mutateAsync({ orderId: 'order-1', lineId: 'line-1' })
    await waitFor(() => {
      expect(orderCounters().list()).toBe(5)
      expect(orderCounters().detail()).toBe(4)
    })

    await orderMutations().send.mutateAsync('order-1')
    await waitFor(() => {
      expect(orderCounters().list()).toBe(6)
      expect(orderCounters().detail()).toBe(5)
    })

    // §14.2 — close mutation removed: the order-close → SALE_RECEIPT path
    // is retired; the backend route returns HTTP 410. Cancel remains as
    // the non-receipt termination path.

    await orderMutations().cancel.mutateAsync({ orderId: 'order-1', reason: 'duplicate' })
    await waitFor(() => {
      expect(orderCounters().list()).toBe(7)
      expect(orderCounters().detail()).toBe(6)
    })
    expect(queryClient.getQueryData(['orders', 'list', { status: 'open' }, 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B-list',
    })
    expect(queryClient.getQueryData([...orderKeys.detail('order-1'), 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B-detail',
    })
  })
})
