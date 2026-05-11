import { useAuthStore } from '../stores/authStore'
import { useCompanyStore } from '../stores/companyStore'

/**
 * Append the active tenant + company scope to a queryKey so TanStack Query
 * cache entries invalidate automatically when the user switches between
 * tenants or companies.
 *
 * Reads from store snapshots via getState(); does NOT subscribe. The
 * queryKey is stamped at render time, so a useQuery call that wraps its
 * key in tenantScopedKey() will only see the new tenant/company values
 * if its host component is ALSO subscribed to whatever store change
 * fired (typically via useAuthStore / useCompanyStore selector hooks
 * elsewhere in the same render tree, or via an explicit dependency
 * threading from a provider). Don't rely on ancestor provider re-renders
 * as the sole trigger — when in doubt, also gate the query with
 * `{ enabled: !!user && !!currentCompanyId }` and read those values via
 * the hook form so cache invalidation is causal, not incidental.
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
