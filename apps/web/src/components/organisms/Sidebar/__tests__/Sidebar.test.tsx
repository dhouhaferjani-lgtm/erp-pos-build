import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import {
  defaultCompanyConfig,
  mechanicCompanyConfig,
  type TestCompanyConfig,
} from '@/test/fixtures/companyConfig'
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

// Mock useProductConfig — return Otospex so the "Automotive" group
// (which renders the Vehicle + Workshop links the tests assert on) is
// included in the navigation. Note: vi.mock specifier must match the
// exact specifier used by the component-under-test's import for the
// hoisting to apply. Sidebar imports from `../../../contexts/...`, so
// mock against the equivalent relative path from this file.
vi.mock('../../../../contexts/ProductConfigContext', () => ({
  useProductConfig: () => ({
    product: 'otospex',
    isIziPOS: false,
    isOtospex: true,
    productName: 'Otospex',
    productDescription: 'Automotive',
  }),
  ProductConfigProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}))

describe('Sidebar - Vertical-Based Navigation Filtering', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // Pre-seed localStorage so every collapsible group starts expanded —
    // child links (like "vehicles", "services") only render when their
    // parent group is in `expandedModules`, and the default route '/'
    // wouldn't auto-expand anything.
    localStorage.setItem(
      'autoerp-sidebar-expanded',
      JSON.stringify([
        'sales',
        'purchases',
        'inventoryAndCatalog',
        'pointOfSale',
        'marketing',
        'bankingAndPayments',
        'accountingAndReports',
        'automotive',
        'parapharmacy',
        'reports',
      ]),
    )

    // Default: All permissions granted (we're testing vertical filtering, not permissions)
    mockCanAccessModule.mockReturnValue(true)
  })

  const renderSidebar = (
    companyConfig: TestCompanyConfig = defaultCompanyConfig,
  ) => {
    return renderWithProviders(<Sidebar isOpen={true} />, {
      productConfig: { product: 'otospex' },
      companyConfig,
    })
  }

  // Rich configs mirroring the on-prod payloads. The default mechanic
  // fixture is module-minimal; these carry the Treasury / Accounting /
  // Tenant modules the sidebar tests check for.
  const mechanicFullConfig: TestCompanyConfig = {
    ...mechanicCompanyConfig,
    default_modules: ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Workshop', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
    all_enabled_modules: ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Partner', 'Workshop', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
  }

  describe('Mechanic Vertical (Automotive)', () => {
    it('shows Vehicle module for mechanic vertical', async () => {
      renderSidebar(mechanicFullConfig)

      const vehiclesLink = await screen.findByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).toBeInTheDocument()
      expect(vehiclesLink).toHaveAttribute('href', '/vehicles')
    })

    it('shows Workshop (Services) module for mechanic vertical', async () => {
      renderSidebar(mechanicFullConfig)

      // Services now renders as a pair of flat links inside the Automotive
      // group (allServices + serviceCategories), not a sub-group button.
      const allServicesLink = await screen.findByRole('link', { name: /navigation\.allServices/i })
      expect(allServicesLink).toBeInTheDocument()
      expect(allServicesLink).toHaveAttribute('href', '/services')
    })

    it('shows core modules (Sales, Inventory, Treasury)', async () => {
      renderSidebar(mechanicFullConfig)

      const salesButton = await screen.findByRole('button', { name: /navigation\.sales/i })
      const inventoryButton = await screen.findByRole('button', { name: /navigation\.inventory/i })
      const bankingButton = await screen.findByRole('button', { name: /navigation\.bankingAndPayments/i })

      expect(salesButton).toBeInTheDocument()
      expect(inventoryButton).toBeInTheDocument()
      expect(bankingButton).toBeInTheDocument()
    })
  })

  describe('Pharmacy Vertical', () => {
    const pharmacyFullConfig: TestCompanyConfig = {
      vertical: 'pharmacy',
      default_modules: ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'BatchExpiry'],
      enabled_extras: [],
      all_enabled_modules: ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'BatchExpiry'],
      currency: 'TND',
      locale: 'fr_TN',
      country_code: 'TN',
      smart_prompts_enabled: false,
      smart_prompts_variant: 'off',
      line_designation_override_enabled: false,
    }

    it('hides Vehicle module for pharmacy vertical', async () => {
      renderSidebar(pharmacyFullConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      const vehiclesLink = screen.queryByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).not.toBeInTheDocument()
    })

    it('hides Workshop (Services) module for pharmacy vertical', async () => {
      renderSidebar(pharmacyFullConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      const servicesButton = screen.queryByRole('button', { name: /navigation\.services/i })
      expect(servicesButton).not.toBeInTheDocument()
    })

    it('shows core modules (Sales, Inventory, Treasury) for pharmacy vertical', async () => {
      renderSidebar(pharmacyFullConfig)

      const salesButton = await screen.findByRole('button', { name: /navigation\.sales/i })
      const inventoryButton = await screen.findByRole('button', { name: /navigation\.inventory/i })
      const bankingButton = await screen.findByRole('button', { name: /navigation\.bankingAndPayments/i })

      expect(salesButton).toBeInTheDocument()
      expect(inventoryButton).toBeInTheDocument()
      expect(bankingButton).toBeInTheDocument()
    })
  })

  describe('Restaurant Vertical', () => {
    const restaurantConfig: TestCompanyConfig = {
      vertical: 'restaurant',
      default_modules: ['Identity', 'Tenant', 'Catalog', 'Menu', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
      enabled_extras: ['Tables', 'Appointments'],
      all_enabled_modules: ['Identity', 'Tenant', 'Catalog', 'Menu', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting', 'Tables', 'Appointments'],
      currency: 'EUR',
      locale: 'fr_FR',
      country_code: 'FR',
      smart_prompts_enabled: false,
      smart_prompts_variant: 'off',
      line_designation_override_enabled: false,
    }

    it('hides Vehicle module for restaurant vertical', async () => {
      renderSidebar(restaurantConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      const vehiclesLink = screen.queryByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).not.toBeInTheDocument()
    })

    it('shows core modules for restaurant vertical', async () => {
      renderSidebar(restaurantConfig)

      const salesButton = await screen.findByRole('button', { name: /navigation\.sales/i })
      const inventoryButton = await screen.findByRole('button', { name: /navigation\.inventory/i })

      expect(salesButton).toBeInTheDocument()
      expect(inventoryButton).toBeInTheDocument()
    })
  })

  describe('Permission and Vertical Filtering Combined', () => {
    it('hides module when permission denied even if vertical allows it', async () => {
      // Vehicle module exists in mechanic vertical, but user lacks permission
      mockCanAccessModule.mockImplementation((module: string) => {
        return module !== 'vehicles' // Deny access to vehicles
      })

      renderSidebar(mechanicFullConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      const vehiclesLink = screen.queryByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).not.toBeInTheDocument()
    })

    it('shows module only when both vertical AND permissions allow it', async () => {
      mockCanAccessModule.mockReturnValue(true)

      renderSidebar(mechanicFullConfig)

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
    it('maps "vehicles" sidebar key to "Vehicle" module name', async () => {
      renderSidebar(mechanicCompanyConfig)

      const vehiclesLink = await screen.findByRole('link', { name: /navigation\.vehicles/i })
      expect(vehiclesLink).toBeInTheDocument()
    })

    it('maps "services" sidebar key to "Workshop" module name', async () => {
      renderSidebar(mechanicCompanyConfig)

      // allServices is rendered under the Automotive group because its
      // `module: 'services'` resolves through MODULE_NAME_MAP to Workshop,
      // which is enabled for the mechanic vertical.
      const allServicesLink = await screen.findByRole('link', { name: /navigation\.allServices/i })
      expect(allServicesLink).toBeInTheDocument()
    })
  })

  describe('AutoSpecs Vertical-Scoping Regression', () => {
    // Regression test for AutoSpecs vertical scoping (Part 2 of the design-audit task).
    //
    // All five AutoSpecs child entries under the Automotive group —
    //   * vehicles          → MODULE_NAME_MAP.vehicles = Vehicle
    //   * scheduling        → MODULE_NAME_MAP.scheduling = Workshop
    //   * workshopWorkOrders→ MODULE_NAME_MAP['workshop-work-orders'] = Workshop
    //   * workshopBundles   → MODULE_NAME_MAP['workshop-bundles'] = Workshop
    //   * workshopTechnicians→ MODULE_NAME_MAP['workshop-technicians'] = Workshop
    //
    // must be hidden for any non-automotive vertical. Missing a MODULE_NAME_MAP
    // entry makes the filter fall through to "always visible", which would leak
    // workshop links into retail / pharmacy / restaurant sidebars. Guard here.
    const retailConfig: TestCompanyConfig = {
      vertical: 'retail',
      default_modules: ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
      enabled_extras: [],
      all_enabled_modules: ['Identity', 'Tenant', 'Catalog', 'Partner', 'Sales', 'Inventory', 'Treasury', 'Accounting'],
      currency: 'EUR',
      locale: 'fr_FR',
      country_code: 'FR',
      smart_prompts_enabled: false,
      smart_prompts_variant: 'off',
      line_designation_override_enabled: false,
    }

    it('hides Vehicles entry for retail vertical', async () => {
      renderSidebar(retailConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      const link = screen.queryByRole('link', { name: /navigation\.vehicles/i })
      expect(link).not.toBeInTheDocument()
    })

    it('hides Scheduling entry for retail vertical', async () => {
      renderSidebar(retailConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      const link = screen.queryByRole('link', { name: /navigation\.scheduling/i })
      expect(link).not.toBeInTheDocument()
    })

    it('hides Workshop Work Orders entry for retail vertical', async () => {
      renderSidebar(retailConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      const link = screen.queryByRole('link', { name: /navigation\.workshopWorkOrders/i })
      expect(link).not.toBeInTheDocument()
    })

    it('hides Workshop Bundles entry for retail vertical', async () => {
      renderSidebar(retailConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      const link = screen.queryByRole('link', { name: /navigation\.workshopBundles/i })
      expect(link).not.toBeInTheDocument()
    })

    it('hides Workshop Technicians entry for retail vertical', async () => {
      renderSidebar(retailConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      // Regression: workshop-technicians was missing from MODULE_NAME_MAP before
      // the design-audit fix, which caused the filter to fall through to
      // "always visible" and leaked the entry into retail sidebars.
      const link = screen.queryByRole('link', { name: /navigation\.workshopTechnicians/i })
      expect(link).not.toBeInTheDocument()
    })

    it('hides the Automotive group entirely for retail vertical', async () => {
      renderSidebar(retailConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      const automotiveButton = screen.queryByRole('button', { name: /navigation\.automotive/i })
      expect(automotiveButton).not.toBeInTheDocument()
    })

    it('shows all five AutoSpecs entries for mechanic vertical', async () => {
      renderSidebar(mechanicFullConfig)

      // All five AutoSpecs links should be visible for a fully-provisioned
      // mechanic tenant (Vehicle + Workshop modules both enabled).
      expect(
        await screen.findByRole('link', { name: /navigation\.vehicles/i })
      ).toBeInTheDocument()
      expect(
        await screen.findByRole('link', { name: /navigation\.scheduling/i })
      ).toBeInTheDocument()
      expect(
        await screen.findByRole('link', { name: /navigation\.workshopWorkOrders/i })
      ).toBeInTheDocument()
      expect(
        await screen.findByRole('link', { name: /navigation\.workshopBundles/i })
      ).toBeInTheDocument()
      expect(
        await screen.findByRole('link', { name: /navigation\.workshopTechnicians/i })
      ).toBeInTheDocument()
    })
  })

  describe('Always Visible Modules', () => {
    // Minimal config with only core modules
    const minimalConfig: TestCompanyConfig = {
      vertical: 'retail',
      default_modules: ['Identity', 'Sales', 'Inventory'],
      enabled_extras: [],
      all_enabled_modules: ['Identity', 'Sales', 'Inventory'],
      currency: 'USD',
      locale: 'en_US',
      country_code: 'US',
      smart_prompts_enabled: false,
      smart_prompts_variant: 'off',
      line_designation_override_enabled: false,
    }

    it('always shows Dashboard regardless of vertical', async () => {
      renderSidebar(minimalConfig)

      const dashboardLink = await screen.findByRole('link', { name: /navigation\.dashboard/i })
      expect(dashboardLink).toBeInTheDocument()
      expect(dashboardLink).toHaveAttribute('href', '/dashboard')
    })

    it('always shows Settings regardless of vertical', async () => {
      renderSidebar(minimalConfig)

      const settingsLink = await screen.findByRole('link', { name: /navigation\.settings/i })
      expect(settingsLink).toBeInTheDocument()
      expect(settingsLink).toHaveAttribute('href', '/settings')
    })

    // Reports was folded into accountingAndReports + POS-local z-reports;
    // the top-level `/reports` link no longer exists. Assert instead that
    // the Accounting group (which surfaces financial reports) is visible.
    it('always shows Accounting & Reports regardless of vertical', async () => {
      renderSidebar(minimalConfig)

      const accountingButton = await screen.findByRole('button', {
        name: /navigation\.accountingAndReports/i,
      })
      expect(accountingButton).toBeInTheDocument()
    })
  })
})
