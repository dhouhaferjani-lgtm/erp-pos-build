import type { OffsetPaginationMeta } from '@/types/pagination'

export type PricingType = 'flat_rate' | 'hourly' | 'percentage'

export interface ServiceCategory {
  id: string
  name: string
  description: string | null
  parent_id: string | null
  sort_order: number
  is_active: boolean
  created_at: string
  updated_at: string | null
  children?: ServiceCategory[]
  parent?: ServiceCategory
}

export interface Service {
  id: string
  code: string
  name: string
  description: string | null
  category_id: string | null
  pricing_type: PricingType
  base_price: string
  currency: string
  default_duration_minutes: number | null
  hourly_rate: string | null
  tax_rate: string | null
  default_tax_configuration_id: string | null
  is_active: boolean
  created_at: string
  updated_at: string | null
  category?: ServiceCategory
}

export interface ServicesResponse {
  data: Service[]
  meta?: OffsetPaginationMeta
}

export interface CategoriesResponse {
  data: ServiceCategory[]
  meta?: OffsetPaginationMeta
}

export interface CreateServiceData {
  code: string
  name: string
  description?: string | null
  category_id?: string | null
  pricing_type: PricingType
  base_price: string
  currency?: string
  default_duration_minutes?: number | null
  hourly_rate?: string | null
  tax_rate?: string | null
  default_tax_configuration_id?: string | null
  is_active?: boolean
}

export interface UpdateServiceData extends Partial<CreateServiceData> {}

export interface CreateCategoryData {
  name: string
  description?: string | null
  parent_id?: string | null
  sort_order?: number
  is_active?: boolean
}

export interface UpdateCategoryData extends Partial<CreateCategoryData> {}
