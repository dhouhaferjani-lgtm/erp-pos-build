import { useState, useEffect, useCallback, useMemo } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  LayoutDashboard,
  ShoppingCart,
  Users,
  FileText,
  FileQuestion,
  ClipboardList,
  Receipt,
  Truck,
  Package,
  Layers,
  FileBox,
  Car,
  Wallet,
  Landmark,
  BarChart3,
  Settings,
  X,
  ChevronDown,
  ChevronRight,
  Calculator,
  BookOpen,
  FileSpreadsheet,
  FileStack,
  Scale,
  TrendingUp,
  PieChart,
  Clock,
  ArrowLeftRight,
  Tag,
  MinusCircle,
  ClipboardCheck,
  Wrench,
  FolderTree,
  RotateCcw,
  Pill,
  Award,
  ListChecks,
  Layers3,
  FileCheck,
  Store,
  Monitor,
  History,
  LayoutGrid,
  ChefHat,
  PanelLeftClose,
  PanelLeft,
  Combine,
  Ticket,
  Building2,
  Search,
  Sparkles,
  Package2,
  PackagePlus,
  Calendar,
  CalendarClock,
  Download,
  Cable,
  Tags,
  Repeat,
  Globe,
  Trash2,
  MapPinned,
} from 'lucide-react'
import type { BackendModule } from '../../../lib/modules'
import { usePermissions } from '../../../hooks/usePermissions'
import { useCompanyConfig } from '../../../contexts/CompanyConfigContext'
import { useProductConfig } from '../../../contexts/ProductConfigContext'
import { companyVerticalToCatalog } from '../../../features/catalog/hooks/useVerticalLabels'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

const STORAGE_KEY = 'autoerp-sidebar-expanded'
const COLLAPSED_STORAGE_KEY = 'autoerp-sidebar-collapsed'

/**
 * Tenant vertical keys considered "automotive" — these verticals enable the
 * Workshop, Vehicle, and parts-catalog modules and therefore surface the
 * Automotive sidebar group.
 *
 * Mirrors `App\Enums\Vertical::isAutomotive()` on the backend.
 */
const AUTOMOTIVE_VERTICALS = new Set<string>([
  'mechanic',
  'body_shop',
  'parts_retailer',
  'car_glass',
  'tire_shop',
  'service_station',
])

/**
 * Navigation items carry two independent gates, both fail-closed:
 *
 * - `module`  — backend module name(s) from `all_enabled_modules`
 *               (PascalCase, typed `BackendModule` so unknown names are a
 *               compile error). Visible iff ANY listed module is enabled.
 *               Omitted = core item, always vertical-visible.
 * - `permission` — key into `MODULE_PERMISSIONS` (role gating). Omitted =
 *               no role restriction at the nav level (routes still enforce).
 */
interface NavChild {
  key: string
  href: string
  icon: React.ComponentType<{ className?: string }>
  labelKey?: string
  module?: BackendModule | BackendModule[]
  permission?: string
}

interface NavModule {
  key: string
  icon: React.ComponentType<{ className?: string }>
  href?: string
  labelKey?: string
  children?: NavChild[]
  module?: BackendModule | BackendModule[]
  permission?: string
  section?: 'main' | 'bottom'
}

interface SidebarProps {
  isOpen?: boolean
  onClose?: () => void
}

// Navigation keys that should adapt based on company vertical.
// NOTE: the top-level `catalog` group deliberately does NOT adapt — it is
// the whole what-you-sell group, not the composite-items entry.
const VERTICAL_NAV_KEYS: Record<string, string> = {
  compositeItems: 'compositeItems',
  modifierGroups: 'modifierGroup',
}

/**
 * Build the navigation array dynamically based on vertical config.
 *
 * For automotive verticals (mechanic, body_shop, etc.): Services appear under the Automotive group.
 * For other verticals: Services appear under Inventory & Catalog; Automotive is hidden
 *   downstream by the `isModuleEnabledForVertical('automotive')` filter (none of
 *   Vehicle/Workshop/PlatformIntegration will be enabled).
 */
