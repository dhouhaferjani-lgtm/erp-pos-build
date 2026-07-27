/**
 * Workshop / WorkOrder frontend types — TEMPORARY.
 *
 * These mirror the backend DTOs in
 * `apps/api/app/Modules/Workshop/WorkOrder/Application/DTOs/`. Once the shared
 * type-generation pipeline ships (see
 * `docs/sessions/2026-04-19-types-pipeline-coordination.md`), this file is
 * deleted and consumers import from `@autoerp/shared/types/*`.
 *
 * Until then, keep shapes in sync with the PHP `#[TypeScript]` DTOs by hand.
 */

import type { OffsetPaginationMeta } from '@/types/pagination'

export type WorkOrderStatus =
  | 'received'
  | 'diagnosed'
  | 'quoted'
  | 'approved'
  | 'in_progress'
  | 'paused'
  | 'waiting_parts'
  | 'completed'
  | 'invoiced'
  | 'closed'
  | 'cancelled'

export type WorkOrderType =
  | 'repair'
  | 'maintenance'
  | 'inspection'
  | 'bodywork'
  | 'tire_service'
  | 'electrical'
  | 'diagnostic'
  | 'other'

export type WorkOrderLineType =
  | 'part'
  | 'labor'
  | 'core_charge'
  | 'core_return'
  | 'sublet'
  | 'environmental_fee'
  | 'misc_fee'
  | 'bundle_header'

export type ApprovalMethod = 'in_person' | 'phone' | 'email' | 'sms' | 'signed_document'

export type CancellationReason =
  | 'customer_declined'
  | 'customer_no_show'
  | 'internal_error'
  | 'duplicate'
  | 'vehicle_unfit'
  | 'other'

export type CoreDepositStatus = 'outstanding' | 'returned' | 'expired' | 'credited'

export interface WorkOrderTotals {
  parts_total: string
  labor_total: string
  other_total: string
  tax_total: string
  grand_total: string
}

export interface WorkOrderLine {
  id: string
  work_order_id: string
  line_type: WorkOrderLineType
  display_order: number
  product_id: string | null
  service_id: string | null
  service_bundle_id: string | null
  display_name: string
  sku_or_code: string | null
  description: string | null
  quantity: string
  unit: string
  unit_price: string | null
  tax_rate: string | null
  discount_percent: string | null
  line_total_excl_tax: string | null
  line_total_tax: string | null
  line_total_incl_tax: string | null
  labor_hours_estimated: string | null
  labor_hours_actual: string | null
  assigned_technician_profile_id: string | null
  stock_reservation_id: string | null
  is_customer_supplied: boolean
  core_deposit_partner_id: string | null
  core_deposit_status: CoreDepositStatus | null
  core_return_of_line_id: string | null
  from_bundle_id: string | null
  is_bundle_informational: boolean
  is_completed: boolean
  completed_at: string | null
}

export interface WorkOrderAssignment {
  id: string
  work_order_id: string
  technician_profile_id: string
  technician_display_name: string | null
  is_lead: boolean
  assigned_at: string
  unassigned_at: string | null
  notes: string | null
}

export interface WorkOrderStatusTransition {
  id: string
  work_order_id: string
  from_status: WorkOrderStatus | null
  to_status: WorkOrderStatus
  reason_code: string | null
  triggered_by_user_id: string | null
  triggered_at: string
  context: Record<string, unknown> | null
}

export interface WorkOrder {
  id: string
  tenant_id: string
  company_id: string
  location_id: string | null
  work_order_number: string
  status: WorkOrderStatus
  type: WorkOrderType
  customer_partner_id: string
  customer_display_name: string
  vehicle_id: string
  vehicle_display_name: string
  opened_by_user_id: string
  primary_technician_profile_id: string | null
  primary_technician_display_name: string | null
  mileage_at_intake: number | null
  customer_complaint: string | null
  diagnosis: string | null
  internal_notes: string | null
  scheduled_start_at: string | null
  scheduled_end_at: string | null
  promised_at: string | null
  started_at: string | null
  paused_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  cancellation_reason: CancellationReason | null
  approval_captured_at: string | null
  approval_method: ApprovalMethod | null
  approval_reference: string | null
  currency: string
  estimated_totals: WorkOrderTotals | null
  actual_totals: WorkOrderTotals | null
  quote_document_id: string | null
  invoice_document_id: string | null
  lines: WorkOrderLine[]
  assignments: WorkOrderAssignment[]
  status_history: WorkOrderStatusTransition[]
  created_at: string
  updated_at: string | null
}

export interface WorkOrderListItem {
  id: string
  work_order_number: string
  status: WorkOrderStatus
  type: WorkOrderType
  customer_display_name: string
  vehicle_display_name: string
  primary_technician_display_name: string | null
  scheduled_start_at: string | null
  promised_at: string | null
  currency: string
  estimated_grand_total: string | null
  actual_grand_total: string | null
  created_at: string
}

export interface WorkOrderListFilters {
  status?: WorkOrderStatus
  vehicle_id?: string
  customer_partner_id?: string
  primary_technician_profile_id?: string
  page?: number
  per_page?: number
}

export interface PaginatedWorkOrders {
  data: WorkOrderListItem[]
  meta: OffsetPaginationMeta
}

export interface CreateWorkOrderInput {
  type: WorkOrderType
  customer_partner_id: string
  vehicle_id: string
  currency: string
  location_id?: string | null
  primary_technician_profile_id?: string | null
  mileage_at_intake?: number | null
  customer_complaint?: string | null
  internal_notes?: string | null
  scheduled_start_at?: string | null
  scheduled_end_at?: string | null
  promised_at?: string | null
}

export interface UpdateWorkOrderInput {
  primary_technician_profile_id?: string | null
  diagnosis?: string | null
  internal_notes?: string | null
  scheduled_start_at?: string | null
  scheduled_end_at?: string | null
  promised_at?: string | null
}

export interface TransitionInput {
  to_status: WorkOrderStatus
  reason_code?: string | null
  context?: Record<string, unknown> | null
  expected_updated_at?: string | null
}

export interface ApproveInput {
  approval_method: ApprovalMethod
  approval_reference?: string | null
  approval_captured_at?: string | null
  expected_updated_at?: string | null
}
