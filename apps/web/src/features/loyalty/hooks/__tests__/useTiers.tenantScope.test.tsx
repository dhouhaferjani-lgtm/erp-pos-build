import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import { tiersKey, useCreateTier, useDeleteTier, useTiers, useUpdateTier } from '../useTiers'
import type { CreateTierData, Tier } from '../../types/loyalty'

const mockListTiers = vi.hoisted(() => vi.fn())
const mockCreateTier = vi.hoisted(() => vi.fn())
const mockUpdateTier = vi.hoisted(() => vi.fn())
const mockDeleteTier = vi.hoisted(() => vi.fn())

vi.mock('../../api/tierApi', () => ({
  listTiers: mockListTiers,
  createTier: mockCreateTier,
  updateTier: mockUpdateTier,
  deleteTier: mockDeleteTier,
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

function tierFixture(id: string): Tier {
  return {
    id,
    program_id: 'program-1',
    name: `Tier ${id}`,
    level: 1,
    icon: null,
    color: null,
    qualification_type: 'spend',
    qualification_threshold: '100',
    qualification_period_months: null,
    earning_multiplier: '1',
    benefits: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
  }
}

const createPayload: CreateTierData = {
  name: 'Tier',
  level: 1,
  qualification_type: 'spend',
  qualification_threshold: '100',
}

beforeEach(() => {
  mockListTiers.mockReset()
  mockListTiers.mockResolvedValue([])
  mockCreateTier.mockReset()
  mockCreateTier.mockResolvedValue(tierFixture('tier-new'))
  mockUpdateTier.mockReset()
  mockUpdateTier.mockResolvedValue(tierFixture('tier-1'))
  mockDeleteTier.mockReset()
  mockDeleteTier.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('tier queryKey shape', () => {
  it('wraps the program tiers key with tenant/company (.372)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()

    const { result } = renderHook(() => useTiers('program-1'), {
      wrapper: makeWrapper(client),
    })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    expect(keys.find((k) => k[0] === 'loyalty-tiers')).toEqual([
      'loyalty-tiers',
      'program-1',
      'tenant-A',
      'company-1',
    ])
  })
})

describe('tier mutation cascades', () => {
  async function expectListRefetchAfterMutation(
    mutate: (client: QueryClient) => Promise<void>,
  ) {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    mockListTiers.mockImplementation(async () => {
      listCalls += 1
      return [tierFixture(`tier-${String(listCalls)}`)]
    })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result } = renderHook(() => useTiers('program-1'), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(listCalls).toBe(1)

    await mutate(client)

    await waitFor(() => { expect(listCalls).toBe(2) })
  }

  it('create/update/delete refetch the scoped program list (.373-.375)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useCreateTier('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync(createPayload)
    })
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useUpdateTier('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync({ id: 'tier-1', data: { name: 'Updated' } })
    })
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useDeleteTier('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync('tier-1')
    })
  })
})

describe('cross-tenant tier isolation', () => {
  it('tenant-A tiers data does not contain tenant-B entries (L18)', async () => {
    const client = persistentQueryClient()
    const tenantBKey = ['loyalty-tiers', 'program-1', 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, [tierFixture('leaked-tenant-b-tier')])

    setTenant('tenant-A', 'company-1')
    const { result } = renderHook(() => useTiers('program-1'), {
      wrapper: makeWrapper(client),
    })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const tenantAKey = ['loyalty-tiers', 'program-1', 'tenant-A', 'company-1']
    const tenantAData = client.getQueryData<Tier[]>(tenantAKey)
    expect(tenantAData).toEqual([])
    expect(tenantAData?.map((tier) => tier.id)).not.toContain('leaked-tenant-b-tier')
    expect(client.getQueryData(tenantBKey)).toEqual([
      tierFixture('leaked-tenant-b-tier'),
    ])
  })
})

describe('tiersKey factory shape', () => {
  it('exposes the expected unscoped key', () => {
    expect(tiersKey('program-1')).toEqual(['loyalty-tiers', 'program-1'])
  })
})
