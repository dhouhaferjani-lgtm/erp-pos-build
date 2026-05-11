import { QueryClient, useQuery } from '@tanstack/react-query'
import { act, waitFor } from '@testing-library/react'
import { useRef } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { useDiscountPermissions } from '../useDiscountPermissions'
import { useDiscountPreview } from '../useDiscountPreview'
import {
  useDiscardOrder,
  useHeldOrders,
  useHoldOrder,
  useRecallOrder,
} from '../useHeldOrders'
import { useKitchenChannel } from '../useKitchenChannel'
import {
  kitchenKeys,
  useBumpOrder,
  useKitchenOrders,
  useMarkOrderServed,
  useUpdateLineStatus,
} from '../useKitchenOrders'
import type { HoldOrderRequest } from '../../api/heldOrderApi'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockPreviewDiscounts = vi.hoisted(() => vi.fn())
const mockHeldOrderApi = vi.hoisted(() => ({
  getHeldOrders: vi.fn(),
  holdOrder: vi.fn(),
  recallHeldOrder: vi.fn(),
  discardHeldOrder: vi.fn(),
}))
const mockKitchenApi = vi.hoisted(() => ({
  getKitchenOrders: vi.fn(),
  updateLineStatus: vi.fn(),
  bumpOrder: vi.fn(),
  markOrderServed: vi.fn(),
}))
const realtimeEvents = vi.hoisted(() => [] as Array<() => void>)

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
  }
})

vi.mock('../../api/discountApi', async () => {
  const actual = await vi.importActual<typeof import('../../api/discountApi')>('../../api/discountApi')
  return {
    ...actual,
    previewDiscounts: mockPreviewDiscounts,
  }
})

vi.mock('../../api/heldOrderApi', async () => {
  const actual = await vi.importActual<typeof import('../../api/heldOrderApi')>('../../api/heldOrderApi')
  return {
    ...actual,
    ...mockHeldOrderApi,
  }
})

vi.mock('../../api/kitchenApi', async () => {
  const actual = await vi.importActual<typeof import('../../api/kitchenApi')>('../../api/kitchenApi')
  return {
    ...actual,
    ...mockKitchenApi,
  }
})

vi.mock('@/hooks/useRealtimeChannel', () => ({
  useRealtimeChannel: vi.fn((options: { onEvent: () => void }) => {
    realtimeEvents.push(options.onEvent)
  }),
}))

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

function scopedPosKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

const holdRequest: HoldOrderRequest = {
  terminal_id: 'terminal-1',
  shift_id: 'shift-1',
  cart_snapshot: {
    lines: [],
    customer: null,
    consumption_mode: null,
    notes: null,
    discount: null,
  },
}

beforeEach(() => {
  vi.clearAllMocks()
  realtimeEvents.length = 0
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockResolvedValue({
    canDiscount: true,
    canApplyLineDiscounts: true,
    canApplyTransactionDiscounts: true,
    maxDiscountPercent: 10,
    requiresReason: false,
    effectiveLimit: 10,
  })
  mockPreviewDiscounts.mockResolvedValue({ lines: [] })
  mockHeldOrderApi.getHeldOrders.mockResolvedValue([])
  mockHeldOrderApi.holdOrder.mockResolvedValue({ id: 'held-1' })
  mockHeldOrderApi.recallHeldOrder.mockResolvedValue({ id: 'held-1' })
  mockHeldOrderApi.discardHeldOrder.mockResolvedValue(undefined)
  mockKitchenApi.getKitchenOrders.mockResolvedValue([])
  mockKitchenApi.updateLineStatus.mockResolvedValue({ line: { id: 'line-1', status: 'ready', prepared_at: null }, order: { id: 'order-1' } })
  mockKitchenApi.bumpOrder.mockResolvedValue({ id: 'order-1' })
  mockKitchenApi.markOrderServed.mockResolvedValue({ id: 'order-1' })
})

afterEach(() => {
  resetTenant()
  vi.useRealTimers()
})

