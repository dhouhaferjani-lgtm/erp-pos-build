import { useState, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { Modal } from '@/components/organisms/Modal'
import { POSButton } from '../atoms/POSButton'
import { MoneyInput } from '@/components/atoms/MoneyInput'
import { useCurrency } from '@/hooks/useCurrency'
import { Banknote } from 'lucide-react'
import { tokens, colors, textColors, borderColors, focusRing } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

export interface CashTenderedModalProps {
  isOpen: boolean
  onClose: () => void
  onConfirm: (tenderedAmount: number) => void
  total: string
  isProcessing: boolean
}

const DENOMINATIONS = [5, 10, 20, 50, 100]

export function CashTenderedModal({
  isOpen,
  onClose,
  onConfirm,
  total,
  isProcessing,
}: CashTenderedModalProps) {
  const { t } = useTranslation(['pos'])
  const { currency, decimals, format: formatMoney } = useCurrency()
  const totalNum = parseFloat(total)
  const [tenderedStr, setTenderedStr] = useState('')

  // Reset tendered amount when modal opens with a new total
  useEffect(() => {
    if (isOpen) {
      setTenderedStr(totalNum.toFixed(decimals))
    }
  }, [isOpen, totalNum])

  const tenderedNum = parseFloat(tenderedStr) || 0
  const changeDue = Math.max(0, tenderedNum - totalNum)
  const isValid = tenderedNum >= totalNum && tenderedStr !== ''

  const handleDenomination = useCallback((amount: number) => {
    setTenderedStr(amount.toFixed(decimals))
  }, [])

  const handleExact = useCallback(() => {
    setTenderedStr(totalNum.toFixed(decimals))
  }, [totalNum, decimals])

  const handleConfirm = useCallback(() => {
    if (isValid) {
      onConfirm(tenderedNum)
    }
  }, [isValid, onConfirm, tenderedNum])

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('pos:cashTendered.title')}
      size="md"
    >
      <div className="space-y-6">
        {/* Amount Due */}
        <div className={cn('text-center rounded-xl p-4', colors.neutral[50])}>
          <p className={cn('text-sm font-medium mb-1', textColors.tertiary)}>
            {t('pos:cashTendered.amountDue')}
          </p>
          <p className={cn('text-3xl font-bold tabular-nums', textColors.primary)}>
            {formatMoney(totalNum)}
          </p>
        </div>

        {/* Tendered Amount Input */}
        <div>
          <label className={cn('block text-sm font-medium mb-2', textColors.secondary)}>
            {t('pos:cashTendered.tenderedAmount')}
          </label>
          <div className="relative">
            <span className={cn('absolute left-3 top-1/2 -translate-y-1/2 text-lg font-medium', textColors.disabled)}>
              {currency}
            </span>
            <MoneyInput
              currency={currency}
              min="0"
              value={tenderedStr}
              onChange={setTenderedStr}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && isValid && !isProcessing) {
                  handleConfirm()
                }
              }}
              className={cn('w-full pl-14 pr-4 py-3 text-2xl font-semibold text-right tabular-nums border rounded-lg focus:ring-2', borderColors.default, focusRing.primary)}
              autoFocus
            />
          </div>
        </div>

        {/* Denomination Buttons */}
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={handleExact}
            className={cn('flex-1 min-w-[80px] px-3 py-2.5 text-sm font-medium rounded-lg transition-colors', tokens.button.primary)}
          >
            {t('pos:cashTendered.exactAmount')}
          </button>
          {DENOMINATIONS.map((amount) => (
            <button
              key={amount}
              type="button"
              onClick={() => { handleDenomination(amount); }}
              className={cn('flex-1 min-w-[60px] px-3 py-2.5 text-sm font-medium tabular-nums rounded-lg transition-colors', tokens.button.secondary)}
            >
              {formatMoney(amount)}
            </button>
          ))}
        </div>

        {/* Change Due */}
        {tenderedNum > totalNum && (
          <div className={cn('text-center border rounded-xl p-4', tokens.alert.success, borderColors.success)}>
            <p className={cn('text-sm font-medium mb-1', textColors.success)}>
              {t('pos:cashTendered.changeDue')}
            </p>
            <p className={cn('text-2xl font-bold tabular-nums', textColors.success)}>
              {formatMoney(changeDue)}
            </p>
          </div>
        )}

        {/* Confirm Button */}
        <POSButton
          onClick={handleConfirm}
          variant="success"
          size="lg"
          fullWidth
          disabled={!isValid || isProcessing}
          icon={<Banknote className="w-5 h-5" />}
        >
          {isProcessing ? t('pos:cashTendered.processing') : t('pos:cashTendered.confirm')}
        </POSButton>
      </div>
    </Modal>
  )
}
