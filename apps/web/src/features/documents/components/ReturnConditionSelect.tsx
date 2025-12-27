import { useTranslation } from 'react-i18next'
import type { ReturnCondition } from '@/types/returnNote'

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
      <label htmlFor="return-condition" className="block text-sm font-medium text-gray-700">
        {t('sales:returnNotes.condition.label')}
        {required && <span className="ms-1 text-red-500">*</span>}
      </label>
      <select
        id="return-condition"
        value={value || ''}
        onChange={(e) => { onChange(e.target.value as ReturnCondition); }}
        disabled={disabled}
        className={`mt-1 block w-full rounded-md border ${
          error
            ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
            : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
        } px-3 py-2 text-sm ${disabled ? 'bg-gray-50 text-gray-500' : ''}`}
      >
        <option value="">{t('common:select', 'Select...')}</option>
        <option value="unopened">{t('sales:returnNotes.condition.unopened')}</option>
        <option value="used">{t('sales:returnNotes.condition.used')}</option>
        <option value="damaged">{t('sales:returnNotes.condition.damaged')}</option>
        <option value="unusable">{t('sales:returnNotes.condition.unusable')}</option>
      </select>
      {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
    </div>
  )
}
