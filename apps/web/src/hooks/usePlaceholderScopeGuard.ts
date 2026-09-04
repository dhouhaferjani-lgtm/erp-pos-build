import { useState } from 'react'
import { useAuthStore } from '../stores/authStore'
import { useCompanyStore } from '../stores/companyStore'

/**
 * Guard for `useQuery({ placeholderData: keepPreviousData })` on a
 * tenant/company-scoped read.
 *
 * TanStack v5 feeds the placeholder from the observer's last query that had
 * data with NO key-lineage check (`queryObserver.js` #lastQueryWithDefinedData),
 * so it happily hands the PREVIOUS company's rows back across the tenant/company
 * suffix that {@link tenantScopedKey} appends — `CompanySelector` only
 * invalidates, it never unmounts the page. On a list page that means the
 * operator reads, links into, and acts on another company's records for the
 * whole in-flight window.
 *
 * `keepPreviousData` still earns its place WITHIN one scope (paging 1 -> 2 must
 * not flash an empty table), so the fix is not to drop it but to distinguish the
 * two cases. This hook records the scope that produced the last SETTLED data and
 * reports whether the placeholder currently on offer predates a scope change:
 *
 * ```ts
 * const { data, isLoading, isPlaceholderData } = useQuery({ ... })
 * const isStaleScopeData = usePlaceholderScopeGuard(isPlaceholderData, data !== undefined)
 * const rows = isStaleScopeData ? [] : data?.data ?? []
 * // ...
 * <DataTable data={rows} isLoading={isLoading || isStaleScopeData} />
 * ```
 *
 * The scope is recorded during render (React's documented derived-state
 * pattern, the same idiom the list pages use for their filter signature) rather
 * than in an effect: an effect would commit one paint of the foreign rows first,
 * which is precisely the leak being closed.
 *
 * It subscribes to the auth/company stores itself, so the guard re-renders on a
 * switch whether or not the host component happens to read those stores — the
 * causality caveat documented on {@link tenantScopedKey} does not apply.
 *
 * @param isPlaceholderData `useQuery`'s flag: the returned data is a placeholder.
 * @param hasData          Whether `data` is currently defined.
 * @returns `true` while the rendered data belongs to a different tenant/company
 *          than the active one — render the loading state, not the rows.
 */
export function usePlaceholderScopeGuard(isPlaceholderData: boolean, hasData: boolean): boolean {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const scopeSignature = JSON.stringify([tenantId, companyId])

  // Only real (non-placeholder) data proves which scope the server answered
  // for; a pending or disabled query must never be allowed to re-stamp the
  // signature, or the next placeholder would masquerade as same-scope.
  const [settledScopeSignature, setSettledScopeSignature] = useState<string | null>(null)
  if (!isPlaceholderData && hasData && settledScopeSignature !== scopeSignature) {
    setSettledScopeSignature(scopeSignature)
  }

  return isPlaceholderData && settledScopeSignature !== scopeSignature
}
