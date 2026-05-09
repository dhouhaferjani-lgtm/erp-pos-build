import { useAuthStore } from '../stores/authStore'
import { useCompanyStore } from '../stores/companyStore'

/**
 * Append the active tenant + company scope to a queryKey so TanStack Query
 * cache entries invalidate automatically when the user switches between
 * tenants or companies.
 *
 * Reads from store snapshots via getState(); does not subscribe. The
 * queryKey is stamped at render time, and on tenant/company change the
 * subscribing component re-renders (because companyStore + authStore
 * subscriptions exist elsewhere in the tree), the new queryKey is
 * computed, and TanStack Query treats it as a fresh query.
 *
 * The architecture-test gate at apps/web/tools/audit-tanstack-keys.mjs
 * approves any queryKey whose call expression is `tenantScopedKey(...)`.
 * This is the canonical way to make a query tenant-scoped without
 * threading companyId / tenantId through every hook's props.
 *
 * Returns null sentinels when stores are mid-hydration; downstream
 * hooks should pair with `{ enabled: !!user && !!currentCompanyId }`
 * to suppress fetches before the user is fully authenticated.
 */
export function tenantScopedKey<T extends readonly unknown[]>(
  segments: T,
): readonly [...T, string | null, string | null] {
  const tenantId = useAuthStore.getState().user?.tenant_id ?? null
  const companyId = useCompanyStore.getState().currentCompanyId ?? null
  return [...segments, tenantId, companyId] as const
}
