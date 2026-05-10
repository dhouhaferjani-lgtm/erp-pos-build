import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  PROGRAMS_KEY,
  programsInvalidationPredicate,
  useActivateProgram,
  useActivePrograms,
  useCreateProgram,
  useDeactivateProgram,
  useDeleteProgram,
  useProgram,
  usePrograms,
  useUpdateProgram,
} from '../usePrograms'
import type { CreateProgramData, LoyaltyProgram } from '../../types/loyalty'

const mockListPrograms = vi.hoisted(() => vi.fn())
const mockGetProgram = vi.hoisted(() => vi.fn())
const mockCreateProgram = vi.hoisted(() => vi.fn())
const mockUpdateProgram = vi.hoisted(() => vi.fn())
const mockDeleteProgram = vi.hoisted(() => vi.fn())
const mockActivateProgram = vi.hoisted(() => vi.fn())
const mockDeactivateProgram = vi.hoisted(() => vi.fn())
const mockListActivePrograms = vi.hoisted(() => vi.fn())

vi.mock('../../api/programApi', () => ({
  listPrograms: mockListPrograms,
  getProgram: mockGetProgram,
  createProgram: mockCreateProgram,
  updateProgram: mockUpdateProgram,
  deleteProgram: mockDeleteProgram,
  activateProgram: mockActivateProgram,
  deactivateProgram: mockDeactivateProgram,
  listActivePrograms: mockListActivePrograms,
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

function programFixture(id: string): LoyaltyProgram {
  return {
    id,
    name: `Program ${id}`,
    program_type: 'points',
    status: 'active',
    currency: null,
    start_date: null,
    end_date: null,
    terms_and_conditions: null,
    metadata: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
  }
}

const createPayload: CreateProgramData = {
  name: 'Program',
  program_type: 'points',
}

beforeEach(() => {
  mockListPrograms.mockReset()
  mockListPrograms.mockResolvedValue([])
  mockGetProgram.mockReset()
  mockGetProgram.mockResolvedValue(programFixture('program-1'))
  mockCreateProgram.mockReset()
  mockCreateProgram.mockResolvedValue(programFixture('program-new'))
  mockUpdateProgram.mockReset()
  mockUpdateProgram.mockResolvedValue(programFixture('program-1'))
  mockDeleteProgram.mockReset()
  mockDeleteProgram.mockResolvedValue(undefined)
  mockActivateProgram.mockReset()
  mockActivateProgram.mockResolvedValue(programFixture('program-1'))
  mockDeactivateProgram.mockReset()
  mockDeactivateProgram.mockResolvedValue(programFixture('program-1'))
  mockListActivePrograms.mockReset()
  mockListActivePrograms.mockResolvedValue([])
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('programsInvalidationPredicate', () => {
  it('matches loyalty-programs keys for the active tenant/company', () => {
    const pred = programsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['loyalty-programs', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['loyalty-programs', 'program-1', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['loyalty-programs', 'active', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects wrong tenant/company and sibling namespaces', () => {
    const pred = programsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['loyalty-programs', 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['loyalty-programs', 'tenant-A', 'company-2'] })).toBe(false)
    expect(pred({ queryKey: ['loyalty-members', 'tenant-A', 'company-1'] })).toBe(false)
  })
})

describe('loyalty program queryKey shapes', () => {
  it('wraps list, detail, and active-program keys (.354-.356)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)

    const { result: list } = renderHook(() => usePrograms(), { wrapper })
    const { result: detail } = renderHook(() => useProgram('program-1'), { wrapper })
    const { result: active } = renderHook(() => useActivePrograms(), { wrapper })

    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(detail.current.isSuccess).toBe(true)
      expect(active.current.isSuccess).toBe(true)
    })

    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    expect(keys.find((k) => k[0] === 'loyalty-programs' && k.length === 3)).toEqual([
      'loyalty-programs',
      'tenant-A',
      'company-1',
    ])
    expect(keys.find((k) => k[0] === 'loyalty-programs' && k[1] === 'program-1')).toEqual([
      'loyalty-programs',
      'program-1',
      'tenant-A',
      'company-1',
    ])
    expect(keys.find((k) => k[0] === 'loyalty-programs' && k[1] === 'active')).toEqual([
      'loyalty-programs',
      'active',
      'tenant-A',
      'company-1',
    ])
  })
})

describe('loyalty program mutation cascades', () => {
  async function expectListRefetchAfterMutation(
    mutate: (client: QueryClient) => Promise<void>,
  ) {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    mockListPrograms.mockImplementation(async () => {
      listCalls += 1
      return [programFixture(`program-${String(listCalls)}`)]
    })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result } = renderHook(() => usePrograms(), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(listCalls).toBe(1)

    await mutate(client)

    await waitFor(() => { expect(listCalls).toBe(2) })
  }

  it('useCreateProgram refetches active tenant program queries (.357)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useCreateProgram(), { wrapper: makeWrapper(client) })
      await result.current.mutateAsync(createPayload)
    })
  })

  it('useUpdateProgram refetches active tenant program queries (.358)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useUpdateProgram(), { wrapper: makeWrapper(client) })
      await result.current.mutateAsync({ id: 'program-1', data: { name: 'Updated' } })
    })
  })

  it('delete/activate/deactivate refetch active tenant program queries (.359-.361)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useDeleteProgram(), { wrapper: makeWrapper(client) })
      await result.current.mutateAsync('program-1')
    })
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useActivateProgram(), { wrapper: makeWrapper(client) })
      await result.current.mutateAsync('program-1')
    })
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useDeactivateProgram(), { wrapper: makeWrapper(client) })
      await result.current.mutateAsync('program-1')
    })
  })
})

describe('cross-tenant loyalty program isolation', () => {
  it('tenant-A programs data does not contain tenant-B entries (L18)', async () => {
    const client = persistentQueryClient()
    const tenantBKey = ['loyalty-programs', 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, [programFixture('leaked-tenant-b-program')])

    setTenant('tenant-A', 'company-1')
    const { result } = renderHook(() => usePrograms(), { wrapper: makeWrapper(client) })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const tenantAKey = ['loyalty-programs', 'tenant-A', 'company-1']
    const tenantAData = client.getQueryData<LoyaltyProgram[]>(tenantAKey)
    expect(tenantAData).toEqual([])
    expect(tenantAData?.map((program) => program.id)).not.toContain(
      'leaked-tenant-b-program',
    )
    expect(client.getQueryData(tenantBKey)).toEqual([
      programFixture('leaked-tenant-b-program'),
    ])
  })
})

describe('PROGRAMS_KEY factory shape', () => {
  it('exposes the expected unscoped root key', () => {
    expect(PROGRAMS_KEY).toEqual(['loyalty-programs'])
  })
})
