import React from 'react'
import { useTranslation } from 'react-i18next'
import { Check, Circle } from 'lucide-react'
import { tokens, textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

/** A single readiness check row, keyed to a catalog.editor.checklist.* label. */
export interface ChecklistItem {
  key: 'name' | 'sku' | 'salePrice' | 'tax'
  satisfied: boolean
}

/**
 * BeforePublishChecklist — right-rail card listing the required-field checks
 * derived from the live form state. Matches the mock:
 * - 11px 700 uppercase tracked "Before publish" label.
 * - Each row: a brand-GREEN ✓ (theme-success) when satisfied / a muted ○ when
 *   pending, with the label text in gray-700 / muted gray respectively.
 */
export function BeforePublishChecklist({
  items,
}: {
  items: readonly ChecklistItem[]
}): React.JSX.Element {
  const { t } = useTranslation('catalog')

  return (
    <div className={tokens.card.base}>
      <h3
        className={cn(
          'mb-3 text-[11px] font-bold uppercase tracking-[0.1em]',
          textColors.tertiary,
        )}
      >
        {t('editor.checklist.title')}
      </h3>
      <ul className="flex flex-col gap-2.5" role="list">
        {items.map((item) => (
          <li
            key={item.key}
            data-satisfied={item.satisfied}
            className="flex items-center gap-2.5 text-[13.5px]"
          >
            {item.satisfied ? (
              // Brand green check via theme var (no semantic token resolves to #1F8A5B)
              <Check
                className="h-4 w-4 shrink-0 text-[var(--theme-success)]"
                aria-hidden="true"
              />
            ) : (
              <Circle className={cn('h-4 w-4 shrink-0', textColors.disabled)} aria-hidden="true" />
            )}
            <span className={item.satisfied ? textColors.secondary : textColors.disabled}>
              {t(`editor.checklist.${item.key}`)}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}
