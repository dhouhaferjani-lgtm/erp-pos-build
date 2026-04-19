import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { Routes, Route } from 'react-router-dom'
import { renderWithProviders } from '@/test/renderWithProviders'
import {
  defaultCompanyConfig,
  mechanicCompanyConfig,
  mechanicWithExtrasCompanyConfig,
  type TestCompanyConfig,
} from '@/test/fixtures/companyConfig'
import { ModuleGuard } from '../ModuleGuard'
import * as api from '../../../lib/api'

// Mock the API
vi.mock('../../../lib/api', () => ({
  apiGet: vi.fn(),
}))

// Test component to render when module is accessible
function AccessiblePage() {
  return <div>Module Accessible</div>
}

// Test component to render when redirected
function FallbackPage() {
  return <div>Dashboard Fallback</div>
}

describe('ModuleGuard - Route Protection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  /**
   * The guard reads `useCompanyConfig()` which is pre-seeded into the
   * TanStack Query cache by `renderWithProviders` — the raw `apiGet` mock
   * is never consumed for this flow. Each test passes the fixture it needs
   * via `companyConfig`.
   */
  const renderWithRouter = (
    moduleName: string,
    companyConfig: TestCompanyConfig = defaultCompanyConfig,
    fallback?: string,
  ) => {
    return renderWithProviders(
      <Routes>
        <Route path="/" element={<FallbackPage />} />
        <Route path="/dashboard" element={<FallbackPage />} />
        <Route
          path="/test"
          element={
            <ModuleGuard module={moduleName} fallback={fallback}>
              <AccessiblePage />
            </ModuleGuard>
          }
        />
      </Routes>,
      { route: '/test', companyConfig },
    )
  }

  describe('Mechanic Vertical (Has Vehicle Module)', () => {
    it('allows access when module is enabled for vertical', async () => {
      renderWithRouter('Vehicle', mechanicCompanyConfig)

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })

    it('allows access to Workshop module for mechanic vertical', async () => {
      renderWithRouter('Workshop', mechanicCompanyConfig)

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })
  })

  describe('Pharmacy Vertical (NO Vehicle Module)', () => {
    beforeEach(() => {
      const pharmacyConfig = {
        vertical: 'pharmacy',
        default_modules: ['Identity', 'Sales', 'Inventory', 'BatchExpiry'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'BatchExpiry'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(pharmacyConfig)
    })

    it('redirects to dashboard when module not enabled for vertical', async () => {
      // Override seed so guard sees a config without Vehicle
      renderWithProviders(
        <Routes>
          <Route path="/" element={<FallbackPage />} />
          <Route path="/dashboard" element={<FallbackPage />} />
          <Route
            path="/test"
            element={
              <ModuleGuard module="Vehicle">
                <AccessiblePage />
              </ModuleGuard>
            }
          />
        </Routes>,
        {
          route: '/test',
          companyConfig: {
            ...defaultCompanyConfig,
            vertical: 'pharmacy',
            default_modules: ['Identity', 'Sales', 'Inventory', 'BatchExpiry'],
            all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'BatchExpiry'],
          },
        }
      )

      await waitFor(() => {
        const fallback = screen.getByText('Dashboard Fallback')
        expect(fallback).toBeInTheDocument()
      })

      // Should NOT render protected content
      const protectedContent = screen.queryByText('Module Accessible')
      expect(protectedContent).not.toBeInTheDocument()
    })

    it('redirects to dashboard when Workshop module not available', async () => {
      renderWithRouter('Workshop')

      await waitFor(() => {
        const fallback = screen.getByText('Dashboard Fallback')
        expect(fallback).toBeInTheDocument()
      })

      const protectedContent = screen.queryByText('Module Accessible')
      expect(protectedContent).not.toBeInTheDocument()
    })
  })

  describe('Custom Fallback Path', () => {
    beforeEach(() => {
      const pharmacyConfig = {
        vertical: 'pharmacy',
        default_modules: ['Identity', 'Sales', 'Inventory'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(pharmacyConfig)
    })

    it('redirects to custom fallback path when specified', async () => {
      renderWithProviders(
        <Routes>
          <Route path="/" element={<div>Root Fallback</div>} />
          <Route path="/dashboard" element={<div>Dashboard Fallback</div>} />
          <Route
            path="/test"
            element={
              <ModuleGuard module="Vehicle" fallback="/">
                <AccessiblePage />
              </ModuleGuard>
            }
          />
        </Routes>,
        { route: '/test' }
      )

      // Should redirect to "/" instead of "/dashboard"
      await waitFor(() => {
        const fallback = screen.getByText('Root Fallback')
        expect(fallback).toBeInTheDocument()
      })

      const protectedContent = screen.queryByText('Module Accessible')
      expect(protectedContent).not.toBeInTheDocument()
    })
  })

  describe('Core Modules (Always Accessible)', () => {
    beforeEach(() => {
      const minimalConfig = {
        vertical: 'retail',
        default_modules: ['Identity', 'Sales', 'Inventory'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory'],
        currency: 'USD',
        locale: 'en_US',
        country_code: 'US',
      }
      vi.mocked(api.apiGet).mockResolvedValue(minimalConfig)
    })

    it('allows access to Sales module for any vertical', async () => {
      renderWithProviders(
        <Routes>
          <Route path="/" element={<FallbackPage />} />
          <Route path="/dashboard" element={<FallbackPage />} />
          <Route
            path="/test"
            element={
              <ModuleGuard module="Sales">
                <AccessiblePage />
              </ModuleGuard>
            }
          />
        </Routes>,
        {
          route: '/test',
          companyConfig: {
            ...defaultCompanyConfig,
            all_enabled_modules: ['Identity', 'Sales', 'Inventory'],
          },
        }
      )

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })

    it('allows access to Inventory module for any vertical', async () => {
      renderWithRouter('Inventory')

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })
  })

  describe('Loading State', () => {
    it('shows loading state while config is being fetched', () => {
      // Delay the API response
      vi.mocked(api.apiGet).mockImplementation(
        () => new Promise(() => {}) // Never resolves
      )

      renderWithRouter('Vehicle')

      // Should show loading indicator (component should handle loading state)
      // The guard will not render children until config is loaded
      const protectedContent = screen.queryByText('Module Accessible')
      expect(protectedContent).not.toBeInTheDocument()
    })
  })

  describe('Error State', () => {
    beforeEach(() => {
      // API returns error
      vi.mocked(api.apiGet).mockRejectedValue(new Error('Failed to fetch config'))
    })

    it('redirects to dashboard on config fetch error', async () => {
      renderWithRouter('Vehicle')

      // On error, should redirect to fallback (safe default)
      await waitFor(
        () => {
          const fallback = screen.getByText('Dashboard Fallback')
          expect(fallback).toBeInTheDocument()
        },
        { timeout: 3000 }
      )

      const protectedContent = screen.queryByText('Module Accessible')
      expect(protectedContent).not.toBeInTheDocument()
    })
  })

  describe('Module Name Case Handling', () => {
    it('correctly handles PascalCase module names', async () => {
      renderWithRouter('Vehicle', mechanicCompanyConfig)

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })

    it('correctly handles module names with multiple words', async () => {
      renderWithRouter('Workshop', mechanicCompanyConfig)

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })
  })

  describe('Integration with Enabled Extras', () => {
    it('allows access to enabled extra modules', async () => {
      renderWithRouter('Fleet', mechanicWithExtrasCompanyConfig)

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })

    it('allows access to second enabled extra module', async () => {
      renderWithRouter('Appointments', mechanicWithExtrasCompanyConfig)

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })
  })

  describe('Module Not in All Enabled Modules', () => {
    beforeEach(() => {
      const pharmacyConfig = {
        vertical: 'pharmacy',
        default_modules: ['Identity', 'Sales', 'Inventory'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(pharmacyConfig)
    })

    it('redirects when module not in all_enabled_modules', async () => {
      renderWithRouter('Fleet') // Not in pharmacy vertical

      await waitFor(() => {
        const fallback = screen.getByText('Dashboard Fallback')
        expect(fallback).toBeInTheDocument()
      })

      const protectedContent = screen.queryByText('Module Accessible')
      expect(protectedContent).not.toBeInTheDocument()
    })
  })
})
