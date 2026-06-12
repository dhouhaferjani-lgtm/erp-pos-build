import { beforeEach, describe, expect, it } from 'vitest'
import { useAdminAuthStore } from '../stores/adminAuthStore'

const admin = {
  id: 'a1',
  email: 'root@synerivia.test',
  name: 'Root',
  role: 'super_admin' as const,
}

describe('adminAuthStore', () => {
  beforeEach(() => {
    useAdminAuthStore.getState().logout()
    localStorage.clear()
  })

  it('keeps the bearer token in memory after setAuth', () => {
    useAdminAuthStore.getState().setAuth(admin, 'secret-bearer-token')
    expect(useAdminAuthStore.getState().token).toBe('secret-bearer-token')
    expect(useAdminAuthStore.getState().isAuthenticated).toBe(true)
  })

  it('never persists the token to localStorage', () => {
    useAdminAuthStore.getState().setAuth(admin, 'secret-bearer-token')
    const persisted = localStorage.getItem('admin-auth-storage') ?? ''
    // Assert that the blob exists and contains the admin email (proving persistence
    // actually ran — not a vacuous pass because nothing was written at all)
    expect(persisted).toContain('root@synerivia.test')
    // Token must be absent from the persisted blob
    expect(persisted).not.toContain('secret-bearer-token')
  })

  it('clears the token on logout', () => {
    useAdminAuthStore.getState().setAuth(admin, 'secret-bearer-token')
    useAdminAuthStore.getState().logout()
    expect(useAdminAuthStore.getState().token).toBeNull()
    expect(useAdminAuthStore.getState().isAuthenticated).toBe(false)
  })
})
