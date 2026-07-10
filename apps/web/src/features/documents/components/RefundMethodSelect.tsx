import { useTranslation } from 'react-i18next'
import type { RefundMethod } from '@/types/returnNote'
import { colorClasses } from '@/lib/designTokens'

export type { RefundMethod }

interface RefundMethodSelectProps{
  value?: RefundMethod | ''
  onChange: (value: RefundMethod) => void
  required?: boolean
  disabled?: boolean
  error?: string
  className?: string
}

export function RefundMethodSelect({
  value,
  onChange,
  required = false,
  disabled = false,
  error,
  className = '',
}: RefundMethodSelectProps) {
  const { t } = useTranslation(['sales'])

  return (
    <div className={className}>
      <label htmlFor="refund-method" className={`block text-sm font-medium ${colorClasses.textGray700}`}>
        {t('sales:returnNotes.refundMethod.label')}
        {required && <span className={`ms-1 ${colorClasses.textRed500}`}>*</span>}
      </label>
      <select
        id="refund-method"
        value={value || ''}
        onChange={(e) => { onChange(e.target.value as RefundMethod); }}
        disabled={disabled}
        className={`mt-1 block w-full rounded-md border ${
          error
            ? `${colorClasses.borderRed300} ${colorClasses.focusBorderRed500} ${colorClasses.focusRingRed500}`
            : `${colorClasses.borderGray300} ${colorClasses.focusBorderBlue500} ${colorClasses.focusRingBlue500}`
        } px-3 py-2 text-sm ${disabled ? `${colorClasses.bgGray50} ${colorClasses.textGray500}` : ''}`}
      >
        <option value="">{t('common:select', 'Select...')}</option>
        <option value="original_payment">{t('sales:returnNotes.refundMethod.originalPayment')}</option>
        <option value="store_credit">{t('sales:returnNotes.refundMethod.storeCredit')}</option>
        <option value="exchange">{t('sales:returnNotes.refundMethod.exchange')}</option>
        <option value="none">{t('sales:returnNotes.refundMethod.none')}</option>
      </select>
      {error && <p className={`mt-1 text-sm ${colorClasses.textRed600}`}>{error}</p>}
    </div>
  )
}
