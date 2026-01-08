/**
 * Product Types
 * Shared types for product management across the application
 */

export type ProductType = 'part' | 'service' | 'consumable' | 'storable'

export interface ProductImage {
  id: string
  product_id: string
  filename: string
  original_filename: string
  storage_path: string
  storage_disk: string
  mime_type: string
  file_size: number
  width: number | null
  height: number | null
  sort_order: number
  is_primary: boolean
  created_at: string
  updated_at: string | null
}

export interface Product {
  id: string
  name: string
  sku: string
  type: ProductType
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
  images?: ProductImage[]
  primary_image?: ProductImage
  created_at: string
  updated_at: string | null
}

export interface GetProductsParams {
  search?: string | undefined
  type?: ProductType
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
  type: ProductType
  is_active: boolean
}
