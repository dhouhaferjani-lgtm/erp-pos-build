import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useAuthStore } from '../../stores/authStore'
import { handleUnauthorized, __resetRedirectGuard, __setRedirectForTests } from '../api'

// jsdom makes window.location.assign non-configurable, so vi.spyOn throws
// "Cannot redefine property: assign". Per the plan's documented fallback we
// inject the redirect via the exported __setRedirectForTests hook instead.
describe('handleUnauthorized', () => {
  beforeEach(() => {
    __resetRedirectGuard()
    useAuthStore.setState({ user: null, token: 'stale-token', isAuthenticated: true, isLoading: false })
    window.history.pushState({}, '', '/dashboard')
  })

  it('non-auth/me 401 logs out and hard-redirects to /login once', () => {
    const assign = vi.fn()
    __setRedirectForTests(assign)
    handleUnauthorized('/user/companies', 'stale-token')
    handleUnauthorized('/products', 'stale-token')
    expect(useAuthStore.getState().token).toBeNull()
    expect(assign).toHaveBeenCalledTimes(1)
    expect(assign).toHaveBeenCalledWith('/login')
  })

  it('does not redirect when already on a public path', () => {
    window.history.pushState({}, '', '/login')
    const assign = vi.fn()
    __setRedirectForTests(assign)
    handleUnauthorized('/user/companies', 'stale-token')
    expect(assign).not.toHaveBeenCalled()
  })

  it('auth/me 401 with the CURRENT token clears it (stale-token cold load)', () => {
    handleUnauthorized('/auth/me', 'stale-token')
    expect(useAuthStore.getState().token).toBeNull()
  })

  it('auth/me 401 with a DIFFERENT token is ignored (registration race)', () => {
    useAuthStore.setState({ token: 'fresh-token' })
    handleUnauthorized('/auth/me', 'old-token')
    expect(useAuthStore.getState().token).toBe('fresh-token')
  })
})
