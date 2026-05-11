/**
 * Tenant-scoped invalidation predicates for the services feature.
 *
 * 3 namespaces:
 * - `service-categories` — list useQuery + 3 invalidates from category mutations
 *   (create/update/delete in ServiceCategoryListPage).
 * - `services` — filtered list useQuery + 3 invalidates from service mutations
 *   (create/update in ServiceForm; delete in ServiceDetailPage).
 * - `service` — singular detail useQuery + 1 invalidate from update mutation.
 *   Singular invalidates use exact-match wrap (`tenantScopedKey(['service', id])`)
 *   so no predicate is needed.
 */

export function serviceCategoriesInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'service-categories' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function servicesInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'services' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}
