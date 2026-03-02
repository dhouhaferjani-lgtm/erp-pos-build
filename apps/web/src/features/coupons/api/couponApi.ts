import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'

export interface CouponData {
  id: string
  name: string
  code: string
  type: string
  status: string
  is_single_use: boolean
  max_uses: number | null
  use_count: number
  max_uses_per_customer: number | null
  discount_type: string
  discount_value: string
  max_discount_amount: string | null
  minimum_order_amount: string | null
  qualifying_product_ids: string[] | null
  qualifying_category_ids: string[] | null
  is_exclusive: boolean
  stacking_group: string
  starts_at: string | null
  expires_at: string | null
  created_at: string
  updated_at: string | null
}

export interface CouponListResponse {
  data: CouponData[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface CouponListParams {
  page?: number
  per_page?: number
  search?: string
  status?: string
}

export interface CreateCouponData {
  name: string
  code: string
  type: string
  is_single_use?: boolean
  max_uses?: number | null
  max_uses_per_customer?: number | null
  discount_type: string
  discount_value: string
  max_discount_amount?: string | null
  minimum_order_amount?: string | null
  qualifying_product_ids?: string[] | null
  qualifying_category_ids?: string[] | null
  is_exclusive?: boolean
  stacking_group?: string
  starts_at?: string | null
  expires_at?: string | null
}

export type UpdateCouponData = Partial<CreateCouponData>

export interface ValidateCouponRequest {
  code: string
  subtotal: string
  customer_id?: string
  items?: Array<{
    product_id: string
    category_id?: string
    quantity: number
    unit_price: string
    line_total: string
  }>
}

export interface ValidateCouponResponse {
  valid: boolean
  discount_amount?: string
  promotion_name?: string
  message?: string
}

export async function listCoupons(params: CouponListParams = {}): Promise<CouponListResponse> {
  const searchParams = new URLSearchParams()
  if (params.page) searchParams.set('page', String(params.page))
  if (params.per_page) searchParams.set('per_page', String(params.per_page))
  if (params.search) searchParams.set('search', params.search)
  if (params.status) searchParams.set('status', params.status)

  const query = searchParams.toString()
  return apiGet<CouponListResponse>(`/coupons${query ? `?${query}` : ''}`)
}

export async function getCoupon(id: string): Promise<CouponData> {
  return apiGet<CouponData>(`/coupons/${id}`)
}

export async function createCoupon(data: CreateCouponData): Promise<CouponData> {
  return apiPost<CouponData>('/coupons', data)
}

export async function updateCoupon(id: string, data: UpdateCouponData): Promise<CouponData> {
  return apiPatch<CouponData>(`/coupons/${id}`, data)
}

export async function deleteCoupon(id: string): Promise<void> {
  return apiDelete(`/coupons/${id}`)
}

export async function validateCoupon(data: ValidateCouponRequest): Promise<ValidateCouponResponse> {
  return apiPost<ValidateCouponResponse>('/coupons/validate', data)
}

export async function revokeCoupon(id: string): Promise<CouponData> {
  return apiPost<CouponData>(`/coupons/${id}/revoke`)
}

export async function reactivateCoupon(id: string): Promise<CouponData> {
  return apiPost<CouponData>(`/coupons/${id}/reactivate`)
}
