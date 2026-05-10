import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  stampCardsKey,
  useCreateStampCard,
  useDeleteStampCard,
  useStampCards,
  useUpdateStampCard,
} from '../useStampCards'
import type { CreateStampCardData, StampCard } from '../../types/loyalty'

const mockListStampCards = vi.hoisted(() => vi.fn())
const mockCreateStampCard = vi.hoisted(() => vi.fn())
const mockUpdateStampCard = vi.hoisted(() => vi.fn())
const mockDeleteStampCard = vi.hoisted(() => vi.fn())

vi.mock('../../api/stampCardApi', () => ({
  listStampCards: mockListStampCards,
  createStampCard: mockCreateStampCard,
  updateStampCard: mockUpdateStampCard,
  deleteStampCard: mockDeleteStampCard,
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

function stampCardFixture(id: string): StampCard {
  return {
    id,
    program_id: 'program-1',
    name: `Stamp ${id}`,
    stamps_required: 10,
    stamps_per_item: 1,
    qualifying_items: null,
    reward_id: 'reward-1',
    max_active_cards: null,
    expiry_days: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
  }
}

const createPayload: CreateStampCardData = {
  name: 'Stamp',
  stamps_required: 10,
  reward_id: 'reward-1',
}

beforeEach(() => {
  mockListStampCards.mockReset()
  mockListStampCards.mockResolvedValue([])
  mockCreateStampCard.mockReset()
  mockCreateStampCard.mockResolvedValue(stampCardFixture('stamp-new'))
  mockUpdateStampCard.mockReset()
  mockUpdateStampCard.mockResolvedValue(stampCardFixture('stamp-1'))
  mockDeleteStampCard.mockReset()
  mockDeleteStampCard.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('stamp-card queryKey shape', () => {
  it('wraps the program stamp-cards key with tenant/company (.368)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()

    const { result } = renderHook(() => useStampCards('program-1'), {
      wrapper: makeWrapper(client),
    })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    expect(keys.find((k) => k[0] === 'loyalty-stamp-cards')).toEqual([
      'loyalty-stamp-cards',
      'program-1',
      'tenant-A',
      'company-1',
    ])
  })
})

describe('stamp-card mutation cascades', () => {
  async function expectListRefetchAfterMutation(
    mutate: (client: QueryClient) => Promise<void>,
  ) {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    mockListStampCards.mockImplementation(async () => {
      listCalls += 1
      return [stampCardFixture(`stamp-${String(listCalls)}`)]
    })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result } = renderHook(() => useStampCards('program-1'), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(listCalls).toBe(1)

    await mutate(client)

    await waitFor(() => { expect(listCalls).toBe(2) })
  }

  it('create/update/delete refetch the scoped program list (.369-.371)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useCreateStampCard('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync(createPayload)
    })
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useUpdateStampCard('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync({ id: 'stamp-1', data: { name: 'Updated' } })
    })
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useDeleteStampCard('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync('stamp-1')
    })
  })
})

describe('cross-tenant stamp-card isolation', () => {
  it('tenant-A stamp-cards data does not contain tenant-B entries (L18)', async () => {
    const client = persistentQueryClient()
    const tenantBKey = ['loyalty-stamp-cards', 'program-1', 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, [stampCardFixture('leaked-tenant-b-stamp-card')])

    setTenant('tenant-A', 'company-1')
    const { result } = renderHook(() => useStampCards('program-1'), {
      wrapper: makeWrapper(client),
    })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const tenantAKey = ['loyalty-stamp-cards', 'program-1', 'tenant-A', 'company-1']
    const tenantAData = client.getQueryData<StampCard[]>(tenantAKey)
    expect(tenantAData).toEqual([])
    expect(tenantAData?.map((card) => card.id)).not.toContain(
      'leaked-tenant-b-stamp-card',
    )
    expect(client.getQueryData(tenantBKey)).toEqual([
      stampCardFixture('leaked-tenant-b-stamp-card'),
    ])
  })
})

describe('stampCardsKey factory shape', () => {
  it('exposes the expected unscoped key', () => {
    expect(stampCardsKey('program-1')).toEqual(['loyalty-stamp-cards', 'program-1'])
  })
})
