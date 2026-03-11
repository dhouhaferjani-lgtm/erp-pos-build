import { useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { Ticket, X, Loader2, Check } from 'lucide-react'
import { cn } from '@/lib/utils'
import { POSButton } from '../../atoms'
import { validateCoupon, type ValidateCouponResponse } from '@/features/coupons/api/couponApi'
import { useCurrency } from '@/hooks/useCurrency'

export interface CouponCodeInputProps {
  subtotal: string
  customerId?: string
  couponCode: string | null
  onCouponApplied: (code: string, discountAmount: string, promotionName: string) => void
  onCouponRemoved: () => void
  className?: string
}

type CouponState =
  | { status: 'idle' }
  | { status: 'validating' }
  | { status: 'applied'; name: string; discountAmount: string }
  | { status: 'error'; message: string }

export function CouponCodeInput({
  subtotal,
  customerId,
  couponCode,
  onCouponApplied,
  onCouponRemoved,
  className,
}: CouponCodeInputProps) {
  const { t } = useTranslation(['pos'])
  const { currency, toFixed: toFixedCurrency } = useCurrency()
  const [inputValue, setInputValue] = useState('')
  const [state, setState] = useState<CouponState>(
    couponCode
      ? { status: 'applied', name: couponCode, discountAmount: '0' }
      : { status: 'idle' },
  )

  const handleApply = useCallback(async () => {
    const code = inputValue.trim().toUpperCase()
    if (!code) return

    setState({ status: 'validating' })

    try {
      const result: ValidateCouponResponse = await validateCoupon({
        code,
        subtotal,
        ...(customerId ? { customer_id: customerId } : {}),
      })

      if (result.valid) {
        setState({
          status: 'applied',
          name: result.promotion_name ?? code,
          discountAmount: result.discount_amount ?? '0',
        })
        onCouponApplied(code, result.discount_amount ?? '0', result.promotion_name ?? code)
        setInputValue('')
      } else {
        setState({ status: 'error', message: result.message ?? t('pos:errors.invalidCoupon') })
      }
    } catch {
      setState({ status: 'error', message: t('pos:errors.invalidCoupon') })
    }
  }, [inputValue, subtotal, customerId, onCouponApplied, t])

  const handleRemove = useCallback(() => {
    setState({ status: 'idle' })
    setInputValue('')
    onCouponRemoved()
  }, [onCouponRemoved])

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter') {
      e.preventDefault()
      void handleApply()
    }
  }

  if (state.status === 'applied') {
    return (
      <div
        className={cn(
          'flex items-center justify-between px-3 py-2 rounded-lg border border-emerald-200 bg-emerald-50',
          className,
        )}
      >
        <div className="flex items-center gap-2 min-w-0">
          <Check className="w-4 h-4 text-emerald-600 flex-shrink-0" />
          <div className="min-w-0">
            <span className="text-sm font-medium text-emerald-700 truncate block">
              {state.name}
            </span>
            {parseFloat(state.discountAmount) > 0 && (
              <span className="text-xs text-emerald-600">
                -{toFixedCurrency(parseFloat(state.discountAmount))} {currency}
              </span>
            )}
          </div>
        </div>
        <button
          type="button"
          onClick={handleRemove}
          className="p-1 rounded text-emerald-500 hover:text-red-500 hover:bg-red-50 transition-colors"
          aria-label={t('pos:cart.removeCoupon')}
        >
          <X className="w-4 h-4" />
        </button>
      </div>
    )
  }

  return (
    <div className={cn('space-y-1', className)}>
      <div className="flex gap-2">
        <div className="relative flex-1">
          <Ticket className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input
            type="text"
            value={inputValue}
            onChange={(e) => {
              setInputValue(e.target.value.toUpperCase())
              if (state.status === 'error') setState({ status: 'idle' })
            }}
            onKeyDown={handleKeyDown}
            placeholder={t('pos:cart.couponCode')}
            className={cn(
              'w-full pl-9 pr-3 py-2 text-sm border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500',
              state.status === 'error' ? 'border-red-300 bg-red-50' : 'border-gray-300 bg-white',
            )}
            disabled={state.status === 'validating'}
          />
        </div>
        <POSButton
          variant="secondary"
          size="sm"
          onClick={() => void handleApply()}
          disabled={!inputValue.trim() || state.status === 'validating'}
        >
          {state.status === 'validating' ? (
            <Loader2 className="w-4 h-4 animate-spin" />
          ) : (
            t('pos:cart.applyCoupon')
          )}
        </POSButton>
      </div>
      {state.status === 'error' && (
        <p className="text-xs text-red-600 px-1">{state.message}</p>
      )}
    </div>
  )
}
