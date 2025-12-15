/**
 * Opening Balance Batch Types
 */

export type OpeningBatchType = 'ACCOUNTING' | 'INVENTORY' | 'AR_OPEN_ITEMS' | 'AP_OPEN_ITEMS'

export type OpeningBatchStatus = 'DRAFT' | 'VALIDATED' | 'LOCKED'

export type OpeningImportRowStatus = 'PENDING' | 'VALID' | 'INVALID' | 'SKIPPED' | 'POSTED'

export interface OpeningBalanceBatch {
  id: string
  tenant_id: string
  company_id: string
  type: OpeningBatchType
  name: string
  cutover_date: string
  status: OpeningBatchStatus
  source_system: string | null
  import_file_reference: {
    original_name?: string
    stored_path?: string
    mime_type?: string
    size?: number
  } | null
  hash: string | null
  previous_hash: string | null
  validated_at: string | null
  validated_by: string | null
  locked_at: string | null
  locked_by: string | null
  created_at: string
  updated_at: string
  created_by: string
  rows_count?: number
  valid_rows_count?: number
  invalid_rows_count?: number
}

export interface OpeningBalanceImportRow {
  id: string
  batch_id: string
  row_number: number
  row_type: string
  raw_data: Record<string, unknown>
  mapped_data: Record<string, unknown> | null
  status: OpeningImportRowStatus
  validation_errors: Record<string, string[]> | null
  mapped_entity_id: string | null
  created_at: string
  updated_at: string
}

export interface OpeningBatchTypeInfo {
  type: OpeningBatchType
  label: string
  description: string
  icon: string
}

export interface OpeningBatchStatusInfo {
  type: OpeningBatchType
  label: string
  has_batch: boolean
  batch: OpeningBalanceBatch | null
  is_locked: boolean
  is_ready: boolean
}

export interface OpeningBatchStatusResponse {
  types: Record<OpeningBatchType, OpeningBatchStatusInfo>
  all_ready: boolean
  inventory_ready: boolean
}

export interface ValidationResult {
  valid: boolean
  total_rows: number
  valid_rows: number
  invalid_rows: number
  errors: Record<string, Record<string, string[]>>
  total_value?: string
  total_debit?: string
  total_credit?: string
  total_amount?: string
  total_open_amount?: string
}

export interface PostPreview {
  batch: {
    cutover_date: string
    description: string
    is_historical: boolean
    source_type?: string
    batch_type?: string
  }
  lines?: Array<{
    row_number: number
    account_code?: string
    account_name?: string
    product_sku?: string
    product_name?: string
    partner_code?: string
    partner_name?: string
    location_code?: string
    location_name?: string
    debit?: string
    credit?: string
    quantity?: string
    unit_cost?: string
    line_value?: string
    total?: string
    open_amount?: string
  }>
  documents?: Array<{
    row_number: number
    partner_code: string
    partner_name: string
    external_invoice_number: string
    document_type: string
    document_date: string
    due_date: string
    currency: string
    total: string
    open_amount: string
  }>
  totals: {
    total_lines?: number
    total_documents?: number
    total_quantity?: string
    total_value?: string
    total_debit?: string
    total_credit?: string
    total_amount?: string
    total_open_amount?: string
  }
  gl_entry?: {
    debit_account: string
    credit_account: string
    amount: string
  }
  obe_offset?: {
    account_code: string
    account_name: string
    debit: string
    credit: string
  }
  note?: string
}

export interface PostResult {
  success: boolean
  journal_entry?: {
    id: string
    entry_number: string
    entry_date: string
    description: string
    total_debit: string
    total_credit: string
    lines_count: number
    is_historical: boolean
  }
  documents_created?: number
  total_amount?: string
  total_open_amount?: string
  batch_type?: string
}

export interface CreateBatchPayload {
  type: OpeningBatchType
  name: string
  cutover_date: string
  source_system?: string
}

export interface ImportRowPayload {
  rows: Array<Record<string, unknown>>
}

// CSV Column definitions for each batch type
export const GL_COLUMNS = ['account_code', 'debit', 'credit', 'reference'] as const
export const INVENTORY_COLUMNS = ['product_code', 'location_code', 'quantity', 'unit_cost'] as const
export const AR_AP_COLUMNS = [
  'partner_code',
  'external_invoice_number',
  'document_date',
  'due_date',
  'total',
  'open_amount',
  'document_type',
  'currency',
  'notes',
] as const
