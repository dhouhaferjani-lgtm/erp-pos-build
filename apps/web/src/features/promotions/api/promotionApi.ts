import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type { OffsetPaginationMeta } from '@/types/pagination'

export interface PromotionData {
  id: string
  name: string
  description: string | null
  type: string
  status: string
  priority: number
  is_exclusive: boolean
  stacking_group: string
  starts_at: string | null
  ends_at: string | null
  days_of_week: number[] | null
  time_from: string | null
  time_until: string | null
  conditions: Record<string, unknown>
  discount_type: string
  discount_value: string
  max_discount_amount: string | null
  applies_to: string
  usage_limit: number | null
  usage_count: number
  metadata: Record<string, unknown> | null
  created_at: string
  updated_at: string | null
}

export interface PromotionListResponse {
  data: PromotionData[]
  meta: OffsetPaginationMeta
}

export interface PromotionListParams {
  page?: number
  per_page?: number
  search?: string
  status?: string
  type?: string
}

export interface CreatePromotionData {
  name: string
  description?: string
  type: string
  priority?: number
  is_exclusive?: boolean
  stacking_group?: string
  starts_at?: string | null
  ends_at?: string | null
  days_of_week?: number[] | null
  time_from?: string | null
  time_until?: string | null
  conditions?: Record<string, unknown>
  discount_type: string
  discount_value: string
  max_discount_amount?: string | null
  applies_to: string
  usage_limit?: number | null
  metadata?: Record<string, unknown> | null
}

export type UpdatePromotionData = Partial<CreatePromotionData>

export async function listPromotions(params: PromotionListParams = {}): Promise<PromotionListResponse> {
  const searchParams = new URLSearchParams()
  if (params.page) searchParams.set('page', String(params.page))
  if (params.per_page) searchParams.set('per_page', String(params.per_page))
  if (params.search) searchParams.set('search', params.search)
  if (params.status) searchParams.set('status', params.status)
  if (params.type) searchParams.set('type', params.type)

  const query = searchParams.toString()
  return apiGet<PromotionListResponse>(`/promotions${query ? `?${query}` : ''}`)
}

export async function getPromotion(id: string): Promise<PromotionData> {
  return apiGet<PromotionData>(`/promotions/${id}`)
}

export async function createPromotion(data: CreatePromotionData): Promise<PromotionData> {
  return apiPost<PromotionData>('/promotions', data)
}

export async function updatePromotion(id: string, data: UpdatePromotionData): Promise<PromotionData> {
  return apiPatch<PromotionData>(`/promotions/${id}`, data)
}

export async function deletePromotion(id: string): Promise<void> {
  return apiDelete(`/promotions/${id}`)
}

export async function activatePromotion(id: string): Promise<PromotionData> {
  return apiPost<PromotionData>(`/promotions/${id}/activate`)
}

export async function pausePromotion(id: string): Promise<PromotionData> {
  return apiPost<PromotionData>(`/promotions/${id}/pause`)
}

export async function archivePromotion(id: string): Promise<PromotionData> {
  return apiPost<PromotionData>(`/promotions/${id}/archive`)
}
