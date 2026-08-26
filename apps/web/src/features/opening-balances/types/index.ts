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

/**
 * A BATCH-level validation refusal — one that belongs to the sheet as a whole
 * rather than to a row (an unbalanced batch, or cash debited to an account whose
 * tills the sheet never names).
 *
 * Structured on purpose (treasury gate r2 G-1): `message` is the server's
 * English fallback, kept for logs and non-wizard consumers, and the wizard
 * renders `code` + `params` through `t()` so the operator reads their own
 * language. Never render `message` in the UI.
 */
export interface BatchLevelError {
  code: string
  params: Record<string, string>
  message: string
}

/**
 * `errors._batch` carries {@link BatchLevelError}s; every other key is a row id
 * carrying that row's per-field messages.
 */
export type ValidationErrors = Record<string, BatchLevelError[] | Record<string, string[]>>

/**
 * The `_batch` entries of a validation result, or `[]`.
 *
 * A narrowing helper rather than a cast: the server may add batch-level codes
 * this build has never heard of, and a malformed entry must not crash the
 * wizard on the day-one path.
 */
export function batchLevelErrors(errors: ValidationErrors | undefined): BatchLevelError[] {
  // Deliberately read as `unknown`: the server may add batch-level codes this
  // build has never heard of, and a malformed entry must not crash the wizard
  // on the day-one path. The declared type is the contract, not a guarantee.
  const batch: unknown = errors?.['_batch']
  if (!Array.isArray(batch)) return []

  return batch.filter(isBatchLevelError)
}

function isBatchLevelError(entry: unknown): entry is BatchLevelError {
  return typeof entry === 'object' && entry !== null && 'code' in entry && typeof entry.code === 'string'
}

export interface ValidationResult {
  valid: boolean
  total_rows: number
  valid_rows: number
  invalid_rows: number
  errors: ValidationErrors
  total_value?: string
  total_debit?: string
  total_credit?: string
  total_amount?: string
  total_open_amount?: string
}

/**
 * Preview payloads for `GET /companies/{companyId}/opening-batches/{batchId}/preview`.
 *
 * N-3 (campaign report 2026-08-23 §N-3): the three variants are STRUCTURALLY
 * DIFFERENT — ACCOUNTING carries an `entry` header, INVENTORY and AR/AP carry a
 * `batch` header — and the previous single optional-everything interface claimed
 * a `batch` key on all three, so `preview.batch.cutover_date` crashed the wizard
 * on ACCOUNTING batches. These interfaces mirror the API EXACTLY (nothing
 * optional that the API always sends, nothing declared that it never sends) and
 * narrow on the server-sent `batch_type` discriminator.
 *
 * The exact key set of each variant is pinned server-side by
 * `apps/api/tests/Feature/Accounting/OpeningBalancePreviewContractTest.php`.
 */
export interface AccountingPreviewLine {
  row_number: number
  account_code: string
  account_name: string
  debit: string
  credit: string
  description: string
  /**
   * W4-2: null on an ordinary GL line; set when this row also seeds a treasury
   * repository's day-one cash float, so the operator sees WHICH till the money
   * lands in before locking the batch. Pinned server-side by
   * `apps/api/tests/Feature/Accounting/OpeningBalancePreviewContractTest.php`.
   */
  repository_code: string | null
  repository_name: string | null
}

export interface InventoryPreviewLine {
  row_number: number
  product_sku: string
  product_name: string
  location_code: string
  location_name: string
  quantity: string
  quantity_decimals: number
  unit_cost: string
  line_value: string
  /**
   * W4-1 — the expiry the sheet supplied for this opening lot, or null when it
   * supplied none. Null is shown as "No expiry", never as a date: an invented
   * expiry on opening stock is what made FEFO ship the whole opening catalogue
   * first on the launch tenant.
   */
  expiry_date: string | null
}

export interface ArApPreviewDocument {
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
}

export interface AccountingPostPreview {
  batch_type: 'ACCOUNTING'
  entry: {
    entry_date: string
    description: string
    is_historical: boolean
    source_type: string
  }
  lines: AccountingPreviewLine[]
  totals: {
    debit: string
    credit: string
    is_balanced: boolean
  }
}

export interface InventoryPostPreview {
  batch_type: 'INVENTORY'
  batch: {
    cutover_date: string
    description: string
    is_historical: boolean
    source_type: string
  }
  lines: InventoryPreviewLine[]
  totals: {
    total_lines: number
    total_quantity: string
    total_value: string
  }
  gl_entry: {
    debit_account: string
    credit_account: string
    amount: string
  }
}

export interface ArApPostPreview {
  batch_type: 'AR_OPEN_ITEMS' | 'AP_OPEN_ITEMS'
  batch: {
    cutover_date: string
    description: string
    is_historical: boolean
    batch_type: 'AR_OPEN_ITEMS' | 'AP_OPEN_ITEMS'
  }
  documents: ArApPreviewDocument[]
  totals: {
    total_documents: number
    total_amount: string
    total_open_amount: string
  }
  note: string
}

export type PostPreview = AccountingPostPreview | InventoryPostPreview | ArApPostPreview

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
export const GL_COLUMNS = ['account_code', 'debit', 'credit', 'reference', 'repository_code'] as const

/**
 * The headers an ACCOUNTING CSV MUST carry. `repository_code` (W4-2) is
 * deliberately absent: it is optional, and every sheet written before it existed
 * has exactly these four columns. Treating GL_COLUMNS as the required set made
 * the upload refuse "missing columns: repository_code" and bricked the day-one
 * wizard for every legacy file (treasury gate r1 F-1).
 */
export const GL_REQUIRED_COLUMNS = ['account_code', 'debit', 'credit', 'reference'] as const
export const INVENTORY_COLUMNS = [
  'product_code',
  'location_code',
  'quantity',
  'unit_cost',
  'expiry_date',
] as const

/**
 * The headers an INVENTORY CSV MUST carry. `expiry_date` (W4-1) is deliberately
 * absent for the same reason `repository_code` is absent from the GL required
 * set: it is optional, and every sheet written before it existed has exactly
 * these four columns. Leaving it blank means "expiry not supplied" — the opening
 * lot then takes the product's configured shelf life, or is minted undated and
 * ranked LAST by FEFO. It is never invented.
 */
export const INVENTORY_REQUIRED_COLUMNS = [
  'product_code',
  'location_code',
  'quantity',
  'unit_cost',
] as const
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
