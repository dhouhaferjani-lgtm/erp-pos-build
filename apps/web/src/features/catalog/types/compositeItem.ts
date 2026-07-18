export type VerticalType = 'fnb' | 'manufacturing' | 'sewing' | 'bakery' | 'generic'
export type ProductionType = 'made_to_order' | 'batch' | 'stock'
export type PriceAdjustmentType = 'absolute' | 'percentage' | 'override'
export type SelectionType = 'single' | 'multiple'
export type ComponentType = 'product' | 'composite_item'
export type PricingMode = 'standard' | 'fixed_bundle'

export interface CompositeItemData {
  id: string
  code: string
  name: string
  vertical_type: VerticalType
  base_price: string
  manual_cost: string | null
  effective_cost: string | null
  recipe_cost: string | null
  margin_percentage: number | null
  production_type: ProductionType
  pricing_mode: PricingMode
  tax_rate: string | null
  is_active: boolean
  is_available: boolean
  category_id: string | null
  category_name: string | null
  image_url: string | null
  display_order: number
  active_recipe: RecipeData | null
  variants: CompositeItemVariantData[] | null
  modifier_groups: ModifierGroupData[] | null
  created_at: string
  updated_at: string | null
}

export interface RecipeData {
  id: string
  composite_item_id: string
  version: number
  version_name: string | null
  is_active: boolean
  yield_quantity: string
  yield_unit_id: string | null
  calculated_cost: string | null
  prep_time_minutes: number | null
  cook_time_minutes: number | null
  total_time_minutes: number | null
  instructions: string | null
  lines: RecipeLineData[] | null
  created_at: string
  updated_at: string | null
}

export interface RecipeLineData {
  id: string
  recipe_id: string
  component_type: ComponentType
  component_id: string
  component_name: string | null
  component_sku: string | null
  quantity: string
  unit_id: string | null
  unit_name: string | null
  is_optional: boolean
  is_scalable: boolean
  wastage_percent: string
  unit_cost: string | null
  line_cost: string | null
  display_order: number
}

export interface CompositeItemVariantData {
  id: string
  composite_item_id: string
  code: string
  name: string
  price_adjustment_type: PriceAdjustmentType
  price_adjustment: string
  recipe_multiplier: string
  is_default: boolean
  is_active: boolean
  display_order: number
}

export interface ModifierGroupData {
  id: string
  code: string
  name: string
  selection_type: SelectionType
  min_selections: number
  max_selections: number
  is_required: boolean
  is_active: boolean
  display_order: number
  modifiers: ModifierData[] | null
  created_at: string
  updated_at: string | null
}

export interface ModifierData {
  id: string
  modifier_group_id: string
  code: string
  name: string
  price_adjustment: string
  has_inventory_impact: boolean
  component_type: string | null
  component_id: string | null
  component_quantity: string | null
  component_unit_id: string | null
  is_default: boolean
  is_active: boolean
  display_order: number
}

export interface RecipeCostData {
  total_cost: string
  lines: Array<{
    component_name: string
    quantity: string
    unit_cost: string
    line_cost: string
    percent_of_total: string
  }>
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: OffsetPaginationMeta
}

// Request types
export interface CreateCompositeItemData {
  code: string
  name: string
  category_id?: string | null
  vertical_type?: VerticalType
  base_price: string
  manual_cost?: string | null
  production_type?: ProductionType
  pricing_mode?: PricingMode
  tax_rate?: string | null
  stock_unit_id?: string | null
  is_active?: boolean
  is_available?: boolean
  image_url?: string | null
  display_order?: number
}

export interface UpdateCompositeItemData extends Partial<CreateCompositeItemData> {}

export interface CreateRecipeData {
  version_name?: string | null
  yield_quantity?: string
  yield_unit_id?: string | null
  prep_time_minutes?: number | null
  cook_time_minutes?: number | null
  instructions?: string | null
}

export interface CreateRecipeLineData {
  component_type?: ComponentType
  component_id: string
  composite_item_id?: string
  quantity: string
  unit_id?: string | null
  is_optional?: boolean
  is_scalable?: boolean
  wastage_percent?: string
  display_order?: number
}

export interface CreateVariantData {
  code: string
  name: string
  price_adjustment_type?: PriceAdjustmentType
  price_adjustment?: string
  recipe_multiplier?: string
  is_default?: boolean
  is_active?: boolean
  display_order?: number
}

export interface CreateModifierGroupData {
  code: string
  name: string
  selection_type?: SelectionType
  min_selections?: number
  max_selections?: number
  is_required?: boolean
  is_active?: boolean
  display_order?: number
}

export interface CreateModifierData {
  code: string
  name: string
  price_adjustment?: string
  component_type?: string | null
  component_id?: string | null
  component_quantity?: string | null
  component_unit_id?: string | null
  is_default?: boolean
  is_active?: boolean
  display_order?: number
}
import type { OffsetPaginationMeta } from '@/types/pagination'
