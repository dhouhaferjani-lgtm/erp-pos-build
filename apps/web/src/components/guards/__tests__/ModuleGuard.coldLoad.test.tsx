import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { ModuleGuard } from '../ModuleGuard'

/**
 * Regression tests for the cold-load / auth-rehydration redirect race.
 *
 * On a direct URL load of a ModuleGuard-protected route, `isAuthenticated`
 * (deliberately NOT persisted, see authStore) is briefly `false` while
 * `/auth/me` is in flight. The company-config query is gated on
 * `isAuthenticated`, so it stays DISABLED — which in TanStack Query v5 means
 * `isLoading === false` and `data === undefined`. The guard must NOT treat
 * that "config not resolved yet" window as "module absent" and bounce valid
 * modules (Batches / Parapharmacy / Loyalty …) to /dashboard.
 *
 * We isolate the guard by mocking `useCompanyConfig` so we can drive the exact
 * {config, isLoading, error} triples the provider can produce.
 */
const mockUseCompanyConfig = vi.fn()
vi.mock('../../../contexts', () => ({
  useCompanyConfig: () => mockUseCompanyConfig(),
}))

function renderGuard(module = 'BatchExpiry') {
  return render(
    <MemoryRouter initialEntries={['/test']}>
      <Routes>
        <Route path="/dashboard" element={<div>Dashboard Fallback</div>} />
        <Route
          path="/test"
          element={
            <ModuleGuard module={module}>
              <div>Module Accessible</div>
            </ModuleGuard>
          }
        />
      </Routes>
    </MemoryRouter>,
  )
}

describe('ModuleGuard — cold-load / config-unresolved race', () => {
  beforeEach(() => {
    mockUseCompanyConfig.mockReset()
  })

  it('HOLDS (does not redirect) while config is unresolved (query disabled: isLoading false, config null, no error)', () => {
    mockUseCompanyConfig.mockReturnValue({
      config: null,
      isLoading: false,
      error: null,
      hasModule: () => false,
    })

    renderGuard()

    // The bug: guard redirected to /dashboard here. It must hold instead.
    expect(screen.queryByText('Dashboard Fallback')).not.toBeInTheDocument()
    // And of course it must not leak the protected content before config loads.
    expect(screen.queryByText('Module Accessible')).not.toBeInTheDocument()
  })

  it('HOLDS while config is actively loading', () => {
    mockUseCompanyConfig.mockReturnValue({
      config: null,
      isLoading: true,
      error: null,
      hasModule: () => false,
    })

    renderGuard()

    expect(screen.queryByText('Dashboard Fallback')).not.toBeInTheDocument()
    expect(screen.queryByText('Module Accessible')).not.toBeInTheDocument()
  })

  it('renders children once config resolves and the module is enabled', () => {
    mockUseCompanyConfig.mockReturnValue({
      config: { all_enabled_modules: ['BatchExpiry'] },
      isLoading: false,
      error: null,
      hasModule: (m: string) => ['BatchExpiry'].includes(m),
    })

    renderGuard()

    expect(screen.getByText('Module Accessible')).toBeInTheDocument()
  })

  it('redirects once config resolves and the module is absent', () => {
    mockUseCompanyConfig.mockReturnValue({
      config: { all_enabled_modules: ['Sales'] },
      isLoading: false,
      error: null,
      hasModule: (m: string) => ['Sales'].includes(m),
    })

    renderGuard()

    expect(screen.getByText('Dashboard Fallback')).toBeInTheDocument()
  })

  it('redirects on a genuine config fetch error', () => {
    mockUseCompanyConfig.mockReturnValue({
      config: null,
      isLoading: false,
      error: new Error('boom'),
      hasModule: () => false,
    })

    renderGuard()

    expect(screen.getByText('Dashboard Fallback')).toBeInTheDocument()
  })
})
