import { useState, useEffect, useCallback, useMemo } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  LayoutDashboard,
  ShoppingCart,
  Users,
  FileText,
  ClipboardList,
  Receipt,
  Truck,
  Package,
  Layers,
  FileBox,
  Car,
  Wallet,
  BarChart3,
  Settings,
  X,
  ChevronDown,
  ChevronRight,
  Calculator,
  BookOpen,
  FileSpreadsheet,
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
} from 'lucide-react'
import { usePermissions } from '../../../hooks/usePermissions'
import { useCompanyConfig } from '../../../contexts'
import { useProductConfig } from '../../../contexts/ProductConfigContext'
import { companyVerticalToCatalog } from '../../../features/catalog/hooks/useVerticalLabels'

const STORAGE_KEY = 'autoerp-sidebar-expanded'
const COLLAPSED_STORAGE_KEY = 'autoerp-sidebar-collapsed'

/**
 * Maps sidebar navigation keys to backend module names.
 *
 * Modules not in this map are considered "always visible" (core modules)
 * and will be shown regardless of vertical configuration.
 *
 * For groups that aggregate multiple modules, the value is an array —
 * the group is visible if ANY of the listed modules are enabled.
 */
const MODULE_NAME_MAP: Record<string, string | string[]> = {
  vehicles: 'Vehicle',
  services: 'Workshop',
  'composite-items': 'CompositeItems',
  parapharmacy: 'Parapharmacy',
  'parts-catalog': 'PlatformIntegration',
  automotive: ['Vehicle', 'Workshop', 'PlatformIntegration'],
  marketing: ['Promotions', 'Coupons', 'Loyalty'],
  // Inventory module mappings
  inventory: 'Inventory',
  inventoryAndCatalog: 'Inventory',
  products: 'Inventory',
  categories: 'Inventory',
  stockLevels: 'Inventory',
  stockMovements: 'Inventory',
  counting: 'Inventory',
  purchaseOrders: 'Inventory',
}

interface NavChild {
  key: string
  href: string
  icon: React.ComponentType<{ className?: string }>
  module?: string
}

interface NavModule {
  key: string
  icon: React.ComponentType<{ className?: string }>
  href?: string
  children?: NavChild[]
  module?: string | string[]
  section?: 'main' | 'bottom'
}

interface SidebarProps {
  isOpen?: boolean
  onClose?: () => void
}

// Navigation keys that should adapt based on company vertical
const VERTICAL_NAV_KEYS: Record<string, string> = {
  catalog: 'compositeItems',
  compositeItems: 'compositeItems',
  modifierGroups: 'modifierGroup',
}

/**
 * Build the navigation array dynamically based on vertical config.
 *
 * For Otospex: Services appear under the Automotive group.
 * For other verticals: Services appear under Inventory & Catalog; Automotive is hidden.
 */
