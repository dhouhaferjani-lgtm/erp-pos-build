import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Sparkles, X } from 'lucide-react'
import { cn } from '@/lib/utils'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { SmartPromptCard } from '../atoms/SmartPromptCard'
import { ContextQuestion } from '../atoms/ContextQuestion'
import type { Recommendation } from '../types/recommendations'
import type { ContextFieldConfig } from '../config/verticalContextFields'

interface InlineSmartPromptsProps {
  recommendations: Recommendation[]
  contextFields: ContextFieldConfig[]
  skinType: string | null
  onSkinTypeChange: (value: string) => void
  onAdd: (productId: string) => void
  isLoading: boolean
}

export function InlineSmartPrompts({
  recommendations,
  contextFields,
  skinType,
  onSkinTypeChange,
  onAdd,
  isLoading,
}: InlineSmartPromptsProps) {
  const { t } = useTranslation('smart-prompts')
  const [dismissed, setDismissed] = useState(false)

  if (dismissed || (recommendations.length === 0 && !isLoading)) {
    return null
  }

  return (
    <div className={cn('animate-fadeIn border-b px-4 py-2.5', borderColors.light, colors.neutral[50])}>
      <div className="mb-2 flex items-center gap-1.5">
        <Sparkles className={cn('h-4 w-4', textColors.brand)} aria-hidden="true" />
        <span className={cn('text-[11px] font-semibold uppercase tracking-wide', textColors.brand)}>
          {t('section_title')}
        </span>
        <button
          type="button"
          className={cn(
            'ml-auto inline-flex min-h-[32px] items-center gap-1 text-[10px]',
            textColors.disabled,
            textColors.hoverSecondary
          )}
          onClick={() => setDismissed(true)}
        >
          {t('dismiss')}
          <X className="h-3.5 w-3.5" aria-hidden="true" />
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

      <div className="flex flex-col gap-1.5">
        {recommendations.map((rec) => (
          <SmartPromptCard key={rec.product_id} recommendation={rec} onAdd={onAdd} />
        ))}
      </div>
    </div>
  )
}
