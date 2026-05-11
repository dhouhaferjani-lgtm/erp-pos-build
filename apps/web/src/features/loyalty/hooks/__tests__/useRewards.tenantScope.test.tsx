import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  rewardsKey,
  useActivateReward,
  useCreateReward,
  useDeactivateReward,
  useDeleteReward,
  useRewards,
  useUpdateReward,
} from '../useRewards'
import type { CreateRewardData, Reward } from '../../types/loyalty'

const mockListRewards = vi.hoisted(() => vi.fn())
const mockCreateReward = vi.hoisted(() => vi.fn())
const mockUpdateReward = vi.hoisted(() => vi.fn())
const mockDeleteReward = vi.hoisted(() => vi.fn())
const mockActivateReward = vi.hoisted(() => vi.fn())
const mockDeactivateReward = vi.hoisted(() => vi.fn())

vi.mock('../../api/rewardApi', () => ({
  listRewards: mockListRewards,
  createReward: mockCreateReward,
  updateReward: mockUpdateReward,
  deleteReward: mockDeleteReward,
  activateReward: mockActivateReward,
  deactivateReward: mockDeactivateReward,
}))

vi.mock('@/lib/i18n', () => ({
  default: { t: (key: string) => key },
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 't@example.test',
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

function rewardFixture(id: string): Reward {
  return {
    id,
    program_id: 'program-1',
    name: `Reward ${id}`,
    description: null,
    reward_type: 'credit',
    points_cost: '100',
    reward_value: '10',
    qualifying_items: null,
    max_discount: null,
    min_order_value: null,
    tier_ids: null,
    is_active: true,
    quantity_available: null,
    quantity_per_member: null,
    start_date: null,
    end_date: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
  }
}

const createPayload: CreateRewardData = {
  name: 'Reward',
  reward_type: 'credit',
  points_cost: '100',
}

beforeEach(() => {
  mockListRewards.mockReset()
  mockListRewards.mockResolvedValue([])
  mockCreateReward.mockReset()
  mockCreateReward.mockResolvedValue(rewardFixture('reward-new'))
  mockUpdateReward.mockReset()
  mockUpdateReward.mockResolvedValue(rewardFixture('reward-1'))
  mockDeleteReward.mockReset()
  mockDeleteReward.mockResolvedValue(undefined)
  mockActivateReward.mockReset()
  mockActivateReward.mockResolvedValue(rewardFixture('reward-1'))
  mockDeactivateReward.mockReset()
  mockDeactivateReward.mockResolvedValue(rewardFixture('reward-1'))
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('reward queryKey shape', () => {
  it('wraps the program rewards key with tenant/company (.362)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()

    const { result } = renderHook(() => useRewards('program-1'), {
      wrapper: makeWrapper(client),
    })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    expect(keys.find((k) => k[0] === 'loyalty-rewards')).toEqual([
      'loyalty-rewards',
      'program-1',
      'tenant-A',
      'company-1',
    ])
  })
})

describe('reward mutation cascades', () => {
  async function expectListRefetchAfterMutation(
    mutate: (client: QueryClient) => Promise<void>,
  ) {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    mockListRewards.mockImplementation(async () => {
      listCalls += 1
      return [rewardFixture(`reward-${String(listCalls)}`)]
    })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result } = renderHook(() => useRewards('program-1'), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(listCalls).toBe(1)

    await mutate(client)

    await waitFor(() => { expect(listCalls).toBe(2) })
  }

  it('useCreateReward refetches the scoped program list (.363)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useCreateReward('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync(createPayload)
    })
  })

  it('useUpdateReward refetches the scoped program list (.364)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useUpdateReward('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync({ id: 'reward-1', data: { name: 'Updated' } })
    })
  })

  it('delete/activate/deactivate refetch the scoped program list (.365-.367)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useDeleteReward('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync('reward-1')
    })
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useActivateReward('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync('reward-1')
    })
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useDeactivateReward('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync('reward-1')
    })
  })
})

describe('cross-tenant reward isolation', () => {
  it('tenant-A rewards data does not contain tenant-B entries (L18)', async () => {
    const client = persistentQueryClient()
    const tenantBKey = ['loyalty-rewards', 'program-1', 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, [rewardFixture('leaked-tenant-b-reward')])

    setTenant('tenant-A', 'company-1')
    const { result } = renderHook(() => useRewards('program-1'), {
      wrapper: makeWrapper(client),
    })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const tenantAKey = ['loyalty-rewards', 'program-1', 'tenant-A', 'company-1']
    const tenantAData = client.getQueryData<Reward[]>(tenantAKey)
    expect(tenantAData).toEqual([])
    expect(tenantAData?.map((reward) => reward.id)).not.toContain('leaked-tenant-b-reward')
    expect(client.getQueryData(tenantBKey)).toEqual([
      rewardFixture('leaked-tenant-b-reward'),
    ])
  })
})

describe('rewardsKey factory shape', () => {
  it('exposes the expected unscoped key', () => {
    expect(rewardsKey('program-1')).toEqual(['loyalty-rewards', 'program-1'])
  })
})
