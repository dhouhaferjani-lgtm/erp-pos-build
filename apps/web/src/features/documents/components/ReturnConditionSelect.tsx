import { useTranslation } from 'react-i18next'
import type { ReturnCondition } from '@/types/returnNote'
import { colorClasses } from '@/lib/designTokens'

export type { ReturnCondition }

interface ReturnConditionSelectProps {
  value?: ReturnCondition | ''
  onChange: (value: ReturnCondition) => void
  required?: boolean
  disabled?: boolean
  error?: string
  className?: string
}

export function ReturnConditionSelect({
  value,
  onChange,
  required = false,
  disabled = false,
  error,
  className = '',
}: ReturnConditionSelectProps) {
  const { t } = useTranslation(['sales'])

  return (
    <div className={className}>
      <label htmlFor="return-condition" className={`block text-sm font-medium ${colorClasses.textGray700}`}>
        {t('sales:returnNotes.condition.label')}
        {required && <span className={`ms-1 ${colorClasses.textRed500}`}>*</span>}
      </label>
      <select
        id="return-condition"
        value={value || ''}
        onChange={(e) => { onChange(e.target.value as ReturnCondition); }}
        disabled={disabled}
        className={`mt-1 block w-full rounded-md border ${
          error
            ? `${colorClasses.borderRed300} ${colorClasses.focusBorderRed500} ${colorClasses.focusRingRed500}`
            : `${colorClasses.borderGray300} ${colorClasses.focusBorderBlue500} ${colorClasses.focusRingBlue500}`
        } px-3 py-2 text-sm ${disabled ? `${colorClasses.bgGray50} ${colorClasses.textGray500}` : ''}`}
      >
        <option value="">{t('common:select', 'Select...')}</option>
        <option value="unopened">{t('sales:returnNotes.condition.unopened')}</option>
        <option value="used">{t('sales:returnNotes.condition.used')}</option>
        <option value="damaged">{t('sales:returnNotes.condition.damaged')}</option>
        <option value="unusable">{t('sales:returnNotes.condition.unusable')}</option>
      </select>
      {error && <p className={`mt-1 text-sm ${colorClasses.textRed600}`}>{error}</p>}
    </div>
  )
}
