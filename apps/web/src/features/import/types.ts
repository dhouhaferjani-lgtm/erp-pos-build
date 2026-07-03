// Import Types - matches backend API

export type ImportType =
  | 'parties'
  | 'partners'
  | 'products'
  | 'stock_levels'
  | 'opening_balances'
  | 'product_images'
  | 'composite_items'

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
  failed_rows: number
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
}

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
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
  }
}

export interface ImportErrorsResponse {
  data: ImportRow[]
  meta?: {
    current_page?: number
    last_page?: number
    per_page?: number
    total?: number
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
  total_rows: number
  failed_rows_csv_url: string | null
}

export interface ImportPreviewRow {
  row_number: number
  data: Record<string, string>
  is_valid: boolean
  errors: ImportRowErrors
}

export interface ImportPreview {
  headers: string[]
  rows: ImportPreviewRow[]
  summary: {
    total_rows: number
    valid_rows: number
    invalid_rows: number
  }
}

export interface ImportPreviewResponse {
  data: ImportPreview
}
