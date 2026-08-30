// Import Types - matches backend API

import type { OffsetPaginationMeta } from '@/types/pagination'

export type ImportType =
  | 'parties'
  | 'partners'
  | 'products'
  // Retired by owner ruling D4 — no longer selectable, and the API refuses it
  // with a 422. It stays in the union (as the backend enum keeps its case)
  // because `ImportJob.type` is read back from history, where jobs created
  // before the deprecation still carry this value.
  | 'stock_levels'
  | 'opening_balances'
  | 'product_images'
  | 'composite_items'

/**
 * Import types that can no longer be started. Historical jobs of these types
 * still render in the import history — only NEW imports are blocked.
 */
export const DEPRECATED_IMPORT_TYPES = ['stock_levels'] as const satisfies readonly ImportType[]

export type DeprecatedImportType = (typeof DEPRECATED_IMPORT_TYPES)[number]

/**
 * The import types a user can still start.
 *
 * Use this — not `ImportType` — as the key of any per-type config map the
 * wizard needs, so TypeScript keeps enforcing exhaustiveness for every LIVE
 * type. A `Partial<Record<ImportType, …>>` would silently accept a future type
 * with no entry.
 */
export type LiveImportType = Exclude<ImportType, DeprecatedImportType>

/** Type predicate, so callers narrow to `LiveImportType` in the else branch. */
export function isDeprecatedImportType(type: ImportType): type is DeprecatedImportType {
  return (DEPRECATED_IMPORT_TYPES as readonly ImportType[]).includes(type)
}

export type ImportStatus =
  | 'pending'
  | 'validating'
  | 'validated'
  | 'importing'
  | 'completed'
  | 'failed'

export interface ImportJob {
  id: string
  type: ImportType
  status: ImportStatus
  original_filename: string
  total_rows: number
  processed_rows: number
  successful_rows: number
  skipped_rows: number
  failed_rows: number
  warning_rows: number
  warning_summary: Record<string, number> | null
  progress_percentage: number
  options?: ImportJobOptions | null
  error_message: string | null
  started_at: string | null
  completed_at: string | null
  created_at: string
}

export interface ImportJobOptions {
  location_code?: string
  enrichment_enabled?: boolean
  price_authority?: 'ttc' | 'ht' | 'margin'
  placement_mode?: 'strict' | 'auto_create'
  placement_node_types?: LocationNodeType[]
  duplicate_policy?: DuplicatePolicy
}

export type DuplicateBucket = 'new' | 'existing_sku' | 'existing_barcode' | 'existing_name' | 'in_file'
export type DuplicatePolicy = 'override' | 'skip'

export type LocationNodeType = App.Modules.Inventory.Domain.Enums.LocationNodeType

// Backend returns errors as { fieldName: ['error1', 'error2'] }
export type ImportRowErrors = Record<string, string[]> | null

export interface ImportRow {
  row_number: number
  data: Record<string, string>
  is_valid: boolean
  errors: ImportRowErrors
  import_error?: string | null
  error_type?: 'validation' | 'execution'
}

// Migration Wizard Types - matches backend exactly
export interface ImportTypeMetadata {
  type: ImportType
  label: string
  description: string
}

export interface MigrationWizardOrder {
  data: ImportTypeMetadata[]
}

export interface DependencyCheck {
  can_import: boolean
  missing_dependencies: string[]
  warnings: string[]
}

export interface ColumnMappingSuggestions {
  suggestions: Record<string, string | null>
  unmapped_source: string[]
  unmapped_target: string[]
}

export interface MigrationStatus {
  partners: { count: number; has_data: boolean }
  products: { count: number; has_data: boolean }
  stock_levels: { count: number; has_data: boolean }
  composite_items: { count: number; has_data: boolean }
  accounts: { count: number; has_data: boolean }
}

// API Response types
export interface ImportJobResponse {
  data: ImportJob
}

export interface ImportJobListResponse {
  data: ImportJob[]
  meta: OffsetPaginationMeta
}

export interface ImportErrorsResponse {
  data: ImportRow[]
  meta?: Partial<OffsetPaginationMeta> & {
    job_error_message: string | null
    validation_errors: number
    execution_errors: number
  }
}

export interface ImportErrorSummary {
  total_errors: number
  validation_errors: number
  execution_errors: number
  has_errors: boolean
  job_error_message: string | null
}

export interface ImportErrorSummaryResponse {
  data: ImportErrorSummary
}

export interface CreateImportResponse {
  data: ImportJob
  errors?: {
    missing_columns: string[]
    unknown_columns: string[]
  }
}

export interface ImportResult {
  imported_count: number
  skipped_count: number
  execution_error_count: number
  preview_drift_count: number
  total_rows: number
  failed_rows_csv_url: string | null
}

export interface ImportPreviewRow {
  row_number: number
  data: Record<string, string>
  is_valid: boolean
  errors: ImportRowErrors
  duplicate_bucket?: DuplicateBucket | null
}

export interface ImportPreview {
  headers: string[]
  rows: ImportPreviewRow[]
  summary: {
    total_rows: number
    valid_rows: number
    invalid_rows: number
  }
  duplicates?: {
    counts: Record<DuplicateBucket, number>
    matched_by_name: number[]
  }
  placement?: {
    max_depth: number
    nodes_to_create: { path: string; node_type: LocationNodeType }[]
    placements_to_set: { row_number: number; location_code: string; path: string; node_id: string | null }[]
  }
}

export interface ImportPreviewResponse {
  data: ImportPreview
}
