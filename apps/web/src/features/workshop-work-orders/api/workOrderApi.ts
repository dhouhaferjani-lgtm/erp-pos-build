import { api, apiDelete, apiGet, apiPatch, apiPost, apiPut } from '@/lib/api'
import type {
  ApproveInput,
  CreateWorkOrderInput,
  PaginatedWorkOrders,
  TransitionInput,
  UpdateWorkOrderInput,
  WorkOrder,
  WorkOrderAssignment,
  WorkOrderLine,
  WorkOrderListFilters,
} from '../types'

const BASE = '/workshop/work-orders'

/**
 * Workshop / WorkOrder API client.
 *
 * Paginated `list` uses `api.get` directly (not `apiGet`) so the pagination
 * `meta` envelope is preserved — per CLAUDE.md memory note on paginated
 * endpoints (see `project_monetary_precision.md` bucket for the pattern).
 *
 * All other endpoints use `apiGet`/`apiPost`/`apiPatch`/`apiPut`/`apiDelete`
 * which auto-unwrap `response.data.data`.
 */
export const workOrderApi = {
  async list(filters: WorkOrderListFilters = {}): Promise<PaginatedWorkOrders> {
    const params: Record<string, string> = {}
    if (filters.status) params['status'] = filters.status
    if (filters.vehicle_id) params['vehicle_id'] = filters.vehicle_id
    if (filters.customer_partner_id) params['customer_partner_id'] = filters.customer_partner_id
    if (filters.primary_technician_profile_id) {
      params['primary_technician_profile_id'] = filters.primary_technician_profile_id
    }
    if (filters.page) params['page'] = String(filters.page)
    if (filters.per_page) params['per_page'] = String(filters.per_page)
    const response = await api.get<PaginatedWorkOrders>(BASE, { params })
    return response.data
  },

  get(id: string): Promise<WorkOrder> {
    return apiGet<WorkOrder>(`${BASE}/${id}`)
  },

  create(input: CreateWorkOrderInput): Promise<WorkOrder> {
    return apiPost<WorkOrder>(BASE, input)
  },

  update(id: string, input: UpdateWorkOrderInput): Promise<WorkOrder> {
    return apiPatch<WorkOrder>(`${BASE}/${id}`, input)
  },

  addLine(
    id: string,
    input: {
      line_type: string
      product_id?: string | null
      service_id?: string | null
      display_name: string
      sku_or_code?: string | null
      description?: string | null
      quantity: string
      unit: string
      unit_price: string
      tax_rate: string
      discount_percent: string
      labor_hours_estimated?: string | null
      assigned_technician_profile_id?: string | null
      is_customer_supplied?: boolean
    }
  ): Promise<WorkOrderLine> {
    return apiPost<WorkOrderLine>(`${BASE}/${id}/lines`, input)
  },

  addBundle(
    id: string,
    input: { bundle_id: string; quantity: string; vehicle_id?: string | null }
  ): Promise<WorkOrderLine[]> {
    return apiPost<WorkOrderLine[]>(`${BASE}/${id}/lines/bundle`, input)
  },

  updateLine(
    id: string,
    lineId: string,
    input: Partial<{
      display_name: string
      description: string
      quantity: string
      unit_price: string
      tax_rate: string
      discount_percent: string
      labor_hours_actual: string
      assigned_technician_profile_id: string | null
      is_completed: boolean
    }>
  ): Promise<WorkOrderLine> {
    return apiPatch<WorkOrderLine>(`${BASE}/${id}/lines/${lineId}`, input)
  },

  async removeLine(id: string, lineId: string): Promise<void> {
    await apiDelete<undefined>(`${BASE}/${id}/lines/${lineId}`)
  },

  async reorderLines(id: string, orderedLineIds: string[]): Promise<void> {
    await apiPut<undefined>(`${BASE}/${id}/lines/reorder`, { ordered_line_ids: orderedLineIds })
  },

  assignTechnician(
    id: string,
    input: { technician_profile_id: string; is_lead?: boolean; notes?: string | null }
  ): Promise<WorkOrderAssignment> {
    return apiPost<WorkOrderAssignment>(`${BASE}/${id}/assignments`, input)
  },

  async unassignTechnician(id: string, assignmentId: string): Promise<void> {
    await apiDelete<undefined>(`${BASE}/${id}/assignments/${assignmentId}`)
  },

  setPrimaryTechnician(id: string, technicianProfileId: string): Promise<WorkOrderAssignment> {
    return apiPut<WorkOrderAssignment>(`${BASE}/${id}/primary-technician`, {
      technician_profile_id: technicianProfileId,
    })
  },

  transition(id: string, input: TransitionInput): Promise<WorkOrder> {
    return apiPost<WorkOrder>(`${BASE}/${id}/transition`, input)
  },

  approve(id: string, input: ApproveInput): Promise<WorkOrder> {
    return apiPost<WorkOrder>(`${BASE}/${id}/approval`, input)
  },

  cancel(
    id: string,
    input: { reason_code: string; note?: string | null; expected_updated_at?: string | null }
  ): Promise<WorkOrder> {
    return apiPost<WorkOrder>(`${BASE}/${id}/cancel`, input)
  },

  complete(
    id: string,
    input: { completion_mileage?: number | null; expected_updated_at?: string | null } = {}
  ): Promise<WorkOrder> {
    return apiPost<WorkOrder>(`${BASE}/${id}/complete`, input)
  },
}
