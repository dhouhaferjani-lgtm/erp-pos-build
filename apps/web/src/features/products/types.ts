/**
 * Product Types
 * Shared types for product management across the application
 */

export type ProductType = 'part' | 'service' | 'consumable'

export interface ProductMediaItem {
  id: string
  asset_id: string
  is_primary: boolean
  sort_order: number
  role: string
  url: string | null
  alt: string | null
  caption: string | null
}

export interface Product {
  id: string
  name: string
  sku: string
  type?: ProductType | null
  is_physical: boolean
  description: string | null
  sale_price: string | null
  purchase_price: string | null
  tax_rate: string | null
  unit: string | null
  barcode: string | null
  is_active: boolean
  is_active_for_ecommerce: boolean
  oem_numbers: string[] | null
  cross_references: Array<{ brand: string; reference: string }> | null
  images?: ProductMediaItem[]
  primary_image?: ProductMediaItem
  created_at: string
  updated_at: string | null
}

export interface GetProductsParams {
  search?: string | undefined
  type?: string | undefined
  is_physical?: boolean
  active?: boolean
  per_page?: number
  cursor?: string | null
}

export interface PaginatedProductsResponse {
  data: Product[]
  meta: {
    per_page: number
    has_more: boolean
    total?: number
  }
  links: {
    next: string | null
    prev: string | null
  }
}

/**
 * Minimal product info for selection UI
 */
export interface ProductSelectionItem {
  id: string
  name: string
  sku: string
  barcode: string | null
  is_physical: boolean
  is_active: boolean
}
