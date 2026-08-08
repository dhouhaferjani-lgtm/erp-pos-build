/**
 * Tenant-scoped invalidation predicate for the stock-adjustments feature.
 *
 * Placement is DECIDED, not inherited: every existing `_invalidation.ts` in the
 * repo lives inside the feature that consumes it, so this one does too.
 *
 * Why a predicate and not a bare `invalidateQueries({ queryKey: ['x'] })`:
 * `tenantScopedKey()` appends `[tenantId, companyId]` as SUFFIXES, so a bare
 * prefix array does not match a tenant-scoped key at all. The stock-transfers
 * hooks are wrong about this — they invalidate `['stock-levels']` and silently
 * match nothing.
 */
export function stockAdjustmentsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'stock-adjustments' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}
