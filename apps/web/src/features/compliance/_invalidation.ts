/**
 * Tenant-scoped invalidation predicates for the compliance feature.
 *
 * 3 invalidatable namespaces:
 * - `fraud-alerts` — list useQuery + 3 modal mutation invalidates.
 * - `fraud-alert-statistics` — top-bar useQuery + 3 modal mutation
 *   invalidates (FraudAlertActionModals each cascade BOTH).
 * - `fraud-settings` — settings page useQuery + 2 mutation invalidates
 *   (update + reset).
 *
 * Sibling namespace `users` (admin-role sub-segment) is wrapped at
 * callsite for tenant scoping but no mutation cascades into it.
 */

export function fraudAlertsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'fraud-alerts' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function fraudAlertStatisticsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'fraud-alert-statistics' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function fraudSettingsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'fraud-settings' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}
