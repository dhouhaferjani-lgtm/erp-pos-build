import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { POSButton } from '../../atoms'
import { textColors, borderColors, tokens, focusRing } from '@/lib/designTokens'
import { bcmul, bcdiv, bccomp, bcsub } from '@/lib/decimal'
import { useCurrency } from '@/hooks/useCurrency'
import {
  computeDiscountAmount,
  isDiscountAboveTolerance,
} from '../../lib/discountValidation'
import type { ToleranceSettings } from '@/types/treasury'

export interface TransactionDiscountInputProps {
  currentAmount?: string | undefined
  currentReason?: string | undefined
  subtotal: string
  effectiveLimit: number
  requiresReason: boolean
  onApply: (amount: string, reason?: string  ) => void
  onClear: () => void
  touchOptimized?: boolean | undefined
  /**
   * Resolved tolerance configuration. When supplied, the input renders an
   * inline below-tolerance error and disables preset buttons whose
   * computed amount would fall sub-threshold (spec §7 frontend mirror).
   * Server is authoritative; this is purely a UX assist.
   */
  toleranceSettings?: ToleranceSettings | undefined
}

/**
 * TransactionDiscountInput Component
 *
 * Input form for applying transaction-level (order) discounts.
 * Supports both percentage and fixed amount entry modes.
 * The output is always a fixed amount (percentage is converted before applying).
 *
 * Features:
 * - Toggle between percentage and fixed amount input
 * - Quick preset buttons (5%, 10%, 15%, 20%) in percentage mode
 * - Validates discount against effective limit
 * - Reason field (required if discount > 10% of subtotal)
 * - Touch-optimized mode for larger inputs
 * - Live preview of subtotal after discount
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
  toleranceSettings,
}: TransactionDiscountInputProps) {
  const { t } = useTranslation(['pos'])
  const { currency } = useCurrency()
  const [inputMode, setInputMode] = useState<'percentage' | 'fixed'>('fixed')
  const [value, setValue] = useState(currentAmount)
  const [reason, setReason] = useState(currentReason)
  const [error, setError] = useState<string | null>(null)

  const presets = [5, 10, 15, 20]

  const handleValueChange = (newValue: string) => {
    setValue(newValue)
    setError(null)
  }

  const handleModeChange = (mode: 'percentage' | 'fixed') => {
    setInputMode(mode)
    setValue('')
    setError(null)
  }

  const handlePresetClick = (percent: number) => {
    setInputMode('percentage')
    setValue(percent.toString())
    setError(null)
  }

  /** Convert current input to a fixed discount amount */
  const resolveAmount = (): string => {
    if (!value || parseFloat(value) <= 0) return '0'
    if (inputMode === 'fixed') return value
    // percentage → fixed: amount = subtotal * percent / 100
    return bcdiv(bcmul(subtotal, value, 3), '100', 3)
  }

  /** The resolved fixed amount for validation / preview */
  const amount = resolveAmount()

  /** Discount as a percentage of subtotal */
  const discountPercent =
    amount && parseFloat(amount) > 0
      ? bcdiv(bcmul(amount, '100', 3), subtotal, 2)
      : '0.00'

  const validateDiscount = (): boolean => {
    const numValue = parseFloat(value)
    if (!value || Number.isNaN(numValue) || numValue <= 0) {
      setError(t('pos:errors.amountRequired'))
      return false
    }

    if (inputMode === 'percentage' && numValue > 100) {
      setError(t('pos:errors.discountExceedsSubtotal'))
      return false
    }

    // Ensure discount doesn't exceed subtotal
    if (bccomp(amount, subtotal) > 0) {
      setError(t('pos:errors.discountExceedsSubtotal'))
      return false
    }

    if (bccomp(discountPercent, effectiveLimit.toString()) > 0) {
      setError(t('pos:errors.discountExceedsLimit', { limit: effectiveLimit }))
      return false
    }

    // Reason required if discount > 10%
    const needsReason = bccomp(discountPercent, '10.00') > 0
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
    subtotal && parseFloat(amount) > 0
      ? bcsub(subtotal, amount, 3)
      : subtotal

  const belowTolerance =
    !!toleranceSettings &&
    !!value &&
    bccomp(amount, '0') > 0 &&
    !isDiscountAboveTolerance(amount, subtotal, toleranceSettings)

  return (
    <div className="space-y-4">
      {/* Discount Mode Toggle */}
      <div>
        <label className={tokens.label.base}>{t('pos:cart.discountType')}</label>
        <div className="mt-2 flex gap-2">
          <POSButton
            variant={inputMode === 'percentage' ? 'primary' : 'secondary'}
            size={touchOptimized ? 'md' : 'sm'}
            touchOptimized={touchOptimized}
            onClick={() => { handleModeChange('percentage'); }}
            fullWidth
          >
            {t('pos:cart.percentage')}
          </POSButton>
          <POSButton
            variant={inputMode === 'fixed' ? 'primary' : 'secondary'}
            size={touchOptimized ? 'md' : 'sm'}
            touchOptimized={touchOptimized}
            onClick={() => { handleModeChange('fixed'); }}
            fullWidth
          >
            {t('pos:cart.fixed')}
          </POSButton>
        </div>
      </div>

      {/* Preset Buttons (percentage mode only) */}
      {inputMode === 'percentage' && (
        <div>
          <label className={tokens.label.base}>{t('pos:cart.presets')}</label>
          <div className="mt-2 grid grid-cols-4 gap-2">
            {presets.map((percent) => {
              const presetAmount = computeDiscountAmount(subtotal, String(percent))
              const presetBelow =
                !!toleranceSettings &&
                !isDiscountAboveTolerance(presetAmount, subtotal, toleranceSettings)
              return (
                <POSButton
                  key={percent}
                  variant="secondary"
                  size={touchOptimized ? 'md' : 'sm'}
                  touchOptimized={touchOptimized}
                  onClick={() => { handlePresetClick(percent); }}
                  disabled={percent > effectiveLimit || presetBelow}
                >
                  {percent}%
                </POSButton>
              )
            })}
          </div>
        </div>
      )}

      {/* Value Input */}
      <div>
        <label
          htmlFor="transaction-discount-value"
          className={cn('block text-sm font-medium mb-1', textColors.secondary)}
        >
          {inputMode === 'percentage' ? t('pos:cart.percentage') : t('pos:cart.discountAmount')}
        </label>
        <div className="relative">
          <input
            id="transaction-discount-value"
            type="number"
            value={value}
            onChange={(e) => {
              handleValueChange(e.target.value)
            }}
            placeholder={inputMode === 'percentage' ? '0.00' : '0.000'}
            step={inputMode === 'percentage' ? '0.01' : '0.001'}
            min="0"
            max={inputMode === 'percentage' ? effectiveLimit.toString() : subtotal}
            className={cn(
              'w-full px-3 rounded border tabular-nums',
              touchOptimized ? 'py-4 text-lg' : 'py-2',
              borderColors.default,
              error && borderColors.error,
              'focus:outline-none focus:ring-2',
              focusRing.primary
            )}
            autoFocus
          />
          <div className="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
            <span className={cn(textColors.tertiary, touchOptimized && 'text-lg')}>
              {inputMode === 'percentage' ? '%' : currency}
            </span>
          </div>
        </div>
        <p className={tokens.helperText.base}>
          {t('pos:cart.maxAllowed')}: {effectiveLimit}%
          {parseFloat(amount) > 0 && inputMode === 'fixed' && (
            <span className="ml-2">
              ({t('pos:cart.currentDiscount')}: {discountPercent}%)
            </span>
          )}
          {parseFloat(amount) > 0 && inputMode === 'percentage' && (
            <span className="ml-2">
              (= {amount} {currency})
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
              'focus:outline-none focus:ring-2',
              focusRing.primary
            )}
          />
        </div>
      )}

      {/* Error Message */}
      {error && (
        <div className={cn(tokens.alert.base, 'border', tokens.alert.error)}>
          <p className="text-sm">{error}</p>
        </div>
      )}

      {/* Sub-tolerance discount warning — anti-abuse boundary mirror
          (spec §7). Authoritative check is server-side. */}
      {belowTolerance && !error && (
        <div className={tokens.alert.error}>
          {t('pos:discount.below_tolerance')}
        </div>
      )}

      {/* Preview */}
      {parseFloat(amount) > 0 && !error && (
        <div className={cn('p-3 rounded-lg', tokens.alert.info, borderColors.primary)}>
          <p className={cn(textColors.secondary, 'text-sm mb-1')}>
            {t('pos:cart.preview')}:
          </p>
          <div className="space-y-1">
            <div className="flex justify-between">
              <span className={cn(textColors.tertiary, 'text-sm')}>
                {t('pos:cart.originalTotal')}:
              </span>
              <span className={cn(textColors.tertiary, 'text-sm line-through tabular-nums')}>
                {subtotal} {currency}
              </span>
            </div>
            <div className="flex justify-between">
              <span className={cn(textColors.secondary, 'text-sm')}>
                {t('pos:cart.discount')}:
              </span>
              <span className={cn(textColors.error, 'text-sm font-medium tabular-nums')}>-{amount} {currency}</span>
            </div>
            <div className={cn('flex justify-between pt-1 border-t', borderColors.primary)}>
              <span className={cn(textColors.brand, 'font-bold')}>
                {t('pos:cart.discountedTotal')}:
              </span>
              <span className={cn(textColors.brand, 'font-bold text-lg tabular-nums')}>
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
          disabled={!value || parseFloat(value) <= 0 || belowTolerance}
        >
          {t('pos:cart.applyDiscount')}
        </POSButton>
      </div>
    </div>
  )
}
