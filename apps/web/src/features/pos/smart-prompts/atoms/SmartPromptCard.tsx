import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Info, Plus } from 'lucide-react'
import { cn } from '@/lib/utils'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import type { Recommendation } from '../types/recommendations'

interface SmartPromptCardProps {
  recommendation: Recommendation
  onAdd: (productId: string) => void
}

export function SmartPromptCard({ recommendation, onAdd }: SmartPromptCardProps) {
  const { t } = useTranslation('smart-prompts')
  const [showInfo, setShowInfo] = useState(false)

  return (
    <div
      className={cn(
        'smart-prompt-card relative flex items-center gap-3 rounded-lg border p-2',
        borderColors.light,
        colors.neutral[50]
      )}
    >
      <div className="min-w-0 flex-1">
        <div className={cn('truncate text-sm font-medium', textColors.primary)}>
          {recommendation.product_name}
        </div>
        <div className="flex items-center gap-1.5">
          <span
            className={cn(
              'rounded px-1.5 py-0.5 text-[10px]',
              colors.primary[100],
              textColors.brand
            )}
          >
            {t(`strategy.${recommendation.strategy}`)}
          </span>
          <span className={cn('truncate text-xs', textColors.tertiary)}>
            {recommendation.reason}
          </span>
        </div>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        <button
          type="button"
          className={cn(
            'flex h-9 w-9 items-center justify-center rounded-full',
            colors.neutral[100],
            textColors.tertiary,
            colors.hover.gray100
          )}
          onClick={(e) => {
            e.stopPropagation()
            setShowInfo(!showInfo)
          }}
          aria-label={t('info_title')}
        >
          <Info className="h-4 w-4" aria-hidden="true" />
        </button>
        <button
          type="button"
          className={cn(
            'flex h-9 w-9 items-center justify-center rounded-full',
            colors.primary[100],
            textColors.brand,
            colors.hover.gray100
          )}
          onClick={() => onAdd(recommendation.product_id)}
          aria-label={t('add_to_cart')}
        >
          <Plus className="h-5 w-5" aria-hidden="true" />
        </button>
      </div>
      {showInfo && (
        <div
          className={cn(
            'absolute right-0 top-full z-10 mt-1 w-64 rounded-lg border p-3 text-xs shadow-lg',
            borderColors.light,
            colors.white
          )}
        >
          <div className={cn('mb-1 font-semibold', textColors.primary)}>{t('info_title')}</div>
          <div className={cn('mb-2', textColors.tertiary)}>{recommendation.reason}</div>
          <div className={textColors.disabled}>
            {t('info_source', { source: recommendation.strategy })} ·{' '}
            {t('info_score', { score: recommendation.score })}
          </div>
        </div>
      )}
    </div>
  )
}
