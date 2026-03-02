import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { POSButton } from '../../atoms'
import { textColors, borderColors, tokens } from '@/lib/designTokens'
import { bcmul, bcdiv, bccomp, bcsub } from '@/lib/decimal'
import { useCurrency } from '@/hooks/useCurrency'

export interface TransactionDiscountInputProps {
  currentAmount?: string
  currentReason?: string
  subtotal: string
  effectiveLimit: number
  requiresReason: boolean
  onApply: (amount: string, reason?: string) => void
  onClear: () => void
  touchOptimized?: boolean
}

/**
 * TransactionDiscountInput Component
 *
 * Input form for applying transaction-level (order) discounts.
 * Unlike line discounts, transaction discounts are FIXED AMOUNT only.
 *
 * Features:
 * - Fixed amount discount input (not percentage)
 * - Validates discount as percentage of subtotal against effective limit
 * - Reason field (required if discount > 10% of subtotal)
 * - Touch-optimized mode for larger inputs
 * - Live preview of subtotal after discount
 *
 * @example
 * ```tsx
 * <TransactionDiscountInput
 *   subtotal="100.000"
 *   effectiveLimit={15}
 *   requiresReason={false}
 *   onApply={(amount, reason) => handleApply(amount, reason)}
 *   onClear={() => handleClear()}
 * />
 * ```
 */
