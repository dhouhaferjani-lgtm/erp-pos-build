import type { OffsetPaginationMeta } from '@/types/pagination'

export interface MenuData {
  id: string
  name: string
  description: string | null
  is_default: boolean
  is_active: boolean
  active_from: string | null
  active_until: string | null
  start_date: string | null
  end_date: string | null
  available_days: number[] | null
  display_order: number
  categories: MenuCategoryData[] | null
  categories_count: number
  items_count: number
  created_at: string
  updated_at: string | null
}

export interface MenuCategoryData {
  id: string
  menu_id: string
  name: string
  description: string | null
  icon: string | null
  display_order: number
  is_active: boolean
  items: MenuItemData[] | null
  created_at: string
  updated_at: string | null
}

export interface MenuItemData {
  id: string
  sellable_id: string
  sellable_type: 'product' | 'composite_item'
  name: string
  code: string
  base_price: string
  override_price: string | null
  effective_price: string
  display_order: number
  is_available: boolean
  image_url: string | null
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: OffsetPaginationMeta
}

// Request types
export interface CreateMenuData {
  name: string
  description?: string | null
  is_default?: boolean
  is_active?: boolean
  active_from?: string | null
  active_until?: string | null
  start_date?: string | null
  end_date?: string | null
  available_days?: number[] | null
  display_order?: number
}

export interface UpdateMenuData extends Partial<CreateMenuData> {}

export interface CreateMenuCategoryData {
  name: string
  description?: string | null
  icon?: string | null
  display_order?: number
  is_active?: boolean
}

export interface UpdateMenuCategoryData extends Partial<CreateMenuCategoryData> {}

export interface SyncMenuCategoryItemsData {
  items: Array<{
    sellable_type: 'product' | 'composite_item'
    sellable_id: string
    override_price?: number | null
    display_order?: number
    is_available?: boolean
  }>
}

export interface AddMenuCategoryItemData {
  sellable_type: 'product' | 'composite_item'
  sellable_id: string
  // Canonical decimal string (matches MenuItemData.override_price). Never a JS
  // float — money stays out of the IEEE-754 pipeline (precision contract).
  override_price?: string | null
  display_order?: number
  is_available?: boolean
}
