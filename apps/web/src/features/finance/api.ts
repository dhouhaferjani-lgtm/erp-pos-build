import { apiGet, apiPost, apiPatch } from '@/lib/api'
import type {
  Account,
  CreateAccountData,
  UpdateAccountData,
  AccountFilters,
  JournalEntry,
  LedgerLine,
  LedgerFilters,
  TrialBalanceFilters,
  TrialBalanceData,
  ProfitLossData,
  ProfitLossFilters,
  BalanceSheetData,
  BalanceSheetFilters,
  AgedReceivablesData,
  AgedReceivablesFilters,
  AgedPayablesData,
  AgedPayablesFilters,
} from './types'

export async function getAccounts(filters?: AccountFilters): Promise<Account[]> {
  const params = new URLSearchParams()

  if (filters?.type) {
    params.append('type', filters.type)
  }

  if (filters?.active !== undefined) {
    params.append('active', filters.active ? '1' : '0')
  }

  if (filters?.search) {
    params.append('search', filters.search)
  }

  const queryString = params.toString()
  const url = queryString ? `/accounts?${queryString}` : '/accounts'

  return apiGet<Account[]>(url)
}

export async function getAccount(id: string): Promise<Account> {
  return apiGet<Account>(`/accounts/${id}`)
}

export async function createAccount(data: CreateAccountData): Promise<Account> {
  return apiPost<Account>('/accounts', data)
}

export async function updateAccount(
  id: string,
  data: UpdateAccountData
): Promise<Account> {
  return apiPatch<Account>(`/accounts/${id}`, data)
}

export async function getJournalEntries(page = 1): Promise<{
  data: JournalEntry[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}> {
  return apiGet(`/journal-entries?page=${String(page)}`)
}

export async function getJournalEntry(id: string): Promise<JournalEntry> {
  return apiGet<JournalEntry>(`/journal-entries/${id}`)
}

export interface CreateJournalLineData {
  account_id: string
  debit: string
  credit: string
  description?: string
}

export interface CreateJournalEntryData {
  entry_date: string
  description?: string
  lines: CreateJournalLineData[]
}

export async function createJournalEntry(data: CreateJournalEntryData): Promise<JournalEntry> {
  return apiPost<JournalEntry>('/journal-entries', data)
}

export async function postJournalEntry(id: string): Promise<JournalEntry> {
  return apiPost<JournalEntry>(`/journal-entries/${id}/post`, undefined)
}

export interface LedgerData {
  lines: LedgerLine[]
  opening_balance: string
  closing_balance: string
  total_debits: string
  total_credits: string
  date_from: string | null
  date_to: string
  account_filter: string | null
  partner_filter: string | null
}

export async function getLedger(filters?: LedgerFilters): Promise<LedgerData> {
  const params = new URLSearchParams()

  if (filters?.account_id) {
    params.append('account_id', filters.account_id)
  }

  if (filters?.date_from) {
    params.append('date_from', filters.date_from)
  }

  if (filters?.date_to) {
    params.append('date_to', filters.date_to)
  }

  if (filters?.min_amount !== undefined) {
    params.append('min_amount', filters.min_amount.toString())
  }

  if (filters?.max_amount !== undefined) {
    params.append('max_amount', filters.max_amount.toString())
  }

  const queryString = params.toString()
  const url = queryString ? `/ledger?${queryString}` : '/ledger'

  return apiGet<LedgerData>(url)
}

export async function getTrialBalance(
  filters?: TrialBalanceFilters
): Promise<TrialBalanceData> {
  const params = new URLSearchParams()

  if (filters?.as_of_date) {
    params.append('as_of_date', filters.as_of_date)
  }
  const queryString = params.toString()
  const url = queryString ? `/reports/trial-balance?${queryString}` : '/reports/trial-balance'

  return apiGet<TrialBalanceData>(url)
}

export async function getProfitLoss(
  filters?: ProfitLossFilters
): Promise<ProfitLossData> {
  const params = new URLSearchParams()

  if (filters?.date_from) {
    params.append('date_from', filters.date_from)
  }

  if (filters?.date_to) {
    params.append('date_to', filters.date_to)
  }

  const queryString = params.toString()
  const url = queryString ? `/reports/profit-loss?${queryString}` : '/reports/profit-loss'

  return apiGet<ProfitLossData>(url)
}

export async function getBalanceSheet(
  filters?: BalanceSheetFilters
): Promise<BalanceSheetData> {
  const params = new URLSearchParams()

  if (filters?.as_of_date) {
    params.append('as_of_date', filters.as_of_date)
  }
  const queryString = params.toString()
  const url = queryString ? `/reports/balance-sheet?${queryString}` : '/reports/balance-sheet'

  return apiGet<BalanceSheetData>(url)
}

export async function getAgedReceivables(
  filters?: AgedReceivablesFilters
): Promise<AgedReceivablesData> {
  const params = new URLSearchParams()

  if (filters?.as_of_date) {
    params.append('as_of_date', filters.as_of_date)
  }
  params.append('group_by', 'location')
  filters?.location_ids?.forEach((id) => params.append('location_ids[]', id))

  const queryString = params.toString()
  const url = queryString ? `/reports/aged-receivables?${queryString}` : '/reports/aged-receivables'

  return apiGet<AgedReceivablesData>(url)
}

export async function getAgedPayables(
  filters?: AgedPayablesFilters
): Promise<AgedPayablesData> {
  const params = new URLSearchParams()

  if (filters?.as_of_date) {
    params.append('as_of_date', filters.as_of_date)
  }
  params.append('group_by', 'location')
  filters?.location_ids?.forEach((id) => params.append('location_ids[]', id))

  const queryString = params.toString()
  const url = queryString ? `/reports/aged-payables?${queryString}` : '/reports/aged-payables'

  return apiGet<AgedPayablesData>(url)
}

export async function getFinanceSummary(): Promise<App.Modules.Accounting.Application.DTOs.Reports.FinanceSummaryData> {
  return apiGet<App.Modules.Accounting.Application.DTOs.Reports.FinanceSummaryData>(
    '/reports/finance-summary'
  )
}

export async function getUpcomingPayments(
  days: number,
  locationIds: string[] = [],
): Promise<App.Modules.Accounting.Application.DTOs.Reports.UpcomingPaymentsData> {
  return apiGet<App.Modules.Accounting.Application.DTOs.Reports.UpcomingPaymentsData>(
    `/reports/upcoming-payments?days=${String(days)}&group_by=location${locationIds.map((id) => `&location_ids[]=${encodeURIComponent(id)}`).join('')}`
  )
}
