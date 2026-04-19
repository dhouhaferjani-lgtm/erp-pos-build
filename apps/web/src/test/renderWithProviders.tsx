import type { ReactElement, ReactNode } from 'react'
import { render, type RenderOptions, type RenderResult } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { ProductConfigProvider } from '@/contexts/ProductConfigContext'
import { CompanyConfigProvider } from '@/contexts/CompanyConfigContext'
import { defaultProductConfig, type TestProductConfig } from './fixtures/productConfig'
import { defaultCompanyConfig, type TestCompanyConfig } from './fixtures/companyConfig'

/**
 * Options for `renderWithProviders`.
 *
 * - `route`: initial entry for `MemoryRouter`.
 * - `queryClient`: override the default in-memory client (useful when a test
 *   needs to inspect cache or pre-seed additional queries).
 * - `productConfig`: seed for `ProductConfigProvider` (forwarded as the
 *   `initialProduct` prop).
 * - `companyConfig`: seed pre-populated into the query cache under the
 *   `company-config` key so `useCompanyConfig()` resolves synchronously.
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
  queryClient.setQueryData(['company-config'], companyConfig)

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
