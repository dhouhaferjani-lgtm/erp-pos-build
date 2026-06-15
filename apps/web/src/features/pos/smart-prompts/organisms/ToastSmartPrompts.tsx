import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronUp, Sparkles, X } from 'lucide-react'
import { cn } from '@/lib/utils'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { SmartPromptCard } from '../atoms/SmartPromptCard'
import { ContextQuestion } from '../atoms/ContextQuestion'
import type { Recommendation } from '../types/recommendations'
import type { ContextFieldConfig } from '../config/verticalContextFields'

const AUTO_COLLAPSE_MS = 15_000

interface ToastSmartPromptsProps {
  recommendations: Recommendation[]
  contextFields: ContextFieldConfig[]
  skinType: string | null
  onSkinTypeChange: (value: string) => void
  onAdd: (productId: string) => void
  isLoading: boolean
}

export function ToastSmartPrompts({
  recommendations,
  contextFields,
  skinType,
  onSkinTypeChange,
  onAdd,
  isLoading,
}: ToastSmartPromptsProps) {
  const { t } = useTranslation('smart-prompts')
  const [expanded, setExpanded] = useState(false)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (expanded) {
      timerRef.current = setTimeout(() => setExpanded(false), AUTO_COLLAPSE_MS)
    }
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
    }
  }, [expanded])

  if (recommendations.length === 0 && !isLoading) {
    return null
  }

  return (
    <div className="sticky bottom-0 left-0 right-0 z-10">
      {!expanded && (
        <button
          type="button"
          className={cn(
            'flex w-full items-center gap-2 border-t px-4 py-3',
            borderColors.light,
            colors.neutral[50]
          )}
          onClick={() => setExpanded(true)}
        >
          <Sparkles className={cn('h-4 w-4', textColors.brand)} aria-hidden="true" />
          <span className={cn('text-xs font-medium', textColors.brand)}>
            {t('toast_collapsed', { count: recommendations.length })}
          </span>
          <ChevronUp className={cn('ml-auto h-5 w-5', textColors.brand)} aria-hidden="true" />
        </button>
      )}

      {expanded && (
        <div className={cn('border-t px-4 py-3', borderColors.light, colors.white)}>
          <div className="mb-2 flex items-center gap-1.5">
            <Sparkles className={cn('h-4 w-4', textColors.brand)} aria-hidden="true" />
            <span
              className={cn('text-[11px] font-semibold uppercase tracking-wide', textColors.brand)}
            >
              {t('section_title')}
            </span>
            <button
              type="button"
              className={cn(
                'ml-auto inline-flex min-h-[32px] items-center',
                textColors.disabled,
                textColors.hoverSecondary
              )}
              onClick={() => setExpanded(false)}
              aria-label={t('dismiss')}
            >
              <X className="h-4 w-4" aria-hidden="true" />
            </button>
          </div>

          {contextFields.map((field) => (
            <ContextQuestion
              key={field.key}
              field={field}
              value={field.key === 'skin_type' ? skinType : null}
              onChange={field.key === 'skin_type' ? onSkinTypeChange : () => {}}
            />
          ))}

          <div className="flex gap-2 overflow-x-auto pb-1">
            {recommendations.map((rec) => (
              <div key={rec.product_id} className="min-w-[160px]">
                <SmartPromptCard recommendation={rec} onAdd={onAdd} />
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
