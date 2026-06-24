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
 * derived from the live form state. Green check when satisfied, neutral circle
 * when still pending.
 */
export function BeforePublishChecklist({
  items,
}: {
  items: readonly ChecklistItem[]
}): React.JSX.Element {
  const { t } = useTranslation('catalog')

  return (
    <div className={tokens.card.base}>
      <h3 className={cn(tokens.heading.section, 'mb-3 font-[family-name:var(--font-display)]')}>
        {t('editor.checklist.title')}
      </h3>
      <ul className="flex flex-col gap-1.5" role="list">
        {items.map((item) => (
          <li
            key={item.key}
            data-satisfied={item.satisfied}
            className="flex items-center gap-2.5 text-sm"
          >
            {item.satisfied ? (
              <Check className={cn('h-4 w-4 shrink-0', textColors.success)} aria-hidden="true" />
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
