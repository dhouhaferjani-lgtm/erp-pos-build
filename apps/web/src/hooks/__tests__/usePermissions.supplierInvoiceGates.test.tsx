import { act, renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { useAuthStore } from '../../stores/authStore'
import { PERMISSIONS, usePermissions } from '../usePermissions'

/**
 * F-W2-14 (gate r1 findings 2 + 3, owner ruling 2026-09-07).
 *
 * `supplier-invoices.manage` and `payments.pay-supplier` are SERVER-AUTHORITATIVE:
 *  - a stale tenant DB (seeder not re-run) must NOT render controls the API will
 *    403 — the static role map may not vouch for them; and
 *  - a user granted one of them DIRECTLY (or through a custom role) must see the
 *    controls even though their role's default is a deny, because the secure
 *    default is a default, not a hard-coded role check.
 */
const baseUser = {
  id: 'user-1',
  name: 'Test User',
  email: 'test@example.com',
  tenant_id: 'tenant-1',
  roles: [] as string[],
  email_verified_at: null,
}

afterEach(() => {
  act(() => {
    useAuthStore.getState().logout()
  })
})

describe('supplier-invoice / supplier-payment permission gates', () => {
  it('seeds the secure default: manager, admin and accountant only', () => {
    expect(PERMISSIONS['supplier-invoices.manage']).toEqual(['accountant', 'admin', 'manager'])
    expect(PERMISSIONS['payments.pay-supplier']).toEqual(['accountant', 'admin', 'manager'])
    expect(PERMISSIONS['supplier-invoices.manage']).not.toContain('operator')
    expect(PERMISSIONS['supplier-invoices.manage']).not.toContain('cashier')
    expect(PERMISSIONS['payments.pay-supplier']).not.toContain('cashier')
    // ... while the coarse permissions they replace on those routes ARE held by
    // a cashier — which is why the dedicated gates exist.
    expect(PERMISSIONS['documents.update']).toContain('cashier')
    expect(PERMISSIONS['payments.create']).toContain('cashier')
  })

  it('fails closed for a manager whose tenant has not been re-seeded', () => {
    useAuthStore.getState().setUser({
      ...baseUser,
      roles: ['manager'],
      permissions: ['documents.update', 'payments.create'],
    })

    const { result } = renderHook(() => usePermissions())

    expect(result.current.hasPermission('supplier-invoices.manage')).toBe(false)
    expect(result.current.hasPermission('payments.pay-supplier')).toBe(false)
  })

  it('honours a direct server grant to an operator', () => {
    useAuthStore.getState().setUser({
      ...baseUser,
      roles: ['operator'],
      permissions: ['documents.update', 'supplier-invoices.manage', 'payments.pay-supplier'],
    })

    const { result } = renderHook(() => usePermissions())

    expect(result.current.hasPermission('supplier-invoices.manage')).toBe(true)
    expect(result.current.hasPermission('payments.pay-supplier')).toBe(true)
  })

  it('denies an operator who was not granted them', () => {
    useAuthStore.getState().setUser({
      ...baseUser,
      roles: ['operator'],
      permissions: ['documents.update', 'payments.create'],
    })

    const { result } = renderHook(() => usePermissions())

    expect(result.current.hasPermission('supplier-invoices.manage')).toBe(false)
    expect(result.current.hasPermission('payments.pay-supplier')).toBe(false)
  })
})
