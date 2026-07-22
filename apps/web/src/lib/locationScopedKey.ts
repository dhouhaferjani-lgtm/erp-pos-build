import type { QueryKey } from '@tanstack/react-query'
import { tenantScopedKey } from './tenantScopedKey'

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
  const locScope = scope === 'all' ? 'all' : [...scope].sort()
  return tenantScopedKey([...segments, { locScope }]) as unknown as QueryKey
}
