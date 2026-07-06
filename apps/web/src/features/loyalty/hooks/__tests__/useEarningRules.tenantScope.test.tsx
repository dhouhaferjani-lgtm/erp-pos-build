import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  earningRulesKey,
  useActivateEarningRule,
  useCreateEarningRule,
  useDeactivateEarningRule,
  useDeleteEarningRule,
  useEarningRules,
  useUpdateEarningRule,
} from '../useEarningRules'
import type { CreateEarningRuleData, EarningRule } from '../../types/loyalty'

const mockListEarningRules = vi.hoisted(() => vi.fn())
const mockCreateEarningRule = vi.hoisted(() => vi.fn())
const mockUpdateEarningRule = vi.hoisted(() => vi.fn())
const mockDeleteEarningRule = vi.hoisted(() => vi.fn())
const mockActivateEarningRule = vi.hoisted(() => vi.fn())
const mockDeactivateEarningRule = vi.hoisted(() => vi.fn())

vi.mock('../../api/earningRuleApi', () => ({
  listEarningRules: mockListEarningRules,
  createEarningRule: mockCreateEarningRule,
  updateEarningRule: mockUpdateEarningRule,
  deleteEarningRule: mockDeleteEarningRule,
  activateEarningRule: mockActivateEarningRule,
  deactivateEarningRule: mockDeactivateEarningRule,
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

function earningRuleFixture(id: string): EarningRule {
  return {
    id,
    program_id: 'program-1',
    name: `Rule ${id}`,
    rule_type: 'spend',
    priority: 1,
    is_active: true,
    conditions: {},
    reward_value: '10',
    reward_type: 'fixed',
    start_date: null,
    end_date: null,
    max_earn_per_transaction: null,
    max_earn_per_day: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
  }
}

const createPayload: CreateEarningRuleData = {
  name: 'Rule',
  rule_type: 'spend',
  priority: 1,
  conditions: {},
  reward_value: '10',
  reward_type: 'fixed',
}

beforeEach(() => {
  mockListEarningRules.mockReset()
  mockListEarningRules.mockResolvedValue([])
  mockCreateEarningRule.mockReset()
  mockCreateEarningRule.mockResolvedValue(earningRuleFixture('rule-new'))
  mockUpdateEarningRule.mockReset()
  mockUpdateEarningRule.mockResolvedValue(earningRuleFixture('rule-1'))
  mockDeleteEarningRule.mockReset()
  mockDeleteEarningRule.mockResolvedValue(undefined)
  mockActivateEarningRule.mockReset()
  mockActivateEarningRule.mockResolvedValue(earningRuleFixture('rule-1'))
  mockDeactivateEarningRule.mockReset()
  mockDeactivateEarningRule.mockResolvedValue(earningRuleFixture('rule-1'))
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('earning rule queryKey shape', () => {
  it('wraps the program earning-rules key with tenant/company (.338)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()

    const { result } = renderHook(() => useEarningRules('program-1'), {
      wrapper: makeWrapper(client),
    })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    expect(keys.find((k) => k[0] === 'loyalty-earning-rules')).toEqual([
      'loyalty-earning-rules',
      'program-1',
      'tenant-A',
      'company-1',
    ])
  })

  it('does not fetch without tenant/company scope', () => {
    const client = createTestQueryClient()
    const { result } = renderHook(() => useEarningRules('program-1'), {
      wrapper: makeWrapper(client),
    })

    expect(result.current.fetchStatus).toBe('idle')
    expect(mockListEarningRules).not.toHaveBeenCalled()
  })
})

describe('earning rule mutation cascades', () => {
  async function expectListRefetchAfterMutation(
    mutate: (client: QueryClient) => Promise<void>,
  ) {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    mockListEarningRules.mockImplementation(async () => {
      listCalls += 1
      return [earningRuleFixture(`rule-${String(listCalls)}`)]
    })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result } = renderHook(() => useEarningRules('program-1'), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(listCalls).toBe(1)

    await mutate(client)

    await waitFor(() => { expect(listCalls).toBe(2) })
  }

  it('useCreateEarningRule refetches the scoped program list (.339)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useCreateEarningRule('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync(createPayload)
    })
  })

  it('useUpdateEarningRule refetches the scoped program list (.340)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useUpdateEarningRule('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync({ id: 'rule-1', data: { name: 'Updated' } })
    })
  })

  it('useDeleteEarningRule refetches the scoped program list (.341)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useDeleteEarningRule('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync('rule-1')
    })
  })

  it('useActivateEarningRule refetches the scoped program list (.342)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useActivateEarningRule('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync('rule-1')
    })
  })

  it('useDeactivateEarningRule refetches the scoped program list (.343)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useDeactivateEarningRule('program-1'), {
        wrapper: makeWrapper(client),
      })
      await result.current.mutateAsync('rule-1')
    })
  })
})

describe('cross-tenant earning rule isolation', () => {
  it('tenant-A earning-rules data does not contain tenant-B entries (L18)', async () => {
    const client = persistentQueryClient()
    const tenantBKey = ['loyalty-earning-rules', 'program-1', 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, [earningRuleFixture('leaked-tenant-b-rule')])

    setTenant('tenant-A', 'company-1')
    const { result } = renderHook(() => useEarningRules('program-1'), {
      wrapper: makeWrapper(client),
    })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const tenantAKey = ['loyalty-earning-rules', 'program-1', 'tenant-A', 'company-1']
    const tenantAData = client.getQueryData<EarningRule[]>(tenantAKey)
    expect(tenantAData).toEqual([])
    expect(tenantAData?.map((rule) => rule.id)).not.toContain('leaked-tenant-b-rule')
    expect(client.getQueryData(tenantBKey)).toEqual([
      earningRuleFixture('leaked-tenant-b-rule'),
    ])
  })
})

describe('earningRulesKey factory shape', () => {
  it('exposes the expected unscoped key', () => {
    expect(earningRulesKey('program-1')).toEqual(['loyalty-earning-rules', 'program-1'])
  })
})
