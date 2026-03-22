import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useAuthStore } from '../../stores/authStore'

vi.mock('laravel-echo', () => {
  const MockEcho = vi.fn().mockImplementation(() => ({
    disconnect: vi.fn(),
  }))
  return { default: MockEcho }
})

vi.mock('pusher-js', () => ({
  default: vi.fn(),
}))

import { getEcho, disconnectEcho } from '../echo'

describe('Echo lifecycle', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // Reset global Echo BEFORE logout to avoid the store subscription
    // calling disconnectEcho on a stale Echo instance from a previous test
    window.Echo = null
    useAuthStore.getState().logout()
  })

  it('creates Echo instance with current token', () => {
    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'my-token')

    const echo = getEcho()
    expect(echo).toBeDefined()
    expect(window.Echo).toBe(echo)
  })

  it('disconnects Echo when token changes from null to string', () => {
    const echo = getEcho()
    const disconnectSpy = vi.spyOn(echo, 'disconnect')

    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'new-token')

    expect(window.Echo).toBeNull()
    expect(disconnectSpy).toHaveBeenCalled()
  })

  it('disconnects Echo when token changes from string to null (logout)', () => {
    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'my-token')

    const echo = getEcho()
    const disconnectSpy = vi.spyOn(echo, 'disconnect')

    useAuthStore.getState().logout()

    expect(window.Echo).toBeNull()
    expect(disconnectSpy).toHaveBeenCalled()
  })

  it('does NOT disconnect Echo when same token is set', () => {
    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'same-token')

    const echo = getEcho()
    const disconnectSpy = vi.spyOn(echo, 'disconnect')

    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'same-token')

    expect(disconnectSpy).not.toHaveBeenCalled()
    expect(window.Echo).toBe(echo)
  })

  it('creates new Echo with correct token after disconnect', () => {
    getEcho()

    useAuthStore.getState().setAuth({
      id: 'u1', name: 'Test', email: 'test@test.com',
      tenant_id: 't1', roles: ['admin'], email_verified_at: null,
    }, 'new-token')

    const newEcho = getEcho()
    expect(newEcho).toBeDefined()
    expect(window.Echo).toBe(newEcho)
  })
})
