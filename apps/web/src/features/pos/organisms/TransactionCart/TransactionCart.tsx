import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { CartLineItem, type CartItem, TransactionDiscountInput, DiscountInput, AppliedDiscountsBadge, CouponCodeInput } from '../../molecules'
import { POSButton } from '../../atoms'
import { ShoppingCart, Trash2, User, UserPlus, Tag, Sparkles } from 'lucide-react'
import { PaymentPanel } from '../PaymentPanel/PaymentPanel'
import { Modal } from '@/components/organisms/Modal/Modal'
import { useDiscountPermissions } from '../../hooks/useDiscountPermissions'
import { useCurrency } from '@/hooks/useCurrency'
import { bcadd, bcmul } from '@/lib/decimal'
import { LoyaltyMemberBadge } from '../../components/LoyaltyMemberBadge'
import { LoyaltyRewardSelector } from '../../components/LoyaltyRewardSelector'
import { EarnPointsPreview } from '../../components/EarnPointsPreview'
import type { LoyaltyMember, LoyaltyEnrollment } from '../../api/loyaltyApi'
import type { DiscountBreakdownData } from '../../api/discountApi'

export interface Customer {
  id: string
  name: string
  phone?: string | undefined
}

export interface TransactionCartProps {
  items: CartItem[]
  onUpdateQuantity: (productId: string, newQuantity: number) => void
  onRemoveItem: (productId: string) => void
  onEditLineDiscount?: ((productId: string, discount: { type: 'percentage' | 'fixed'; value: string; reason?: string | undefined } | undefined) => void) | undefined
  onQuickCheckout: () => void
  onAdvancedPayments: () => void
  onOpenCalculator?: (() => void) | undefined
  selectedCustomer?: Customer | null | undefined
  onChangeCustomer?: (() => void) | undefined
  onClearCart?: (() => void) | undefined
  touchOptimized?: boolean | undefined
  className?: string | undefined
  terminalCode?: string | undefined
  transactionDiscount?: {
    amount: string
    reason?: string | undefined
  } | undefined
  onUpdateTransactionDiscount?: ((discount?: { amount: string; reason?: string | undefined }  ) => void) | undefined
  loyaltyMember?: LoyaltyMember | null | undefined
  loyaltyEnrollment?: LoyaltyEnrollment | null | undefined
  discountBreakdown?: DiscountBreakdownData | null | undefined
  discountSavings?: string | undefined
  /**
   * The discount preview is refetching. The preview carries no `placeholderData`
   * (a placeholder there is a discount priced for a different cart), so without
   * this the applied-discounts block unmounts on every cart edit and an
   * in-flight preview is indistinguishable from "no promotion applies".
   */
  isDiscountPreviewLoading?: boolean | undefined
  couponCode?: string | null | undefined
  onCouponApplied?: ((code: string, discountAmount: string, promotionName: string) => void) | undefined
  onCouponRemoved?: (() => void) | undefined
  onLoyaltyRewardRedeemed?: ((rewardValue: string, rewardName: string, rewardId: string) => void) | undefined
  smartPromptsSlot?: React.ReactNode | undefined
}

