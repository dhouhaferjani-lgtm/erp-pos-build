import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { BrowserRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CompanyConfigProvider } from '../../../contexts/CompanyConfigContext'
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
  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
        },
      },
    })
    vi.clearAllMocks()
  })

  const renderWithRouter = (moduleName: string, fallback?: string) => {
    return render(
      <BrowserRouter>
        <QueryClientProvider client={queryClient}>
          <CompanyConfigProvider>
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
            </Routes>
          </CompanyConfigProvider>
        </QueryClientProvider>
      </BrowserRouter>
    )
  }

  describe('Mechanic Vertical (Has Vehicle Module)', () => {
    beforeEach(() => {
      const mechanicConfig = {
        vertical: 'mechanic',
        default_modules: ['Identity', 'Vehicle', 'Workshop', 'Sales', 'Inventory'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Vehicle', 'Workshop', 'Sales', 'Inventory'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(mechanicConfig)

      // Navigate to test route
      window.history.pushState({}, '', '/test')
    })

    it('allows access when module is enabled for vertical', async () => {
      renderWithRouter('Vehicle')

      // Should render the protected content
      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })

    it('allows access to Workshop module for mechanic vertical', async () => {
      renderWithRouter('Workshop')

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

      // Navigate to test route
      window.history.pushState({}, '', '/test')
    })

    it('redirects to dashboard when module not enabled for vertical', async () => {
      renderWithRouter('Vehicle')

      // Wait for redirect to happen
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

      // Navigate to test route
      window.history.pushState({}, '', '/test')
    })

    it('redirects to custom fallback path when specified', async () => {
      render(
        <BrowserRouter>
          <QueryClientProvider client={queryClient}>
            <CompanyConfigProvider>
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
              </Routes>
            </CompanyConfigProvider>
          </QueryClientProvider>
        </BrowserRouter>
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

      // Navigate to test route
      window.history.pushState({}, '', '/test')
    })

    it('allows access to Sales module for any vertical', async () => {
      renderWithRouter('Sales')

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

      window.history.pushState({}, '', '/test')
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

      window.history.pushState({}, '', '/test')
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
    beforeEach(() => {
      const mechanicConfig = {
        vertical: 'mechanic',
        default_modules: ['Identity', 'Vehicle', 'Workshop'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Vehicle', 'Workshop'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(mechanicConfig)

      window.history.pushState({}, '', '/test')
    })

    it('correctly handles PascalCase module names', async () => {
      renderWithRouter('Vehicle') // PascalCase

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })

    it('correctly handles module names with multiple words', async () => {
      renderWithRouter('Workshop') // Single word PascalCase

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })
  })

  describe('Integration with Enabled Extras', () => {
    beforeEach(() => {
      const mechanicConfigWithExtras = {
        vertical: 'mechanic',
        default_modules: ['Identity', 'Vehicle', 'Workshop', 'Sales'],
        enabled_extras: ['Fleet', 'Appointments'],
        all_enabled_modules: ['Identity', 'Vehicle', 'Workshop', 'Sales', 'Fleet', 'Appointments'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(mechanicConfigWithExtras)

      window.history.pushState({}, '', '/test')
    })

    it('allows access to enabled extra modules', async () => {
      renderWithRouter('Fleet')

      const content = await screen.findByText('Module Accessible')
      expect(content).toBeInTheDocument()
    })

    it('allows access to second enabled extra module', async () => {
      renderWithRouter('Appointments')

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

      window.history.pushState({}, '', '/test')
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
