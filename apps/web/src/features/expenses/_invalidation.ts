/**
 * Tenant-scoped invalidation predicates for the expenses feature.
 *
 * 2 namespaces:
 * - `expenses` — list and analytics useQueries invalidated by money-affecting
 *   expense mutations; analytics is also invalidated by category mutations.
 * - `expense-categories` — list useQuery + 4 invalidates from create + update
 *   + delete mutations.
 *
 * Every predicate gates on both its namespace/slot and the tenant/company
 * suffix. List predicates never match singular detail slots; mutations that
 * refresh a detail continue to use a separate exact-key invalidation.
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

export function expenseAnalyticsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'expenses' &&
      k[1] === 'analytics' &&
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

export function expenseRecurrencesInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'expense-recurrences' &&
      k[1] === 'list' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function expenseRecurrenceDetailInvalidationPredicate(
  id: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length === 5 &&
      k[0] === 'expense-recurrences' &&
      k[1] === 'detail' &&
      k[2] === id &&
      k[3] === tenantId &&
      k[4] === companyId
    )
  }
}
