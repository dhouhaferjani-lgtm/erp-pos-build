import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type {
  BankReconciliation,
  BankReconciliationItem,
  PaymentRepository,
  ReconciliationSummary,
} from '@/types/treasury'

import {
  useCancelReconciliation,
  useCompleteReconciliation,
  useMatchItem,
  usePaymentRepositories,
  useReconciliation,
  useReconciliations,
  useReconciliationSummary,
  useStartReconciliation,
  useUnmatchItem,
} from '../useReconciliation'

const mockListReconciliations = vi.hoisted(() => vi.fn())
const mockGetReconciliation = vi.hoisted(() => vi.fn())
const mockGetReconciliationSummary = vi.hoisted(() => vi.fn())
const mockStartReconciliation = vi.hoisted(() => vi.fn())
const mockMatchItem = vi.hoisted(() => vi.fn())
const mockUnmatchItem = vi.hoisted(() => vi.fn())
const mockCompleteReconciliation = vi.hoisted(() => vi.fn())
const mockCancelReconciliation = vi.hoisted(() => vi.fn())
const mockListRepositories = vi.hoisted(() => vi.fn())

vi.mock('sonner', () => ({
  toast: {
    error: vi.fn(),
    success: vi.fn(),
  },
}))

vi.mock('../../api/reconciliation', () => ({
  cancelReconciliation: mockCancelReconciliation,
  completeReconciliation: mockCompleteReconciliation,
  getReconciliation: mockGetReconciliation,
  getReconciliationSummary: mockGetReconciliationSummary,
  listReconciliations: mockListReconciliations,
  listRepositories: mockListRepositories,
  matchItem: mockMatchItem,
  startReconciliation: mockStartReconciliation,
  unmatchItem: mockUnmatchItem,
}))

const reconciliationFilters = { repository_id: 'repository-1', status: 'draft' }

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

function reconciliationFixture(id: string): BankReconciliation {
  return {
    id,
    repository_id: 'repository-1',
    repository_name: 'Main bank',
    statement_date: '2026-05-11',
    opening_balance: '1000.000',
    closing_balance: '1200.000',
    statement_balance: '1200.000',
    difference: '0.000',
    status: 'draft',
    created_by: 'user-1',
    completed_by: null,
    completed_at: null,
    notes: null,
    created_at: '2026-05-11T09:00:00Z',
    items: [reconciliationItemFixture('item-1')],
  }
}

function reconciliationItemFixture(id: string): BankReconciliationItem {
  return {
    id,
    payment_id: 'payment-1',
    payment_date: '2026-05-11',
    payment_reference: 'PAY-1',
    payment_amount: '100.000',
    partner_name: 'Partner',
    is_matched: false,
    bank_reference: null,
    notes: null,
    matched_at: null,
  }
}

function summaryFixture(id: string): ReconciliationSummary {
  return {
    reconciliation_id: id,
    repository_name: 'Main bank',
    statement_date: '2026-05-11',
    opening_balance: '1000.000',
    closing_balance: '1200.000',
    statement_balance: '1200.000',
    difference: '0.000',
    status: 'draft',
    matched_count: 0,
    unmatched_count: 1,
    matched_total: '0.000',
    unmatched_total: '100.000',
    can_complete: false,
  }
}

function repositoryFixture(id: string): PaymentRepository {
  return {
    id,
    name: 'Main bank',
    type: 'bank',
    balance: '1200.000',
    currency: 'TND',
    last_reconciled_at: null,
    last_reconciled_balance: null,
  }
}

function usePaymentsProbe(queryFn: () => Promise<unknown[]>) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['payments']),
    queryFn,
    enabled: tenantId !== null && companyId !== null,
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockListReconciliations.mockResolvedValue([reconciliationFixture('reconciliation-1')])
  mockGetReconciliation.mockResolvedValue(reconciliationFixture('reconciliation-1'))
  mockGetReconciliationSummary.mockResolvedValue(summaryFixture('reconciliation-1'))
  mockStartReconciliation.mockResolvedValue(reconciliationFixture('reconciliation-new'))
  mockMatchItem.mockResolvedValue(reconciliationItemFixture('item-1'))
  mockUnmatchItem.mockResolvedValue(reconciliationItemFixture('item-1'))
  mockCompleteReconciliation.mockResolvedValue(reconciliationFixture('reconciliation-1'))
  mockCancelReconciliation.mockResolvedValue(reconciliationFixture('reconciliation-1'))
  mockListRepositories.mockResolvedValue([repositoryFixture('repository-1')])
})

afterEach(() => {
  resetTenant()
})

