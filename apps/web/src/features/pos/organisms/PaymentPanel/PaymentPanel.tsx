import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'
import { POSButton } from '../../atoms'
import { type CartItem } from '../../molecules'
import { Calculator, Banknote, CreditCard } from 'lucide-react'
import { useCurrency } from '@/hooks/useCurrency'
import { bcadd, bcsub, bccomp } from '@/lib/decimal'

export interface PaymentPanelProps {
  items: CartItem[]
  onQuickCheckout: () => void
  onAdvancedPayments: () => void
  onOpenCalculator?: (() => void) | undefined
  touchOptimized?: boolean | undefined
  isNarrowScreen?: boolean | undefined
  inline?: boolean | undefined
  className?: string | undefined
  transactionDiscountAmount?: string | undefined
}

/**
 * PaymentPanel - Fixed Payment Summary and Checkout Actions
 *
 * This component is positioned fixed in the bottom right corner of the POS screen,
 * always visible even when scrolling cart items. It displays:
 * - Cart totals (subtotal, tax, grand total)
 * - Quick Checkout button (prominent, full width)
 * - Advanced Payments button (secondary)
 * - Calculator button (optional)
 *
 * Design: Floating panel with shadow and border for visual separation
 */
export function PaymentPanel({
  items,
  onQuickCheckout,
  onAdvancedPayments,
  onOpenCalculator,
  touchOptimized = false,
  isNarrowScreen = false,
  inline = false,
  className,
  transactionDiscountAmount = '0',
}: PaymentPanelProps) {
  const { t } = useTranslation(['common', 'pos'])
  const { decimals, toFixed: toFixedCurrency } = useCurrency()

  // Calculate totals
  const { subtotal, discount, tax, total } = useMemo(() => {
    const subtotal = items.reduce(
      (sum, item) => bcadd(sum, item.line_total, decimals),
      '0'
    )
    const discount = transactionDiscountAmount || '0'
    const subtotalAfterDiscount = bccomp(subtotal, discount) > 0 ? bcsub(subtotal, discount, decimals) : toFixedCurrency(0)
    const tax = items.reduce(
      (sum, item) => bcadd(sum, item.tax_amount || '0', decimals),
      '0'
    )
    const total = bcadd(subtotalAfterDiscount, tax, decimals)

    return {
      subtotal,
      discount,
      tax,
      total,
    }
  }, [items, transactionDiscountAmount, decimals, toFixedCurrency])

  const isEmpty = items.length === 0

  // Different container styling for inline vs floating
  const containerClasses = inline
    ? cn('w-full', className) // Inline mode: full width of parent (cart)
    : cn(
        'fixed z-40',
        isNarrowScreen
          ? 'bottom-0 left-0 right-0 w-full'
          : 'bottom-4 end-4 w-96',
        className
      ) // Floating mode: fixed positioning

  const innerClasses = inline
    ? '' // Inline mode: no special wrapper
    : cn(
        colors.white,
        'border-2',
        borderColors.default,
        'shadow-2xl',
        isNarrowScreen ? 'rounded-t-lg' : 'rounded-lg'
      ) // Floating mode: card styling

  return (
    <div className={containerClasses}>
      <div className={innerClasses}>
        {/* Totals Summary */}
        {!isEmpty && (
          <div className={cn('space-y-2', inline ? 'mb-4' : cn('border-b', borderColors.light), isNarrowScreen ? 'p-3' : inline ? '' : 'p-4')}>
            <div className="flex justify-between">
              <span
                className={cn(
                  textColors.tertiary,
                  touchOptimized ? 'text-lg' : 'text-base'
                )}
              >
                {t('common:pos.subtotal')}
              </span>
              <span
                className={cn(
                  'font-medium tabular-nums',
                  textColors.primary,
                  touchOptimized ? 'text-lg' : 'text-base'
                )}
              >
                {subtotal}
              </span>
            </div>

            {bccomp(discount, '0') > 0 && (
              <div className="flex justify-between">
                <span
                  className={cn(
                    textColors.tertiary,
                    touchOptimized ? 'text-lg' : 'text-base'
                  )}
                >
                  {t('common:pos.discount')}
                </span>
                <span
                  className={cn(
                    'font-medium tabular-nums',
                    textColors.error,
                    touchOptimized ? 'text-lg' : 'text-base'
                  )}
                >
                  -{discount}
                </span>
              </div>
            )}

            <div className="flex justify-between">
              <span
                className={cn(
                  textColors.tertiary,
                  touchOptimized ? 'text-lg' : 'text-base'
                )}
              >
                {t('common:pos.tax')}
              </span>
              <span
                className={cn(
                  'font-medium tabular-nums',
                  textColors.primary,
                  touchOptimized ? 'text-lg' : 'text-base'
                )}
              >
                {tax}
              </span>
            </div>

            <div className={cn('pt-2 border-t', borderColors.light)}>
              <div className="flex justify-between items-center">
                <span
                  className={cn(
                    'font-bold',
                    textColors.primary,
                    touchOptimized ? 'text-2xl' : 'text-xl'
                  )}
                >
                  {t('common:pos.total')}
                </span>
                <span
                  className={cn(
                    'font-bold tabular-nums',
                    textColors.brand,
                    touchOptimized ? 'text-2xl' : 'text-xl'
                  )}
                >
                  {total}
                </span>
              </div>
            </div>
          </div>
        )}

        {/* Action Buttons */}
        <div className={cn('space-y-3', isNarrowScreen ? 'p-3' : inline ? '' : 'p-4')}>
          <POSButton
            variant="success"
            size="lg"
            onClick={onQuickCheckout}
            disabled={isEmpty}
            fullWidth
            touchOptimized={touchOptimized}
            icon={<Banknote className="h-5 w-5" />}
          >
            {t('pos:payment.cashPayment')}
          </POSButton>

          <div className="flex gap-3">
            <POSButton
              variant="primary"
              size="lg"
              onClick={onAdvancedPayments}
              disabled={isEmpty}
              fullWidth
              touchOptimized={touchOptimized}
              icon={<CreditCard className="h-5 w-5" />}
            >
              {t('pos:payment.splitCardPayment')}
            </POSButton>

            {onOpenCalculator && (
              <POSButton
                variant="secondary"
                size="lg"
                onClick={onOpenCalculator}
                icon={<Calculator className="h-5 w-5" />}
                touchOptimized={touchOptimized}
                aria-label={t('common:pos.calculator')}
              />
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
