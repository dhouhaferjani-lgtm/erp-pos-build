import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ChevronRight, Home } from 'lucide-react'

interface BreadcrumbItem {
  label: string
  href?: string | undefined
}

interface BreadcrumbProps {
  items?: BreadcrumbItem[]
}

// Route to breadcrumb mapping
const routeToBreadcrumb: Partial<Record<string, { parent?: string; labelKey: string }>> = {
  '/dashboard': { labelKey: 'navigation.dashboard' },
  '/sales': { labelKey: 'navigation.sales' },
  '/sales/customers': { parent: '/sales', labelKey: 'navigation.customers' },
  '/sales/quotes': { parent: '/sales', labelKey: 'navigation.quotes' },
  '/sales/orders': { parent: '/sales', labelKey: 'navigation.salesOrders' },
  '/sales/invoices': { parent: '/sales', labelKey: 'navigation.invoices' },
  '/sales/credit-notes': { parent: '/sales', labelKey: 'navigation.creditNotes' },
  '/purchases': { labelKey: 'navigation.purchases' },
  '/purchases/suppliers': { parent: '/purchases', labelKey: 'navigation.suppliers' },
  '/purchases/orders': { parent: '/purchases', labelKey: 'navigation.purchaseOrders' },
  '/inventory': { labelKey: 'navigation.inventory' },
  '/inventory/products': { parent: '/inventory', labelKey: 'navigation.products' },
  '/inventory/stock': { parent: '/inventory', labelKey: 'navigation.stockLevels' },
  '/inventory/delivery-notes': { parent: '/inventory', labelKey: 'navigation.deliveryNotes' },
  '/vehicles': { labelKey: 'navigation.vehicles' },
  '/treasury': { labelKey: 'navigation.treasury' },
  '/treasury/payments': { parent: '/treasury', labelKey: 'navigation.payments' },
  '/treasury/instruments': { parent: '/treasury', labelKey: 'navigation.instruments' },
  '/treasury/repositories': { parent: '/treasury', labelKey: 'navigation.repositories' },
  '/reports': { labelKey: 'navigation.reports' },
  '/settings': { labelKey: 'navigation.settings' },
}

function buildBreadcrumbsFromPath(pathname: string, t: (key: string) => string): BreadcrumbItem[] {
  const breadcrumbs: BreadcrumbItem[] = []

  // Find the base path (strip :id segments)
  const segments = pathname.split('/').filter(Boolean)
  let currentPath = ''

  for (let i = 0; i < segments.length; i++) {
    const segment = segments[i]
    currentPath += `/${segment}`

    // Check if this segment is a UUID (detail page)
    const isUuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(segment)
    const isNew = segment === 'new'
    const isEdit = segment === 'edit'

    if (isUuid) {
      // This is a detail page, add "Details" breadcrumb
      breadcrumbs.push({ label: t('details') })
    } else if (isNew) {
      breadcrumbs.push({ label: t('actions.add') })
    } else if (isEdit) {
      breadcrumbs.push({ label: t('actions.edit') })
    } else {
      const routeConfig = routeToBreadcrumb[currentPath]
      if (routeConfig != null) {
        const label = routeConfig.labelKey.startsWith('navigation.')
          ? t(routeConfig.labelKey)
          : routeConfig.labelKey

        // Only add href if this isn't the last segment
        const isLast = i === segments.length - 1
        breadcrumbs.push({
          label,
          href: isLast ? undefined : currentPath,
        })
      }
    }
  }

  return breadcrumbs
}

export function Breadcrumb({ items }: BreadcrumbProps) {
  const { t } = useTranslation()
  const location = useLocation()

  // Use provided items or generate from current path
  const breadcrumbItems = items ?? buildBreadcrumbsFromPath(location.pathname, t)

  // Don't render if we're on dashboard or only have one item
  if (location.pathname === '/dashboard' || breadcrumbItems.length <= 1) {
    return null
  }

  return (
    <nav aria-label={t('navigation.breadcrumb')} className="mb-4">
      <ol className="flex items-center gap-1 text-sm">
        {/* Home link */}
        <li>
          <Link
            to="/dashboard"
            className="flex items-center text-gray-500 hover:text-gray-700 transition-colors"
            aria-label={t('navigation.dashboard')}
          >
            <Home className="h-4 w-4" />
          </Link>
        </li>

        {/* Separator */}
        <li aria-hidden="true">
          <ChevronRight className="h-4 w-4 text-gray-400 rtl:rotate-180" />
        </li>

        {/* Breadcrumb items */}
        {breadcrumbItems.map((item, index) => {
          const isLast = index === breadcrumbItems.length - 1

          return (
            <li key={index} className="flex items-center gap-1">
              {item.href && !isLast ? (
                <Link
                  to={item.href}
                  className="text-gray-500 hover:text-gray-700 transition-colors"
                >
                  {item.label}
                </Link>
              ) : (
                <span className={isLast ? 'font-medium text-gray-900' : 'text-gray-500'}>
                  {item.label}
                </span>
              )}

              {!isLast && (
                <ChevronRight className="h-4 w-4 text-gray-400 rtl:rotate-180" aria-hidden="true" />
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
