import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CompanyConfigProvider } from '../../../../contexts/CompanyConfigContext'
import { Sidebar } from '../Sidebar'
import * as api from '../../../../lib/api'

// Mock the API
vi.mock('../../../../lib/api', () => ({
  apiGet: vi.fn(),
}))

// Mock usePermissions hook
const mockCanAccessModule = vi.fn()
vi.mock('../../../../hooks/usePermissions', () => ({
  usePermissions: () => ({
    canAccessModule: mockCanAccessModule,
  }),
}))

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('Sidebar - Vertical-Based Navigation Filtering', () => {
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

    // Default: All permissions granted (we're testing vertical filtering, not permissions)
    mockCanAccessModule.mockReturnValue(true)
  })

  const renderSidebar = () => {
    return render(
      <BrowserRouter>
        <QueryClientProvider client={queryClient}>
          <CompanyConfigProvider>
            <Sidebar isOpen={true} />
          </CompanyConfigProvider>
        </QueryClientProvider>
      </BrowserRouter>
    )
  }

  describe('Mechanic Vertical (Automotive)', () => {
    beforeEach(() => {
      const mechanicConfig = {
        vertical: 'mechanic',
        default_modules: ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Workshop', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Workshop', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(mechanicConfig)
    })

    it('shows Vehicle module for mechanic vertical', async () => {
      renderSidebar()

      // Wait for config to load
      const vehiclesLink = await screen.findByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).toBeInTheDocument()
      expect(vehiclesLink).toHaveAttribute('href', '/vehicles')
    })

    it('shows Workshop (Services) module for mechanic vertical', async () => {
      renderSidebar()

      // Wait for config to load
      const servicesButton = await screen.findByRole('button', { name: /navigation\.services/i })
      expect(servicesButton).toBeInTheDocument()
    })

    it('shows core modules (Sales, Inventory, Treasury)', async () => {
      renderSidebar()

      // Wait for config to load and check core modules
      const salesButton = await screen.findByRole('button', { name: /navigation\.sales/i })
      const inventoryButton = await screen.findByRole('button', { name: /navigation\.inventory/i })
      const treasuryButton = await screen.findByRole('button', { name: /navigation\.treasury/i })

      expect(salesButton).toBeInTheDocument()
      expect(inventoryButton).toBeInTheDocument()
      expect(treasuryButton).toBeInTheDocument()
    })
  })

  describe('Pharmacy Vertical', () => {
    beforeEach(() => {
      const pharmacyConfig = {
        vertical: 'pharmacy',
        default_modules: ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'BatchExpiry'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'BatchExpiry'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(pharmacyConfig)
    })

    it('hides Vehicle module for pharmacy vertical', async () => {
      renderSidebar()

      // Wait for config to load
      await screen.findByRole('button', { name: /navigation\.sales/i })

      // Vehicle module should NOT be present
      const vehiclesLink = screen.queryByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).not.toBeInTheDocument()
    })

    it('hides Workshop (Services) module for pharmacy vertical', async () => {
      renderSidebar()

      // Wait for config to load
      await screen.findByRole('button', { name: /navigation\.sales/i })

      // Services module should NOT be present
      const servicesButton = screen.queryByRole('button', { name: /navigation\.services/i })
      expect(servicesButton).not.toBeInTheDocument()
    })

    it('shows core modules (Sales, Inventory, Treasury) for pharmacy vertical', async () => {
      renderSidebar()

      // Wait for config to load and check core modules
      const salesButton = await screen.findByRole('button', { name: /navigation\.sales/i })
      const inventoryButton = await screen.findByRole('button', { name: /navigation\.inventory/i })
      const treasuryButton = await screen.findByRole('button', { name: /navigation\.treasury/i })

      expect(salesButton).toBeInTheDocument()
      expect(inventoryButton).toBeInTheDocument()
      expect(treasuryButton).toBeInTheDocument()
    })
  })

  describe('Restaurant Vertical', () => {
    beforeEach(() => {
      const restaurantConfig = {
        vertical: 'restaurant',
        default_modules: ['Identity', 'Tenant', 'Catalog', 'Menu', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
        enabled_extras: ['Tables', 'Appointments'],
        all_enabled_modules: ['Identity', 'Tenant', 'Catalog', 'Menu', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'Tables', 'Appointments'],
        currency: 'EUR',
        locale: 'fr_FR',
        country_code: 'FR',
      }
      vi.mocked(api.apiGet).mockResolvedValue(restaurantConfig)
    })

    it('hides Vehicle module for restaurant vertical', async () => {
      renderSidebar()

      // Wait for config to load
      await screen.findByRole('button', { name: /navigation\.sales/i })

      // Vehicle module should NOT be present
      const vehiclesLink = screen.queryByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).not.toBeInTheDocument()
    })

    it('shows core modules for restaurant vertical', async () => {
      renderSidebar()

      // Wait for config to load and check core modules
      const salesButton = await screen.findByRole('button', { name: /navigation\.sales/i })
      const inventoryButton = await screen.findByRole('button', { name: /navigation\.inventory/i })

      expect(salesButton).toBeInTheDocument()
      expect(inventoryButton).toBeInTheDocument()
    })
  })

  describe('Permission and Vertical Filtering Combined', () => {
    beforeEach(() => {
      const mechanicConfig = {
        vertical: 'mechanic',
        default_modules: ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Workshop', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Workshop', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(mechanicConfig)
    })

    it('hides module when permission denied even if vertical allows it', async () => {
      // Vehicle module exists in mechanic vertical, but user lacks permission
      mockCanAccessModule.mockImplementation((module: string) => {
        return module !== 'vehicles' // Deny access to vehicles
      })

      renderSidebar()

      // Wait for config to load
      await screen.findByRole('button', { name: /navigation\.sales/i })

      // Vehicle module should NOT be present (denied by permissions)
      const vehiclesLink = screen.queryByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).not.toBeInTheDocument()
    })

    it('shows module only when both vertical AND permissions allow it', async () => {
      // All permissions granted
      mockCanAccessModule.mockReturnValue(true)

      renderSidebar()

      // Wait for config to load
      const vehiclesLink = await screen.findByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).toBeInTheDocument()
    })
  })

  describe('Loading State', () => {
    it('renders sidebar while config is loading', () => {
      // Delay the API response
      vi.mocked(api.apiGet).mockImplementation(
        () => new Promise(() => {}) // Never resolves
      )

      renderSidebar()

      // Sidebar should render (even if navigation is empty or loading)
      const sidebar = screen.getByRole('complementary') || screen.getByRole('navigation')
      expect(sidebar).toBeInTheDocument()
    })
  })

  describe('Module Key Mapping', () => {
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
    })

    it('maps "vehicles" sidebar key to "Vehicle" module name', async () => {
      renderSidebar()

      // Vehicle module (capitalized in backend) should map to "vehicles" (lowercase in sidebar)
      const vehiclesLink = await screen.findByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).toBeInTheDocument()
    })

    it('maps "services" sidebar key to "Workshop" module name', async () => {
      renderSidebar()

      // Workshop module should map to "services" sidebar key
      const servicesButton = await screen.findByRole('button', { name: /navigation\.services/i })
      expect(servicesButton).toBeInTheDocument()
    })
  })

  describe('Always Visible Modules', () => {
    beforeEach(() => {
      // Minimal config with only core modules
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

    it('always shows Dashboard regardless of vertical', async () => {
      renderSidebar()

      const dashboardLink = await screen.findByRole('link', { name: /navigation\.dashboard/i })
      expect(dashboardLink).toBeInTheDocument()
      expect(dashboardLink).toHaveAttribute('href', '/dashboard')
    })

    it('always shows Settings regardless of vertical', async () => {
      renderSidebar()

      const settingsLink = await screen.findByRole('link', { name: /navigation\.settings/i })
      expect(settingsLink).toBeInTheDocument()
      expect(settingsLink).toHaveAttribute('href', '/settings')
    })

    it('always shows Reports regardless of vertical', async () => {
      renderSidebar()

      const reportsLink = await screen.findByRole('link', { name: /navigation\.reports/i })
      expect(reportsLink).toBeInTheDocument()
      expect(reportsLink).toHaveAttribute('href', '/reports')
    })
  })
})
