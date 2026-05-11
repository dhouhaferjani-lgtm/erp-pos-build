/**
 * Tenant-scoped invalidation predicate for the import feature.
 *
 * Production has 4 keyed namespaces:
 * - `imports` (factory: list/detail/errors/preview) — predicate handles the
 *   plural list cascade; singular detail/errors/preview keys go through
 *   exact-match wrap (no predicate).
 * - `migration-wizard` (factory: order/status/dependencies(type)) — only
 *   `wizardStatus` is invalidated by mutations (useExecuteImport); exact-match
 *   wrap covers it. No predicate currently needed.
 * - `import-error-summary` and `import-errors` (ErrorViewer bare arrays) —
 *   no mutation cascade currently targets them; only need tenant scoping at
 *   the useQuery callsites.
 *
 * The predicate gates on `k[0] === 'imports' && k[1] === 'list'` so it
 * matches the plural `[imports, list, ...]` slots without colliding with
 * `[imports, detail, id]` / `[imports, errors, id]` / `[imports, preview, id]`
 * (which use exact-match invalidates instead).
 */

export function importsListInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'imports' &&
      k[1] === 'list' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}
