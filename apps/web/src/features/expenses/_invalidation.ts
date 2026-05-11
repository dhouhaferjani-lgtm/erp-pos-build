/**
 * Tenant-scoped invalidation predicates for the expenses feature.
 *
 * 2 namespaces:
 * - `expenses` — list useQuery + 6 invalidates from create + update + delete
 *   + post mutations.
 * - `expense-categories` — list useQuery + 4 invalidates from create + update
 *   + delete mutations.
 *
 * Each predicate gates on `k[0] === <namespace> && k[1] === 'list'` so it
 * matches ONLY the plural-list cache slots (factory.lists() / .list(filters)),
 * NOT the singular detail slots (factory.detail(id)). Mutations that need to
 * refresh a singular detail use exact-match wrap separately
 * (`tenantScopedKey([...factory.detail(id)])`); without the `k[1]` gate those
 * mutations would invalidate the detail twice — once via the predicate, once
 * via exact-match — causing double refetch.
 */

export function expensesInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'expenses' &&
      k[1] === 'list' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function expenseCategoriesInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'expense-categories' &&
      k[1] === 'list' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}
