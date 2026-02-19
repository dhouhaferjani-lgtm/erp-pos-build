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
  CreditCard,
  Wallet,
  Building2,
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
  PanelLeftClose,
  PanelLeft,
  UtensilsCrossed,
  Combine,
} from 'lucide-react'
import { usePermissions } from '../../../hooks/usePermissions'
import { useCompanyConfig } from '../../../contexts'

const STORAGE_KEY = 'autoerp-sidebar-expanded'
const COLLAPSED_STORAGE_KEY = 'autoerp-sidebar-collapsed'

/**
 * Maps sidebar navigation keys to backend module names
 *
 * This allows the sidebar to use lowercase, user-friendly keys (e.g., "vehicles")
 * while the backend uses PascalCase module names (e.g., "Vehicle").
 *
 * Modules not in this map are considered "always visible" (core modules)
 * and will be shown regardless of vertical configuration.
 */
const MODULE_NAME_MAP: Record<string, string> = {
  vehicles: 'Vehicle',
  'composite-items': 'CompositeItems',
  // services: removed - now a core module available to all verticals
  // Core modules (always visible): dashboard, sales, purchases, inventory, treasury, finance, pricing, reports, settings
  // These don't need mapping as they're not filtered by vertical
}

interface NavChild {
  key: string
  href: string
  icon: React.ComponentType<{ className?: string }>
  module?: string // For permission checking
}

interface NavModule {
  key: string
  icon: React.ComponentType<{ className?: string }>
  href?: string
  children?: NavChild[]
  module?: string // For permission checking - defaults to key if not provided
}

const navigation: NavModule[] = [
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
    ],
  },
  {
    key: 'purchases',
    icon: Truck,
    children: [
      { key: 'suppliers', href: '/purchases/suppliers', icon: Users },
      { key: 'purchaseOrders', href: '/purchases/orders', icon: ClipboardList },
      { key: 'goodsReceipts', href: '/purchases/receipts', icon: Package },
    ],
  },
  {
    key: 'inventory',
    icon: Package,
    children: [
      { key: 'products', href: '/inventory/products', icon: Package },
      { key: 'categories', href: '/inventory/categories', icon: FolderTree },
      { key: 'batches', href: '/inventory/batches', icon: Pill },
      { key: 'stockLevels', href: '/inventory/stock', icon: Layers },
      { key: 'stockMovements', href: '/inventory/movements', icon: ArrowLeftRight },
      { key: 'counting', href: '/inventory/counting', icon: ClipboardCheck },
      { key: 'deliveryNotes', href: '/inventory/delivery-notes', icon: FileBox },
      { key: 'returnNotes', href: '/inventory/return-notes', icon: RotateCcw },
    ],
  },
  {
    key: 'vehicles',
    href: '/vehicles',
    icon: Car,
  },
  {
    key: 'services',
    icon: Wrench,
    children: [
      { key: 'allServices', href: '/services', icon: Wrench },
      { key: 'serviceCategories', href: '/services/categories', icon: FolderTree },
    ],
  },
  {
    key: 'treasury',
    icon: CreditCard,
    children: [
      { key: 'payments', href: '/treasury/payments', icon: Wallet },
      { key: 'withholdingCertificates', href: '/treasury/withholding-certificates', icon: FileCheck, module: 'withholding' },
      { key: 'expenses', href: '/expenses', icon: Receipt },
      { key: 'expenseCategories', href: '/expenses/categories', icon: FolderTree },
      { key: 'instruments', href: '/treasury/instruments', icon: CreditCard },
      { key: 'paymentMethods', href: '/treasury/payment-methods', icon: CreditCard },
      { key: 'repositories', href: '/treasury/repositories', icon: Building2 },
    ],
  },
  {
    key: 'finance',
    icon: Calculator,
    module: 'accounts',
    children: [
      { key: 'chartOfAccounts', href: '/finance/chart-of-accounts', icon: BookOpen },
      { key: 'generalLedger', href: '/finance/ledger', icon: FileSpreadsheet },
      { key: 'trialBalance', href: '/finance/trial-balance', icon: Scale },
      { key: 'profitLoss', href: '/finance/profit-loss', icon: TrendingUp },
      { key: 'balanceSheet', href: '/finance/balance-sheet', icon: PieChart },
      { key: 'agedReceivables', href: '/finance/aged-receivables', icon: Clock },
      { key: 'agedPayables', href: '/finance/aged-payables', icon: Clock },
    ],
  },
  {
    key: 'pricing',
    icon: Tag,
    module: 'pricing',
    children: [
      { key: 'priceLists', href: '/pricing/price-lists', icon: Tag },
    ],
  },
  {
    key: 'reports',
    href: '/reports',
    icon: BarChart3,
  },
  {
    key: 'pos',
    icon: Store,
    module: 'pos',
    children: [
      { key: 'openPos', href: '/pos/transactions', icon: Store, module: 'pos' },
      { key: 'terminals', href: '/pos/terminals', icon: Monitor, module: 'pos' },
      { key: 'shiftHistory', href: '/pos/shift-history', icon: History, module: 'pos' },
      { key: 'zReports', href: '/pos/z-reports', icon: FileCheck, module: 'pos' },
      { key: 'receipts', href: '/pos/receipts', icon: Receipt, module: 'pos' },
    ],
  },
  {
    key: 'parapharmacy',
    icon: Pill,
    module: 'settings',
    children: [
      { key: 'ingredients', href: '/parapharmacy/ingredients', icon: Layers3, module: 'settings' },
      { key: 'certifications', href: '/parapharmacy/certifications', icon: Award, module: 'settings' },
      { key: 'healthClaims', href: '/parapharmacy/health-claims', icon: ListChecks, module: 'settings' },
      { key: 'keyComponents', href: '/parapharmacy/key-components', icon: Package, module: 'settings' },
    ],
  },
  {
    key: 'catalog',
    icon: UtensilsCrossed,
    module: 'composite-items',
    children: [
      { key: 'compositeItems', href: '/catalog/composite-items', icon: Combine, module: 'composite-items' },
      { key: 'modifierGroups', href: '/catalog/modifier-groups', icon: Layers, module: 'modifier-groups' },
    ],
  },
  {
    key: 'settings',
    href: '/settings',
    icon: Settings,
  },
]