function buildNavigation(isOtospex: boolean): NavModule[] {
  const servicesChildren: NavChild[] = [
    { key: 'allServices', href: '/services', icon: Wrench, module: 'services' },
    { key: 'serviceCategories', href: '/services/categories', icon: FolderTree, module: 'services' },
  ]

  const inventoryChildren: NavChild[] = [
    { key: 'products', href: '/inventory/products', icon: Package },
    { key: 'categories', href: '/inventory/categories', icon: FolderTree },
    // Services placed here for non-Otospex verticals
    ...(!isOtospex ? servicesChildren : []),
    { key: 'batches', href: '/inventory/batches', icon: Pill, module: 'parapharmacy' },
    { key: 'stockLevels', href: '/inventory/stock', icon: Layers },
    { key: 'stockMovements', href: '/inventory/movements', icon: ArrowLeftRight },
    { key: 'counting', href: '/inventory/counting', icon: ClipboardCheck },
    { key: 'priceLists', href: '/pricing/price-lists', icon: Tag, module: 'pricing' },
    { key: 'compositeItems', href: '/catalog/composite-items', icon: Combine, module: 'composite-items' },
    { key: 'modifierGroups', href: '/catalog/modifier-groups', icon: Layers, module: 'modifier-groups' },
    { key: 'menus', href: '/catalog/menus', icon: BookOpen, module: 'composite-items' },
  ]

  const nav: NavModule[] = [
    {
      key: 'dashboard',
      href: '/dashboard',
      icon: LayoutDashboard,
    },
    {
      key: 'sales',
      icon: ShoppingCart,
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
      children: [
        { key: 'suppliers', href: '/purchases/suppliers', icon: Users },
        { key: 'purchaseOrders', href: '/purchases/orders', icon: ClipboardList },
        { key: 'goodsReceipts', href: '/purchases/receipts', icon: Package },
        { key: 'returnNotes', href: '/inventory/return-notes', icon: RotateCcw },
      ],
    },
    {
      key: 'inventoryAndCatalog',
      icon: Package,
      module: 'inventory',
      children: inventoryChildren,
    },
    {
      key: 'pointOfSale',
      icon: Store,
      module: 'pos',
      children: [
        { key: 'openPos', href: '/pos/transactions', icon: Store, module: 'pos' },
        { key: 'posOrders', href: '/pos/orders', icon: ClipboardList, module: 'pos' },
        { key: 'tables', href: '/pos/tables', icon: LayoutGrid, module: 'pos' },
        { key: 'kitchen', href: '/pos/kitchen', icon: ChefHat, module: 'pos' },
        { key: 'terminals', href: '/pos/terminals', icon: Monitor, module: 'pos' },
        { key: 'shiftHistory', href: '/pos/shift-history', icon: History, module: 'pos' },
        { key: 'receipts', href: '/pos/receipts', icon: Receipt, module: 'pos' },
      ],
    },
    {
      key: 'marketing',
      icon: Tag,
      module: ['promotions', 'coupons', 'loyalty', 'contacts'],
      children: [
        { key: 'promotions', href: '/pos/promotions', icon: Tag, module: 'promotions' },
        { key: 'coupons', href: '/pos/coupons', icon: Ticket, module: 'coupons' },
        { key: 'loyaltyPrograms', href: '/pos/loyalty/programs', icon: Award, module: 'loyalty' },
        { key: 'loyaltyMembers', href: '/pos/loyalty/members', icon: Users, module: 'loyalty' },
        { key: 'companies', href: '/crm/companies', icon: Building2, module: 'contacts' },
        { key: 'contacts', href: '/crm/contacts', icon: Users, module: 'contacts' },
      ],
    },
    {
      key: 'financeAndReports',
      icon: Calculator,
      module: ['accounts', 'treasury', 'reports'],
      children: [
        { key: 'payments', href: '/treasury/payments', icon: Wallet, module: 'treasury' },
        { key: 'expenses', href: '/expenses', icon: Receipt, module: 'treasury' },
        { key: 'withholdingCertificates', href: '/treasury/withholding-certificates', icon: FileCheck, module: 'withholding' },
        { key: 'chartOfAccounts', href: '/finance/chart-of-accounts', icon: BookOpen, module: 'accounts' },
        { key: 'generalLedger', href: '/finance/ledger', icon: FileSpreadsheet, module: 'accounts' },
        { key: 'journalEntries', href: '/finance/journal-entries', icon: FileSpreadsheet, module: 'accounts' },
        { key: 'trialBalance', href: '/finance/trial-balance', icon: Scale, module: 'accounts' },
        { key: 'profitLoss', href: '/finance/profit-loss', icon: TrendingUp, module: 'accounts' },
        { key: 'balanceSheet', href: '/finance/balance-sheet', icon: PieChart, module: 'accounts' },
        { key: 'agedReceivables', href: '/finance/aged-receivables', icon: Clock, module: 'accounts' },
        { key: 'agedPayables', href: '/finance/aged-payables', icon: Clock, module: 'accounts' },
        { key: 'analytics', href: '/pos/analytics', icon: BarChart3, module: 'pos' },
        { key: 'zReports', href: '/pos/z-reports', icon: FileCheck, module: 'pos' },
      ],
    },
  ]

  // Automotive group — Otospex only
  if (isOtospex) {
    nav.push({
      key: 'automotive',
      icon: Car,
      module: 'automotive',
      children: [
        { key: 'vehicles', href: '/vehicles', icon: Car, module: 'vehicles' },
        ...servicesChildren,
        { key: 'partsCatalog', href: '/parts-catalog', icon: Search, module: 'parts-catalog' },
      ],
    })
  }

  // Parapharmacy — vertical-specific
  nav.push({
    key: 'parapharmacy',
    icon: Pill,
    module: 'parapharmacy',
    children: [
      { key: 'ingredients', href: '/parapharmacy/ingredients', icon: Layers3, module: 'parapharmacy' },
      { key: 'certifications', href: '/parapharmacy/certifications', icon: Award, module: 'parapharmacy' },
      { key: 'healthClaims', href: '/parapharmacy/health-claims', icon: ListChecks, module: 'parapharmacy' },
      { key: 'keyComponents', href: '/parapharmacy/key-components', icon: Package, module: 'parapharmacy' },
    ],
  })

  // Settings — pinned to bottom
  nav.push({
    key: 'settings',
    href: '/settings',
    icon: Settings,
    section: 'bottom',
  })

  return nav
}

