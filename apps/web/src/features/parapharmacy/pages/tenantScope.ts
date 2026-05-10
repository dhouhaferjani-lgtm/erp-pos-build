export function parapharmacyListInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
  resource: string,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 5 &&
      k[0] === 'parapharmacy' &&
      k[1] === resource &&
      typeof k[2] === 'number' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}
