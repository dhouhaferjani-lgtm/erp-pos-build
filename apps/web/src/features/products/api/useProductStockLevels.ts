import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { getProductStock } from './productStock'

/**
 * Shared per-product/variant stock-level query for the STOCK-TRANSFER surface
 * (S-6 partial, request-hygiene Task 7).
 *
 * Every stock-transfer consumer of the company-wide stock levels of one
 * product/variant goes through this hook so TanStack Query deduplicates them
 * into a single request instead of one request per component. The key root stays
 * `stock-levels` so the existing prefix invalidations (stock-transfer
 * create/complete/cancel and the goods-receipt
 * `scopedNamespacePredicate('stock-levels', …)`) keep matching.
 *
 * NOT-YET-MIGRATED SIBLING ROOT — dedupe is NOT global. Four consumers call the
 * same `GET /products/{id}/stock-levels` endpoint under `['product-stock', …]`:
 * `features/inventory/components/ProductStockLevels.tsx`,
 * `features/replenishment/components/RequestContextPanel.tsx`,
 * `features/replenishment/components/CreateTransferDialog.tsx`, and
 * `features/pos/organisms/ProductInfoModal/ProductInfoModal.tsx`. The two roots do
 * NOT cross-invalidate (`features/inventory/components/ThresholdEditCell.tsx`
 * invalidates `['product-stock', …]` only, and the stock-transfer mutations
 * invalidate `['stock-levels']` only). No screen mounts both today, so there is no
 * live double-fetch — but do not assume a shared cache across surfaces. Unifying
 * the roots is Phase B B-8 debt, together with the bulk endpoint.
 *
 * Scope is read through the store hooks — not only inside `tenantScopedKey()`,
 * which uses `getState()` and therefore cannot rerender this hook when the
 * auth/company bootstrap completes — and the query stays disabled until both
 * scopes exist.
 *
 * Phase A boundary: this deduplicates consumers of the SAME product/variant only.
 * One request per distinct product/variant remains until the B-8 bulk endpoint —
 * asserted by the distinct-product test, so do not "fix" it by widening the key.
 *
 * Tests: `features/stock-transfers/__tests__/useProductStockLevels.test.tsx`
 * (co-located with its only consumer surface, not under `features/products`).
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