describe('treasury reconciliation hooks tenant scope', () => {
  it('wraps reconciliation read query keys with the active tenant and company (.718-.721)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => ({
      reconciliation: useReconciliation('reconciliation-1'),
      reconciliations: useReconciliations(reconciliationFilters),
      repositories: usePaymentRepositories(),
      summary: useReconciliationSummary('reconciliation-1'),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.reconciliation.isSuccess).toBe(true)
      expect(result.current.reconciliations.isSuccess).toBe(true)
      expect(result.current.repositories.isSuccess).toBe(true)
      expect(result.current.summary.isSuccess).toBe(true)
    })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['reconciliation', 'reconciliation-1', 'tenant-A', 'company-1'],
      ['reconciliations', reconciliationFilters, 'tenant-A', 'company-1'],
      ['payment-repositories', 'tenant-A', 'company-1'],
      ['reconciliation-summary', 'reconciliation-1', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch reconciliation reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      reconciliation: useReconciliation('reconciliation-1'),
      reconciliations: useReconciliations(reconciliationFilters),
      repositories: usePaymentRepositories(),
      summary: useReconciliationSummary('reconciliation-1'),
    }), { wrapper })

    expect(mockGetReconciliation).not.toHaveBeenCalled()
    expect(mockGetReconciliationSummary).not.toHaveBeenCalled()
    expect(mockListReconciliations).not.toHaveBeenCalled()
    expect(mockListRepositories).not.toHaveBeenCalled()
  })

  it('bounds reconciliation mutation invalidation to the active tenant cache (.722-.734)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let reconciliationListCalls = 0
    let reconciliationDetailCalls = 0
    let summaryCalls = 0
    let repositoryCalls = 0
    let paymentCalls = 0

    mockListReconciliations.mockImplementation(async () => {
      reconciliationListCalls += 1
      return [reconciliationFixture(`reconciliation-list-${reconciliationListCalls}`)]
    })
    mockGetReconciliation.mockImplementation(async () => {
      reconciliationDetailCalls += 1
      return reconciliationFixture(`reconciliation-detail-${reconciliationDetailCalls}`)
    })
    mockGetReconciliationSummary.mockImplementation(async () => {
      summaryCalls += 1
      return summaryFixture(`reconciliation-summary-${summaryCalls}`)
    })
    mockListRepositories.mockImplementation(async () => {
      repositoryCalls += 1
      return [repositoryFixture(`repository-${repositoryCalls}`)]
    })
    const paymentsQueryFn = vi.fn(async () => {
      paymentCalls += 1
      return [{ id: `payment-${paymentCalls}` }]
    })

    queryClient.setQueryData(
      ['reconciliations', reconciliationFilters, 'tenant-B', 'company-1'],
      { marker: 'tenant-B-reconciliations-preserved' },
    )
    queryClient.setQueryData(
      ['reconciliation', 'reconciliation-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-reconciliation-preserved' },
    )
    queryClient.setQueryData(
      ['reconciliation-summary', 'reconciliation-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-summary-preserved' },
    )
    queryClient.setQueryData(
      ['payment-repositories', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-repositories-preserved' },
    )
    queryClient.setQueryData(
      ['payments', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-payments-preserved' },
    )

    const { result: reads } = renderHook(() => ({
      payments: usePaymentsProbe(paymentsQueryFn),
      reconciliation: useReconciliation('reconciliation-1'),
      reconciliations: useReconciliations(reconciliationFilters),
      repositories: usePaymentRepositories(),
      summary: useReconciliationSummary('reconciliation-1'),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.payments.isSuccess).toBe(true)
      expect(reads.current.reconciliation.isSuccess).toBe(true)
      expect(reads.current.reconciliations.isSuccess).toBe(true)
      expect(reads.current.repositories.isSuccess).toBe(true)
      expect(reads.current.summary.isSuccess).toBe(true)
      expect(reconciliationListCalls).toBe(1)
      expect(reconciliationDetailCalls).toBe(1)
      expect(summaryCalls).toBe(1)
      expect(repositoryCalls).toBe(1)
      expect(paymentCalls).toBe(1)
    })

    const { result: mutations } = renderHook(() => ({
      cancel: useCancelReconciliation(),
      complete: useCompleteReconciliation(),
      matchItem: useMatchItem(),
      start: useStartReconciliation(),
      unmatchItem: useUnmatchItem(),
    }), { wrapper })

    await act(async () => {
      await mutations.current.start.mutateAsync({
        repository_id: 'repository-1',
        statement_date: '2026-05-11',
        statement_balance: '1200.000',
      })
    })
    await waitFor(() => {
      expect(reconciliationListCalls).toBe(2)
      expect(reconciliationDetailCalls).toBe(1)
      expect(summaryCalls).toBe(1)
      expect(repositoryCalls).toBe(1)
      expect(paymentCalls).toBe(1)
    })

    await act(async () => {
      await mutations.current.matchItem.mutateAsync({
        reconciliationId: 'reconciliation-1',
        paymentId: 'payment-1',
        request: { bank_reference: 'BANK-1' },
      })
    })
    await waitFor(() => {
      expect(reconciliationListCalls).toBe(2)
      expect(reconciliationDetailCalls).toBe(2)
      expect(summaryCalls).toBe(2)
    })

    await act(async () => {
      await mutations.current.unmatchItem.mutateAsync({
        reconciliationId: 'reconciliation-1',
        paymentId: 'payment-1',
      })
    })
    await waitFor(() => {
      expect(reconciliationListCalls).toBe(2)
      expect(reconciliationDetailCalls).toBe(3)
      expect(summaryCalls).toBe(3)
    })

    await act(async () => {
      await mutations.current.complete.mutateAsync('reconciliation-1')
    })
    await waitFor(() => {
      expect(reconciliationListCalls).toBe(3)
      expect(reconciliationDetailCalls).toBe(4)
      expect(summaryCalls).toBe(4)
      expect(repositoryCalls).toBe(2)
      expect(paymentCalls).toBe(2)
    })

    await act(async () => {
      await mutations.current.cancel.mutateAsync('reconciliation-1')
    })
    await waitFor(() => {
      expect(reconciliationListCalls).toBe(4)
      expect(reconciliationDetailCalls).toBe(5)
      expect(summaryCalls).toBe(5)
      expect(repositoryCalls).toBe(2)
      expect(paymentCalls).toBe(2)
    })

    expect(queryClient.getQueryData([
      'reconciliations',
      reconciliationFilters,
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-reconciliations-preserved' })
    expect(queryClient.getQueryData([
      'reconciliation',
      'reconciliation-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-reconciliation-preserved' })
    expect(queryClient.getQueryData([
      'reconciliation-summary',
      'reconciliation-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-summary-preserved' })
    expect(queryClient.getQueryData([
      'payment-repositories',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-repositories-preserved' })
    expect(queryClient.getQueryData([
      'payments',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-payments-preserved' })
  })
})
