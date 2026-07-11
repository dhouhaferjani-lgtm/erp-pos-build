import { act, renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { useAuthStore } from '../../stores/authStore'
import { usePermissions } from '../usePermissions'

const baseUser = {
  id: 'user-1',
  name: 'Test User',
  email: 'test@example.com',
  tenant_id: 'tenant-1',
  roles: ['manager'],
  email_verified_at: null,
}

afterEach(() => {
  act(() => {
    useAuthStore.getState().logout()
  })
})

describe('usePermissions auth payload', () => {
  it('treats the server permission list as authoritative', () => {
    useAuthStore.getState().setUser({
      ...baseUser,
      permissions: ['inventory.view'],
    })

    const { result } = renderHook(() => usePermissions())

    expect(result.current.hasPermission('inventory.view')).toBe(true)
    expect(result.current.hasPermission('inventory.edit')).toBe(true)
    expect(result.current.hasPermission('pricing.view_cost_prices')).toBe(false)
  })

  it('fails cost visibility closed while keeping legacy route-map access for older sessions', () => {
    useAuthStore.getState().setUser(baseUser)

    const { result } = renderHook(() => usePermissions())

    expect(result.current.hasPermission('inventory.edit')).toBe(true)
    expect(result.current.hasPermission('pricing.view_cost_prices')).toBe(false)
  })
})
