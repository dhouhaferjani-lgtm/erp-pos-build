import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { POSButton } from '../../atoms'
import { applyDiscount, formatCurrency, bccomp } from '@/lib/decimal'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { useCurrency } from '@/hooks/useCurrency'

export interface DiscountData {
  type: 'percentage' | 'fixed'
  percent?: string
  amount?: string
  reason?: string
}

export interface DiscountInputProps {
  lineTotal: string
  currentDiscount?: {
    type: 'percentage' | 'fixed' | null
    percent?: string
    amount?: string
    reason?: string
  }
  onApplyDiscount: (discount: DiscountData) => void
  onClearDiscount: () => void
  effectiveLimit: number
  requiresReason: boolean
  touchOptimized?: boolean
}

/**
 * DiscountInput Component
 *
 * Full-featured discount input component for POS cart line items.
 * Supports both percentage and fixed amount discounts with validation.
 *
 * Features:
 * - Toggle between percentage and fixed amount
 * - Quick preset buttons (5%, 10%, 15%, 20%)
 * - Real-time validation against effective limit
 * - Reason field (required if discount > 10%)
 * - Touch-optimized mode for larger buttons
 * - Live preview of discounted total
 *
 * @example
 * ```tsx
 * <DiscountInput
 *   lineTotal="100.000"
 *   effectiveLimit={15}
 *   requiresReason={true}
 *   onApplyDiscount={(discount) => handleApplyDiscount(discount)}
 *   onClearDiscount={() => handleClearDiscount()}
 * />
 * ```
 */
