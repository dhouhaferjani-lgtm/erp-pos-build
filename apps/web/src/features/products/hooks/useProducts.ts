import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getProducts, getProduct } from '../api/products'
import type { GetProductsParams, PaginatedProductsResponse, Product } from '../types'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

/**
 * Query key factory for products.
 *
 * Returns un-scoped structural prefixes; the tenant + company scope is
 * appended at the useQuery call site via tenantScopedKey([...]). The
 * audit-tanstack-keys gate only approves a queryKey expression that is
 * either an array literal carrying an approved scope identifier OR a
 * bare-Identifier call expression `tenantScopedKey(...)`. A property-
 * access factory call like `productKeys.list(...)` is neither, so the
 * wrap MUST happen at the call site.
 */
export const productKeys = {
  all: ['products'] as const,
  lists: () => [...productKeys.all, 'list'] as const,
  list: (params?: GetProductsParams) => [...productKeys.lists(), params] as const,
  details: () => [...productKeys.all, 'detail'] as const,
  detail: (id: string) => [...productKeys.details(), id] as const,
}

/**
 * Predicate factory for tenant-scoped invalidation across the entire
 * `products` namespace. Used by useProductRealtime (and any future
 * consumer that needs to cascade-invalidate after a backend event).
 *
 * tenantScopedKey() puts tenant_id + company_id at the SUFFIX of leaf
 * keys, which breaks the prefix-match cascade that older code used (a
 * wrapped tag like `[products, t, c]` is not a prefix of leaf
 * `[products, list, params, t, c]` because position 1 is `t` vs `list`).
 * The predicate matches on `q.queryKey[0] === 'products'` plus the
 * tenant/company tail, sidestepping the positional mismatch.
 */
export function productsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'products' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

/**
 * Hook to fetch paginated list of products
 */
export function useProducts(
  params?: GetProductsParams
): UseQueryResult<PaginatedProductsResponse> {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...productKeys.list(params)]),
    queryFn: () => getProducts(params),
    enabled: !!tenantId && !!companyId,
    staleTime: 60000, // Consider data fresh for 1 minute
  })
}

/**
 * Hook to fetch a single product by ID
 */
export function useProduct(id: string): UseQueryResult<Product> {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...productKeys.detail(id)]),
    queryFn: () => getProduct(id),
    enabled: Boolean(id) && !!tenantId && !!companyId,
  })
}
