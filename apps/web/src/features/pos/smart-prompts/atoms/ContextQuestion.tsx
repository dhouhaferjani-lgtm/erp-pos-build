import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import type { ContextFieldConfig } from '../config/verticalContextFields'

interface ContextQuestionProps {
  field: ContextFieldConfig
  value: string | null
  onChange: (value: string) => void
}

export function ContextQuestion({ field, value, onChange }: ContextQuestionProps) {
  const { t } = useTranslation()

  return (
    <div className="mb-2">
      <div className={cn('mb-1.5 text-[11px] font-medium', textColors.warningDark)}>
        {t(field.labelKey)}
      </div>
      <div className="flex flex-wrap gap-1.5">
        {field.options.map((option) => (
          <button
            key={option.value}
            type="button"
            className={cn(
              'min-h-[36px] rounded-full border px-3 py-1 text-[11px] transition-colors',
              value === option.value
                ? cn(borderColors.primary, colors.primary[50], textColors.brand)
                : cn(borderColors.default, colors.white, textColors.secondary, colors.hover.gray100)
            )}
            onClick={() => onChange(option.value)}
            aria-pressed={value === option.value}
          >
            {t(option.labelKey)}
          </button>
        ))}
      </div>
    </div>
  )
}
