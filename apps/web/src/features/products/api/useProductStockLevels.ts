import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getProductStock } from './productStock'

/**
 * Shared per-product/variant stock-level query (S-6 partial, request-hygiene Task 7).
 *
 * Every consumer that needs the company-wide stock levels of one product/variant
 * must go through this hook so TanStack Query deduplicates them into a single
 * request instead of one request per component. The key root stays `stock-levels`
 * so the existing prefix invalidations (stock-transfer create/complete/cancel and
 * the goods-receipt `scopedNamespacePredicate('stock-levels', …)`) keep matching.
 *
 * Scope is read through the store hooks — not only inside `tenantScopedKey()`,
 * which uses `getState()` and therefore cannot rerender this hook when the
 * auth/company bootstrap completes — and the query stays disabled until both
 * scopes exist.
 *
 * Phase A boundary: this deduplicates consumers of the SAME product/variant only.
 * One request per distinct product/variant remains until the B-8 bulk endpoint.
 */
export function useProductStockLevels(
  productId: string,
  variantId: string | null,
  requestedEnabled: boolean,
) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return useQuery({
    queryKey: tenantScopedKey(['stock-levels', 'product', productId, variantId]),
    queryFn: () => getProductStock(productId, variantId),
    enabled:
      requestedEnabled
      && productId !== ''
      && tenantId !== null
      && companyId !== null,
    staleTime: 15_000,
  })
}
