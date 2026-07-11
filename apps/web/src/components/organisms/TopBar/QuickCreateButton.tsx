import { useState, useRef, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  Plus,
  FileText,
  FileCheck,
  UserPlus,
  Package,
  Banknote,
  Receipt,
  ShoppingCart,
  Monitor,
} from 'lucide-react'
import { usePermissions, type Permission } from '../../../hooks/usePermissions'
import type { LucideIcon } from 'lucide-react'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface QuickCreateAction {
  labelKey: string
  path: string
  permission: Permission
  icon: LucideIcon
}

const QUICK_CREATE_ACTIONS: QuickCreateAction[] = [
  {
    labelKey: 'common:quickCreate.newInvoice',
    path: '/sales/invoices/new',
    permission: 'sales.create',
    icon: FileText,
  },
  {
    labelKey: 'common:quickCreate.newQuote',
    path: '/sales/quotes/new',
    permission: 'sales.create',
    icon: FileCheck,
  },
  {
    labelKey: 'common:quickCreate.newCustomer',
    path: '/sales/customers/new',
    permission: 'sales.create',
    icon: UserPlus,
  },
  {
    labelKey: 'common:quickCreate.newProduct',
    path: '/inventory/products/new',
    permission: 'inventory.create',
    icon: Package,
  },
  {
    labelKey: 'common:quickCreate.newPayment',
    path: '/treasury/payments/new',
    permission: 'treasury.create',
    icon: Banknote,
  },
  {
    labelKey: 'common:quickCreate.newExpense',
    path: '/expenses/new',
    permission: 'expenses.create',
    icon: Receipt,
  },
  {
    labelKey: 'common:quickCreate.newPurchaseOrder',
    path: '/purchases/orders/new',
    permission: 'purchases.create',
    icon: ShoppingCart,
  },
  {
    labelKey: 'common:quickCreate.openPos',
    path: '/pos/transactions',
    permission: 'pos.operate_terminal',
    icon: Monitor,
  },
]

export function QuickCreateButton() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { hasPermission } = usePermissions()
  const [isOpen, setIsOpen] = useState(false)
  const dropdownRef = useRef<HTMLDivElement>(null)

  const visibleActions = QUICK_CREATE_ACTIONS.filter((action) =>
    hasPermission(action.permission),
  )

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (
        dropdownRef.current &&
        !dropdownRef.current.contains(event.target as Node)
      ) {
        setIsOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [])

  const handleActionClick = (path: string) => {
    setIsOpen(false)
    void navigate(path)
  }

  if (visibleActions.length === 0) {
    return null
  }

  return (
    <div className="relative" ref={dropdownRef}>
      <button
        type="button"
        onClick={() => {
          setIsOpen(!isOpen)
        }}
        className={`flex h-8 w-8 items-center justify-center rounded-full ${colorTokens.intent.primary.bgStrong} ${colorTokens.text.inverse} shadow-sm ${colorTokens.variants.hoverBgBlue700} focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} focus:ring-offset-2`}
        aria-label={t('common:quickCreate.label')}
        aria-expanded={isOpen}
        aria-haspopup="true"
      >
        <Plus className="h-4 w-4" />
      </button>

      {isOpen && (
        <div className={`absolute start-0 z-50 mt-2 w-56 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} py-1 shadow-lg`}>
          {visibleActions.map((action) => {
            const Icon = action.icon
            return (
              <button
                key={action.path}
                type="button"
                onClick={() => {
                  handleActionClick(action.path)
                }}
                className={`flex w-full items-center gap-3 px-4 py-2 text-sm ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray100}`}
              >
                <Icon className={`h-4 w-4 ${colorTokens.text.disabled}`} />
                <span>{t(action.labelKey)}</span>
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
