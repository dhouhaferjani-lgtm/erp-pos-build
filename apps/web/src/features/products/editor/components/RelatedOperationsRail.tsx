import React from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { SquarePen, Truck, ShoppingCart, ChevronRight } from 'lucide-react'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface RelatedOperation {
  /** Stable key + i18n label key suffix under catalog.editor.related.*. */
  key: 'newCount' | 'startPurchase' | 'startSale'
  /** i18n key suffix for the row subtitle. */
  subtitleKey: 'subtitleNewCount' | 'subtitleStartPurchase' | 'subtitleStartSale'
  to: string
  icon: React.ComponentType<{ className?: string }>
  /** Colored icon-chip classes (bg + text). */
  chip: string
}

/**
 * Existing routes only — NO `?productId=` deep links yet (Stage 5).
 *
 * Each row has a colored 30×30 icon chip matching the mock:
 * - adjustment → secondary-50 / secondary-600 (orange-tinted)
 * - purchase   → primary-50 / primary-600 (navy)
 * - sale       → green tint via theme-success var
 */
const OPERATIONS: readonly RelatedOperation[] = [
  {
    key: 'newCount',
    subtitleKey: 'subtitleNewCount',
    to: '/inventory/counting/create',
    icon: SquarePen,
    chip: 'bg-secondary-50 text-secondary-600',
  },
  {
    key: 'startPurchase',
    subtitleKey: 'subtitleStartPurchase',
    to: '/purchases/orders/new',
    icon: Truck,
    chip: 'bg-primary-50 text-primary-600',
  },
  {
    key: 'startSale',
    subtitleKey: 'subtitleStartSale',
    to: '/sales/quotes/new',
    // Green tint: bg uses a soft theme-success wash, icon the brand green var.
    icon: ShoppingCart,
    chip: 'bg-[rgba(31,138,91,.12)] text-[var(--theme-success)]',
  },
]

const MOVEMENTS_ROUTE = '/inventory/movements'

/**
 * RelatedOperationsRail — right-rail card of shortcut links to operations
 * related to the product being edited. Matches the mock: a subtitle, three
 * bordered rows with colored icon chips + a row subtitle + a chevron, then a
 * "View stock movements ›" text link.
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

  const rowClass = cn(
    'flex items-center gap-2.5 rounded-[10px] border px-3 py-2.5 text-left transition-colors',
    borderColors.light,
  )

  return (
    <div className={tokens.card.base}>
      <h3
        className={cn(
          'mb-1 text-[11px] font-bold uppercase tracking-[0.1em]',
          textColors.tertiary,
        )}
      >
        {t('editor.related.title')}
      </h3>
      <p className={cn('mb-3 text-xs', textColors.disabled)}>{t('editor.related.subtitle')}</p>

      <ul className="flex flex-col gap-2" role="list">
        {OPERATIONS.map(({ key, subtitleKey, to, icon: Icon, chip }) => {
          const label = t(`editor.related.${key}`)
          const subtitle = t(`editor.related.${subtitleKey}`)
          const body = (
            <>
              <span
                className={cn(
                  'flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-lg',
                  chip,
                )}
              >
                <Icon className="h-4 w-4" />
              </span>
              <span className="min-w-0 flex-1">
                <span className={cn('block text-[13.5px] font-semibold', textColors.primary)}>
                  {label}
                </span>
                <span className={cn('block text-xs', textColors.disabled)}>{subtitle}</span>
              </span>
              <ChevronRight className={cn('h-4 w-4 shrink-0', textColors.disabled)} />
            </>
          )

          return (
            <li key={key}>
              {disabled ? (
                <span className={cn(rowClass, 'opacity-60')}>{body}</span>
              ) : (
                <Link to={to} className={cn(rowClass, 'hover:border-primary-600 hover:bg-primary-50')}>
                  {body}
                </Link>
              )}
            </li>
          )
        })}
      </ul>

      {/* View stock movements text link */}
      <div className="mt-2 px-1">
        {disabled ? (
          <span className={cn('inline-flex items-center gap-1 text-sm font-semibold', textColors.disabled)}>
            {t('editor.related.viewMovements')}
            <ChevronRight className="h-3.5 w-3.5" />
          </span>
        ) : (
          <Link
            to={MOVEMENTS_ROUTE}
            className={cn(
              'inline-flex items-center gap-1 text-sm font-semibold',
              textColors.brand,
              'hover:text-secondary-600',
            )}
          >
            {t('editor.related.viewMovements')}
            <ChevronRight className="h-3.5 w-3.5" />
          </Link>
        )}
      </div>
    </div>
  )
}
