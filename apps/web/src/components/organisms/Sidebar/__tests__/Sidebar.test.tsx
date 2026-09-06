import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
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

// Mock usePermissions hook.
//
// T4/T15 added role-level gating tests that must exercise the REAL
// `canAccessModule` (fail-closed contract + the MODULE_PERMISSIONS lookup)
// driven by the seeded auth store. `useRealModuleAccess` flips the mocked hook
// over to the actual implementation for those blocks only; every pre-existing
// test keeps the stubbed `mockCanAccessModule` and is untouched.
let useRealModuleAccess = false
const mockCanAccessModule = vi.fn()
vi.mock('../../../../hooks/usePermissions', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../../hooks/usePermissions')>()

  return {
    ...actual,
    usePermissions: () => {
      const real = actual.usePermissions()

      return {
        ...real,
        canAccessModule: useRealModuleAccess ? real.canAccessModule : mockCanAccessModule,
      }
    },
  }
})

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
        'catalog',
        'inventory',
        'pointOfSale',
        'ecommerce',
        'customersAndMarketing',
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

    it('places the deliveries.view-gated to-bill queue after delivery notes', async () => {
      renderSidebar(mechanicFullConfig)

      const deliveryNotes = await screen.findByRole('link', { name: /navigation\.deliveryNotes/i })
      const toBill = screen.getByRole('link', { name: /sales:toBill\.navTitle/i })

      expect(toBill).toHaveAttribute('href', '/sales/to-bill')
      expect(mockCanAccessModule).toHaveBeenCalledWith('deliveries.view')
      expect(deliveryNotes.compareDocumentPosition(toBill) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    })

    it('shows placement management under Inventory', async () => {
      renderSidebar(mechanicFullConfig)

      const placementLink = await screen.findByRole('link', { name: /navigation\.placement/i })
      expect(placementLink).toHaveAttribute('href', '/inventory/placement')
    })

    it('shows treasury overview as the first accounting and reports child', async () => {
      renderSidebar(mechanicFullConfig)

      const overviewLink = await screen.findByRole('link', {
        name: /finance:hub\.cards\.treasuryOverview\.title/i,
      })
      const chartOfAccountsLink = await screen.findByRole('link', {
        name: /navigation\.chartOfAccounts/i,
      })

      expect(overviewLink).toHaveAttribute('href', '/finance/overview')
      expect(
        overviewLink.compareDocumentPosition(chartOfAccountsLink) &
          Node.DOCUMENT_POSITION_FOLLOWING,
      ).toBeTruthy()
    })

    it('shows cash movements beside treasury overview only with reports.operational access', async () => {
      const firstRender = renderSidebar(mechanicFullConfig)

      const overviewLink = await screen.findByRole('link', {
        name: /finance:hub\.cards\.treasuryOverview\.title/i,
      })
      const movementsLink = await screen.findByRole('link', {
        name: /finance:cashMovements\.navTitle/i,
      })

      expect(movementsLink).toHaveAttribute('href', '/finance/cash-movements')
      expect(
        overviewLink.compareDocumentPosition(movementsLink) &
          Node.DOCUMENT_POSITION_FOLLOWING,
      ).toBeTruthy()

      firstRender.unmount()
      // Gate finding I-3 (2026-08-06 review): treasuryOverview/cashMovements
      // moved off the shared 'reports' module key (which stayed
      // reports.financial, wrongly hiding both entries for an
      // operational-only manager) onto their own 'reports.operational' key.
      mockCanAccessModule.mockImplementation((permission: string) => permission !== 'reports.operational')
      renderSidebar(mechanicFullConfig)

      expect(screen.queryByRole('link', {
        name: /finance:cashMovements\.navTitle/i,
      })).not.toBeInTheDocument()
    })

    it('aligns the lane-separation entry one-to-one with reports.financial', async () => {
      const allowed = renderSidebar(mechanicFullConfig)

      expect(await screen.findByRole('link', {
        name: /finance:laneSeparation\.navTitle/i,
      })).toHaveAttribute('href', '/finance/lane-separation')
      expect(mockCanAccessModule).toHaveBeenCalledWith('reports.financial')

      allowed.unmount()
      mockCanAccessModule.mockImplementation(
        (permission: string) => permission !== 'reports.financial',
      )
      renderSidebar(mechanicFullConfig)

      expect(screen.queryByRole('link', {
        name: /finance:laneSeparation\.navTitle/i,
      })).not.toBeInTheDocument()
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
      purchase_bonus_enabled: false,
      platform_import_enrichment_available: false,
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
      purchase_bonus_enabled: false,
      platform_import_enrichment_available: false,
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
      expect(screen.getByRole('complementary')).toBeInTheDocument()
    })
  })

  describe('Recurring expense permission', () => {
    it('shows the recurring schedules link only through the recurrence view gate', async () => {
      const allowed = renderSidebar(mechanicFullConfig)
      expect(await screen.findByRole('link', { name: /navigation\.recurringExpenses/i }))
        .toHaveAttribute('href', '/expenses/recurring')
      expect(mockCanAccessModule).toHaveBeenCalledWith('expense-recurrences')

      allowed.unmount()
      mockCanAccessModule.mockImplementation((permission: string) => permission !== 'expense-recurrences')
      renderSidebar(mechanicFullConfig)

      expect(screen.queryByRole('link', { name: /navigation\.recurringExpenses/i }))
        .not.toBeInTheDocument()
    })
  })

  describe('Bank statement permission', () => {
    it('shows the statement workspace link only through bank-statements.view', async () => {
      const allowed = renderSidebar(mechanicFullConfig)
      expect(await screen.findByRole('link', { name: /navigation\.bankReconciliation/i }))
        .toHaveAttribute('href', '/treasury/statements')
      expect(mockCanAccessModule).toHaveBeenCalledWith('bank-statements')

      allowed.unmount()
      mockCanAccessModule.mockImplementation((permission: string) => permission !== 'bank-statements')
      renderSidebar(mechanicFullConfig)

      expect(screen.queryByRole('link', { name: /navigation\.bankReconciliation/i }))
        .not.toBeInTheDocument()
    })
  })

  describe('Expense analytics navigation', () => {
    it('links analytics through the expenses module permission map', async () => {
      renderSidebar(mechanicFullConfig)

      expect(await screen.findByRole('link', { name: /navigation\.expenseAnalytics/i }))
        .toHaveAttribute('href', '/expenses/analytics')
      expect(mockCanAccessModule).toHaveBeenCalledWith('expenses')
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

      // allServices is rendered because its nav item declares the typed
      // `module: 'Workshop'` prop directly, and Workshop is enabled for the
      // mechanic vertical.
      const allServicesLink = await screen.findByRole('link', { name: /navigation\.allServices/i })
      expect(allServicesLink).toBeInTheDocument()
    })
  })

  describe('AutoSpecs Vertical-Scoping Regression', () => {
    // Regression test for AutoSpecs vertical scoping (Part 2 of the design-audit task).
    //
    // All five AutoSpecs child entries under the Automotive group —
    //   * vehicles           → module: 'Vehicle'
    //   * scheduling         → module: 'Workshop'
    //   * workshopWorkOrders → module: 'Workshop'
    //   * workshopBundles    → module: 'Workshop'
    //   * workshopTechnicians→ module: 'Workshop'
    //
    // must be hidden for any non-automotive vertical. Each nav item declares
    // its typed `module:` prop directly; an item with NO module prop is
    // always visible, which would leak workshop links into retail / pharmacy
    // / restaurant sidebars. Guard here.
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
      purchase_bonus_enabled: false,
      platform_import_enrichment_available: false,
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

      // Regression: workshopTechnicians historically lacked a module
      // declaration, which caused the filter to fall through to "always
      // visible" and leaked the entry into retail sidebars. The typed
      // `module: 'Workshop'` prop on the nav item guards against that.
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
      purchase_bonus_enabled: false,
      platform_import_enrichment_available: false,
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

    // Accounting & Reports is module-gated (fail-closed) since the
    // production-hardening pass: visible only when the Accounting module is
    // enabled. Every real vertical ships it in defaultModules; this synthetic
    // minimal config does not, so the group must be hidden.
    it('hides Accounting & Reports when the Accounting module is absent', async () => {
      renderSidebar(minimalConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      const accountingButton = screen.queryByRole('button', {
        name: /navigation\.accountingAndReports/i,
      })
      expect(accountingButton).not.toBeInTheDocument()
    })

    it('shows Accounting & Reports when the Accounting module is enabled', async () => {
      renderSidebar({
        ...minimalConfig,
        all_enabled_modules: [...minimalConfig.all_enabled_modules, 'Accounting'],
      })

      const accountingButton = await screen.findByRole('button', {
        name: /navigation\.accountingAndReports/i,
      })
      expect(accountingButton).toBeInTheDocument()
    })
  })

  describe('Fail-Closed Module Gating (production hardening)', () => {
    // Nav items now declare backend module names directly (PascalCase,
    // typed). An item whose module is not in `all_enabled_modules` must be
    // hidden — there is no fall-through to "always visible".
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
      purchase_bonus_enabled: false,
      platform_import_enrichment_available: false,
    }

    const restaurantConfig: TestCompanyConfig = {
      ...retailConfig,
      vertical: 'restaurant',
      all_enabled_modules: [...retailConfig.all_enabled_modules, 'Menu', 'Tables'],
    }

    it('hides Tables and Kitchen for a retail vertical (no Tables/Menu modules)', async () => {
      renderSidebar(retailConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      expect(screen.queryByRole('link', { name: /navigation\.tables/i })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /navigation\.kitchen/i })).not.toBeInTheDocument()
    })

    it('shows Tables and Kitchen for a restaurant vertical', async () => {
      renderSidebar(restaurantConfig)

      expect(await screen.findByRole('link', { name: /navigation\.tables/i })).toBeInTheDocument()
      expect(await screen.findByRole('link', { name: /navigation\.kitchen/i })).toBeInTheDocument()
    })

    // Note: compositeItems / modifierGroups resolve through the vertical
    // label map (catalog:vertical.<vertical>.<key>) for non-generic
    // verticals, so match on the key fragment rather than the namespace.
    it('shows Composite Items, Menus, and Modifier Groups when Menu module is enabled', async () => {
      renderSidebar(restaurantConfig)

      expect(await screen.findByRole('link', { name: /compositeItems/i })).toBeInTheDocument()
      expect(await screen.findByRole('link', { name: /navigation\.menus/i })).toBeInTheDocument()
      expect(await screen.findByRole('link', { name: /modifierGroup/i })).toBeInTheDocument()
    })

    it('hides Composite Items, Menus, and Modifier Groups for retail (no Menu module)', async () => {
      renderSidebar(retailConfig)

      await screen.findByRole('button', { name: /navigation\.sales/i })

      expect(screen.queryByRole('link', { name: /compositeItems/i })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /navigation\.menus/i })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /modifierGroup/i })).not.toBeInTheDocument()
    })

    it('shows the E-commerce group only when the Ecommerce module is enabled', async () => {
      renderSidebar(retailConfig)
      await screen.findByRole('button', { name: /navigation\.sales/i })
      expect(screen.queryByRole('button', { name: /navigation\.ecommerce/i })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /navigation\.channels/i })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /navigation\.channelOrders/i })).not.toBeInTheDocument()
    })

    it('shows Channels and Channel Orders under E-commerce when the Ecommerce module is enabled', async () => {
      renderSidebar({
        ...retailConfig,
        enabled_extras: ['Ecommerce'],
        all_enabled_modules: [...retailConfig.all_enabled_modules, 'Ecommerce'],
      })

      expect(await screen.findByRole('button', { name: /navigation\.ecommerce/i })).toBeInTheDocument()
      const channelsLink = await screen.findByRole('link', { name: /navigation\.channels$/i })
      expect(channelsLink).toHaveAttribute('href', '/channels')
      const channelOrdersLink = await screen.findByRole('link', { name: /navigation\.channelOrders/i })
      expect(channelOrdersLink).toHaveAttribute('href', '/ecommerce/orders')
    })

    it('shows Batches only when BatchExpiry or Parapharmacy is enabled', async () => {
      renderSidebar(retailConfig)
      await screen.findByRole('button', { name: /navigation\.sales/i })
      expect(screen.queryByRole('link', { name: /navigation\.batches/i })).not.toBeInTheDocument()
    })

    it('shows Stock Transfers under Inventory', async () => {
      renderSidebar(retailConfig)

      const transfersLink = await screen.findByRole('link', { name: /navigation\.stockTransfers/i })
      expect(transfersLink).toHaveAttribute('href', '/inventory/stock-transfers')
    })

    it('shows replenishment but hides transfer links when an operator lacks transfer view', async () => {
      mockCanAccessModule.mockImplementation((permission: string) => (
        permission === 'inventory' || permission === 'replenishment.view'
      ))
      renderSidebar(retailConfig)

      expect(await screen.findByRole('link', { name: 'replenishment:title' })).toHaveAttribute(
        'href',
        '/inventory/replenishment',
      )
      expect(screen.queryByRole('link', { name: /navigation\.stockTransfers/i })).not.toBeInTheDocument()
      expect(mockCanAccessModule).toHaveBeenCalledWith('replenishment.view')
      expect(mockCanAccessModule).toHaveBeenCalledWith('inventory.transfers.view')
    })

    it('splits Catalog and Inventory into separate groups', async () => {
      renderSidebar(retailConfig)

      expect(await screen.findByRole('button', { name: /navigation\.catalog/i })).toBeInTheDocument()
      expect(await screen.findByRole('button', { name: /navigation\.inventory\b/i })).toBeInTheDocument()
    })
  })

  describe('Bottom Navigation', () => {
    it('pins only Settings to the bottom — refund policies and customer history audit moved into the Settings hub', async () => {
      renderSidebar(mechanicFullConfig)

      const settingsLink = await screen.findByRole('link', { name: /navigation\.settings/i })
      expect(settingsLink).toHaveAttribute('href', '/settings')

      expect(screen.queryByRole('link', { name: /navigation\.posRefundPolicies/i })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /navigation\.customerHistoryAudit/i })).not.toBeInTheDocument()
    })
  })

  describe('POS sidebar and route permission parity', () => {
    const posCompanyConfig: TestCompanyConfig = {
      ...defaultCompanyConfig,
      default_modules: [...defaultCompanyConfig.default_modules, 'Menu', 'Tables'],
      all_enabled_modules: [...defaultCompanyConfig.all_enabled_modules, 'Menu', 'Tables'],
    }

    const setGrantedPermissions = (grants: ReadonlySet<string>) => {
      mockCanAccessModule.mockImplementation((permission: string) => {
        if (permission === 'pos') {
          return ['pos.operate_terminal', 'pos.manage_terminals', 'pos.view_receipts']
            .some((candidate) => grants.has(candidate))
        }
        if (permission === 'compliance') {
          return ['compliance.export_jet', 'compliance.verify_chains', 'compliance.view_reprint_log']
            .some((candidate) => grants.has(candidate))
        }
        return grants.has(permission)
      })
    }

    const visiblePosHrefs = () => screen.getAllByRole('link')
      .map((link) => link.getAttribute('href'))
      .filter((href): href is string => href?.startsWith('/pos/') === true)
      .sort()

    it('shows accountants exactly the POS routes they can enter plus compliance export', () => {
      setGrantedPermissions(new Set([
        'pos.view_receipts',
        'pos.view_reports',
        'compliance.verify_chains',
      ]))

      renderSidebar(posCompanyConfig)

      expect(visiblePosHrefs()).toEqual([
        '/pos/analytics',
        '/pos/receipts',
        '/pos/vouchers',
        '/pos/z-reports',
      ])
      expect(screen.getByRole('link', { name: /navigation\.complianceExport/i }))
        .toHaveAttribute('href', '/settings/compliance/export')
    })

    it('shows cashiers every enterable POS route and hides every denied route', () => {
      const cashierGrants = new Set([
        'pos.operate_terminal',
        'pos.manage_shifts',
        'pos.view_receipts',
      ])
      setGrantedPermissions(cashierGrants)

      renderSidebar(posCompanyConfig)

      expect(visiblePosHrefs()).toEqual([
        '/pos/kitchen',
        '/pos/orders',
        '/pos/receipts',
        '/pos/shift-history',
        '/pos/vouchers',
      ])
      expect(cashierGrants.has('pos.manage_tables')).toBe(false)
      expect(cashierGrants.has('pos.manage_terminals')).toBe(false)
      expect(cashierGrants.has('pos.view_reports')).toBe(false)
    })

    it('does not render a group header when all children are denied', () => {
      mockCanAccessModule.mockReturnValue(false)

      renderSidebar(mechanicFullConfig)

      expect(screen.queryByRole('button', { name: /navigation\.automotive/i })).not.toBeInTheDocument()
    })
  })

  /**
   * T4 (UI-01): role-level gating through the REAL `canAccessModule`.
   *
   * `goods-receipt.create-standalone` was never a MODULE_PERMISSIONS key, so
   * the old fail-open lookup showed the "newGoodsReceipt" entry to every role
   * that could see the Purchases group. It is now a real, self-mapped key
   * granted to admin/manager only — a user-visible narrowing (brief F-3).
   */
  describe('Role gating via the real canAccessModule (T4)', () => {
    beforeEach(() => {
      useRealModuleAccess = true
    })

    afterEach(() => {
      useRealModuleAccess = false
      resetAuth()
    })

    it('hides newGoodsReceipt from a purchases role that lacks goods-receipt.create-standalone', async () => {
      seedAuth({ roles: ['purchases'] })
      renderSidebar(mechanicFullConfig)

      // Positive control: the sibling with no permission of its own proves the
      // Purchases group itself rendered and is expanded.
      expect(await screen.findByRole('link', { name: /navigation\.goodsReceipts/i })).toHaveAttribute(
        'href',
        '/purchases/receipts',
      )
      expect(
        screen.queryByRole('link', { name: /navigation\.newGoodsReceipt/i }),
      ).not.toBeInTheDocument()
    })

    it('shows newGoodsReceipt to a manager who holds the permission', async () => {
      seedAuth({ roles: ['manager'] })
      renderSidebar(mechanicFullConfig)

      expect(await screen.findByRole('link', { name: /navigation\.newGoodsReceipt/i })).toHaveAttribute(
        'href',
        '/purchases/receipts/new',
      )
    })

    /**
     * T15 (UI-07): the /expenses nav item was gated on the `treasury` module
     * key (treasury.view = accountant/admin/manager) while the route itself
     * admits everyone holding `expenses.view` — cashier, operator and viewer
     * could reach /expenses only by typing the URL. The gate is now
     * `expenses`, matching its `expenseAnalytics` sibling.
     *
     * Ordering note: T4 (fail-closed union) landed first on this branch, so
     * these assertions run under the strict contract. Both orders leave them
     * true — `treasury` and `expenses` are both long-standing valid keys, so
     * neither reading of the gate depends on the fail-open behaviour.
     */
    it('shows the expenses nav item to a cashier who holds expenses.view but not treasury.view', async () => {
      seedAuth({ roles: ['cashier'] })
      renderSidebar(mechanicFullConfig)

      expect(await screen.findByRole('link', { name: /navigation\.expenses$/i })).toHaveAttribute(
        'href',
        '/expenses',
      )
    })

    /**
     * Gate r2 finding 1 — the accountant this PR grants `supplier-invoices.manage`
     * could not see (nor open) any supplier-invoice screen, because the whole
     * Purchases group hung off the `purchases.view` UI ROLE ALIAS. The group now
     * also admits `supplier-invoices.manage` holders, and each child an
     * accountant cannot use is gated on the real permission its own API checks —
     * so the widening never offers a page the server will refuse.
     */
    it('shows Supplier invoices to an accountant, and hides the purchases pages their API refuses', async () => {
      seedAuth({
        roles: ['accountant'],
        permissions: ['documents.view', 'supplier-invoices.manage', 'payments.pay-supplier', 'partners.view', 'deliveries.view'],
      })
      renderSidebar(mechanicFullConfig)

      expect(await screen.findByRole('link', { name: /navigation\.supplierInvoices/i })).toHaveAttribute(
        'href',
        '/purchases/supplier-invoices',
      )
      // No purchase-orders.view / purchase-quote-requests.view => those APIs 403.
      expect(screen.queryByRole('link', { name: /navigation\.purchaseOrders/i })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /navigation\.quoteRequests/i })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /navigation\.goodsReceipts/i })).not.toBeInTheDocument()
    })

    it('keeps every Purchases child for a manager (the widening is additive)', async () => {
      // `supplier-invoices.manage` is SERVER-AUTHORITATIVE (fail closed on a
      // tenant whose seeder has not re-run), so the manager's session must carry
      // the real server permission list — exactly what a live login sends.
      seedAuth({
        roles: ['manager'],
        permissions: ['documents.view', 'purchase-orders.view', 'purchase-quote-requests.view', 'supplier-invoices.manage'],
      })
      renderSidebar(mechanicFullConfig)

      expect(await screen.findByRole('link', { name: /navigation\.purchaseOrders/i })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: /navigation\.quoteRequests/i })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: /navigation\.goodsReceipts/i })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: /navigation\.supplierInvoices/i })).toBeInTheDocument()
    })

    it('still hides the Purchases group from a cashier', async () => {
      seedAuth({ roles: ['cashier'], permissions: ['documents.view', 'documents.update', 'payments.create'] })
      renderSidebar(mechanicFullConfig)

      // Positive control: the cashier renders navigation at all.
      expect(await screen.findByRole('link', { name: /navigation\.expenses$/i })).toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /navigation\.supplierInvoices/i })).not.toBeInTheDocument()
    })

    it('still hides the expenses nav item from a role holding neither expenses.view nor treasury.view', async () => {
      seedAuth({ roles: ['purchases'] })
      renderSidebar(mechanicFullConfig)

      // Positive control: this role does render navigation (its Purchases
      // entries are visible), so the absence below is the item's own gate and
      // not an empty sidebar.
      expect(await screen.findByRole('link', { name: /navigation\.goodsReceipts/i })).toBeInTheDocument()
      expect(screen.queryByRole('link', { name: /navigation\.expenses$/i })).not.toBeInTheDocument()
    })
  })
})
