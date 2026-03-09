import { useMemo } from 'react'
import { useActiveMenu } from './useActiveMenu'
import type { Product } from '../molecules/ProductCard/ProductCard'
import type { MenuModifierGroup } from './useActiveMenu'

export interface FnBProduct extends Product {
  sellableType: 'composite_item'
  compositeItemId: string
  modifierGroups?: MenuModifierGroup[]
}

export function useFnBProducts(options?: { enabled?: boolean }) {
  const enabled = options?.enabled ?? true
  const { data: menu, isLoading, error } = useActiveMenu({ enabled })

  const result = useMemo(() => {
    if (!menu) {
      return { products: [] as FnBProduct[], categories: [] as string[], menuName: undefined }
    }

    const products: FnBProduct[] = []
    const categories: string[] = []

    for (const category of menu.categories) {
      categories.push(category.name)

      if (!category.items) continue

      for (const item of category.items) {
        const fnbProduct: FnBProduct = {
          id: item.composite_item_id,
          name: item.name,
          sku: item.code,
          sale_price: item.effective_price,
          stock_quantity: 999, // F&B items are typically made-to-order
          category: category.name,
          sellableType: 'composite_item',
          compositeItemId: item.composite_item_id,
        }
        if (item.image_url) {
          fnbProduct.image_url = item.image_url
        }
        const activeGroups = item.modifier_groups?.filter((g) => g.is_active)
        if (activeGroups && activeGroups.length > 0) {
          fnbProduct.modifierGroups = activeGroups
        }
        products.push(fnbProduct)
      }
    }

    return { products, categories, menuName: menu.name }
  }, [menu])

  return {
    data: result.products,
    categories: result.categories,
    menuName: result.menuName,
    isLoading,
    error,
  }
}
