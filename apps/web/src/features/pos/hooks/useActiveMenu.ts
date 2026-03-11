import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import type { ModifierGroupData, ModifierData } from '@/features/catalog/types/compositeItem'

export interface MenuModifier extends ModifierData {}

export interface MenuModifierGroup extends ModifierGroupData {}

export interface MenuItem {
  id: string
  sellable_id: string
  sellable_type: 'product' | 'composite_item'
  name: string
  code: string
  base_price: string
  override_price: string | null
  effective_price: string
  tax_rate: string | null
  display_order: number
  is_available: boolean
  image_url: string | null
  modifier_groups: MenuModifierGroup[] | null
}

export interface MenuCategory {
  id: string
  menu_id: string
  name: string
  description: string | null
  icon: string | null
  display_order: number
  is_active: boolean
  items: MenuItem[] | null
  created_at: string
  updated_at: string | null
}

export interface ActiveMenu {
  id: string
  name: string
  description: string | null
  is_default: boolean
  categories: MenuCategory[]
}

export const activeMenuKeys = {
  all: ['pos', 'active-menu'] as const,
}

export function useActiveMenu(options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: activeMenuKeys.all,
    queryFn: () => apiGet<ActiveMenu>('/active-menu'),
    staleTime: 60000, // 1 minute
    enabled: options?.enabled ?? true,
  })
}
