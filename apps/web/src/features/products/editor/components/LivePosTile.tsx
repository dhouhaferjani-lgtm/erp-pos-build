import React from 'react'
import { useTranslation } from 'react-i18next'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface LivePosTileProps {
  /** Live product name from the form (used in the POS card preview). */
  name: string
  /** Pre-formatted sale price string (e.g. "$2.85") from the form. */
  price: string
}

/**
 * LivePosTile — right-rail "Live on POS" card that mirrors how the product
 * will appear on the POS grid: a small (140px) tile with an image placeholder,
 * the product name and the sale price (mono, orange accent).
 *
 * Bound to the form's `name` + `sale_price` so the preview stays live as the
 * operator types. The image is a placeholder until media upload lands (1.7b).
 */
export function LivePosTile({ name, price }: LivePosTileProps): React.JSX.Element {
  const { t } = useTranslation('catalog')
  const displayName = name.trim().length > 0 ? name : t('editor.livePos.namePlaceholder')

  return (
    <div className={tokens.card.base}>
      {/* Uppercase 11px tracked rail label */}
      <p className={cn('mb-3 text-[11px] font-bold uppercase tracking-[0.1em]', textColors.tertiary)}>
        {t('editor.livePos.title')}
      </p>

      {/* 140px POS tile: image placeholder + name + price */}
      <div className={cn('w-[140px] overflow-hidden rounded-[10px] border', borderColors.light)}>
        {/* Image placeholder — diagonal hatch echoes the mock's empty-image state */}
        <div
          aria-hidden="true"
          className="h-[84px] bg-[repeating-linear-gradient(45deg,var(--color-gray-50)_0,var(--color-gray-50)_8px,var(--color-gray-100)_8px,var(--color-gray-100)_16px)]"
        />
        <div className="px-2.5 py-2">
          <div className={cn('text-[13px] font-semibold leading-tight', textColors.primary)}>
            {displayName}
          </div>
          <div className="mt-0.5 font-mono text-[13px] font-semibold text-secondary-500">
            {price}
          </div>
        </div>
      </div>
    </div>
  )
}
