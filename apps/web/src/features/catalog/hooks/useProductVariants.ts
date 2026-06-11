import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { getVariantsForProduct, type ProductVariant } from '../api/variantApi'

/**
 * Active-and-inactive variants for a product. Callers that only want
 * sellable/transferable variants filter on `is_active` themselves. Disabled
 * when no product is selected.
 */
export function useProductVariants(productId: string) {
  return useQuery<ProductVariant[]>({
    queryKey: tenantScopedKey(['product-variants', productId]),
    queryFn: () => getVariantsForProduct(productId),
    enabled: productId !== '',
  })
}
