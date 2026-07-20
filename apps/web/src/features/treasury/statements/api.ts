import { api } from '@/lib/api'

export type StatementStatus = 'imported' | 'reconciling' | 'reconciled' | 'voided'
export type StatementParserKey = 'csv' | 'xlsx'
export type StatementDirectionConvention = 'signed_amount' | 'debit_credit_columns'
export type StatementDecimalFormat = 'comma' | 'comma_decimal' | 'dot' | 'dot_decimal'

export interface BankStatementSummary {
  id: string
  payment_repository_id: string
  currency: string
  period_start: string
  period_end: string
  opening_balance: string
  closing_balance: string
  status: StatementStatus
  parser_profile_id: string | null
  imported_at: string
  lines_count: number
}

export interface StatementListResponse {
  data: BankStatementSummary[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface StatementProfile {
  id: string
  payment_repository_id: string
  name: string
  is_active: boolean
  parser_key: StatementParserKey
  column_map: Record<string, string | null>
  date_format: string
  decimal_format: StatementDecimalFormat
  direction_convention: StatementDirectionConvention
  header_rows: number
  matching_window_days: number
}

export type StatementProfileInput = Omit<StatementProfile, 'id'>

export interface StatementPreviewLine {
  line_number: number
  value_date: string
  booking_date: string | null
  direction: 'in' | 'out'
  amount: string
  reference: string | null
  bank_transaction_id: string | null
  label: string
  counterparty_hint: string | null
}

export interface StatementPreview {
  preview_token: string
  source_file_sha256: string
  preview_lines: StatementPreviewLine[]
  accepted_line_count: number
  duplicate_fingerprint_count: number
  dropped_zero_amount_rows: number
  unparseable_rows: { row: number; reason: string }[]
  detected_opening: string | null
  detected_closing: string | null
}

export interface StatementPreviewInput {
  repositoryId: string
  profileId: string
  file: File
}

export interface StatementConfirmInput {
  previewToken: string
  repositoryId: string
  currency: string
  periodStart: string
  periodEnd: string
  openingBalance: string
  closingBalance: string
  acknowledgeEmpty: boolean
}

export async function listBankStatements(filters: {
  status?: string
  repositoryId?: string
  page: number
  perPage: number
}): Promise<StatementListResponse> {
  const response = await api.get<StatementListResponse>('/bank-statements', {
    params: {
      status: filters.status || undefined,
      payment_repository_id: filters.repositoryId || undefined,
      page: filters.page,
      per_page: filters.perPage,
    },
  })
  return response.data
}

export async function listStatementProfiles(): Promise<StatementProfile[]> {
  const response = await api.get<{ data: StatementProfile[] }>('/statement-import-profiles')
  return response.data.data
}

export async function createStatementProfile(input: StatementProfileInput): Promise<StatementProfile> {
  const response = await api.post<{ data: StatementProfile }>('/statement-import-profiles', input)
  return response.data.data
}

export async function uploadStatementPreview(input: StatementPreviewInput): Promise<StatementPreview> {
  const form = new FormData()
  form.append('payment_repository_id', input.repositoryId)
  form.append('parser_profile_id', input.profileId)
  form.append('file', input.file)
  const response = await api.post<{ data: StatementPreview }>('/bank-statements/upload', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 120000,
  })
  return response.data.data
}

export async function confirmBankStatement(input: StatementConfirmInput): Promise<{ statementId: string }> {
  const response = await api.post<{ data: BankStatementSummary }>('/bank-statements', {
    preview_token: input.previewToken,
    currency: input.currency,
    period_start: input.periodStart,
    period_end: input.periodEnd,
    opening_balance: input.openingBalance,
    closing_balance: input.closingBalance,
    acknowledge_empty: input.acknowledgeEmpty,
  })
  return { statementId: response.data.data.id }
}
