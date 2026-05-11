import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { tenantScopedKey } from '../tenantScopedKey'

describe('tenantScopedKey', () => {
  const baseUser = {
    id: 'user-1',
    name: 'Test User',
    email: 'test@otospex.com',
    tenant_id: 'tenant-A',
    roles: ['admin'],
    email_verified_at: '2026-01-01T00:00:00Z',
  }

  beforeEach(() => {
    useAuthStore.setState({
      user: baseUser,
      token: 'tok-test',
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({
      currentCompanyId: 'company-1',
      companies: [],
      isLoading: false,
    })
  })

  afterEach(() => {
    useAuthStore.setState({
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: true,
    })
    useCompanyStore.setState({
      currentCompanyId: null,
      companies: [],
      isLoading: true,
    })
  })

  it('appends tenant_id and currentCompanyId to the segments', () => {
    const key = tenantScopedKey(['payment-methods'])

    expect(key).toEqual(['payment-methods', 'tenant-A', 'company-1'])
  })

  it('preserves multi-segment input order', () => {
    const key = tenantScopedKey(['products', 'list', { search: 'foo', page: 2 }])

    expect(key).toEqual([
      'products',
      'list',
      { search: 'foo', page: 2 },
      'tenant-A',
      'company-1',
    ])
  })

  it('emits null for tenant when authStore.user is unset (pre-login / mid-hydration)', () => {
    useAuthStore.setState({
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: false,
    })

    const key = tenantScopedKey(['orders'])

    expect(key).toEqual(['orders', null, 'company-1'])
  })

  it('emits null for company when companyStore.currentCompanyId is unset', () => {
    useCompanyStore.setState({
      currentCompanyId: null,
      companies: [],
      isLoading: false,
    })

    const key = tenantScopedKey(['orders'])

    expect(key).toEqual(['orders', 'tenant-A', null])
  })

  it('emits both nulls when both stores are empty', () => {
    useAuthStore.setState({
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: true,
    })
    useCompanyStore.setState({
      currentCompanyId: null,
      companies: [],
      isLoading: true,
    })

    const key = tenantScopedKey(['anything'])

    expect(key).toEqual(['anything', null, null])
  })

  it('produces different keys when tenant_id changes (cache-invalidation invariant)', () => {
    const before = tenantScopedKey(['users'])

    useAuthStore.setState({
      user: { ...baseUser, tenant_id: 'tenant-B' },
      token: 'tok-test',
      isAuthenticated: true,
      isLoading: false,
    })

    const after = tenantScopedKey(['users'])

    expect(before).not.toEqual(after)
    expect(after).toEqual(['users', 'tenant-B', 'company-1'])
  })

  it('produces different keys when currentCompanyId changes (cache-invalidation invariant)', () => {
    const before = tenantScopedKey(['users'])

    useCompanyStore.setState({
      currentCompanyId: 'company-2',
      companies: [],
      isLoading: false,
    })

    const after = tenantScopedKey(['users'])

    expect(before).not.toEqual(after)
    expect(after).toEqual(['users', 'tenant-A', 'company-2'])
  })

  it('is stable for the same input + same store state (idempotency)', () => {
    const a = tenantScopedKey(['x', 1, true])
    const b = tenantScopedKey(['x', 1, true])

    expect(a).toEqual(b)
  })

  it('accepts an empty segment array', () => {
    const key = tenantScopedKey([])

    expect(key).toEqual(['tenant-A', 'company-1'])
  })
})
