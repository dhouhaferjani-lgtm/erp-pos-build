/**
 * Predicate factories for tenant-scoped invalidation across the POS
 * `[pos, shift, ...]` and `[pos, shift-balance, ...]` namespaces.
 *
 * `tenantScopedKey()` puts tenant_id + company_id at the SUFFIX of leaf
 * keys. A wrapped tag like `tenantScopedKey(['pos', 'shift'])` resolves to
 * `[pos, shift, t, c]`, which is NOT a prefix of leaf
 * `[pos, shift, terminalCode, t, c]` because position 2 is `t` vs the
 * terminalCode. Predicate-based invalidation sidesteps that positional
 * mismatch by matching on `queryKey[0]` + `queryKey[1]` plus the
 * tenant/company tail.
 *
 * Used by POSTransactions (post-receipt cleanup, modal `onSuccess`) to
 * cascade-invalidate every
 * shift / shift-balance entry for the active tenant + company.
 */
export function posShiftInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'pos' &&
      k[1] === 'shift' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function posShiftBalanceInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'pos' &&
      k[1] === 'shift-balance' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}
