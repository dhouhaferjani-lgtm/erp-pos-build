import { useState } from 'react'
import { useTranslation } from 'react-i18next'
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
    <div className="animate-fadeIn border-b border-indigo-500/15 bg-indigo-500/[0.06] px-4 py-2.5">
      <div className="mb-2 flex items-center gap-1.5">
        <span className="text-xs text-indigo-400">✦</span>
        <span className="text-[11px] font-semibold uppercase tracking-wide text-indigo-400">
          {t('section_title')}
        </span>
        <button
          type="button"
          className="ml-auto text-[10px] text-gray-500 hover:text-gray-400"
          onClick={() => setDismissed(true)}
        >
          {t('dismiss')} ✕
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
