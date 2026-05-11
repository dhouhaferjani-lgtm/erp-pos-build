/**
 * Tenant-scoped invalidation predicates for the pricing feature.
 *
 * Two namespaces:
 * - `price-lists` (plural) — list-page useQuery + cross-page invalidates
 *   carry positional segments (e.g., statusFilter), so tenantScopedKey
 *   wrap puts t/c at the suffix and a fixed [...['price-lists']] wrap
 *   does NOT prefix-match. Use predicate.
 * - `price-list` (singular) — detail useQuery + detail invalidates use
 *   the same shape `[price-list, id, t, c]` after wrap, so exact-match
 *   invalidate suffices. No predicate needed (a singleton predicate
 *   would be equivalent to the exact match).
 */

export function priceListsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'price-lists' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}