function buildNavigation(isAutomotiveVertical: boolean): NavModule[] {
  const servicesChildren: NavChild[] = [
    { key: 'allServices', href: '/services', icon: Wrench, module: 'Workshop', permission: 'services' },
    { key: 'serviceCategories', href: '/services/categories', icon: FolderTree, module: 'Workshop', permission: 'services' },
  ]

  const nav: NavModule[] = [
    {
      key: 'dashboard',
      href: '/dashboard',
      icon: LayoutDashboard,
      permission: 'dashboard',
    },
    {
      key: 'sales',
      icon: ShoppingCart,
      module: 'Sales',
      permission: 'sales',
      children: [
        { key: 'customers', href: '/sales/customers', icon: Users },
        { key: 'quotes', href: '/sales/quotes', icon: FileText },
        { key: 'salesOrders', href: '/sales/orders', icon: ClipboardList },
        { key: 'invoices', href: '/sales/invoices', icon: Receipt },
        { key: 'creditNotes', href: '/sales/credit-notes', icon: MinusCircle },
        { key: 'deliveryNotes', href: '/inventory/delivery-notes', icon: FileBox },
      ],
    },
    {
      key: 'purchases',
      icon: Truck,
      permission: 'purchases',
      children: [
        { key: 'suppliers', href: '/purchases/suppliers', icon: Users },
        { key: 'quoteRequests', href: '/purchases/quote-requests', icon: FileQuestion },
        { key: 'purchaseOrders', href: '/purchases/orders', icon: ClipboardList },
        { key: 'goodsReceipts', href: '/purchases/receipts', icon: Package },
        { key: 'newGoodsReceipt', href: '/purchases/receipts/new', icon: Package2, permission: 'goods-receipt.create-standalone' },
        { key: 'scans', href: '/purchases/scans', icon: FileText, permission: 'document-ingestions' },
        { key: 'supplierInvoices', href: '/purchases/supplier-invoices', icon: Receipt },
        { key: 'returnNotes', href: '/inventory/return-notes', icon: RotateCcw },
      ],
    },
    // Catalog — "what you sell": product definitions, categorisation, pricing.
    {
      key: 'catalog',
      icon: BookOpen,
      module: 'Catalog',
      permission: 'inventory',
      children: [
        { key: 'products', href: '/inventory/products', icon: Package },
        { key: 'categories', href: '/inventory/categories', icon: FolderTree },
        // Services placed here for non-automotive verticals (for automotive
        // verticals they live under the Automotive group below).
        ...(!isAutomotiveVertical ? servicesChildren : []),
        { key: 'productAttributes', href: '/catalog/attributes', icon: Tags },
        { key: 'compositeItems', href: '/catalog/composite-items', icon: Combine, module: ['Menu', 'CompositeItems'], permission: 'composite-items' },
        { key: 'menus', href: '/catalog/menus', icon: BookOpen, module: ['Menu', 'CompositeItems'], permission: 'composite-items' },
        { key: 'modifierGroups', href: '/catalog/modifier-groups', icon: Layers, module: ['Menu', 'CompositeItems'], permission: 'modifier-groups' },
        { key: 'priceLists', href: '/pricing/price-lists', icon: Tag, permission: 'pricing' },
      ],
    },
    // Inventory — "what you have": stock quantities and their movements.
    {
      key: 'inventory',
      icon: Package,
      module: 'Inventory',
      permission: 'inventory',
      children: [
        { key: 'stockLevels', href: '/inventory/stock', icon: Layers },
        { key: 'stockByLocation', href: '/inventory/stock-by-location', icon: Layers, permission: 'inventory.view' },
        { key: 'placement', href: '/inventory/placement', icon: MapPinned, permission: 'inventory' },
        { key: 'stockMovements', href: '/inventory/movements', icon: ArrowLeftRight },
        { key: 'stockTransfers', href: '/inventory/stock-transfers', icon: Repeat, permission: 'inventory.transfers.view' },
        { key: 'replenishment', labelKey: 'replenishment:title', href: '/inventory/replenishment', icon: PackagePlus, permission: 'replenishment.view' },
        { key: 'counting', href: '/inventory/counting', icon: ClipboardCheck },
        { key: 'batches', href: '/inventory/batches', icon: Pill, module: 'BatchExpiry' },
        { key: 'expiryWriteOff', href: '/inventory/expiry-write-off', icon: Trash2, module: 'BatchExpiry', permission: 'batches.write-off' },
        { key: 'enrichmentQueue', href: '/inventory/enrichment-results', icon: Sparkles },
      ],
    },
    {
      key: 'pointOfSale',
      icon: Store,
      permission: 'pos',
      children: [
        // Web new-sale POS is retired — checkout runs in the IziPOS desktop app.
        // The /pos/transactions route stays registered as a translated info page
        // for deep links, but is intentionally not surfaced in the web nav.
        { key: 'posOrders', href: '/pos/orders', icon: ClipboardList, permission: 'pos' },
        { key: 'tables', href: '/pos/tables', icon: LayoutGrid, module: 'Tables', permission: 'pos' },
        { key: 'kitchen', href: '/pos/kitchen', icon: ChefHat, module: 'Menu', permission: 'pos' },
        { key: 'terminals', href: '/pos/terminals', icon: Monitor, permission: 'pos' },
        { key: 'shiftHistory', href: '/pos/shift-history', icon: History, permission: 'pos' },
        { key: 'zReports', href: '/pos/z-reports', icon: FileCheck, permission: 'pos' },
        { key: 'vouchers', href: '/pos/vouchers', icon: Ticket, permission: 'pos' },
        { key: 'analytics', href: '/pos/analytics', icon: BarChart3, permission: 'pos' },
      ],
    },
    // E-commerce — external sales channels. Gated on the Ecommerce extra.
    // Channel Orders is the aggregate inbound-orders view across all
    // channels (per-channel staging still lives inside each channel).
    {
      key: 'ecommerce',
      icon: Globe,
      module: 'Ecommerce',
      permission: 'inventory',
      children: [
        { key: 'channels', href: '/channels', icon: Cable },
        { key: 'channelOrders', href: '/ecommerce/orders', icon: ClipboardList },
      ],
    },
    {
      key: 'customersAndMarketing',
      icon: Tag,
      children: [
        { key: 'companies', href: '/crm/companies', icon: Building2, permission: 'contacts' },
        { key: 'contacts', href: '/crm/contacts', icon: Users, permission: 'contacts' },
        { key: 'loyaltyPrograms', href: '/pos/loyalty/programs', icon: Award, module: 'Loyalty', permission: 'loyalty' },
        { key: 'loyaltyMembers', href: '/pos/loyalty/members', icon: Users, module: 'Loyalty', permission: 'loyalty' },
        { key: 'promotions', href: '/pos/promotions', icon: Tag, permission: 'promotions' },
        { key: 'coupons', href: '/pos/coupons', icon: Ticket, permission: 'coupons' },
      ],
    },
    {
      key: 'bankingAndPayments',
      icon: Wallet,
      module: 'Treasury',
      children: [
        { key: 'payments', href: '/treasury/payments', icon: Wallet, permission: 'treasury' },
        { key: 'repositories', href: '/treasury/repositories', icon: Landmark, permission: 'treasury' },
        { key: 'instruments', href: '/treasury/instruments', icon: FileText, permission: 'treasury' },
        { key: 'remittances', href: '/treasury/remittances', icon: FileStack, permission: 'remittances' },
        { key: 'bankReconciliation', href: '/treasury/statements', icon: ArrowLeftRight, permission: 'bank-statements' },
        { key: 'expenses', href: '/expenses', icon: Receipt, permission: 'treasury' },
        { key: 'expenseAnalytics', href: '/expenses/analytics', icon: BarChart3, permission: 'expenses' },
        { key: 'recurringExpenses', href: '/expenses/recurring', icon: CalendarClock, permission: 'expense-recurrences' },
        { key: 'withholdingCertificates', href: '/treasury/withholding-certificates', icon: FileCheck, permission: 'withholding' },
      ],
    },
    {
      key: 'accountingAndReports',
      icon: Calculator,
      module: 'Accounting',
      permission: 'accounts',
      children: [
        // Gate findings I-2/I-3/I-5 (2026-08-06 review): these nine entries
        // used to be split only 'accounts' vs 'reports' (= reports.financial),
        // which disagreed with the routes they link to (routes/index.tsx) —
        // a viewer/manager could see a nav item that then silently bounced
        // to /dashboard on click. Aligned 1:1 to the route's permission.
        { key: 'treasuryOverview', labelKey: 'finance:hub.cards.treasuryOverview.title', href: '/finance/overview', icon: Wallet, permission: 'reports.operational' },
        { key: 'cashMovements', labelKey: 'finance:cashMovements.navTitle', href: '/finance/cash-movements', icon: ArrowLeftRight, permission: 'reports.operational' },
        { key: 'chartOfAccounts', href: '/finance/chart-of-accounts', icon: BookOpen, permission: 'accounts' },
        { key: 'generalLedger', href: '/finance/ledger', icon: FileSpreadsheet, permission: 'ledger.view' },
        { key: 'journalEntries', href: '/finance/journal-entries', icon: FileSpreadsheet, permission: 'accounts' },
        { key: 'trialBalance', href: '/finance/trial-balance', icon: Scale, permission: 'reports.financial' },
        { key: 'profitLoss', href: '/finance/profit-loss', icon: TrendingUp, permission: 'reports.financial' },
        { key: 'balanceSheet', href: '/finance/balance-sheet', icon: PieChart, permission: 'reports.financial' },
        { key: 'agedReceivables', href: '/finance/aged-receivables', icon: Clock, permission: 'reports.operational' },
        { key: 'agedPayables', href: '/finance/aged-payables', icon: Clock, permission: 'reports.operational' },
        { key: 'vatReporting', href: '/finance/vat-periods', icon: Receipt, permission: 'reports' },
      ],
    },
    // Automotive — shown whenever the tenant's vertical enables at least one
    // automotive module (Vehicle, Workshop, or PlatformIntegration).
    {
      key: 'automotive',
      icon: Car,
      module: ['Vehicle', 'Workshop', 'PlatformIntegration'],
      children: [
        { key: 'vehicles', href: '/vehicles', icon: Car, module: 'Vehicle', permission: 'vehicles' },
        // Only duplicate services into the Automotive group when the tenant's
        // vertical is automotive — otherwise services live under Catalog.
        ...(isAutomotiveVertical ? servicesChildren : []),
        { key: 'scheduling', href: '/scheduling', icon: Calendar, module: 'Workshop', permission: 'scheduling' },
        { key: 'workshopWorkOrders', href: '/workshop/work-orders', icon: ClipboardList, module: 'Workshop', permission: 'workshop-work-orders' },
        { key: 'workshopBundles', href: '/workshop/bundles', icon: Package2, module: 'Workshop', permission: 'workshop-bundles' },
        { key: 'workshopTechnicians', href: '/workshop/technicians', icon: Users, module: 'Workshop', permission: 'workshop-technicians' },
        { key: 'workshopPayrollExports', href: '/workshop/payroll-exports', icon: Download, module: 'Workshop', permission: 'workshop-payroll' },
        { key: 'partsCatalog', href: '/parts-catalog', icon: Search, module: 'PlatformIntegration' },
      ],
    },
    // Parapharmacy — vertical-specific
    {
      key: 'parapharmacy',
      icon: Pill,
      module: 'Parapharmacy',
      children: [
        { key: 'ingredients', href: '/parapharmacy/ingredients', icon: Layers3 },
        { key: 'certifications', href: '/parapharmacy/certifications', icon: Award },
        { key: 'healthClaims', href: '/parapharmacy/health-claims', icon: ListChecks },
        { key: 'keyComponents', href: '/parapharmacy/key-components', icon: Package },
      ],
    },
    // Owner Reports dashboard — gated to dashboard.owner permission via ownerReports mapping.
    {
      key: 'reports',
      href: '/reports',
      icon: BarChart3,
      permission: 'ownerReports',
    },
    // Settings — the only bottom item. Its sub-pages (refund policies,
    // customer-history audit, …) are reached from the Settings hub page.
    {
      key: 'settings',
      href: '/settings',
      icon: Settings,
      permission: 'settings',
      section: 'bottom',
    },
  ]

  return nav
}