interface SidebarProps {
  isOpen?: boolean
  onClose?: () => void
}

export function Sidebar({ isOpen = true, onClose }: SidebarProps) {
  const { t } = useTranslation()
  const location = useLocation()
  const { canAccessModule } = usePermissions()
  const { hasModule } = useCompanyConfig()

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
   * Check if a module should be visible based on vertical configuration
   *
   * Modules in MODULE_NAME_MAP are vertical-specific (e.g., Vehicle, Workshop)
   * and will only show if the tenant's vertical includes them.
   *
   * Modules NOT in the map are core modules (e.g., Sales, Inventory)
   * and are always visible regardless of vertical.
   */
  const isModuleEnabledForVertical = useCallback(
    (moduleKey: string): boolean => {
      const backendModuleName = MODULE_NAME_MAP[moduleKey]

      // If not in the map, it's a core module - always visible
      if (!backendModuleName) {
        return true
      }

      // If in the map, check if the vertical has this module
      return hasModule(backendModuleName)
    },
    [hasModule]
  )

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
        return canAccessModule(moduleKey)
      })
      .map((module) => {
        if (!module.children) return module
        // Filter children based on their module permissions
        const filteredChildren = module.children.filter((child) => {
          // Children inherit parent module permission by default
          const childModuleKey = child.module ?? module.module ?? module.key
          return canAccessModule(childModuleKey)
        })
        return { ...module, children: filteredChildren }
      })
      .filter((module) => {
        // Remove modules with no visible children (if they had children)
        if (module.children && module.children.length === 0) return false
        return true
      })
  }, [canAccessModule, isModuleEnabledForVertical])

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
        className={`fixed inset-y-0 start-0 z-50 flex flex-col bg-gray-900 transition-all duration-300 lg:static lg:translate-x-0 rtl:lg:-translate-x-0 ${
          isCollapsed ? 'w-16' : 'w-64'
        } ${
          isOpen ? 'translate-x-0 rtl:-translate-x-0' : '-translate-x-full rtl:translate-x-full'
        }`}
      >
        {/* Logo */}
        <div className={`flex h-16 items-center border-b border-gray-800 ${
          isCollapsed ? 'justify-center px-2' : 'justify-between px-6'
        }`}>
          {!isCollapsed && (
            <span className="text-xl font-bold text-white">{t('appName')}</span>
          )}
          <div className="flex items-center gap-2">
            {/* Collapse/Expand toggle (desktop only) */}
            <button
              type="button"
              onClick={() => { setIsCollapsed(!isCollapsed) }}
              className="hidden lg:block rounded-lg p-1 text-gray-400 hover:bg-gray-800 hover:text-gray-200"
              aria-label={isCollapsed ? t('actions.expand') : t('actions.collapse')}
            >
              {isCollapsed ? (
                <PanelLeft className="h-5 w-5" />
              ) : (
                <PanelLeftClose className="h-5 w-5" />
              )}
            </button>
            {/* Mobile close button */}
            {onClose && (
              <button
                type="button"
                onClick={onClose}
                className="rounded-lg p-1 text-gray-400 hover:bg-gray-800 hover:text-gray-200 lg:hidden"
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
            {filteredNavigation.map((module) => {
              const Icon = module.icon
              const isActive = isModuleActive(module)
              const isExpanded = expandedModules.has(module.key)
              const hasChildren = module.children && module.children.length > 0

              // Simple link (no children)
              if (!hasChildren && module.href) {
                return (
                  <li key={module.key}>
                    <Link
                      to={module.href}
                      onClick={onClose}
                      className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                        isActive
                          ? 'bg-blue-600/20 text-white'
                          : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                      } ${isCollapsed ? 'justify-center' : ''}`}
                      title={isCollapsed ? t(`navigation.${module.key}`) : undefined}
                    >
                      <Icon className="h-5 w-5 flex-shrink-0" />
                      {!isCollapsed && t(`navigation.${module.key}`)}
                    </Link>
                  </li>
                )
              }

              // Collapsible module with children
              return (
                <li key={module.key}>
                  <button
                    type="button"
                    onClick={() => { toggleModule(module.key) }}
                    className={`flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                      isActive
                        ? 'bg-blue-600/20 text-white'
                        : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                    } ${isCollapsed ? 'justify-center' : ''}`}
                    aria-expanded={isExpanded}
                    aria-label={`${t(`navigation.${module.key}`)} - ${isExpanded ? t('actions.collapse') : t('actions.expand')}`}
                    title={isCollapsed ? t(`navigation.${module.key}`) : undefined}
                  >
                    <Icon className="h-5 w-5 flex-shrink-0" />
                    {!isCollapsed && (
                      <>
                        <span className="flex-1 text-start">{t(`navigation.${module.key}`)}</span>
                        {isExpanded ? (
                          <ChevronDown className="h-4 w-4 flex-shrink-0" />
                        ) : (
                          <ChevronRight className="h-4 w-4 flex-shrink-0 rtl:rotate-180" />
                        )}
                      </>
                    )}
                  </button>

                  {/* Children */}
                  {isExpanded && !isCollapsed && module.children && (
                    <ul className="mt-1 space-y-1 ps-4">
                      {module.children.map((child) => {
                        const ChildIcon = child.icon
                        const isChildActive = isLinkActive(child.href)

                        return (
                          <li key={child.key}>
                            <Link
                              to={child.href}
                              onClick={onClose}
                              className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors ${
                                isChildActive
                                  ? 'bg-blue-600/20 text-white font-medium'
                                  : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200'
                              }`}
                            >
                              <ChildIcon className="h-4 w-4 flex-shrink-0" />
                              {t(`navigation.${child.key}`)}
                            </Link>
                          </li>
                        )
                      })}
                    </ul>
                  )}
                </li>
              )
            })}
          </ul>
        </nav>
      </aside>
    </>
  )
}
