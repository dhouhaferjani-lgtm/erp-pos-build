import { api } from '@/lib/api'
import type { OffsetPaginationMeta } from '@/types/pagination'

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

export type StatementLineStatus = 'unmatched' | 'partial' | 'matched' | 'resolved_by_creation' | 'ignored'
export type StatementActionType = 'outbound_clear' | 'inbound_clear' | 'expense_settle' | 'acquirer_fee' | 'create_expense' | 'create_income'
export type StatementIgnoreReason = 'duplicate' | 'informational' | 'bank_error' | 'out_of_scope' | 'other'
export type MovementSourceType = 'payment' | 'expense' | 'income' | 'refund' | 'fiscal_event' | 'transfer' | 'adjustment' | 'opening_balance' | 'instrument'
export type StatementSuggestionReasonCode = 'reference_amount_match' | 'unique_amount_window' | 'bounced_instrument' | 'pending_instrument' | 'unsettled_expense' | 'card_batch_fee'

export interface StatementLineAllocation {
  repository_movement_id: string
  matched_amount: string
  match_type: 'manual' | 'suggestion_confirmed' | 'created_from_line'
  movement_direction: 'in' | 'out'
}

export interface StatementExecution {
  action_type: StatementActionType
  target_type: string | null
  target_id: string | null
  produced_repository_movement_ids: string[]
}

export interface BankStatementLine {
  id: string
  line_number: number
  value_date: string
  booking_date: string | null
  direction: 'in' | 'out'
  amount: string
  reference: string | null
  label: string
  match_status: StatementLineStatus
  ignore_reason: StatementIgnoreReason | null
  ignore_text: string | null
  location_id: string | null
  allocations: StatementLineAllocation[]
  executions: StatementExecution[]
}

export interface BankStatementDetail extends BankStatementSummary {
  lines: BankStatementLine[]
}

export interface StatementSuggestion {
  tier: number
  kind: string
  movement_ids: string[]
  action_type: StatementActionType | null
  target_type: string | null
  target_id: string | null
  amount: string
  reason: string
  reason_code: StatementSuggestionReasonCode | null
  reason_params: Record<string, string | number>
  reference_matched: boolean
  action_params: Record<string, unknown>
}

export interface RepositoryMovementCandidate {
  id: string
  direction: 'in' | 'out'
  amount: string
  allocated_amount: string
  remaining_allocatable_amount: string
  currency: string
  balance_after: string
  ordinal: number
  source_type: MovementSourceType
  source_id: string
  journal_entry_id: string | null
  reason_code: string | null
  occurred_at: string
}

export interface StatementLineMutationResult {
  id: string
  match_status: StatementLineStatus
  ignore_reason: StatementIgnoreReason | null
  ignore_text: string | null
  allocations: StatementLineAllocation[]
  executions: StatementExecution[]
}

export interface StatementTargetProvenance {
  bank_statement_id: string
  bank_statement_line_id: string
  action_type: StatementActionType
  executed_at: string
}

export interface StatementListResponse {
  data: BankStatementSummary[]
  meta: OffsetPaginationMeta
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

export async function getBankStatement(id: string): Promise<BankStatementDetail> {
  const response = await api.get<{ data: BankStatementDetail }>(`/bank-statements/${id}`)
  return response.data.data
}

export async function getStatementSuggestions(lineId: string): Promise<StatementSuggestion[]> {
  const response = await api.get<{ data: StatementSuggestion[] }>(`/bank-statement-lines/${lineId}/suggestions`)
  return response.data.data
}

export async function searchRepositoryMovements(repositoryId: string, search: string): Promise<RepositoryMovementCandidate[]> {
  const response = await api.get<{ data: RepositoryMovementCandidate[] }>(`/payment-repositories/${repositoryId}/movements`, {
    params: { search: search || undefined },
  })
  return response.data.data
}

export async function allocateStatementLine(lineId: string, allocations: { repository_movement_id: string; amount: string }[]): Promise<StatementLineMutationResult> {
  const response = await api.post<{ data: StatementLineMutationResult }>(`/bank-statement-lines/${lineId}/allocations`, { allocations })
  return response.data.data
}

export async function unallocateStatementLine(lineId: string, movementId: string): Promise<StatementLineMutationResult> {
  const response = await api.delete<{ data: StatementLineMutationResult }>(`/bank-statement-lines/${lineId}/allocations/${movementId}`)
  return response.data.data
}

export async function executeStatementAction(lineId: string, input: { action: StatementActionType; params: Record<string, unknown> }): Promise<StatementLineMutationResult> {
  const response = await api.post<{ data: StatementLineMutationResult }>(`/bank-statement-lines/${lineId}/actions`, input)
  return response.data.data
}

export async function ignoreStatementLine(lineId: string, input: { reason: StatementIgnoreReason; text: string }): Promise<StatementLineMutationResult> {
  const response = await api.post<{ data: StatementLineMutationResult }>(`/bank-statement-lines/${lineId}/ignore`, input)
  return response.data.data
}

export async function unignoreStatementLine(lineId: string): Promise<StatementLineMutationResult> {
  const response = await api.delete<{ data: StatementLineMutationResult }>(`/bank-statement-lines/${lineId}/ignore`)
  return response.data.data
}

export async function completeBankStatement(id: string, acknowledgeIgnoredTotal: boolean): Promise<BankStatementSummary> {
  const response = await api.post<{ data: BankStatementSummary }>(`/bank-statements/${id}/complete`, {
    acknowledge_ignored_total: acknowledgeIgnoredTotal,
  })
  return response.data.data
}

export async function reopenBankStatement(id: string): Promise<BankStatementSummary> {
  const response = await api.post<{ data: BankStatementSummary }>(`/bank-statements/${id}/reopen`)
  return response.data.data
}

export async function getStatementTargetProvenance(targetType: 'payment_instrument' | 'expense_document' | 'income_document', targetId: string): Promise<StatementTargetProvenance[]> {
  const response = await api.get<{ data: StatementTargetProvenance[] }>(`/bank-statement-targets/${targetType}/${targetId}/lines`)
  return response.data.data
}
