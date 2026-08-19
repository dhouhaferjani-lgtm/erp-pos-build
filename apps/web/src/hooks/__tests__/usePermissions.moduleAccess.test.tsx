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