describe('POS operation queryKey tenant scope', () => {
  function QueryProbe() {
    useDiscountPermissions('TERM-1')
    useDiscountPreview({
      cartItems: [],
      subtotal: '0.000',
    })
    useHeldOrders('terminal-1', 'shift-1')
    useKitchenOrders()
    return null
  }

  it('scopes discount, held-order, and kitchen query keys (.473-.475, .480)', () => {
    const queryClient = createPersistentQueryClient()

    renderWithProviders(<QueryProbe />, { queryClient })

    expect(scopedPosKeysFromCache(queryClient)).toEqual(expect.arrayContaining([
      ['pos', 'discount-permissions', 'TERM-1', 'tenant-A', 'company-1'],
      ['pos', 'discount-preview', null, 'tenant-A', 'company-1'],
      ['held-orders', 'list', 'terminal-1', 'shift-1', 'tenant-A', 'company-1'],
      ['kitchen', 'orders', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch without tenant/company state', () => {
    resetTenant()

    renderWithProviders(<QueryProbe />)

    expect(mockApiGet).not.toHaveBeenCalled()
    expect(mockHeldOrderApi.getHeldOrders).not.toHaveBeenCalled()
    expect(mockKitchenApi.getKitchenOrders).not.toHaveBeenCalled()
  })
})

describe('held-order mutation invalidation', () => {
  function HeldOrdersProbe() {
    const listFetchesRef = useRef(0)
    ;(globalThis as Record<string, unknown>)['__heldOrderCounters'] = {
      list: () => listFetchesRef.current,
    }
    mockHeldOrderApi.getHeldOrders.mockImplementation(async () => {
      listFetchesRef.current += 1
      return []
    })
    useHeldOrders('terminal-1', 'shift-1')
    const hold = useHoldOrder()
    const recall = useRecallOrder()
    const discard = useDiscardOrder()
    ;(globalThis as Record<string, unknown>)['__heldOrderMutations'] = { hold, recall, discard }
    return null
  }

  function heldCounters() {
    return (globalThis as Record<string, unknown>)['__heldOrderCounters'] as { list: () => number }
  }

  function heldMutations() {
    return (globalThis as Record<string, unknown>)['__heldOrderMutations'] as {
      hold: { mutateAsync: (input: HoldOrderRequest) => Promise<unknown> }
      recall: { mutateAsync: (id: string) => Promise<unknown> }
      discard: { mutateAsync: (id: string) => Promise<unknown> }
    }
  }

  it('refetches current-tenant held-order lists and preserves tenant-B cache (.476-.478)', async () => {
    const queryClient = createPersistentQueryClient()
    renderWithProviders(<HeldOrdersProbe />, { queryClient })

    await waitFor(() => {
      expect(heldCounters().list()).toBe(1)
    })
    queryClient.setQueryData(
      ['held-orders', 'list', 'terminal-1', 'shift-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B' },
    )

    await heldMutations().hold.mutateAsync(holdRequest)
    await waitFor(() => {
      expect(heldCounters().list()).toBe(2)
    })

    await heldMutations().recall.mutateAsync('held-1')
    await waitFor(() => {
      expect(heldCounters().list()).toBe(3)
    })

    await heldMutations().discard.mutateAsync('held-1')
    await waitFor(() => {
      expect(heldCounters().list()).toBe(4)
    })
    expect(queryClient.getQueryData(['held-orders', 'list', 'terminal-1', 'shift-1', 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B',
    })
  })
})

describe('kitchen invalidation', () => {
  function OrdersProbe({ onFetch }: { onFetch: () => void }) {
    const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
    const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
    useQuery({
      queryKey: tenantScopedKey(['orders', { status: 'open' }]),
      queryFn: async () => {
        onFetch()
        return []
      },
      enabled: tenantId !== null && companyId !== null,
    })
    return null
  }

  function KitchenProbe() {
    const kitchenFetchesRef = useRef(0)
    const orderFetchesRef = useRef(0)
    ;(globalThis as Record<string, unknown>)['__kitchenCounters'] = {
      kitchen: () => kitchenFetchesRef.current,
      orders: () => orderFetchesRef.current,
    }
    mockKitchenApi.getKitchenOrders.mockImplementation(async () => {
      kitchenFetchesRef.current += 1
      return []
    })
    useKitchenOrders()
    useKitchenChannel()
    const update = useUpdateLineStatus()
    const bump = useBumpOrder()
    const served = useMarkOrderServed()
    ;(globalThis as Record<string, unknown>)['__kitchenMutations'] = { update, bump, served }
    return <OrdersProbe onFetch={() => { orderFetchesRef.current += 1 }} />
  }

  function kitchenCounters() {
    return (globalThis as Record<string, unknown>)['__kitchenCounters'] as {
      kitchen: () => number
      orders: () => number
    }
  }

  function kitchenMutations() {
    return (globalThis as Record<string, unknown>)['__kitchenMutations'] as {
      update: { mutateAsync: (input: { orderId: string; lineId: string; status: string }) => Promise<unknown> }
      bump: { mutateAsync: (id: string) => Promise<unknown> }
      served: { mutateAsync: (id: string) => Promise<unknown> }
    }
  }

  it('refetches current-tenant kitchen caches from realtime and mutations, preserving tenant-B (.479-.484)', async () => {
    const queryClient = createPersistentQueryClient()
    renderWithProviders(<KitchenProbe />, { queryClient })

    await waitFor(() => {
      expect(kitchenCounters().kitchen()).toBe(1)
      expect(kitchenCounters().orders()).toBe(1)
    })
    queryClient.setQueryData([...kitchenKeys.orders(), 'tenant-B', 'company-1'], { marker: 'tenant-B' })
    queryClient.setQueryData(['orders', { status: 'open' }, 'tenant-B', 'company-1'], { marker: 'tenant-B' })

    await act(async () => {
      realtimeEvents[0]?.()
    })
    await waitFor(() => {
      expect(kitchenCounters().kitchen()).toBe(2)
    })

    await kitchenMutations().update.mutateAsync({ orderId: 'order-1', lineId: 'line-1', status: 'ready' })
    await waitFor(() => {
      expect(kitchenCounters().kitchen()).toBe(3)
    })

    await kitchenMutations().bump.mutateAsync('order-1')
    await waitFor(() => {
      expect(kitchenCounters().kitchen()).toBe(4)
    })

    await kitchenMutations().served.mutateAsync('order-1')
    await waitFor(() => {
      expect(kitchenCounters().kitchen()).toBe(5)
      expect(kitchenCounters().orders()).toBe(2)
    })
    expect(queryClient.getQueryData([...kitchenKeys.orders(), 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B' })
    expect(queryClient.getQueryData(['orders', { status: 'open' }, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B' })
  })
})
