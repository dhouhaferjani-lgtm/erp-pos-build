import type { ReactElement, ReactNode } from 'react'
import { render, type RenderOptions, type RenderResult } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { ProductConfigProvider } from '@/contexts/ProductConfigContext'
import { CompanyConfigProvider } from '@/contexts/CompanyConfigContext'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { defaultProductConfig, type TestProductConfig } from './fixtures/productConfig'
import { defaultCompanyConfig, type TestCompanyConfig } from './fixtures/companyConfig'

/**
 * Options for `renderWithProviders`.
 *
 * - `route`: initial entry for `MemoryRouter`.
 * - `queryClient`: override the default in-memory client (useful when a test
 *   needs to inspect cache or pre-seed additional queries). Note: the helper
 *   will call `setQueryData(tenantScopedKey(['company-config']), companyConfig)`
 *   on whichever client you pass, mutating it. If you rely on cache isolation,
 *   use the default.
 * - `productConfig`: seed for `ProductConfigProvider` (forwarded as the
 *   `initialProduct` prop).
 * - `companyConfig`: seed pre-populated into the query cache under the
 *   tenant-scoped `company-config` key so `useCompanyConfig()` resolves
 *   synchronously.
 *   Caveats:
 *   (a) The helper does not mock `apiGet`. Any refetch (e.g. via
 *       `invalidateQueries` or a hook that calls `apiGet('/company/config')`
 *       without its own mock) will replace the seed with the real network
 *       response.
 *   (b) `isAuthenticated` defaults to `false` in tests; tests that need an
 *       authenticated user / a populated company id are expected to call
 *       `useAuthStore.setState(...)` / `useCompanyStore.setState(...)`
 *       themselves (typically in beforeEach). The helper does not seed those
 *       stores so the tenant-scope tests that explicitly assert on null/
 *       empty state keep working.
 *
 * Extends RTL `RenderOptions` minus `wrapper`, which this helper owns.
 */
export interface RenderWithProvidersOptions extends Omit<RenderOptions, 'wrapper'> {
  route?: string
  queryClient?: QueryClient
  productConfig?: TestProductConfig
  companyConfig?: TestCompanyConfig
}

/**
 * Create a QueryClient tuned for tests: no retries, zero gc time, and
 * `throwOnError` left default so failures surface as hook errors rather than
 * silent pending states.
 */
export function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0 },
      mutations: { retry: false },
    },
  })
}

/**
 * Render a component inside the same provider tree as `App.tsx`, seeded with
 * deterministic test data for `ProductConfigProvider` and
 * `CompanyConfigProvider`.
 *
 * AuthProvider / CompanyProvider / LocationProvider are intentionally omitted:
 * those bring in their own network dependencies and most tests only need
 * ProductConfig + CompanyConfig. If a test needs them, it should compose
 * explicitly; this helper stays minimal.
 *
 * Auth/company store coupling (F.5, addresses Round 1 P2-4):
 *
 *   The `tenantScopedKey([...])` cache key used here resolves the active
 *   tenant_id + company_id from `useAuthStore` and `useCompanyStore` (the
 *   zustand stores). Tests that need a populated tenant/company scope
 *   should seed those stores via `seedAuth()` in `beforeEach` and reset
 *   via `resetAuth()` in `afterEach` — see `@/test/seedAuth` and the
 *   M1.5b/A.4 test files for the canonical pattern. Without seeding,
 *   the scoped key resolves to `[..., null, null]`, which matches the
 *   pre-auth fixture this helper pre-seeds; both branches work, but
 *   anything that calls a useQuery hook gated on `useAuthStore.getState().isAuthenticated`
 *   needs `seedAuth()` to open the gate.
 */
export function renderWithProviders(
  ui: ReactElement,
  {
    route = '/',
    queryClient = createTestQueryClient(),
    productConfig = defaultProductConfig,
    companyConfig = defaultCompanyConfig,
    ...renderOptions
  }: RenderWithProvidersOptions = {},
): RenderResult & { queryClient: QueryClient } {
  // Pre-seed the company-config query so CompanyConfigProvider resolves
  // synchronously without hitting the network (and without modifying the
  // provider's production fetch path).
  //
  // CompanyConfigProvider wraps the cache key with tenantScopedKey() so the
  // entry includes the active tenant_id + company_id (or null sentinels in
  // tests where the auth/company stores have not been seeded). We resolve
  // the same scoped key here so the seed lands at the lookup site whether
  // the test sets up authenticated stores or not.
  queryClient.setQueryData(tenantScopedKey(['company-config']), companyConfig)

  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[route]}>
          <ProductConfigProvider initialProduct={productConfig.product}>
            <CompanyConfigProvider>{children}</CompanyConfigProvider>
          </ProductConfigProvider>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }

  return { ...render(ui, { wrapper: Wrapper, ...renderOptions }), queryClient }
}
