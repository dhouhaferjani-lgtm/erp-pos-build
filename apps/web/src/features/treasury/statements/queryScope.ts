const WORKSPACE_QUERY_NAMESPACES = new Set([
  'bank-statement',
  'bank-statements',
  'bank-statement-line-suggestions',
  'repository-movements',
  'payment-repositories',
  'bank-statement-target-provenance',
])

export function shouldRefreshWorkspaceQuery(
  queryKey: readonly unknown[],
  tenantId: string | null,
  companyId: string | null,
): boolean {
  if (tenantId === null || companyId === null || queryKey.length < 3) return false
  const namespace = queryKey[0]
  return typeof namespace === 'string'
    && WORKSPACE_QUERY_NAMESPACES.has(namespace)
    && queryKey.at(-2) === tenantId
    && queryKey.at(-1) === companyId
}
