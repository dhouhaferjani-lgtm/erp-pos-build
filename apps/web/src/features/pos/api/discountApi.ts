import { apiPost } from '@/lib/api'

export interface PreviewDiscountItem {
  product_id: string
  quantity: number
  unit_price: string
  line_total: string
  category_id?: string
}

export interface PreviewDiscountsRequest {
  items: PreviewDiscountItem[]
  subtotal: string
  manual_discount_amount?: string
  coupon_code?: string
  customer_id?: string
  loyalty_discount_amount?: string
  loyalty_reward_id?: string
}

export interface DiscountLineData {
  source: 'manual' | 'promotion' | 'coupon' | 'loyalty'
  stacking_group: string
  is_exclusive: boolean
  priority: number
  discount_amount: string
  label: string
  reference_id: string | null
  applies_to: string
}

export interface DiscountBreakdownData {
  lines: DiscountLineData[]
  total_transaction_discount: string
  line_discounts: Record<string, string>
}

export async function previewDiscounts(
  request: PreviewDiscountsRequest
): Promise<DiscountBreakdownData> {
  return apiPost<DiscountBreakdownData>('/pos/cart/preview-discounts', request)
}
