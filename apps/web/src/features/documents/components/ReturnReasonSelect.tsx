import { useTranslation } from 'react-i18next'
import type { ReturnReason } from '@/types/returnNote'

export type { ReturnReason }

interface ReturnReasonSelectProps {
  value?: ReturnReason | '' | undefined
  onChange: (value: ReturnReason) => void
  required?: boolean | undefined
  disabled?: boolean | undefined
  error?: string | undefined
  className?: string | undefined
}

export function ReturnReasonSelect({
  value,
  onChange,
  required = false,
  disabled = false,
  error,
  className = '',
}: ReturnReasonSelectProps) {
  const { t } = useTranslation(['sales'])

  return (
    <div className={className}>
      <label htmlFor="return-reason" className="block text-sm font-medium text-gray-700">
        {t('sales:returnNotes.reason.label')}
        {required && <span className="ms-1 text-red-500">*</span>}
      </label>
      <select
        id="return-reason"
        value={value || ''}
        onChange={(e) => { onChange(e.target.value as ReturnReason); }}
        disabled={disabled}
        className={`mt-1 block w-full rounded-md border ${
          error
            ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
            : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
        } px-3 py-2 text-sm ${disabled ? 'bg-gray-50 text-gray-500' : ''}`}
      >
        <option value="">{t('common:select', 'Select...')}</option>
        <option value="defective">{t('sales:returnNotes.reason.defective')}</option>
        <option value="wrong_item">{t('sales:returnNotes.reason.wrongItem')}</option>
        <option value="customer_regret">{t('sales:returnNotes.reason.customerRegret')}</option>
        <option value="damaged_in_transit">{t('sales:returnNotes.reason.damagedInTransit')}</option>
        <option value="warranty">{t('sales:returnNotes.reason.warranty')}</option>
        <option value="exchange">{t('sales:returnNotes.reason.exchange')}</option>
        <option value="other">{t('sales:returnNotes.reason.other')}</option>
      </select>
      {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
    </div>
  )
}
