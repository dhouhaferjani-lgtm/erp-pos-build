export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'

export interface Account {
  id: string
  tenant_id: string
  parent_id: string | null
  code: string
  name: string
  type: AccountType
  description: string | null
  is_active: boolean
  is_system: boolean
  balance: string
  created_at: string
  updated_at: string
}

export interface CreateAccountData {
  code: string
  name: string
  type: AccountType
  description?: string | undefined
  parent_id?: string | undefined
  is_active?: boolean
}

export interface UpdateAccountData {
  name?: string
  description?: string | null
  is_active?: boolean
}

export interface AccountFilters {
  type?: AccountType
  active?: boolean
  search?: string
}

export type JournalEntryStatus = 'draft' | 'posted'

export interface JournalLine {
  id: string
  journal_entry_id: string
  account_id: string
  account_code: string
  account_name: string
  debit: string
  credit: string
  description: string | null
  line_number: number
}

export interface JournalEntry {
  id: string
  tenant_id: string
  entry_number: string
  entry_date: string
  description: string | null
  status: JournalEntryStatus
  source_type: string | null
  source_id: string | null
  lines: JournalLine[]
  created_at: string
  updated_at: string
}

export interface LedgerLine {
  id: string
  date: string
  entry_number: string
  description: string
  account_code: string
  account_name: string
  debit: string
  credit: string
  balance: string
  source_type: string | null
  source_id: string | null
}

export interface LedgerFilters {
  account_id?: string | undefined
  date_from?: string | undefined
  date_to?: string | undefined
  min_amount?: number | undefined
  max_amount?: number | undefined
}

export interface TrialBalanceLine {
  account_code: string
  account_name: string
  account_type: AccountType
  debit: string
  credit: string
  level: number
  is_parent: boolean
}

export interface TrialBalanceData {
  lines: TrialBalanceLine[]
  total_debit: string
  total_credit: string
  is_balanced: boolean
  as_of_date: string
}

export interface TrialBalanceFilters {
  as_of_date?: string | undefined
}

export interface ProfitLossLine {
  account_code: string
  account_name: string
  amount: string
}

export interface ProfitLossData {
  revenue: ProfitLossLine[]
  expenses: ProfitLossLine[]
  total_revenue: string
  total_expenses: string
  net_income: string
}

export interface ProfitLossFilters {
  date_from?: string | undefined
  date_to?: string | undefined
}

export interface BalanceSheetLine {
  account_code: string
  account_name: string
  amount: string
}

export interface BalanceSheetData {
  assets: BalanceSheetLine[]
  liabilities: BalanceSheetLine[]
  equity: BalanceSheetLine[]
  total_assets: string
  total_liabilities: string
  total_equity: string
}

export interface BalanceSheetFilters {
  as_of_date?: string | undefined
}

export interface AgedReceivablesLine {
  customer_id: string
  customer_name: string
  current: string
  days_30: string
  days_60: string
  days_90: string
  over_90: string
  total: string
}

export interface AgedReceivablesData {
  lines: AgedReceivablesLine[]
  total_current: string
  total_days_30: string
  total_days_60: string
  total_days_90: string
  total_over_90: string
  grand_total: string
  as_of_date: string
}

export interface AgedReceivablesFilters {
  as_of_date?: string | undefined
}

export interface AgedPayablesLine {
  vendor_id: string
  vendor_name: string
  current: string
  days_30: string
  days_60: string
  days_90: string
  over_90: string
  total: string
}

export interface AgedPayablesData {
  lines: AgedPayablesLine[]
  total_current: string
  total_days_30: string
  total_days_60: string
  total_days_90: string
  total_over_90: string
  grand_total: string
  as_of_date: string
}

export interface AgedPayablesFilters {
  as_of_date?: string | undefined
}

export interface FinanceSummary {
  total_assets: string
  total_liabilities: string
  total_equity: string
  net_income_mtd: string
  net_income_ytd: string
  accounts_receivable: string
  accounts_payable: string
}
