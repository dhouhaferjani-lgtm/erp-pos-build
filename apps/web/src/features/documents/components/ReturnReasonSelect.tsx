import { useTranslation } from 'react-i18next'
import type { ReturnReason } from '@/types/returnNote'
import { colorClasses } from '@/lib/designTokens'

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
      <label htmlFor="return-reason" className={`block text-sm font-medium ${colorClasses.textGray700}`}>
        {t('sales:returnNotes.reason.label')}
        {required && <span className={`ms-1 ${colorClasses.textRed500}`}>*</span>}
      </label>
      <select
        id="return-reason"
        value={value || ''}
        onChange={(e) => { onChange(e.target.value as ReturnReason); }}
        disabled={disabled}
        className={`mt-1 block w-full rounded-md border ${
          error
            ? `${colorClasses.borderRed300} ${colorClasses.focusBorderRed500} ${colorClasses.focusRingRed500}`
            : `${colorClasses.borderGray300} ${colorClasses.focusBorderBlue500} ${colorClasses.focusRingBlue500}`
        } px-3 py-2 text-sm ${disabled ? `${colorClasses.bgGray50} ${colorClasses.textGray500}` : ''}`}
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
      {error && <p className={`mt-1 text-sm ${colorClasses.textRed600}`}>{error}</p>}
    </div>
  )
}
