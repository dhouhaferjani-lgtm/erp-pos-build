import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  MEMBERS_KEY,
  membersInvalidationPredicate,
  useAdjustPoints,
  useCreateMember,
  useEnrollMember,
  useEnrollments,
  useMember,
  useMembers,
  useOptOutEnrollment,
  useReactivateEnrollment,
  useTransactions,
  useUpdateMember,
} from '../useMembers'
import type { LoyaltyMember, MemberListParams, MemberListResponse } from '../../types/loyalty'

const mockListMembers = vi.hoisted(() => vi.fn())
const mockGetMember = vi.hoisted(() => vi.fn())
const mockCreateMember = vi.hoisted(() => vi.fn())
const mockUpdateMember = vi.hoisted(() => vi.fn())
const mockListEnrollments = vi.hoisted(() => vi.fn())
const mockEnrollMember = vi.hoisted(() => vi.fn())
const mockOptOutEnrollment = vi.hoisted(() => vi.fn())
const mockReactivateEnrollment = vi.hoisted(() => vi.fn())
const mockListTransactions = vi.hoisted(() => vi.fn())
const mockAdjustPoints = vi.hoisted(() => vi.fn())

vi.mock('../../api/memberApi', () => ({
  listMembers: mockListMembers,
  getMember: mockGetMember,
  createMember: mockCreateMember,
  updateMember: mockUpdateMember,
  listEnrollments: mockListEnrollments,
  enrollMember: mockEnrollMember,
  optOutEnrollment: mockOptOutEnrollment,
  reactivateEnrollment: mockReactivateEnrollment,
  listTransactions: mockListTransactions,
  adjustPoints: mockAdjustPoints,
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

function memberFixture(id: string): LoyaltyMember {
  return {
    id,
    customer_id: null,
    phone: '+33612345678',
    email: null,
    first_name: id,
    last_name: null,
    date_of_birth: null,
    status: 'active',
    enrollment_date: '2026-01-01',
    external_id: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
  }
}

function memberListResponse(data: LoyaltyMember[] = []): MemberListResponse {
  return {
    data,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: data.length,
      from: data.length > 0 ? 1 : null,
      to: data.length > 0 ? data.length : null,
    },
  }
}

const params: MemberListParams = { page: 1, per_page: 20 }

beforeEach(() => {
  mockListMembers.mockReset()
  mockListMembers.mockResolvedValue(memberListResponse())
  mockGetMember.mockReset()
  mockGetMember.mockResolvedValue(memberFixture('member-1'))
  mockCreateMember.mockReset()
  mockCreateMember.mockResolvedValue(memberFixture('member-new'))
  mockUpdateMember.mockReset()
  mockUpdateMember.mockResolvedValue(memberFixture('member-1'))
  mockListEnrollments.mockReset()
  mockListEnrollments.mockResolvedValue([])
  mockEnrollMember.mockReset()
  mockEnrollMember.mockResolvedValue({ id: 'enrollment-1' })
  mockOptOutEnrollment.mockReset()
  mockOptOutEnrollment.mockResolvedValue(undefined)
  mockReactivateEnrollment.mockReset()
  mockReactivateEnrollment.mockResolvedValue(undefined)
  mockListTransactions.mockReset()
  mockListTransactions.mockResolvedValue(memberListResponse())
  mockAdjustPoints.mockReset()
  mockAdjustPoints.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('membersInvalidationPredicate', () => {
  it('matches loyalty-members keys for the active tenant/company', () => {
    const pred = membersInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['loyalty-members', params, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['loyalty-members', 'member-1', 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['loyalty-members', 'member-1', 'enrollments', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects wrong tenant/company and sibling namespaces', () => {
    const pred = membersInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['loyalty-members', params, 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['loyalty-members', params, 'tenant-A', 'company-2'] })).toBe(false)
    expect(pred({ queryKey: ['loyalty-programs', 'tenant-A', 'company-1'] })).toBe(false)
  })
})

describe('loyalty member queryKey shapes', () => {
  it('wraps list, detail, enrollment, and transaction keys (.344, .345, .348, .352)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)

    const { result: list } = renderHook(() => useMembers(params), { wrapper })
    const { result: detail } = renderHook(() => useMember('member-1'), { wrapper })
    const { result: enrollments } = renderHook(() => useEnrollments('member-1'), { wrapper })
    const { result: transactions } = renderHook(
      () => useTransactions('member-1', 'enrollment-1', 2),
      { wrapper },
    )

    await waitFor(() => {
      expect(list.current.isSuccess).toBe(true)
      expect(detail.current.isSuccess).toBe(true)
      expect(enrollments.current.isSuccess).toBe(true)
      expect(transactions.current.isSuccess).toBe(true)
    })

    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    expect(keys.find((k) => k[0] === 'loyalty-members' && typeof k[1] === 'object')).toEqual([
      'loyalty-members',
      params,
      'tenant-A',
      'company-1',
    ])
    expect(keys.find((k) => k[0] === 'loyalty-members' && k[1] === 'member-1' && k.length === 4)).toEqual([
      'loyalty-members',
      'member-1',
      'tenant-A',
      'company-1',
    ])
    expect(keys.find((k) => k[0] === 'loyalty-members' && k[2] === 'enrollments' && k.length === 5)).toEqual([
      'loyalty-members',
      'member-1',
      'enrollments',
      'tenant-A',
      'company-1',
    ])
    expect(keys.find((k) => k[0] === 'loyalty-members' && k[4] === 'transactions')).toEqual([
      'loyalty-members',
      'member-1',
      'enrollments',
      'enrollment-1',
      'transactions',
      2,
      'tenant-A',
      'company-1',
    ])
  })
})

describe('loyalty member mutation cascades', () => {
  async function expectListRefetchAfterMutation(
    mutate: (client: QueryClient) => Promise<void>,
  ) {
    setTenant('tenant-A', 'company-1')
    let listCalls = 0
    mockListMembers.mockImplementation(async () => {
      listCalls += 1
      return memberListResponse([memberFixture(`member-${String(listCalls)}`)])
    })

    const client = createTestQueryClient()
    const wrapper = makeWrapper(client)
    const { result } = renderHook(() => useMembers(params), { wrapper })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })
    expect(listCalls).toBe(1)

    await mutate(client)

    await waitFor(() => { expect(listCalls).toBe(2) })
  }

  it('useCreateMember refetches active tenant member queries (.346)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useCreateMember(), { wrapper: makeWrapper(client) })
      await result.current.mutateAsync({ phone: '+33612345678' })
    })
  })

  it('useUpdateMember refetches active tenant member queries (.347)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useUpdateMember(), { wrapper: makeWrapper(client) })
      await result.current.mutateAsync({ id: 'member-1', data: { first_name: 'Updated' } })
    })
  })

  it('enrollment mutations refetch active tenant member queries (.349-.351)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const wrapper = makeWrapper(client)
      const { result: enroll } = renderHook(() => useEnrollMember(), { wrapper })
      await enroll.current.mutateAsync({ memberId: 'member-1', programId: 'program-1' })
    })
    await expectListRefetchAfterMutation(async (client) => {
      const wrapper = makeWrapper(client)
      const { result: optOut } = renderHook(() => useOptOutEnrollment(), { wrapper })
      await optOut.current.mutateAsync({ memberId: 'member-1', enrollmentId: 'enrollment-1' })
    })
    await expectListRefetchAfterMutation(async (client) => {
      const wrapper = makeWrapper(client)
      const { result: reactivate } = renderHook(() => useReactivateEnrollment(), { wrapper })
      await reactivate.current.mutateAsync({ memberId: 'member-1', enrollmentId: 'enrollment-1' })
    })
  })

  it('useAdjustPoints refetches active tenant member queries (.353)', async () => {
    await expectListRefetchAfterMutation(async (client) => {
      const { result } = renderHook(() => useAdjustPoints(), { wrapper: makeWrapper(client) })
      await result.current.mutateAsync({
        memberId: 'member-1',
        enrollmentId: 'enrollment-1',
        data: { points: '10', reason: 'manual' },
      })
    })
  })
})

describe('cross-tenant loyalty member isolation', () => {
  it('tenant-A members data does not contain tenant-B entries (L18)', async () => {
    const client = persistentQueryClient()
    const tenantBKey = ['loyalty-members', params, 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, memberListResponse([memberFixture('leaked-tenant-b-member')]))

    setTenant('tenant-A', 'company-1')
    const { result } = renderHook(() => useMembers(params), { wrapper: makeWrapper(client) })
    await waitFor(() => { expect(result.current.isSuccess).toBe(true) })

    const tenantAKey = ['loyalty-members', params, 'tenant-A', 'company-1']
    const tenantAData = client.getQueryData<MemberListResponse>(tenantAKey)
    expect(tenantAData?.data).toEqual([])
    expect(tenantAData?.data.map((member) => member.id)).not.toContain(
      'leaked-tenant-b-member',
    )
    expect(client.getQueryData(tenantBKey)).toEqual(
      memberListResponse([memberFixture('leaked-tenant-b-member')]),
    )
  })
})

describe('MEMBERS_KEY factory shape', () => {
  it('exposes the expected unscoped root key', () => {
    expect(MEMBERS_KEY).toEqual(['loyalty-members'])
  })
})
