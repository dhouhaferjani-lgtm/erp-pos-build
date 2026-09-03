import { describe, it, expect, vi } from 'vitest'
import { stockAdjustmentsInvalidationPredicate } from '../_invalidation'
import {
  stockLevelsInvalidationPredicate,
  stockMovementsInvalidationPredicate,
} from '@/features/inventory/_invalidation'

vi.mock('@/stores/authStore', () => {
  const state = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (selector: (s: typeof state) => unknown) => selector(state)
  useAuthStore.getState = () => state
  return { useAuthStore }
})

vi.mock('@/stores/companyStore', () => {
  const state = { currentCompanyId: 'company-1' }
  const useCompanyStore = (selector: (s: typeof state) => unknown) => selector(state)
  useCompanyStore.getState = () => state
  return { useCompanyStore }
})

/**
 * `tenantScopedKey()` appends `[tenantId, companyId]` as SUFFIXES, so a bare
 * prefix array does not match a tenant-scoped key at all — which is why the
 * mutations invalidate through PREDICATES. These assert the predicates actually
 * match the keys the pages register, in all three namespaces the lane touches.
 */
describe('invalidation predicates', () => {
  const tenant = 'tenant-1'
  const company = 'company-1'

  it('matches this feature s own tenant-scoped keys', () => {
    const predicate = stockAdjustmentsInvalidationPredicate(tenant, company)

    expect(predicate({ queryKey: ['stock-adjustments', 'list', {}, tenant, company] })).toBe(true)
    expect(predicate({ queryKey: ['stock-adjustments', 'detail', 'adj-1', tenant, company] })).toBe(
      true,
    )
  })

  it('does NOT match another tenant or company', () => {
    const predicate = stockAdjustmentsInvalidationPredicate(tenant, company)

    expect(predicate({ queryKey: ['stock-adjustments', 'list', {}, 'tenant-2', company] })).toBe(
      false,
    )
    expect(predicate({ queryKey: ['stock-adjustments', 'list', {}, tenant, 'company-2'] })).toBe(
      false,
    )
  })

  it('does NOT match an unrelated namespace', () => {
    const predicate = stockAdjustmentsInvalidationPredicate(tenant, company)

    expect(predicate({ queryKey: ['stock-levels', 'list', tenant, company] })).toBe(false)
    // And the picker lookups now live under their OWN resource literals, so
    // posting an adjustment no longer refetches locations, products and every
    // lot list.
    expect(predicate({ queryKey: ['locations', 'options', tenant, company] })).toBe(false)
    expect(predicate({ queryKey: ['products', 'options', tenant, company] })).toBe(false)
    expect(predicate({ queryKey: ['batch-stock', 'prod-1', tenant, company] })).toBe(false)
  })

  it('reaches the two inventory caches an adjustment actually changes', () => {
    // Posting writes stock_levels and stock_movements, so both must be
    // invalidated — through inventory's own predicates, not a bare prefix.
    expect(
      stockLevelsInvalidationPredicate(tenant, company)({
        queryKey: ['stock-levels', '', { locScope: 'all' }, tenant, company],
      }),
    ).toBe(true)
    expect(
      stockMovementsInvalidationPredicate(tenant, company)({
        queryKey: ['stock-movements', '', 'all', 1, 25, { locScope: 'all' }, tenant, company],
      }),
    ).toBe(true)
  })

  it('is inert while the stores are mid-hydration', () => {
    const predicate = stockAdjustmentsInvalidationPredicate(null, null)
    expect(predicate({ queryKey: ['stock-adjustments', 'list', {}, tenant, company] })).toBe(false)
  })
})
