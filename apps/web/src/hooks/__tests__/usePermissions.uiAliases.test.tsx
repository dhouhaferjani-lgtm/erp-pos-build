import { act, renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { useAuthStore } from '../../stores/authStore'
import * as permissionsModule from '../usePermissions'

const baseUser = {
  id: 'user-1',
  name: 'Test User',
  email: 'test@example.com',
  tenant_id: 'tenant-1',
  email_verified_at: null,
}

afterEach(() => {
  act(() => {
    useAuthStore.getState().logout()
  })
})

describe('usePermissions UI aliases', () => {
  it('exports only the eight approved UI grouping gates with unchanged role lists', () => {
    const exports = permissionsModule as Record<string, unknown>

    expect(exports['UI_ALIAS_PERMISSIONS']).toEqual({
      'dashboard.view': ['admin', 'sales', 'purchases', 'inventory', 'treasury', 'accountant', 'manager', 'user'],
      'sales.view': ['admin', 'sales', 'manager'],
      'sales.create': ['admin', 'sales', 'manager'],
      'purchases.view': ['admin', 'purchases', 'manager'],
      'purchases.create': ['admin', 'purchases', 'manager'],
      'services.view': ['admin', 'sales', 'manager'],
      'inventory.create': ['admin', 'inventory', 'manager'],
      'treasury.create': ['admin', 'treasury', 'accountant', 'manager'],
    })
  })

  it('resolves server grants before generated grants and UI aliases', () => {
    useAuthStore.getState().setUser({
      ...baseUser,
      roles: [],
      permissions: ['sales.create'],
    })

    const { result } = renderHook(() => permissionsModule.usePermissions())

    expect(result.current.hasPermission('sales.create')).toBe(true)
  })

  it('uses generated seeder grants for backend permissions', () => {
    useAuthStore.getState().setUser({
      ...baseUser,
      roles: ['cashier'],
    })

    const { result } = renderHook(() => permissionsModule.usePermissions())

    expect(result.current.hasPermission('inventory.view')).toBe(true)
  })

  it('preserves alias visibility while removing legacy roles from backend fallbacks', () => {
    useAuthStore.getState().setUser({
      ...baseUser,
      roles: ['manager'],
    })

    const { result } = renderHook(() => permissionsModule.usePermissions())

    expect(result.current.hasPermission('sales.create')).toBe(true)
    expect(result.current.hasPermission('reports.view')).toBe(false)

    act(() => {
      useAuthStore.getState().setUser({
        ...baseUser,
        roles: ['sales'],
      })
    })

    const legacyRoleResult = renderHook(() => permissionsModule.usePermissions())
    expect(legacyRoleResult.result.current.hasPermission('contacts.view')).toBe(false)
  })
})
