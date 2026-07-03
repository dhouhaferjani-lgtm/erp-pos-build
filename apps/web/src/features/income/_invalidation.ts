/**
 * Tenant-scoped invalidation predicate for the income feature.
 *
 * Gates on `k[0] === 'income' && k[1] === 'list'` so it matches ONLY the
 * plural-list cache slots, not singular detail slots (mirror of the expenses
 * predicate).
 */
export function incomeInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'income' &&
      k[1] === 'list' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}