export function Sidebar({ isOpen = true, onClose }: SidebarProps) {
  const { t } = useTranslation(['common', 'catalog'])
  const location = useLocation()
  const { canAccessModule } = usePermissions()
  const { hasModule, config } = useCompanyConfig()
  const { isOtospex, productName } = useProductConfig()
  const catalogVertical = companyVerticalToCatalog(config?.vertical)

  const getNavLabel = useCallback(
    (key: string): string => {
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
   * Check if a module should be visible based on vertical configuration.
   *
   * Supports both single string and string[] module keys.
   * For arrays, returns true if ANY of the modules are enabled.
   */
  const isModuleEnabledForVertical = useCallback(
    (moduleKey: string | string[]): boolean => {
      if (Array.isArray(moduleKey)) {
        return moduleKey.some((key) => {
          const backendName = MODULE_NAME_MAP[key]
          if (!backendName) return true
          if (Array.isArray(backendName)) {
            return backendName.some((name) => hasModule(name))
          }
          return hasModule(backendName)
        })
      }

      const backendModuleName = MODULE_NAME_MAP[moduleKey]
      if (!backendModuleName) return true

      if (Array.isArray(backendModuleName)) {
        return backendModuleName.some((name) => hasModule(name))
      }

      return hasModule(backendModuleName)
    },
    [hasModule]
  )

  const navigation = useMemo(() => buildNavigation(isOtospex), [isOtospex])

  // Filter navigation based on BOTH vertical configuration AND user permissions
  const filteredNavigation = useMemo(() => {
    return navigation
      .filter((module) => {
        const moduleKey = module.module ?? module.key

        // First check: Is this module enabled for the current vertical?
        if (!isModuleEnabledForVertical(moduleKey)) {
          return false
        }

        // Second check: Does the user have permission to access this module?
        if (Array.isArray(moduleKey)) {
          return moduleKey.some((key) => canAccessModule(key))
        }
        return canAccessModule(moduleKey)
      })
      .map((module) => {
        if (!module.children) return module
        // Filter children based on their module permissions
        const filteredChildren = module.children.filter((child) => {
          const childModuleKey = child.module ?? (Array.isArray(module.module) ? module.key : (module.module ?? module.key))

          // Check vertical first
          if (!isModuleEnabledForVertical(childModuleKey)) {
            return false
          }

          return canAccessModule(childModuleKey)
        })
        return { ...module, children: filteredChildren }
      })
      .filter((module) => {
        if (module.children && module.children.length === 0) return false
        return true
      })
  }, [navigation, canAccessModule, isModuleEnabledForVertical])

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
      const stored = localStorage.getItem(STORAGE_KEY)
      if (stored) {
        const parsed = JSON.parse(stored) as string[]
        return new Set(parsed)
      }
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
      ? (isActive ? 'bg-secondary-50 text-secondary-700 font-semibold' : 'text-gray-600 hover:bg-gray-200 hover:text-gray-900')
      : (isActive ? 'bg-blue-600/20 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white')

    if (!hasChildren && module.href) {
      return (
        <li key={module.key}>
          <Link
            to={module.href}
            onClick={onClose}
            className={`flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors ${parentActiveClass} ${isCollapsed ? 'justify-center' : ''}`}
            title={isCollapsed ? getNavLabel(module.key) : undefined}
          >
            <Icon className="h-5 w-5 flex-shrink-0" />
            {!isCollapsed && getNavLabel(module.key)}
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
          aria-label={`${getNavLabel(module.key)} - ${isExpanded ? t('actions.collapse') : t('actions.expand')}`}
          title={isCollapsed ? getNavLabel(module.key) : undefined}
        >
          <Icon className="h-5 w-5 flex-shrink-0" />
          {!isCollapsed && (
            <>
              <span className="flex-1 text-start">{getNavLabel(module.key)}</span>
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
                ? (isChildActive ? 'bg-secondary-50 text-secondary-700 font-medium' : 'text-gray-500 hover:bg-gray-200 hover:text-gray-800')
                : (isChildActive ? 'bg-blue-600/20 text-white font-medium' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200')

              return (
                <li key={child.key}>
                  <Link
                    to={child.href}
                    onClick={onClose}
                    className={`flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition-colors ${childActiveClass}`}
                  >
                    <ChildIcon className="h-4 w-4 flex-shrink-0" />
                    {getNavLabel(child.key)}
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
          className="fixed inset-0 z-40 bg-black/50 lg:hidden"
          onClick={onClose}
          aria-hidden="true"
        />
      )}

      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 start-0 z-50 flex flex-col transition-all duration-300 lg:static lg:translate-x-0 rtl:lg:-translate-x-0 ${
          isOtospex
            ? 'bg-gray-100 border-e border-gray-200'
            : 'bg-gray-900'
        } ${
          isCollapsed ? 'w-16' : 'w-64'
        } ${
          isOpen ? 'translate-x-0 rtl:-translate-x-0' : '-translate-x-full rtl:translate-x-full'
        }`}
      >
        {/* Logo */}
        <div className={`flex h-16 items-center ${
          isOtospex ? 'border-b border-gray-200' : 'border-b border-gray-800'
        } ${
          isCollapsed ? 'justify-center px-2' : 'justify-between px-6'
        }`}>
          {!isCollapsed && (
            <span className={`text-xl font-bold ${isOtospex ? 'text-gray-900' : 'text-white'}`}>{productName}</span>
          )}
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => { setIsCollapsed(!isCollapsed) }}
              className={`hidden lg:block rounded-lg p-1 ${
                isOtospex
                  ? 'text-gray-500 hover:bg-gray-200 hover:text-gray-700'
                  : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200'
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
                    ? 'text-gray-500 hover:bg-gray-200 hover:text-gray-700'
                    : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200'
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
          <div className={`border-t px-3 py-3 ${isOtospex ? 'border-gray-200' : 'border-gray-800'}`}>
            <ul className="space-y-1">
              {bottomNavigation.map(renderNavItem)}
            </ul>
          </div>
        )}
      </aside>
    </>
  )
}
