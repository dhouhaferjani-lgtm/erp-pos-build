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
 * **"Scope" is tenant + company PLUS whatever the caller adds.** Tenant and
 * company are signed here because every scoped key carries them; any FURTHER
 * dimension the key carries must be passed in `additionalScope`, or the
 * placeholder crosses that dimension unguarded. A key built with
 * {@link locationScopedKey} carries a location scope, so its caller passes
 * `[normalizeViewScope(scope)]`. The rule of thumb: a key segment goes in
 * `additionalScope` when showing another segment-value's data would be WRONG,
 * and stays out when it is merely the previous slice of the same result set
 * (page, offset, sort) — those are the reason `keepPreviousData` is there.
 *
 * **When NOT to reach for this hook at all:** if the key varies per *input*
 * rather than per *page* — `apps/web/src/features/pos/hooks/useDiscountPreview.ts`
 * keys on the whole cart shape — then every placeholder is data computed for a
 * different question, there is no same-scope win to preserve, and the right fix
 * is to drop `placeholderData` outright rather than guard it.
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
 * **Invariant it rests on:** `useQuery` reports the placeholder in the SAME
 * render that changes the key. TanStack takes the placeholder branch only when
 * `data === undefined` for the current key (`queryObserver.js:266`), so a render
 * can never show settled data sitting under a scope it was not fetched for —
 * which is what makes `!isPlaceholderData && hasData` safe to treat as proof of
 * scope. Tests that drive this hook directly must therefore change the scope and
 * the query result in one commit; see `__tests__/usePlaceholderScopeGuard.test.tsx`.
 *
 * @param isPlaceholderData `useQuery`'s flag: the returned data is a placeholder.
 * @param hasData          Whether `data` is currently defined.
 * @param additionalScope  Further key dimensions across which a placeholder must
 *                         never be shown (e.g. the location scope). Compared by
 *                         JSON value, so pass already-normalised values.
 * @returns `true` while the rendered data belongs to a different scope than the
 *          active one — render the loading state, not the rows.
 */
export function usePlaceholderScopeGuard(
  isPlaceholderData: boolean,
  hasData: boolean,
  additionalScope: readonly unknown[] = [],
): boolean {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const scopeSignature = JSON.stringify([tenantId, companyId, ...additionalScope])

  // Only real (non-placeholder) data proves which scope the server answered
  // for; a pending or disabled query must never be allowed to re-stamp the
  // signature, or the next placeholder would masquerade as same-scope.
  const [settledScopeSignature, setSettledScopeSignature] = useState<string | null>(null)
  if (!isPlaceholderData && hasData && settledScopeSignature !== scopeSignature) {
    setSettledScopeSignature(scopeSignature)
  }

  return isPlaceholderData && settledScopeSignature !== scopeSignature
}
