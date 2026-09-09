/**
 * T4 (UI-01 / UI-02): `canAccessModule` must fail CLOSED.
 *
 * Before this task the helper returned `true` for any key absent from
 * `MODULE_PERMISSIONS`, so a typo — or a key that never existed, like
 * `parts_catalog` — silently opened the gate for every role. The contract is
 * now: an unrecognised module key denies access, and the key space is a
 * literal union (`ModuleKey`) so a bad key is a compile error first.
 */
import { act, renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { useAuthStore } from '../../stores/authStore'
import { MODULE_PERMISSIONS, usePermissions, type ModuleKey } from '../usePermissions'

const baseUser = {
  id: 'user-1',
  name: 'Test User',
  email: 'test@example.com',
  tenant_id: 'tenant-1',
  email_verified_at: null,
}

function renderWithRoles(roles: string[]) {
  act(() => {
    useAuthStore.getState().setUser({ ...baseUser, roles })
  })

  return renderHook(() => usePermissions())
}

afterEach(() => {
  act(() => {
    useAuthStore.getState().logout()
  })
})

describe('canAccessModule fail-closed contract', () => {
  it('denies an unrecognised module key even for admin', () => {
    const { result } = renderWithRoles(['admin'])

    // Cast is deliberate: the union makes this a compile error at every call
    // site, so the only way to reach the runtime branch is to force it.
    expect(result.current.canAccessModule('parts_catalog' as ModuleKey)).toBe(false)
    expect(result.current.canAccessModule('totally-unknown-module' as ModuleKey)).toBe(false)
  })

  it('still resolves a known key against the role grants', () => {
    const { result } = renderWithRoles(['cashier'])

    expect(result.current.canAccessModule('expenses')).toBe(true)
    expect(result.current.canAccessModule('treasury')).toBe(false)
  })
})

describe('MODULE_PERMISSIONS covers the keys the navigation actually uses', () => {
  it('maps goods-receipt.create-standalone to its own backend permission', () => {
    expect(MODULE_PERMISSIONS['goods-receipt.create-standalone']).toEqual([
      'goods-receipt.create-standalone',
    ])
  })

  it('maps inventory.view to its own backend permission', () => {
    expect(MODULE_PERMISSIONS['inventory.view']).toEqual(['inventory.view'])
  })

  it('gates goods-receipt.create-standalone to admin and manager only', () => {
    const purchases = renderWithRoles(['purchases'])
    expect(purchases.result.current.canAccessModule('goods-receipt.create-standalone')).toBe(false)
    purchases.unmount()

    const manager = renderWithRoles(['manager'])
    expect(manager.result.current.canAccessModule('goods-receipt.create-standalone')).toBe(true)
  })

  it('keeps partners out of the module key space (it is a permission, not a module)', () => {
    expect(Object.keys(MODULE_PERMISSIONS)).not.toContain('partners')
  })
})

/**
 * Gate r3 finding N1: the Sidebar Purchases GROUP gate must be split from the
 * SHARED `purchases` module key, because that shared key is also the sole
 * guard on seven unrelated purchase-order/quote-request ROUTES
 * (routes/index.tsx:896,906,916,926,938,958,982, all `moduleKey="purchases"`
 * with no `permission`). An accountant granted `supplier-invoices.manage`
 * (and `documents.view`, but no `purchases.view`) must see the Sidebar's
 * "Purchases" group (and, inside it, the Supplier-invoices entry) without
 * that also opening the seven purchase-order routes whose only guard is the
 * shared `purchases` key — those still fail closed for them.
 */
describe('Purchases group nav key is split from the shared purchases module key (gate r3 N1)', () => {
  function renderWithPermissions(roles: string[], permissions: string[]) {
    act(() => {
      useAuthStore.getState().setUser({ ...baseUser, roles, permissions })
    })

    return renderHook(() => usePermissions())
  }

  it('MODULE_PERMISSIONS.purchases is NOT widened — it stays the narrow route guard', () => {
    expect(MODULE_PERMISSIONS.purchases).toEqual(['purchases.view'])
  })

  it('nav.purchasesGroup admits supplier-invoices.manage holders', () => {
    expect(MODULE_PERMISSIONS['nav.purchasesGroup']).toEqual([
      'purchases.view',
      'supplier-invoices.manage',
    ])
  })

  it('an accountant with supplier-invoices.manage + documents.view (no purchases.view) sees the Purchases nav group and the Supplier-invoices entry, but does NOT get the seven purchase-order routes', () => {
    const { result } = renderWithPermissions(
      ['accountant'],
      ['documents.view', 'supplier-invoices.manage']
    )

    // Nav: the group itself, and the Supplier-invoices child, both open.
    expect(result.current.canAccessModule('nav.purchasesGroup')).toBe(true)
    expect(result.current.canAccessModule('nav.supplierInvoices')).toBe(true)

    // Routes: the seven purchase-order/quote-request routes gate on the
    // SHARED `purchases` key alone (routes/index.tsx:896,906,916,926,938,958,982)
    // — this accountant must NOT reach them.
    expect(result.current.canAccessModule('purchases')).toBe(false)
  })
})