export function TransactionCart({
  items,
  onUpdateQuantity,
  onRemoveItem,
  onEditLineDiscount,
  onQuickCheckout,
  onAdvancedPayments,
  onOpenCalculator,
  selectedCustomer,
  onChangeCustomer,
  onClearCart,
  touchOptimized = false,
  className,
  terminalCode,
  transactionDiscount,
  onUpdateTransactionDiscount,
  loyaltyMember,
  loyaltyEnrollment,
  discountBreakdown,
  discountSavings,
  isDiscountPreviewLoading = false,
  couponCode,
  onCouponApplied,
  onCouponRemoved,
  onLoyaltyRewardRedeemed,
  smartPromptsSlot,
}: TransactionCartProps) {
  const { t } = useTranslation(['pos', 'common'])
  const { currency, decimals, toFixed: toFixedCurrency } = useCurrency()
  const { permissions } = useDiscountPermissions(terminalCode)
  const [showTransactionDiscountModal, setShowTransactionDiscountModal] = useState(false)
  const [editingLineDiscountProductId, setEditingLineDiscountProductId] = useState<string | null>(null)

  // Calculate item count for header badge
  const itemCount = useMemo(() => {
    return items.reduce((sum, item) => sum + item.quantity, 0)
  }, [items])

  // Calculate subtotal for discount validation
  const subtotal = useMemo(() => {
    return items.reduce((sum, item) => bcadd(sum, item.line_total, decimals), '0')
  }, [items, decimals])

  const isEmpty = items.length === 0

  return (
    <div
      className={cn(
        'flex flex-col h-full rounded-lg border',
        colors.neutral[50],
        borderColors.light,
        touchOptimized ? 'p-6' : 'p-4',
        className
      )}
    >
      {/* Header */}
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <ShoppingCart className={cn('w-6 h-6', textColors.secondary)} />
          <h2
            className={cn(
              'font-bold',
              textColors.primary,
              touchOptimized ? 'text-2xl' : 'text-xl'
            )}
          >
            {t('pos:cart.title')}
          </h2>
          {!isEmpty && (
            <span
              className={cn(
                'px-2 py-1 rounded-full font-medium',
                tokens.badge.blue,
                touchOptimized ? 'text-base' : 'text-sm'
              )}
            >
              {itemCount} {itemCount === 1 ? t('pos:cart.item') : t('pos:cart.items')}
            </span>
          )}
        </div>

        {onClearCart && !isEmpty && (
          <POSButton
            variant="secondary"
            size="sm"
            onClick={onClearCart}
            icon={<Trash2 className="w-4 h-4" />}
            aria-label={t('pos:cart.clear')}
          >
            {t('pos:cart.clear')}
          </POSButton>
        )}
      </div>

      {/* Customer Section */}
      <div className="mb-4">
        <div
          className={cn(
            'flex items-center justify-between p-3 bg-white rounded-lg border',
            selectedCustomer ? borderColors.success : borderColors.default
          )}
        >
          <div className="flex items-center gap-3">
            {selectedCustomer ? (
              <User className={cn('w-5 h-5', textColors.success)} />
            ) : (
              <UserPlus className={cn('w-5 h-5', textColors.disabled)} />
            )}
            <div>
              <div
                className={cn(
                  'font-medium',
                  selectedCustomer ? textColors.primary : textColors.tertiary
                )}
              >
                {selectedCustomer ? selectedCustomer.name : t('pos:cart.walkInCustomer')}
              </div>
              {selectedCustomer?.phone && (
                <div className={cn('text-sm', textColors.tertiary)}>
                  {selectedCustomer.phone}
                </div>
              )}
            </div>
          </div>

          {onChangeCustomer && (
            <POSButton
              variant="secondary"
              size="sm"
              onClick={onChangeCustomer}
              aria-label={t('pos:cart.change')}
            >
              {t('pos:cart.change')}
            </POSButton>
          )}
        </div>
      </div>

      {/* Loyalty Section */}
      {loyaltyMember && loyaltyEnrollment && (
        <div className="mb-4 space-y-2">
          <LoyaltyMemberBadge
            member={loyaltyMember}
            enrollment={loyaltyEnrollment}
          />
          {!isEmpty && onLoyaltyRewardRedeemed && (
            <LoyaltyRewardSelector
              enrollmentId={loyaltyEnrollment.id}
              onRewardRedeemed={onLoyaltyRewardRedeemed}
            />
          )}
          {!isEmpty && (
            <EarnPointsPreview
              enrollmentId={loyaltyEnrollment.id}
              cartTotal={subtotal}
              cartItems={items.map((item) => ({
                product_id: item.product.id,
                quantity: item.quantity,
                price: parseFloat(item.unit_price),
              }))}
            />
          )}
        </div>
      )}

      {/* Cart Items - Scrollable area */}
      <div className="flex-1 overflow-y-auto space-y-3 mb-4">
        {isEmpty ? (
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <ShoppingCart className={cn('w-16 h-16 mb-4', textColors.disabled)} />
            <p className={cn('text-lg font-medium', textColors.tertiary)}>{t('pos:cart.empty')}</p>
            <p className={cn('text-sm mt-2', textColors.disabled)}>
              {t('pos:cart.addProducts')}
            </p>
          </div>
        ) : (
          items.map((item) => (
            <CartLineItem
              key={item.product.id}
              item={item}
              onUpdateQuantity={onUpdateQuantity}
              onRemove={onRemoveItem}
              {...(permissions?.canApplyLineDiscounts ? {
                showDiscount: true as const,
                onEditDiscount: (productId: string) => {
                  setEditingLineDiscountProductId(productId)
                },
              } : {})}
              touchOptimized={touchOptimized}
            />
          ))
        )}
      </div>

      {/* Smart Prompts Slot (inline variant) */}
      {smartPromptsSlot}

      {/* Transaction Discount Section */}
      {!isEmpty && permissions?.canApplyTransactionDiscounts && onUpdateTransactionDiscount && (
        <div className={cn('border-t pt-3 pb-3', borderColors.light)}>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Tag className={cn('w-4 h-4', textColors.tertiary)} />
              <span className={cn('text-sm font-medium', textColors.secondary)}>
                {t('pos:cart.transactionDiscount')}
              </span>
            </div>
            {transactionDiscount && parseFloat(transactionDiscount.amount) > 0 ? (
              <div className="flex items-center gap-3">
                <div className="text-right">
                  <div className={cn('font-semibold tabular-nums', textColors.error)}>
                    -{toFixedCurrency(parseFloat(transactionDiscount.amount))} {currency}
                  </div>
                  {transactionDiscount.reason && (
                    <div className={cn('text-xs italic', textColors.tertiary)}>
                      {transactionDiscount.reason}
                    </div>
                  )}
                </div>
                <POSButton
                  variant="secondary"
                  size="sm"
                  onClick={() => {
                    setShowTransactionDiscountModal(true)
                  }}
                  aria-label={t('pos:cart.editDiscount')}
                >
                  {t('common:edit')}
                </POSButton>
              </div>
            ) : (
              <POSButton
                variant="secondary"
                size="sm"
                onClick={() => {
                  setShowTransactionDiscountModal(true)
                }}
                icon={<Tag className="w-4 h-4" />}
              >
                {t('pos:cart.addDiscount')}
              </POSButton>
            )}
          </div>
        </div>
      )}

      {/* Coupon Code Input */}
      {!isEmpty && onCouponApplied && onCouponRemoved && (
        <div className={cn('border-t pt-3 pb-3', borderColors.light)}>
          <CouponCodeInput
            subtotal={subtotal}
            {...(selectedCustomer?.id ? { customerId: selectedCustomer.id } : {})}
            couponCode={couponCode ?? null}
            onCouponApplied={onCouponApplied}
            onCouponRemoved={onCouponRemoved}
          />
        </div>
      )}

      {/* Applied discounts still computing — hold the row, do not read as "none" */}
      {!isEmpty && isDiscountPreviewLoading && (
        <div
          data-testid="applied-discounts-loading"
          aria-busy="true"
          aria-live="polite"
          className={cn('border-t pt-3 pb-3 space-y-2', borderColors.light)}
        >
          <div className={cn('animate-pulse h-6 w-2/5 rounded-full', colors.neutral[200])} />
          <div className={cn('animate-pulse h-4 w-1/4 rounded', colors.neutral[200])} />
        </div>
      )}

      {/* Applied Discounts (promotions, coupons, loyalty — not manual) */}
      {!isEmpty && !isDiscountPreviewLoading && discountBreakdown && discountBreakdown.lines.filter((l) => l.source !== 'manual').length > 0 && (
        <div className={cn('border-t pt-3 pb-3 space-y-2', borderColors.light)}>
          {discountBreakdown.lines
            .filter((l) => l.source !== 'manual')
            .map((line, idx) => (
              <AppliedDiscountsBadge key={`${line.source}-${idx}`} line={line} />
            ))}
          {parseFloat(discountSavings ?? '0') > 0 && (
            <div className="flex items-center justify-between px-3 py-1.5 text-sm">
              <span className={cn('flex items-center gap-1.5 font-medium', textColors.success)}>
                <Sparkles className="w-4 h-4" />
                {t('pos:cart.totalSavings')}
              </span>
              <span className={cn('font-semibold tabular-nums', textColors.success)}>
                -{toFixedCurrency(parseFloat(discountSavings ?? '0'))} {currency}
              </span>
            </div>
          )}
        </div>
      )}

      {/* Payment Panel - Fixed at bottom of cart section */}
      {!isEmpty && (
        <div className={cn('border-t pt-4', borderColors.light)}>
          <PaymentPanel
            items={items}
            onQuickCheckout={onQuickCheckout}
            onAdvancedPayments={onAdvancedPayments}
            onOpenCalculator={onOpenCalculator}
            touchOptimized={touchOptimized}
            inline={true}
            transactionDiscountAmount={transactionDiscount?.amount}
          />
        </div>
      )}

      {/* Line Discount Modal */}
      {editingLineDiscountProductId && permissions && onEditLineDiscount && (() => {
        const editingItem = items.find((i) => i.product.id === editingLineDiscountProductId)
        if (!editingItem) return null
        const grossLineTotal = bcmul(editingItem.unit_price, String(editingItem.quantity), decimals)
        return (
          <Modal
            isOpen={!!editingLineDiscountProductId}
            onClose={() => { setEditingLineDiscountProductId(null); }}
            title={`${t('pos:cart.discount')} — ${editingItem.product.name}`}
            size="md"
          >
            <div className="p-4">
              <DiscountInput
                lineTotal={grossLineTotal}
                {...(editingItem.discount_type != null ? {
                  currentDiscount: {
                    type: editingItem.discount_type as 'percentage' | 'fixed' | null,
                    ...(editingItem.discount_percent != null ? { percent: editingItem.discount_percent } : {}),
                    ...(editingItem.discount_amount != null ? { amount: editingItem.discount_amount } : {}),
                    ...(editingItem.discount_reason != null ? { reason: editingItem.discount_reason } : {}),
                  },
                } : {})}
                effectiveLimit={permissions.effectiveLimit}
                requiresReason={permissions.requiresReason}
                onApplyDiscount={(discount) => {
                  onEditLineDiscount(editingLineDiscountProductId, {
                    type: discount.type,
                    value: discount.type === 'percentage' ? (discount.percent ?? '0') : (discount.amount ?? '0'),
                    ...(discount.reason != null ? { reason: discount.reason } : {}),
                  })
                  setEditingLineDiscountProductId(null)
                }}
                onClearDiscount={() => {
                  onEditLineDiscount(editingLineDiscountProductId, undefined)
                  setEditingLineDiscountProductId(null)
                }}
                touchOptimized={touchOptimized}
              />
            </div>
          </Modal>
        )
      })()}

      {/* Transaction Discount Modal */}
      {showTransactionDiscountModal && permissions && onUpdateTransactionDiscount && (
        <Modal
          isOpen={showTransactionDiscountModal}
          onClose={() => {
            setShowTransactionDiscountModal(false)
          }}
          title={t('pos:cart.transactionDiscount')}
          size="md"
        >
          <div className="p-4">
            <TransactionDiscountInput
              currentAmount={transactionDiscount?.amount}
              currentReason={transactionDiscount?.reason}
              subtotal={subtotal}
              effectiveLimit={permissions.effectiveLimit}
              requiresReason={permissions.requiresReason}
              onApply={(amount, reason) => {
                onUpdateTransactionDiscount({ amount, reason })
                setShowTransactionDiscountModal(false)
              }}
              onClear={() => {
                onUpdateTransactionDiscount(undefined)
                setShowTransactionDiscountModal(false)
              }}
              touchOptimized={touchOptimized}
            />
          </div>
        </Modal>
      )}
    </div>
  )
}
