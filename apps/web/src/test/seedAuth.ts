import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

/**
 * Seed the auth + company zustand stores with default authenticated test
 * values. Use in `beforeEach` for tests whose queries gate on
 * `useAuthStore.getState().isAuthenticated` and a populated
 * `currentCompanyId` via {@see tenantScopedKey}.
 *
 * The sweep merge added these gates to most useQuery hooks. Tests that
 * predate the sweep (and therefore did not pre-authenticate) end up with
 * disabled queries and no loading/data state. Calling `seedAuth()` opens
 * the gates so the test behaves like a logged-in production session.
 *
 * Tests that explicitly assert on unauthenticated state should NOT call
 * this; they should rely on the existing fallback (auth/company stores
 * remain empty) or call {@see resetAuth}.
 */
export function seedAuth(overrides?: {
  tenantId?: string
  companyId?: string
  userId?: string
  email?: string
  roles?: string[]
  permissions?: string[]
}): void {
  const tenantId = overrides?.tenantId ?? 'test-tenant-id'
  const companyId = overrides?.companyId ?? 'test-company-id'
  const userId = overrides?.userId ?? 'test-user-id'
  const email = overrides?.email ?? 'test@example.com'
  const roles = overrides?.roles ?? []
  const permissions = overrides?.permissions

  useAuthStore.setState({
    user: {
      id: userId,
      name: 'Test User',
      email,
      tenant_id: tenantId,
      roles,
      ...(permissions === undefined ? {} : { permissions }),
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })

  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

/**
 * Clear the auth + company stores back to logged-out defaults. Use in
 * `afterEach` so subsequent tests start from a clean slate (zustand stores
 * are module-level singletons and persist between tests by default).
 */
export function resetAuth(): void {
  useAuthStore.setState({
    user: null,
    token: null,
    isAuthenticated: false,
    isLoading: false,
  })

  useCompanyStore.setState({
    currentCompanyId: null,
    companies: [],
    isLoading: false,
  })
}
