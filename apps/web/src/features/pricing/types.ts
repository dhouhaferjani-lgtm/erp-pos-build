/**
 * Price List types for the pricing module
 */

import type { OffsetPaginationMeta } from '@/types/pagination'

export interface PriceList {
  id: string
  code: string
  name: string
  description: string | null
  currency: string
  is_active: boolean
  is_default: boolean
  valid_from: string | null
  valid_until: string | null
  items_count?: number
  created_at: string
  updated_at?: string
}

export interface PriceListItem {
  id: string
  product_id: string
  product_name: string
  product_sku: string
  price: string
  min_quantity: number
  max_quantity: number | null
}

export interface PriceListPartner {
  id: string
  name: string
  valid_from?: string | null
  valid_until?: string | null
  priority?: number
}

export interface PriceListDetail extends PriceList {
  items: PriceListItem[]
  partners: PriceListPartner[]
}

export interface PriceListFormData {
  code: string
  name: string
  description?: string
  currency: string
  is_active: boolean
  is_default: boolean
  valid_from?: string | null
  valid_until?: string | null
}

export interface PriceListItemFormData {
  product_id: string
  price: string
  min_quantity: number
  max_quantity?: number | null
}

export interface AssignPartnerFormData {
  partner_id: string
  valid_from?: string | null
  valid_until?: string | null
  priority?: number
}

export interface PriceListsResponse {
  data: PriceList[]
  meta: Pick<OffsetPaginationMeta, 'total'> &
    Partial<Omit<OffsetPaginationMeta, 'total'>>
}

export interface PriceListResponse {
  data: PriceListDetail
}