export function DiscountInput({
  lineTotal,
  currentDiscount,
  onApplyDiscount,
  onClearDiscount,
  effectiveLimit,
  requiresReason,
  touchOptimized = false,
}: DiscountInputProps) {
  const { t } = useTranslation('pos')
  const { currency } = useCurrency()

  // State
  const [discountType, setDiscountType] = useState<'percentage' | 'fixed'>(
    currentDiscount?.type ?? 'percentage'
  )
  const [discountValue, setDiscountValue] = useState<string>(
    currentDiscount?.type === 'percentage'
      ? currentDiscount?.percent ?? ''
      : currentDiscount?.amount ?? ''
  )
  const [reason, setReason] = useState<string>(currentDiscount?.reason ?? '')
  const [error, setError] = useState<string>('')

  // Preset percentages
  const presets = [5, 10, 15, 20]

  // Update state when currentDiscount changes
  useEffect(() => {
    if (currentDiscount && currentDiscount.type) {
      setDiscountType(currentDiscount.type)
      setDiscountValue(
        currentDiscount.type === 'percentage'
          ? currentDiscount.percent ?? ''
          : currentDiscount.amount ?? ''
      )
      setReason(currentDiscount.reason ?? '')
    }
  }, [currentDiscount])

  // Validation
  const validate = (): boolean => {
    setError('')

    if (!discountValue || parseFloat(discountValue) === 0) {
      setError(t('errors.reasonRequired'))
      return false
    }

    // Check if discount exceeds limit (percentage only)
    if (discountType === 'percentage') {
      const discountPercent = parseFloat(discountValue)
      if (discountPercent > effectiveLimit) {
        setError(t('errors.discountExceedsLimit', { limit: effectiveLimit }))
        return false
      }
    }

    // Check if reason is required (percentage > 10%)
    if (discountType === 'percentage' && parseFloat(discountValue) > 10 && !reason.trim()) {
      setError(t('errors.reasonRequired'))
      return false
    }

    return true
  }

  // Calculate preview
  const calculatePreview = (): { original: string; discounted: string } | null => {
    if (!discountValue || parseFloat(discountValue) === 0) {
      return null
    }

    try {
      const discounted = applyDiscount(lineTotal, discountType, discountValue)
      // Ensure discounted total is not negative
      if (bccomp(discounted, '0') < 0) {
        return null
      }
      return {
        original: lineTotal,
        discounted,
      }
    } catch {
      return null
    }
  }

  const preview = calculatePreview()

  // Handlers
  const handleApply = () => {
    if (!validate()) {
      return
    }

    const discount: DiscountData = {
      type: discountType,
      reason: reason.trim() || undefined,
    }

    if (discountType === 'percentage') {
      discount.percent = discountValue
    } else {
      discount.amount = discountValue
    }

    onApplyDiscount(discount)
  }

  const handlePresetClick = (percent: number) => {
    setDiscountType('percentage')
    setDiscountValue(percent.toString())
    setError('')
  }

  const handleTypeChange = (type: 'percentage' | 'fixed') => {
    setDiscountType(type)
    setDiscountValue('')
    setError('')
  }

  return (
    <div className="space-y-4">
      {/* Discount Type Toggle */}
      <div>
        <label className={tokens.label.base}>{t('cart.discountType')}</label>
        <div className="mt-2 flex gap-2">
          <POSButton
            variant={discountType === 'percentage' ? 'primary' : 'secondary'}
            size={touchOptimized ? 'md' : 'sm'}
            touchOptimized={touchOptimized}
            onClick={() => handleTypeChange('percentage')}
            fullWidth
          >
            {t('cart.percentage')}
          </POSButton>
          <POSButton
            variant={discountType === 'fixed' ? 'primary' : 'secondary'}
            size={touchOptimized ? 'md' : 'sm'}
            touchOptimized={touchOptimized}
            onClick={() => handleTypeChange('fixed')}
            fullWidth
          >
            {t('cart.fixed')}
          </POSButton>
        </div>
      </div>

      {/* Preset Buttons (percentage only) */}
      {discountType === 'percentage' && (
        <div>
          <label className={tokens.label.base}>{t('cart.presets')}</label>
          <div className="mt-2 grid grid-cols-4 gap-2">
            {presets.map((percent) => (
              <POSButton
                key={percent}
                variant="secondary"
                size={touchOptimized ? 'md' : 'sm'}
                touchOptimized={touchOptimized}
                onClick={() => handlePresetClick(percent)}
                disabled={percent > effectiveLimit}
              >
                {percent}%
              </POSButton>
            ))}
          </div>
        </div>
      )}

      {/* Discount Value Input */}
      <div>
        <label htmlFor="discount-value" className={tokens.label.base}>
          {discountType === 'percentage' ? t('cart.percentage') : t('cart.fixed')}
        </label>
        <div className="relative mt-1">
          <input
            id="discount-value"
            type="number"
            step={discountType === 'percentage' ? '0.01' : '0.001'}
            min="0"
            max={discountType === 'percentage' ? effectiveLimit.toString() : undefined}
            value={discountValue}
            onChange={(e) => {
              setDiscountValue(e.target.value)
              setError('')
            }}
            className={cn(
              tokens.input.base,
              error && tokens.input.error,
              touchOptimized && 'text-lg py-3'
            )}
            placeholder={discountType === 'percentage' ? '0.00' : '0.000'}
          />
          <div className="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
            <span className={cn(textColors.tertiary, touchOptimized && 'text-lg')}>
              {discountType === 'percentage' ? '%' : currency}
            </span>
          </div>
        </div>
        {discountType === 'percentage' && (
          <p className={tokens.helperText.base}>
            {t('cart.maxAllowed')}: {effectiveLimit}%
          </p>
        )}
      </div>

      {/* Reason Field (shown if discount > 10% or requiresReason) */}
      {(requiresReason || parseFloat(discountValue || '0') > 10) && (
        <div>
          <label htmlFor="discount-reason" className={tokens.label.base}>
            {t('cart.reason')}
            {parseFloat(discountValue || '0') > 10 && (
              <span className={textColors.error}> *</span>
            )}
          </label>
          <textarea
            id="discount-reason"
            rows={touchOptimized ? 3 : 2}
            value={reason}
            onChange={(e) => {
              setReason(e.target.value)
              setError('')
            }}
            className={cn(
              tokens.textarea.base,
              touchOptimized && 'text-lg'
            )}
            placeholder={t('cart.reasonRequired')}
          />
        </div>
      )}

      {/* Error Message */}
      {error && (
        <div className={tokens.alert.error}>
          {error}
        </div>
      )}

      {/* Preview */}
      {preview && !error && (
        <div className={cn('p-3 rounded-lg', 'bg-blue-50', borderColors.primary)}>
          <p className={cn(textColors.secondary, 'text-sm mb-1')}>
            {t('cart.preview')}:
          </p>
          <div className="flex justify-between items-center">
            <div>
              <p className={cn(textColors.tertiary, 'text-sm line-through')}>
                {t('cart.originalTotal')}: {formatCurrency(preview.original, true, currency)}
              </p>
              <p className={cn(textColors.brand, 'font-bold text-lg')}>
                {t('cart.discountedTotal')}: {formatCurrency(preview.discounted, true, currency)}
              </p>
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
          onClick={onClearDiscount}
        >
          {t('cart.clearDiscount')}
        </POSButton>
        <POSButton
          variant="primary"
          size={touchOptimized ? 'md' : 'sm'}
          touchOptimized={touchOptimized}
          onClick={handleApply}
          disabled={!discountValue || parseFloat(discountValue) === 0}
        >
          {t('cart.applyDiscount')}
        </POSButton>
      </div>
    </div>
  )
}
