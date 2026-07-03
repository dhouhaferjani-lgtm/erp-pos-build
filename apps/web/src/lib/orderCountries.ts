/**
 * Order a country list for pickers: the preferred country (usually the
 * current tenant/company country) first, everything else alphabetical by
 * display name. No country gets a hardcoded first position.
 */
export function orderCountries<T extends { code: string; name: string }>(
  list: readonly T[],
  preferredCode?: string | null,
): T[] {
  const preferred = (preferredCode ?? '').trim().toUpperCase()
  return [...list].sort((a, b) => {
    if (a.code === preferred && b.code !== preferred) return -1
    if (b.code === preferred && a.code !== preferred) return 1
    return a.name.localeCompare(b.name)
  })
}