export function TransactionDiscountInput({
  currentAmount = '',
  currentReason = '',
  subtotal,
  effectiveLimit,
  requiresReason,
  onApply,
  onClear,
  touchOptimized = false,
}: TransactionDiscountInputProps) {
  const { t } = useTranslation(['pos'])
  const { currency } = useCurrency()
  const [amount, setAmount] = useState(currentAmount)
  const [reason, setReason] = useState(currentReason)
  const [error, setError] = useState<string | null>(null)

  const handleAmountChange = (value: string) => {
    setAmount(value)
    setError(null)
  }

  const validateDiscount = (): boolean => {
    const numAmount = parseFloat(amount)
    if (!amount || Number.isNaN(numAmount) || numAmount <= 0) {
      setError(t('pos:errors.amountRequired'))
      return false
    }

    // Ensure discount doesn't exceed subtotal
    if (bccomp(amount, subtotal, 3) > 0) {
      setError(t('pos:errors.discountExceedsSubtotal'))
      return false
    }

    // Calculate discount as percentage of subtotal
    const discountPercent = bcdiv(bcmul(amount, '100', 3), subtotal, 2)

    if (bccomp(discountPercent, effectiveLimit.toString(), 2) > 0) {
      setError(t('pos:errors.discountExceedsLimit', { limit: effectiveLimit }))
      return false
    }

    // Calculate if discount is > 10% for reason requirement
    const tenPercent = '10.00'
    const needsReason = bccomp(discountPercent, tenPercent, 2) > 0

    if ((requiresReason || needsReason) && !reason.trim()) {
      setError(t('pos:errors.reasonRequired'))
      return false
    }

    return true
  }

  const handleApply = () => {
    if (validateDiscount()) {
      onApply(amount, reason || undefined)
    }
  }

  const preview =
    subtotal && amount && parseFloat(amount) > 0
      ? bcsub(subtotal, amount, 3)
      : subtotal

  // Calculate discount as percentage for display
  const discountPercent =
    amount && parseFloat(amount) > 0
      ? bcdiv(bcmul(amount, '100', 3), subtotal, 2)
      : '0.00'

  return (
    <div className="space-y-4">
      {/* Amount Input */}
      <div>
        <label
          htmlFor="transaction-discount-amount"
          className={cn('block text-sm font-medium mb-1', textColors.secondary)}
        >
          {t('pos:cart.discountAmount')}
        </label>
        <div className="relative">
          <input
            id="transaction-discount-amount"
            type="number"
            value={amount}
            onChange={(e) => {
              handleAmountChange(e.target.value)
            }}
            placeholder="0.000"
            step="0.001"
            min="0"
            max={subtotal}
            className={cn(
              'w-full px-3 rounded border',
              touchOptimized ? 'py-4 text-lg' : 'py-2',
              borderColors.default,
              error && 'border-red-500',
              'focus:outline-none focus:ring-2 focus:ring-blue-500'
            )}
          />
          <div className="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
            <span className={cn(textColors.tertiary, touchOptimized && 'text-lg')}>{currency}</span>
          </div>
        </div>
        <p className="text-xs text-gray-500 mt-1">
          {t('pos:cart.maxAllowed')}: {effectiveLimit}%
          {amount && parseFloat(amount) > 0 && (
            <span className="ml-2">
              ({t('pos:cart.currentDiscount')}: {discountPercent}%)
            </span>
          )}
        </p>
      </div>

      {/* Reason (conditional) */}
      {(requiresReason || parseFloat(discountPercent) > 10) && (
        <div>
          <label
            htmlFor="transaction-discount-reason"
            className={cn('block text-sm font-medium mb-1', textColors.secondary)}
          >
            {t('pos:cart.reason')}
            {parseFloat(discountPercent) > 10 && (
              <span className={textColors.error}> *</span>
            )}
          </label>
          <textarea
            id="transaction-discount-reason"
            value={reason}
            onChange={(e) => {
              setReason(e.target.value)
              setError(null)
            }}
            placeholder={t('pos:cart.reasonPlaceholder')}
            rows={touchOptimized ? 3 : 2}
            className={cn(
              'w-full px-3 py-2 rounded border',
              borderColors.default,
              touchOptimized && 'text-lg',
              'focus:outline-none focus:ring-2 focus:ring-blue-500'
            )}
          />
        </div>
      )}

      {/* Error Message */}
      {error && (
        <div className="p-3 bg-red-50 border border-red-200 rounded">
          <p className="text-red-600 text-sm">{error}</p>
        </div>
      )}

      {/* Preview */}
      {amount && parseFloat(amount) > 0 && !error && (
        <div className={cn('p-3 rounded-lg', 'bg-blue-50', borderColors.primary)}>
          <p className={cn(textColors.secondary, 'text-sm mb-1')}>
            {t('pos:cart.preview')}:
          </p>
          <div className="space-y-1">
            <div className="flex justify-between">
              <span className={cn(textColors.tertiary, 'text-sm')}>
                {t('pos:cart.originalTotal')}:
              </span>
              <span className={cn(textColors.tertiary, 'text-sm line-through')}>
                {subtotal} {currency}
              </span>
            </div>
            <div className="flex justify-between">
              <span className={cn(textColors.secondary, 'text-sm')}>
                {t('pos:cart.discount')}:
              </span>
              <span className="text-red-600 text-sm font-medium">-{amount} {currency}</span>
            </div>
            <div className="flex justify-between pt-1 border-t border-blue-200">
              <span className={cn(textColors.brand, 'font-bold')}>
                {t('pos:cart.discountedTotal')}:
              </span>
              <span className={cn(textColors.brand, 'font-bold text-lg')}>
                {preview} {currency}
              </span>
            </div>
          </div>
        </div>
      )}

      {/* Action Buttons */}
      <div className={tokens.modal.footer}>
        <POSButton
          variant="secondary"
          size={touchOptimized ? 'md' : 'sm'}
          touchOptimized={touchOptimized}
          onClick={onClear}
        >
          {t('pos:cart.clearDiscount')}
        </POSButton>
        <POSButton
          variant="primary"
          size={touchOptimized ? 'md' : 'sm'}
          touchOptimized={touchOptimized}
          onClick={handleApply}
          disabled={!amount || parseFloat(amount) <= 0}
        >
          {t('pos:cart.applyDiscount')}
        </POSButton>
      </div>
    </div>
  )
}
