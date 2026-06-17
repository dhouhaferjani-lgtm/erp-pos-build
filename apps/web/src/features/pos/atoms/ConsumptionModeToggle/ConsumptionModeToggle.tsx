import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { colors, textColors, focusRing } from '@/lib/designTokens'
import { UtensilsCrossed, Package } from 'lucide-react'

export type ConsumptionMode = 'SUR_PLACE' | 'A_EMPORTER'

export interface ConsumptionModeToggleProps {
  value: ConsumptionMode
  onChange: (mode: ConsumptionMode) => void
  className?: string
}

export function ConsumptionModeToggle({ value, onChange, className }: ConsumptionModeToggleProps) {
  const { t } = useTranslation(['pos'])

  return (
    <div className={cn('inline-flex rounded-lg p-1', colors.neutral[100], className)}>
      <button
        type="button"
        onClick={() => { onChange('SUR_PLACE'); }}
        className={cn(
          'flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-colors',
          focusRing.default,
          focusRing.primary,
          value === 'SUR_PLACE'
            ? cn(colors.white, textColors.primary, 'shadow-sm')
            : cn(textColors.tertiary, textColors.hoverPrimary),
        )}
      >
        <UtensilsCrossed className="w-4 h-4" />
        {t('pos:consumptionMode.dineIn')}
      </button>
      <button
        type="button"
        onClick={() => { onChange('A_EMPORTER'); }}
        className={cn(
          'flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-colors',
          focusRing.default,
          focusRing.primary,
          value === 'A_EMPORTER'
            ? cn(colors.white, textColors.primary, 'shadow-sm')
            : cn(textColors.tertiary, textColors.hoverPrimary),
        )}
      >
        <Package className="w-4 h-4" />
        {t('pos:consumptionMode.takeout')}
      </button>
    </div>
  )
}
