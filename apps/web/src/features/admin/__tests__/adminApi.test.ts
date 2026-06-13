import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AxiosError, isAxiosError, type InternalAxiosRequestConfig } from 'axios'
import { adminApi } from '../lib/adminApi'
import { useAdminAuthStore } from '../stores/adminAuthStore'

const admin = { id: 'a1', email: 'r@s.test', name: 'Root', role: 'super_admin' as const }

function okAdapter(captured: { config?: InternalAxiosRequestConfig }) {
  return async (config: InternalAxiosRequestConfig) => {
    captured.config = config
    return { data: { data: null }, status: 200, statusText: 'OK', headers: {}, config }
  }
}

describe('adminApi auth interceptors', () => {
  const originalLocation = window.location

  beforeEach(() => {
    useAdminAuthStore.getState().logout()
    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, pathname: '/admin/tenants', assign: vi.fn() },
      writable: true,
    })
  })

  afterEach(() => {
    Object.defineProperty(window, 'location', { value: originalLocation, writable: true })
    delete adminApi.defaults.adapter
  })

  it('attaches Authorization: Bearer when a token is in the store', async () => {
    useAdminAuthStore.getState().setAuth(admin, 'tok-123')
    const captured: { config?: InternalAxiosRequestConfig } = {}
    adminApi.defaults.adapter = okAdapter(captured) as never
    await adminApi.get('/admin/dashboard')
    expect(captured.config?.headers.Authorization).toBe('Bearer tok-123')
  })

  it('sends no Authorization header without a token', async () => {
    const captured: { config?: InternalAxiosRequestConfig } = {}
    adminApi.defaults.adapter = okAdapter(captured) as never
    await adminApi.get('/admin/dashboard')
    expect(captured.config?.headers.Authorization).toBeUndefined()
  })

  it('clears auth and redirects to /admin/login on 401', async () => {
    useAdminAuthStore.getState().setAuth(admin, 'tok-123')
    adminApi.defaults.adapter = (async (config: InternalAxiosRequestConfig) => {
      throw new AxiosError('Unauthenticated', '401', config, null, {
        status: 401, statusText: 'Unauthorized', headers: {}, config, data: {},
      })
    }) as never
    // The original AxiosError must flow through unchanged so downstream
    // getErrorMessage() can still read error.response.
    await expect(adminApi.get('/admin/dashboard')).rejects.toSatisfy(
      (e: unknown) => isAxiosError(e) && e.response?.status === 401
    )
    expect(useAdminAuthStore.getState().token).toBeNull()
    expect(window.location.assign).toHaveBeenCalledWith('/admin/login')
  })

  it('does not redirect when already on the login page', async () => {
    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, pathname: '/admin/login', assign: vi.fn() },
      writable: true,
    })
    adminApi.defaults.adapter = (async (config: InternalAxiosRequestConfig) => {
      throw new AxiosError('Unauthenticated', '401', config, null, {
        status: 401, statusText: 'Unauthorized', headers: {}, config, data: {},
      })
    }) as never
    await expect(adminApi.get('/admin/auth/me')).rejects.toThrow()
    expect(window.location.assign).not.toHaveBeenCalled()
  })
})
