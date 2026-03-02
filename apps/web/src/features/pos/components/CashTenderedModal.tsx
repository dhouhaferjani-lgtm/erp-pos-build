import { useState, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { Modal } from '@/components/organisms/Modal'
import { POSButton } from '../atoms/POSButton'
import { useCurrency } from '@/hooks/useCurrency'
import { Banknote } from 'lucide-react'

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
  const { currency, format: formatMoney } = useCurrency()
  const totalNum = parseFloat(total)
  const [tenderedStr, setTenderedStr] = useState('')

  // Reset tendered amount when modal opens with a new total
  useEffect(() => {
    if (isOpen) {
      setTenderedStr(totalNum.toFixed(2))
    }
  }, [isOpen, totalNum])

  const tenderedNum = parseFloat(tenderedStr) || 0
  const changeDue = Math.max(0, tenderedNum - totalNum)
  const isValid = tenderedNum >= totalNum && tenderedStr !== ''

  const handleDenomination = useCallback((amount: number) => {
    setTenderedStr(amount.toFixed(2))
  }, [])

  const handleExact = useCallback(() => {
    setTenderedStr(totalNum.toFixed(2))
  }, [totalNum])

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
        <div className="text-center bg-gray-50 rounded-xl p-4">
          <p className="text-sm font-medium text-gray-500 mb-1">
            {t('pos:cashTendered.amountDue')}
          </p>
          <p className="text-3xl font-bold text-gray-900">
            {formatMoney(totalNum)}
          </p>
        </div>

        {/* Tendered Amount Input */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('pos:cashTendered.tenderedAmount')}
          </label>
          <div className="relative">
            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg font-medium">
              {currency}
            </span>
            <input
              type="number"
              step="0.01"
              min={0}
              value={tenderedStr}
              onChange={(e) => setTenderedStr(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && isValid && !isProcessing) {
                  handleConfirm()
                }
              }}
              className="w-full pl-14 pr-4 py-3 text-2xl font-semibold text-right border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              autoFocus
            />
          </div>
        </div>

        {/* Denomination Buttons */}
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={handleExact}
            className="flex-1 min-w-[80px] px-3 py-2.5 text-sm font-medium bg-blue-50 text-blue-700 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors"
          >
            {t('pos:cashTendered.exactAmount')}
          </button>
          {DENOMINATIONS.map((amount) => (
            <button
              key={amount}
              type="button"
              onClick={() => handleDenomination(amount)}
              className="flex-1 min-w-[60px] px-3 py-2.5 text-sm font-medium bg-gray-50 text-gray-700 border border-gray-200 rounded-lg hover:bg-gray-100 transition-colors"
            >
              {formatMoney(amount)}
            </button>
          ))}
        </div>

        {/* Change Due */}
        {tenderedNum > totalNum && (
          <div className="text-center bg-green-50 border border-green-200 rounded-xl p-4">
            <p className="text-sm font-medium text-green-600 mb-1">
              {t('pos:cashTendered.changeDue')}
            </p>
            <p className="text-2xl font-bold text-green-700">
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