function parseExpandedModules(value: string | null): Set<string> {
  if (!value) return new Set<string>()

  const parsed: unknown = JSON.parse(value)
  if (!Array.isArray(parsed)) return new Set<string>()

  return new Set(parsed.filter((item): item is string => typeof item === 'string'))
}

export function Sidebar({ isOpen = true, onClose }: SidebarProps) {
  const { t } = useTranslation(['common', 'catalog', 'finance'])
  const location = useLocation()
  const { canAccessModule } = usePermissions()
  const { hasModule, config } = useCompanyConfig()
  const { isOtospex, productName } = useProductConfig()
  const catalogVertical = companyVerticalToCatalog(config?.vertical)
  const isAutomotiveVertical = AUTOMOTIVE_VERTICALS.has(config?.vertical ?? '')

  const getNavLabel = useCallback(
    (key: string, labelKey?: string): string => {
      if (labelKey) {
        return t(labelKey)
      }
      const verticalLabelKey = VERTICAL_NAV_KEYS[key]
      if (verticalLabelKey && catalogVertical !== 'generic') {
        return t(`catalog:vertical.${catalogVertical}.${verticalLabelKey}`)
      }
      return t(`navigation.${key}`)
    },
    [catalogVertical, t]
  )

  // Collapsed state with localStorage persistence
  const [isCollapsed, setIsCollapsed] = useState<boolean>(() => {
    try {
      const stored = localStorage.getItem(COLLAPSED_STORAGE_KEY)
      return stored === 'true'
    } catch {
      return false
    }
  })

  // Persist collapsed state to localStorage
  useEffect(() => {
    localStorage.setItem(COLLAPSED_STORAGE_KEY, String(isCollapsed))
  }, [isCollapsed])

  /**
   * Visibility gate for a nav item — fail-closed on both axes.
   *
   * - `module` declared → at least ONE of the backend modules must be in
   *   the tenant's `all_enabled_modules` (no fallback to "visible").
   * - `permission` declared → the user's role must grant it.
   */
  const isNavItemVisible = useCallback(
    (module?: BackendModule | BackendModule[], permission?: string): boolean => {
      if (module !== undefined) {
        const names = Array.isArray(module) ? module : [module]
        if (!names.some((name) => hasModule(name))) {
          return false
        }
      }
      if (permission !== undefined && !canAccessModule(permission)) {
        return false
      }
      return true
    },
    [hasModule, canAccessModule]
  )

  const navigation = useMemo(() => buildNavigation(isAutomotiveVertical), [isAutomotiveVertical])

  // Filter navigation based on BOTH vertical configuration AND user permissions
  const filteredNavigation = useMemo(() => {
    return navigation
      .filter((module) => isNavItemVisible(module.module, module.permission))
      .map((module) => {
        if (!module.children) return module
        const filteredChildren = module.children.filter((child) =>
          isNavItemVisible(child.module, child.permission)
        )
        return { ...module, children: filteredChildren }
      })
      .filter((module) => {
        // A group whose children are all gated away has nothing to show.
        if (module.children?.length === 0) return false
        return true
      })
  }, [navigation, isNavItemVisible])

  // Split into main and bottom sections
  const mainNavigation = useMemo(
    () => filteredNavigation.filter((m) => m.section !== 'bottom'),
    [filteredNavigation]
  )
  const bottomNavigation = useMemo(
    () => filteredNavigation.filter((m) => m.section === 'bottom'),
    [filteredNavigation]
  )

  // Load expanded state from localStorage
  const [expandedModules, setExpandedModules] = useState<Set<string>>(() => {
    try {
      return parseExpandedModules(localStorage.getItem(STORAGE_KEY))
    } catch {
      // Ignore parsing errors
    }
    // Default: expand modules that contain the current route
    const defaultExpanded = new Set<string>()
    filteredNavigation.forEach((module) => {
      if (module.children) {
        const isChildActive = module.children.some((child) =>
          location.pathname.startsWith(child.href)
        )
        if (isChildActive) {
          defaultExpanded.add(module.key)
        }
      }
    })
    return defaultExpanded
  })

  // Persist expanded state to localStorage
  useEffect(() => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify([...expandedModules]))
  }, [expandedModules])

  // Auto-expand parent module when navigating to a child route
  useEffect(() => {
    filteredNavigation.forEach((module) => {
      if (module.children) {
        const isChildActive = module.children.some((child) =>
          location.pathname.startsWith(child.href)
        )
        if (isChildActive && !expandedModules.has(module.key)) {
          setExpandedModules((prev) => new Set([...prev, module.key]))
        }
      }
    })
  }, [location.pathname, expandedModules, filteredNavigation])

  const toggleModule = useCallback((moduleKey: string) => {
    setExpandedModules((prev) => {
      const next = new Set(prev)
      if (next.has(moduleKey)) {
        next.delete(moduleKey)
      } else {
        next.add(moduleKey)
      }
      return next
    })
  }, [])

  const isModuleActive = (module: NavModule): boolean => {
    if (module.href) {
      return location.pathname === module.href || location.pathname.startsWith(module.href + '/')
    }
    if (module.children) {
      return module.children.some((child) => location.pathname.startsWith(child.href))
    }
    return false
  }

  const isLinkActive = (href: string): boolean => {
    return location.pathname === href || location.pathname.startsWith(href + '/')
  }

  const renderNavItem = (module: NavModule) => {
    const Icon = module.icon
    const isActive = isModuleActive(module)
    const isExpanded = expandedModules.has(module.key)
    const hasChildren = module.children && module.children.length > 0

    const parentActiveClass = isOtospex
      ? (isActive ? `${colorTokens.variants.bgSecondary50} ${colorTokens.variants.textSecondary700} font-semibold` : `${colorTokens.text.muted} ${colorTokens.variants.hoverBgGray200} ${colorTokens.variants.hoverTextGray900}`)
      : (isActive ? `${colorTokens.variants.bgBlue600Alpha20} ${colorTokens.text.inverse}` : `${colorTokens.text.faint} ${colorTokens.variants.hoverBgGray800} ${colorTokens.variants.hoverTextWhite}`)

    if (!hasChildren && module.href) {
      return (
        <li key={module.key}>
          <Link
            to={module.href}
            onClick={onClose}
            className={`flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors ${parentActiveClass} ${isCollapsed ? 'justify-center' : ''}`}
            title={isCollapsed ? getNavLabel(module.key, module.labelKey) : undefined}
          >
            <Icon className="h-5 w-5 flex-shrink-0" />
            {!isCollapsed && getNavLabel(module.key, module.labelKey)}
          </Link>
        </li>
      )
    }

    return (
      <li key={module.key}>
        <button
          type="button"
          onClick={() => { toggleModule(module.key) }}
          className={`flex w-full items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors ${parentActiveClass} ${isCollapsed ? 'justify-center' : ''}`}
          aria-expanded={isExpanded}
          aria-label={`${getNavLabel(module.key, module.labelKey)} - ${isExpanded ? t('actions.collapse') : t('actions.expand')}`}
          title={isCollapsed ? getNavLabel(module.key, module.labelKey) : undefined}
        >
          <Icon className="h-5 w-5 flex-shrink-0" />
          {!isCollapsed && (
            <>
              <span className="flex-1 text-start">{getNavLabel(module.key, module.labelKey)}</span>
              {isExpanded ? (
                <ChevronDown className="h-4 w-4 flex-shrink-0" />
              ) : (
                <ChevronRight className="h-4 w-4 flex-shrink-0 rtl:rotate-180" />
              )}
            </>
          )}
        </button>

        {isExpanded && !isCollapsed && module.children && (
          <ul className="mt-1 space-y-1 ps-4">
            {module.children.map((child) => {
              const ChildIcon = child.icon
              const isChildActive = isLinkActive(child.href)

              const childActiveClass = isOtospex
                ? (isChildActive ? `${colorTokens.variants.bgSecondary50} ${colorTokens.variants.textSecondary700} font-medium` : `${colorTokens.text.subtle} ${colorTokens.variants.hoverBgGray200} ${colorTokens.variants.hoverTextGray800}`)
                : (isChildActive ? `${colorTokens.variants.bgBlue600Alpha20} ${colorTokens.text.inverse} font-medium` : `${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray800} ${colorTokens.variants.hoverTextGray200}`)

              return (
                <li key={child.key}>
                  <Link
                    to={child.href}
                    onClick={onClose}
                    className={`flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition-colors ${childActiveClass}`}
                  >
                    <ChildIcon className="h-4 w-4 flex-shrink-0" />
                    {getNavLabel(child.key, child.labelKey)}
                  </Link>
                </li>
              )
            })}
          </ul>
        )}
      </li>
    )
  }

  return (
    <>
      {/* Mobile overlay */}
      {isOpen && onClose && (
        <div
          className={`fixed inset-0 z-40 ${colorTokens.surface.overlay} lg:hidden`}
          onClick={onClose}
          aria-hidden="true"
        />
      )}

      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 start-0 z-50 flex flex-col transition-all duration-300 lg:static lg:translate-x-0 rtl:lg:-translate-x-0 ${
          isOtospex
            ? `${colorTokens.surface.muted} border-e ${colorTokens.border.subtle}`
            : `${colorTokens.surface.inverseStrong}`
        } ${
          isCollapsed ? 'w-16' : 'w-64'
        } ${
          isOpen ? 'translate-x-0 rtl:-translate-x-0' : '-translate-x-full rtl:translate-x-full'
        }`}
      >
        {/* Logo */}
        <div className={`flex h-16 items-center ${
          isOtospex ? `border-b ${colorTokens.border.subtle}` : `border-b ${colorTokens.variants.borderGray800}`
        } ${
          isCollapsed ? 'justify-center px-2' : 'justify-between px-6'
        }`}>
          {!isCollapsed && (
            <span className={`text-xl font-bold ${isOtospex ? `${colorTokens.text.primary}` : `${colorTokens.text.inverse}`}`}>{productName}</span>
          )}
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => { setIsCollapsed(!isCollapsed) }}
              className={`hidden lg:block rounded-lg p-1 ${
                isOtospex
                  ? `${colorTokens.text.subtle} ${colorTokens.variants.hoverBgGray200} ${colorTokens.variants.hoverTextGray700}`
                  : `${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray800} ${colorTokens.variants.hoverTextGray200}`
              }`}
              aria-label={isCollapsed ? t('actions.expand') : t('actions.collapse')}
            >
              {isCollapsed ? (
                <PanelLeft className="h-5 w-5" />
              ) : (
                <PanelLeftClose className="h-5 w-5" />
              )}
            </button>
            {onClose && (
              <button
                type="button"
                onClick={onClose}
                className={`rounded-lg p-1 lg:hidden ${
                  isOtospex
                    ? `${colorTokens.text.subtle} ${colorTokens.variants.hoverBgGray200} ${colorTokens.variants.hoverTextGray700}`
                    : `${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray800} ${colorTokens.variants.hoverTextGray200}`
                }`}
                aria-label={t('actions.close')}
              >
                <X className="h-5 w-5" />
              </button>
            )}
          </div>
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto px-3 py-4">
          <ul className="space-y-1">
            {mainNavigation.map(renderNavItem)}
          </ul>
        </nav>

        {/* Bottom navigation (Settings) */}
        {bottomNavigation.length > 0 && (
          <div className={`border-t px-3 py-3 ${isOtospex ? `${colorTokens.border.subtle}` : colorTokens.variants.borderGray800}`}>
            <ul className="space-y-1">
              {bottomNavigation.map(renderNavItem)}
            </ul>
          </div>
        )}
      </aside>
    </>
  )
}
