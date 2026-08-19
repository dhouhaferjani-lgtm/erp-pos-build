import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { useAccount, useAccounts } from '../useAccounts'
import { useAgedPayables } from '../useAgedPayables'
import { useAgedReceivables } from '../useAgedReceivables'
import { useBalanceSheet } from '../useBalanceSheet'
import { useFinanceSummary } from '../useFinanceSummary'
import { useJournalEntry, useJournalEntries as useJournalEntriesRead } from '../useJournalEntries'
import { useCreateJournalEntry, usePostJournalEntry } from '../useJournalEntryMutations'
import { useJournalEntries as useLedgerJournalEntries, useLedger } from '../useLedger'
import { useProfitLoss } from '../useProfitLoss'
import { useTrialBalance } from '../useTrialBalance'

const mockGetAgedPayables = vi.hoisted(() => vi.fn())
const mockGetAgedReceivables = vi.hoisted(() => vi.fn())
const mockGetBalanceSheet = vi.hoisted(() => vi.fn())
const mockGetFinanceSummary = vi.hoisted(() => vi.fn())
const mockGetProfitLoss = vi.hoisted(() => vi.fn())
const mockGetTrialBalance = vi.hoisted(() => vi.fn())
const mockGetAccounts = vi.hoisted(() => vi.fn())
const mockGetAccount = vi.hoisted(() => vi.fn())
const mockCreateAccount = vi.hoisted(() => vi.fn())
const mockUpdateAccount = vi.hoisted(() => vi.fn())
const mockGetJournalEntries = vi.hoisted(() => vi.fn())
const mockGetJournalEntry = vi.hoisted(() => vi.fn())
const mockGetLedger = vi.hoisted(() => vi.fn())
const mockCreateJournalEntry = vi.hoisted(() => vi.fn())
const mockPostJournalEntry = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))
vi.mock('react-router-dom', () => ({ useNavigate: () => mockNavigate }))
vi.mock('../../api', () => ({
  createAccount: mockCreateAccount,
  createJournalEntry: mockCreateJournalEntry,
  getAgedPayables: mockGetAgedPayables,
  getAgedReceivables: mockGetAgedReceivables,
  getAccount: mockGetAccount,
  getAccounts: mockGetAccounts,
  getBalanceSheet: mockGetBalanceSheet,
  getFinanceSummary: mockGetFinanceSummary,
  getJournalEntries: mockGetJournalEntries,
  getJournalEntry: mockGetJournalEntry,
  getLedger: mockGetLedger,
  getProfitLoss: mockGetProfitLoss,
  getTrialBalance: mockGetTrialBalance,
  postJournalEntry: mockPostJournalEntry,
  updateAccount: mockUpdateAccount,
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: { id: 'user-1', name: 'User', email: 'u@example.test', tenant_id: tenantId, roles: [], email_verified_at: null },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockGetAccounts.mockResolvedValue([{ id: 'account-1' }])
  mockGetAccount.mockResolvedValue({ id: 'account-1' })
  mockGetJournalEntries.mockResolvedValue([{ id: 'journal-1' }])
  mockGetJournalEntry.mockResolvedValue({ id: 'journal-1' })
  mockGetLedger.mockResolvedValue([{ id: 'ledger-1' }])
  mockGetAgedPayables.mockResolvedValue({ rows: [{ id: 'payable-1' }] })
  mockGetAgedReceivables.mockResolvedValue({ rows: [{ id: 'receivable-1' }] })
  mockGetBalanceSheet.mockResolvedValue({ assets: [] })
  mockGetFinanceSummary.mockResolvedValue({ revenue: 1 })
  mockGetProfitLoss.mockResolvedValue({ revenue: [] })
  mockGetTrialBalance.mockResolvedValue({ rows: [] })
  mockCreateJournalEntry.mockResolvedValue({ id: 'journal-1' })
  mockPostJournalEntry.mockResolvedValue({ id: 'journal-1' })
  mockCreateAccount.mockResolvedValue({ id: 'account-1' })
  mockUpdateAccount.mockResolvedValue({ id: 'account-1' })
})

afterEach(() => {
  resetTenant()
})

describe('finance hooks tenant scope', () => {
  it('wraps finance read keys and gates missing tenant/company (.269-.270, .277-.278, .282-.283)', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => ({
      account: useAccount('account-1'),
      accounts: useAccounts({ search: 'cash' }),
      journalEntry: useJournalEntry('journal-1'),
      journalEntries: useJournalEntriesRead(2),
      ledger: useLedger({ account_id: 'account-1' }),
      ledgerJournalEntries: useLedgerJournalEntries(3),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.account.isSuccess).toBe(true)
      expect(result.current.accounts.isSuccess).toBe(true)
      expect(result.current.journalEntry.isSuccess).toBe(true)
      expect(result.current.journalEntries.isSuccess).toBe(true)
      expect(result.current.ledger.isSuccess).toBe(true)
      expect(result.current.ledgerJournalEntries.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['accounts', { search: 'cash' }, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['accounts', 'account-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['journal-entries', 2, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['journal-entry', 'journal-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['ledger', { account_id: 'account-1' }, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['journal-entries', 3, 'tenant-A', 'company-1'])).toBeDefined()

    resetTenant()
    const gatedClient = createClient()
    renderHook(() => useAccounts(), { wrapper: wrapper(gatedClient) })
    expect(mockGetAccounts).toHaveBeenCalledTimes(1)
  })

  it('bounds journal mutation invalidation to active tenant (.279-.281)', async () => {
    const queryClient = createClient()
    let listCalls = 0
    let detailCalls = 0
    mockGetJournalEntries.mockImplementation(async () => [{ id: `journal-list-${++listCalls}` }])
    mockGetJournalEntry.mockImplementation(async () => ({ id: `journal-detail-${++detailCalls}` }))
    queryClient.setQueryData(['journal-entries', 1, 'tenant-B', 'company-1'], { marker: 'tenant-B-list' })
    queryClient.setQueryData(['journal-entry', 'journal-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-detail' })

    const { result: reads } = renderHook(() => ({
      detail: useJournalEntry('journal-1'),
      list: useJournalEntriesRead(1),
    }), { wrapper: wrapper(queryClient) })
    await waitFor(() => {
      expect(reads.current.detail.isSuccess).toBe(true)
      expect(reads.current.list.isSuccess).toBe(true)
      expect(listCalls).toBe(1)
      expect(detailCalls).toBe(1)
    })

    const { result: mutations } = renderHook(() => ({
      createEntry: useCreateJournalEntry(),
      postEntry: usePostJournalEntry(),
    }), { wrapper: wrapper(queryClient) })

    await act(async () => {
      await mutations.current.createEntry.mutateAsync({ lines: [] } as never)
    })
    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(detailCalls).toBe(1)
    })

    await act(async () => {
      await mutations.current.postEntry.mutateAsync('journal-1')
    })
    await waitFor(() => {
      expect(listCalls).toBe(3)
      expect(detailCalls).toBe(2)
    })

    expect(queryClient.getQueryData(['journal-entries', 1, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-list' })
    expect(queryClient.getQueryData(['journal-entry', 'journal-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-detail' })
  })

  it('wraps finance report query keys and gates missing tenant/company', async () => {
    const queryClient = createClient()
    const agedFilters = { as_of_date: '2026-05-11' }
    const dateFilters = { date_from: '2026-05-01', date_to: '2026-05-11' }
    const { result } = renderHook(() => ({
      agedPayables: useAgedPayables(agedFilters),
      agedReceivables: useAgedReceivables(agedFilters),
      balanceSheet: useBalanceSheet({ as_of_date: '2026-05-11' }),
      financeSummary: useFinanceSummary(),
      profitLoss: useProfitLoss(dateFilters),
      trialBalance: useTrialBalance({ as_of_date: '2026-05-11' }),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.agedPayables.isSuccess).toBe(true)
      expect(result.current.agedReceivables.isSuccess).toBe(true)
      expect(result.current.balanceSheet.isSuccess).toBe(true)
      expect(result.current.financeSummary.isSuccess).toBe(true)
      expect(result.current.profitLoss.isSuccess).toBe(true)
      expect(result.current.trialBalance.isSuccess).toBe(true)
    })

    // Promoted L3 locationScopedKey lane: aged reports key the effective location filter and scope.
    expect(queryClient.getQueryData(['aged-payables', { ...agedFilters, location_ids: [] }, { locScope: 'all' }, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['aged-receivables', { ...agedFilters, location_ids: [] }, { locScope: 'all' }, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['balance-sheet', { as_of_date: '2026-05-11' }, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['finance-summary', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['profit-loss', dateFilters, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['trial-balance', { as_of_date: '2026-05-11' }, 'tenant-A', 'company-1'])).toBeDefined()

    resetTenant()
    renderHook(() => useFinanceSummary(), { wrapper: wrapper(createClient()) })
    expect(mockGetFinanceSummary).toHaveBeenCalledTimes(1)
  })
})
