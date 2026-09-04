import type { QueryKey } from '@tanstack/react-query'
import { tenantScopedKey } from './tenantScopedKey'

/**
 * Canonical form of a view scope: `'all'`, or the selected location ids sorted
 * so that a permuted-but-equal selection produces an identical value.
 *
 * Exported so that every consumer which compares scopes (query keys, filter
 * signatures, reset guards) uses ONE normalisation — two different answers to
 * "are these scopes the same?" is how a page resets its offset without its
 * query key changing (gate r1, N2).
 */
export function normalizeViewScope(scope: 'all' | readonly string[]): 'all' | string[] {
  return scope === 'all' ? 'all' : [...scope].sort()
}

/**
 * Location-aware tenant-scoped query key. The effective view scope is baked as
 * a NON-leading segment ({ locScope }) so the resource literal stays
 * segments[0]: mutations can still invalidate by bare literal prefix (e.g.
 * ['stock-levels']) and match every scope variant. Tenant/company remain
 * suffixes via tenantScopedKey.
 */
export function locationScopedKey(
  segments: readonly unknown[],
  scope: 'all' | readonly string[],
): QueryKey {
  const locScope = normalizeViewScope(scope)
  return tenantScopedKey([...segments, { locScope }]) as unknown as QueryKey
}
