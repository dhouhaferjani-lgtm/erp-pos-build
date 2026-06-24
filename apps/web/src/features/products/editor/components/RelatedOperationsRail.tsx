import React from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ClipboardList, ShoppingCart, Receipt, ArrowLeftRight } from 'lucide-react'
import { tokens, textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface RelatedOperation {
  /** Stable key + i18n label key suffix under catalog.editor.related.*. */
  key: 'newCount' | 'startPurchase' | 'startSale' | 'viewMovements'
  to: string
  icon: React.ComponentType<{ className?: string }>
}

/**
 * Existing routes only — NO `?productId=` deep links yet (Stage 5).
 */
const OPERATIONS: readonly RelatedOperation[] = [
  { key: 'newCount', to: '/inventory/counting/create', icon: ClipboardList },
  { key: 'startPurchase', to: '/purchases/orders/new', icon: ShoppingCart },
  { key: 'startSale', to: '/sales/quotes/new', icon: Receipt },
  { key: 'viewMovements', to: '/inventory/movements', icon: ArrowLeftRight },
]

/**
 * RelatedOperationsRail — right-rail card of shortcut links to operations
 * related to the product being edited.
 *
 * In `disabled` (create) mode the shortcuts render as muted, non-interactive
 * rows (no anchors) since the operations are only meaningful once the product
 * is persisted.
 */
export function RelatedOperationsRail({
  disabled = false,
}: {
  disabled?: boolean
}): React.JSX.Element {
  const { t } = useTranslation('catalog')

  const rowClass = 'flex items-center gap-2.5 py-1.5 text-sm'

  return (
    <div className={tokens.card.base}>
      <h3 className={cn(tokens.heading.section, 'mb-3 font-[family-name:var(--font-display)]')}>
        {t('editor.related.title')}
      </h3>
      <ul className="flex flex-col gap-0.5" role="list">
        {OPERATIONS.map(({ key, to, icon: Icon }) => {
          const label = t(`editor.related.${key}`)
          return (
            <li key={key}>
              {disabled ? (
                <span className={cn(rowClass, textColors.disabled)}>
                  <Icon className="h-4 w-4 shrink-0" />
                  {label}
                </span>
              ) : (
                <Link
                  to={to}
                  className={cn(rowClass, textColors.tertiary, textColors.hoverPrimary)}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  {label}
                </Link>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
