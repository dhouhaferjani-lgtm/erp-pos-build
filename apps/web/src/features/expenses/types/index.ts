type DocumentStatus =
  | 'draft'
  | 'confirmed'
  | 'posted'
  | 'cancelled'

type DocumentType =
  | 'quote'
  | 'sales_order'
  | 'purchase_order'
  | 'invoice'
  | 'credit_note'
  | 'delivery_note'
  | 'return_note'
  | 'expense'
  | 'supplier_invoice'
  | 'supplier_credit_note'

export type ExpenseKind = 'generic' | 'linked_cost'
export type AdditionalCostType =
  | 'transport'
  | 'shipping'
  | 'insurance'
  | 'customs'
  | 'handling'
  | 'other'
export type LandedCostSplitMethod = 'by_value' | 'by_quantity'

/**
 * Expense metadata with payment and category information
 */
export interface ExpenseMetadata {
  vendor_name: string | null
  receipt_number: string | null
  payment_date: string | null
  is_paid: boolean
  expense_category_id: string | null
  payment_method_id: string | null
  payment_repository_id: string | null
  expense_kind: ExpenseKind
  linked_invoice_id?: string | null
  linked_operation_id?: string | null
  cost_type?: AdditionalCostType | null
  split_method?: LandedCostSplitMethod | null
  category?: {
    id: string
    name: string
    parent_id: string | null
  } | null
  payment_method?: {
    id: string
    name: string
    code: string
  } | null
  payment_repository?: {
    id: string
    name: string
    type: string
  } | null
}

/**
 * Expense document
 */
export interface Expense {
  id: string
  type: DocumentType
  status: DocumentStatus
  document_number: string
  document_date: string
  total: string
  currency: string
  notes: string | null
  internal_notes: string | null
  created_at: string
  updated_at: string
  metadata: ExpenseMetadata | null
  company?: {
    id: string
    name: string
  }
}

/**
 * Expense category with hierarchy support
 */
export interface ExpenseCategory {
  id: string
  name: string
  description: string | null
  parent_id: string | null
  account_id: string | null
  sort_order: number
  is_active: boolean
  created_at: string
  updated_at: string
  parent?: {
    id: string
    name: string
  } | null
  children?: Array<{
    id: string
    name: string
    is_active: boolean
  }>
  account?: {
    id: string
    code: string
    name: string
  } | null
  company?: {
    id: string
    name: string
  }
}

/**
 * DTO for creating/updating an expense
 */
export interface CreateExpenseDTO {
  vendor_name?: string
  expense_category_id?: string
  payment_method_id?: string
  payment_repository_id?: string
  payment_date?: string
  receipt_number?: string
  total: string
  notes?: string
  internal_notes?: string
  is_paid?: boolean
  status?: DocumentStatus
  document_date?: string
  idempotency_key?: string
  expense_kind?: ExpenseKind
  linked_invoice_id?: string
  linked_operation_id?: string
  cost_type?: AdditionalCostType
  split_method?: LandedCostSplitMethod
}

export interface LinkableInvoice {
  id: string
  document_number: string
  partner_name: string | null
  document_date: string | null
  total: string
  currency: string
  side: 'purchase' | 'sales'
}

export interface OperationRef {
  document_id: string
  kind: string
  number: string
  date: string | null
  status: string
  received_at: string | null
  line_count: number
  total: string
  currency: string
}

export interface OperationResolution {
  side: 'purchase' | 'sales'
  invoice: {
    id: string
    document_number: string
    currency: string
  }
  operations: OperationRef[]
  auto_selected_id: string | null
}

/**
 * DTO for creating/updating an expense category
 */
export interface CreateExpenseCategoryDTO {
  name: string
  description?: string
  parent_id?: string
  account_id?: string
  sort_order?: number
  is_active?: boolean
}

/**
 * API response wrapper
 */
export interface ExpenseResponse {
  data: Expense
  message?: string
}

/**
 * API list response wrapper
 */
export interface ExpenseListResponse {
  data: Expense[]
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

/**
 * API category response wrapper
 */
export interface ExpenseCategoryResponse {
  data: ExpenseCategory
  message?: string
}

/**
 * API category list response wrapper
 */
export interface ExpenseCategoryListResponse {
  data: ExpenseCategory[]
}

/**
 * Expense filter parameters
 */
export interface ExpenseFilters {
  status?: DocumentStatus
  category_id?: string
  date_from?: string
  date_to?: string
  search?: string
  per_page?: number
}

/**
 * Expense category filter parameters
 */
export interface ExpenseCategoryFilters {
  is_active?: boolean
  roots_only?: boolean
  parent_id?: string
  search?: string
}
