import { useTranslation } from 'react-i18next'
import type { RefundMethod } from '@/types/returnNote'

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
      <label htmlFor="refund-method" className="block text-sm font-medium text-gray-700">
        {t('sales:returnNotes.refundMethod.label')}
        {required && <span className="ms-1 text-red-500">*</span>}
      </label>
      <select
        id="refund-method"
        value={value || ''}
        onChange={(e) => { onChange(e.target.value as RefundMethod); }}
        disabled={disabled}
        className={`mt-1 block w-full rounded-md border ${
          error
            ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
            : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
        } px-3 py-2 text-sm ${disabled ? 'bg-gray-50 text-gray-500' : ''}`}
      >
        <option value="">{t('common:select', 'Select...')}</option>
        <option value="original_payment">{t('sales:returnNotes.refundMethod.originalPayment')}</option>
        <option value="store_credit">{t('sales:returnNotes.refundMethod.storeCredit')}</option>
        <option value="exchange">{t('sales:returnNotes.refundMethod.exchange')}</option>
        <option value="none">{t('sales:returnNotes.refundMethod.none')}</option>
      </select>
      {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
    </div>
  )
}
