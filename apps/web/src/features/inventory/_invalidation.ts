/**
 * Tenant-scoped invalidation predicates for inventory screens.
 *
 * `tenantScopedKey()` appends tenant/company at the suffix. Prefix
 * invalidation such as `['products']` or `['stock-levels']` no longer
 * matches filtered leaf keys, so list-like cascades use predicates.
 */
export function inventoryProductsInvalidationPredicate(
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

export function stockLevelsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'stock-levels' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}
