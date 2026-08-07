import { beforeEach, describe, expect, it } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { tenantSupportAccessKeys } from '../hooks/useTenantSupportAccess'

describe('tenant support-access query keys', () => {
  beforeEach(() => {
    useAuthStore.getState().setAuth({
      id: 'user-1', name: 'User', email: 'user@test', tenant_id: 'tenant-key', roles: [],
      permissions: ['support-access.view'], email_verified_at: null, impersonation: null,
    })
  })

  it('stamps every tenant-data key with the authenticated tenant', () => {
    const keys = Object.values(tenantSupportAccessKeys).map((factory) => tenantScopedKey(factory()))
    expect(keys.length).toBeGreaterThan(0)
    for (const key of keys) {
      expect(key).toContain('tenant-key')
    }
  })
})
