import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { X, CreditCard, Banknote, FileText, Building2, CheckCircle, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { POSButton } from '../../atoms/POSButton'
import { ReceiptPrintButton } from '../../components/ReceiptPrintButton'
import { useCompanySettings } from '../../hooks'
import { fetchPaymentMethods } from '../../api/paymentMethodApi'
import { fetchPaymentRepositories } from '../../api/paymentRepositoryApi'
import type { CartItem } from '../../molecules/CartLineItem'

// Icon mapping for payment methods (based on common payment method codes)
const PAYMENT_METHOD_ICONS: Record<string, typeof Banknote> = {
  cash: Banknote,
  card: CreditCard,
  check: FileText,
  bank: Building2,
  transfer: Building2,
}

export interface AdvancedPaymentsModalProps {
  isOpen: boolean
  onClose: () => void
  cartItems: CartItem[]
  onComplete: (paymentData: PaymentData) => Promise<{ receiptId: string; receiptNumber: string }>
  touchOptimized?: boolean
}

export interface PaymentData {
  methods: PaymentMethodAmount[]
  discount?: DiscountData
  voucherCode?: string
  overpaymentHandling?: 'change' | 'credit'
  invoiceAllocations?: Record<string, number>
}

export interface PaymentMethodAmount {
  methodId: string
  amount: number
  repositoryId?: string
  cardLastFour?: string
  transactionReference?: string
}

export interface DiscountData {
  type: 'percentage' | 'fixed'
  value: number
  reason: string
}

/**
 * AdvancedPaymentsModal - Full-Screen Payment Configuration
 *
 * This modal provides advanced payment options with progressive disclosure:
 * - Split payments (multiple payment methods)
 * - Discounts (percentage or fixed amount)
 * - Vouchers with validation
 * - Overpayment handling (change or customer credit)
 * - Customer balance info (when customer selected)
 * - Invoice allocation (for customers with open invoices)
 *
 * Layout: 70% payment configuration (left) + 30% mini cart (right)
 * Design: Matches application design system with proper section cards and styling
 */
export function AdvancedPaymentsModal({
  isOpen,
  onClose,
  cartItems,
  onComplete,
  touchOptimized = false,
}: AdvancedPaymentsModalProps) {
  const { t } = useTranslation('pos')
  const { autoPrintReceipts } = useCompanySettings()
  const [selectedMethods, setSelectedMethods] = useState<string[]>([])
  const [payments, setPayments] = useState<Record<string, string>>({})
  const [repositories, setRepositories] = useState<Record<string, string>>({})
  const [_discount, _setDiscount] = useState<DiscountData | null>(null)
  const [_voucherCode, _setVoucherCode] = useState('')
  const [overpaymentHandling, setOverpaymentHandling] = useState<'change' | 'credit'>('change')
  const [completedReceipt, setCompletedReceipt] = useState<{ receiptId: string; receiptNumber: string } | null>(null)
  const [isProcessing, setIsProcessing] = useState(false)

  // Fetch payment methods
  const { data: paymentMethods = [], isLoading: isLoadingMethods } = useQuery({
    queryKey: ['payment-methods'],
    queryFn: fetchPaymentMethods,
    staleTime: 10 * 60 * 1000, // Cache for 10 minutes
  })

  // Fetch payment repositories (safes, registers, bank accounts)
  const { data: paymentRepositories = [], isLoading: isLoadingRepos } = useQuery({
    queryKey: ['payment-repositories'],
    queryFn: fetchPaymentRepositories,
    staleTime: 10 * 60 * 1000,
  })

  const isLoadingData = isLoadingMethods || isLoadingRepos

  // Calculate cart totals
  const { subtotal, tax, total } = useMemo(() => {
    const subtotal = cartItems.reduce(
      (sum, item) => sum + parseFloat(item.line_total),
      0
    )
    const tax = cartItems.reduce(
      (sum, item) => sum + parseFloat(item.tax_amount || '0'),
      0
    )
    let total = subtotal + tax

    // Apply discount if present
    if (_discount) {
      if (_discount.type === 'percentage') {
        total = total * (1 - _discount.value / 100)
      } else {
        total = total - _discount.value
      }
    }

    return {
      subtotal: subtotal.toFixed(3),
      tax: tax.toFixed(3),
      total: total.toFixed(3),
    }
  }, [cartItems, _discount])

  // Calculate total paid
  const totalPaid = useMemo(() => {
    return Object.values(payments).reduce((sum, amount) => {
      const parsed = parseFloat(amount || '0')
      return sum + (isNaN(parsed) ? 0 : parsed)
    }, 0)
  }, [payments])

  const remaining = parseFloat(total) - totalPaid

  // Validate that all selected methods have repositories and amounts
  const allMethodsConfigured = useMemo(() => {
    return selectedMethods.every((methodId) => {
      const hasRepository = !!repositories[methodId]
      const hasAmount = !!payments[methodId] && parseFloat(payments[methodId]) > 0
      return hasRepository && hasAmount
    })
  }, [selectedMethods, repositories, payments])

  const isValid = Math.abs(remaining) < 0.001 && totalPaid > 0 && allMethodsConfigured
  const hasOverpayment = totalPaid > parseFloat(total) + 0.001

  const handleComplete = () => {
    if (!isValid || isProcessing) return

    setIsProcessing(true)

    const paymentData: PaymentData = {
      methods: selectedMethods.map((methodId) => ({
        methodId,
        amount: parseFloat(payments[methodId] || '0'),
        repositoryId: repositories[methodId],
      })),
      discount: _discount || undefined,
      voucherCode: _voucherCode || undefined,
      overpaymentHandling: hasOverpayment ? overpaymentHandling : undefined,
    }

    void onComplete(paymentData)
      .then((result) => {
        setCompletedReceipt(result)
      })
      .catch((error: unknown) => {
        console.error('Payment processing failed:', error)
        setIsProcessing(false)
        // Error handling will be done by parent (toast, etc.)
      })
  }

  const handleNewTransaction = () => {
    // Reset modal state
    setCompletedReceipt(null)
    setSelectedMethods([])
    setPayments({})
    setIsProcessing(false)
    onClose()
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className={cn('w-full h-full flex flex-col', colors.neutral[50])}>
        {/* Header - Consistent with Modal design */}
        <div className={cn(
          'flex items-center justify-between px-6 py-4',
          colors.white,
          borderColors.light,
          'border-b shadow-sm'
        )}>
          <h2 className={cn(
            'font-semibold',
            textColors.primary,
            touchOptimized ? 'text-2xl' : 'text-xl'
          )}>
            {t('advancedPayments.title')}
          </h2>
          <button
            onClick={onClose}
            className={cn(
              'rounded-lg p-1 transition-colors',
              textColors.disabled,
              colors.hover.gray100,
              textColors.hoverSecondary
            )}
            aria-label="Close"
          >
            <X className="h-6 w-6" />
          </button>
        </div>

        {/* Content */}
        <div className="flex flex-1 overflow-hidden">
          {isLoadingData ? (
            // Loading State
            <div className="flex-1 flex items-center justify-center">
              <div className="text-center space-y-4">
                <Loader2 className={cn('w-12 h-12 mx-auto animate-spin', textColors.primary)} />
                <p className={cn('text-lg', textColors.secondary)}>
                  {t('advancedPayments.loadingMethods')}
                </p>
              </div>
            </div>
          ) : completedReceipt ? (
            // Success State - Show receipt print options
            <div className="flex-1 flex items-center justify-center p-6">
              <div className="max-w-md w-full text-center space-y-6">
                <CheckCircle className={cn('w-24 h-24 mx-auto', textColors.success)} />
                <h3 className={cn('text-2xl font-bold', textColors.primary)}>
                  {t('payment.success')}
                </h3>
                <p className={cn('text-lg', textColors.secondary)}>
                  {t('payment.receiptNumber', { number: completedReceipt.receiptNumber })}
                </p>

                <div className="mt-8">
                  <ReceiptPrintButton
                    receiptId={completedReceipt.receiptId}
                    autoPrint={autoPrintReceipts}
                    showDownload={true}
                  />
                </div>

                <POSButton
                  variant="secondary"
                  size="lg"
                  fullWidth
                  onClick={handleNewTransaction}
                  className="mt-6"
                >
                  {t('payment.newTransaction')}
                </POSButton>
              </div>
            </div>
          ) : (
            <>
              {/* Left Panel: Payment Configuration (70%) */}
              <div className="flex-[7] overflow-y-auto p-6">
                <div className="max-w-4xl mx-auto space-y-6">
              {/* Payment Method Selector - Using standard card styling */}
              <div className={tokens.card.base}>
                <h3 className={cn('text-lg font-medium mb-4', textColors.primary)}>
                  {t('advancedPayments.paymentMethods')}
                </h3>
                {paymentMethods.filter(m => m.is_active).length === 0 ? (
                  <div className="py-8 text-center">
                    <Banknote className={cn('w-12 h-12 mx-auto mb-3', textColors.disabled)} />
                    <p className={cn('text-sm font-medium', textColors.secondary)}>
                      {t('advancedPayments.noPaymentMethods', { defaultValue: 'No payment methods configured' })}
                    </p>
                    <p className={cn('text-xs mt-1', textColors.tertiary)}>
                      {t('advancedPayments.configureInSettings', { defaultValue: 'Configure payment methods in Settings > Treasury' })}
                    </p>
                  </div>
                ) : (
                <div className="grid grid-cols-2 gap-3">
                  {paymentMethods.filter(m => m.is_active).map((method) => {
                    const Icon = PAYMENT_METHOD_ICONS[method.code.toLowerCase()] || Banknote
                    const isSelected = selectedMethods.includes(method.id)
                    return (
                      <POSButton
                        key={method.id}
                        variant={isSelected ? 'primary' : 'secondary'}
                        onClick={() => {
                          if (isSelected) {
                            setSelectedMethods(selectedMethods.filter((id) => id !== method.id))
                            const { [method.id]: _, ...newPayments } = payments
                            const { [method.id]: __, ...newRepositories } = repositories
                            setPayments(newPayments)
                            setRepositories(newRepositories)
                          } else {
                            setSelectedMethods([...selectedMethods, method.id])
                            // Auto-select first active repository
                            const activeRepos = paymentRepositories.filter(r => r.is_active)
                            if (activeRepos.length > 0) {
                              setRepositories((prev) => ({ ...prev, [method.id]: activeRepos[0].id }))
                            }
                          }
                        }}
                        className={cn(
                          'p-4 rounded-lg transition-all flex items-center gap-3 w-full justify-start',
                          isSelected
                            ? cn('border-2', borderColors.primary, colors.primary[50])
                            : cn('border-2', borderColors.light, borderColors.hover)
                        )}
                        icon={<Icon className="h-6 w-6" />}
                      >
                        {method.name}
                      </POSButton>
                    )
                  })}
                </div>
                )}
              </div>

              {/* Split Payment Inputs - Using standard card styling */}
              {selectedMethods.length > 0 && (
                <div className={tokens.card.base}>
                  <h3 className={cn('text-lg font-medium mb-4', textColors.primary)}>
                    {t('advancedPayments.paymentDistribution')}
                  </h3>
                  <div className="space-y-6">
                    {selectedMethods.map((methodId) => {
                      const method = paymentMethods.find((m) => m.id === methodId)
                      if (!method) return null

                      return (
                        <div key={methodId} className="space-y-3">
                          <div className="flex items-center justify-between">
                            <label className={cn('text-base font-semibold', textColors.primary)}>
                              {method.name}
                            </label>
                          </div>

                          {/* Repository Selection */}
                          <div className="space-y-2">
                            <label className={cn('text-sm font-medium', textColors.secondary)}>
                              {t('advancedPayments.selectRepository')}
                            </label>
                            {paymentRepositories.filter(r => r.is_active).length === 0 ? (
                              <p className={cn('text-sm py-2', textColors.error)}>
                                {t('advancedPayments.noRepositories', { defaultValue: 'No payment repositories configured. Add a cash register or bank account in Settings.' })}
                              </p>
                            ) : (
                            <select
                              value={repositories[methodId] || ''}
                              onChange={(e) => {
                                setRepositories({ ...repositories, [methodId]: e.target.value })
                              }}
                              className={tokens.input.base}
                            >
                              <option value="">
                                {t('advancedPayments.selectRepositoryPlaceholder')}
                              </option>
                              {paymentRepositories.filter(r => r.is_active).map((repo) => (
                                <option key={repo.id} value={repo.id}>
                                  {repo.name} ({t(`advancedPayments.repositoryType.${repo.type}`)})
                                </option>
                              ))}
                            </select>
                            )}
                          </div>

                          {/* Amount Input */}
                          <div className="flex items-center gap-3">
                            <div className="flex-1">
                              <label className={cn('text-sm font-medium mb-1 block', textColors.secondary)}>
                                {t('advancedPayments.amount')}
                              </label>
                              <input
                                type="number"
                                step="0.001"
                                min="0"
                                value={payments[methodId] || ''}
                                onChange={(e) => {
                                  setPayments({ ...payments, [methodId]: e.target.value })
                                }}
                                placeholder="0.000"
                                className={tokens.input.base}
                              />
                            </div>
                            <POSButton
                              variant="secondary"
                              size="sm"
                              onClick={() => {
                                setPayments({ ...payments, [methodId]: remaining.toFixed(3) })
                              }}
                              disabled={remaining <= 0}
                              className="mt-6"
                            >
                              {t('advancedPayments.useRemaining')}
                            </POSButton>
                            <span className={cn('text-sm font-medium mt-6', textColors.tertiary)}>
                              TND
                            </span>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                </div>
              )}

              {/* Discount Section - Using standard card styling */}
              <div className={tokens.card.base}>
                <h3 className={cn('text-lg font-medium mb-2', textColors.primary)}>
                  {t('advancedPayments.discount')}
                </h3>
                <p className={cn('text-sm', textColors.tertiary)}>
                  {t('advancedPayments.discountComingSoon')}
                </p>
              </div>

              {/* Progressive Disclosure: Overpayment Handler - Using alert styling */}
              {hasOverpayment && (
                <div className={cn(tokens.alert.warning, 'border rounded-lg p-4', borderColors.default)}>
                  <h4 className={cn('font-medium mb-1', textColors.warning)}>
                    {t('advancedPayments.overpaymentDetected')}
                  </h4>
                  <p className={cn('text-sm', textColors.warning)}>
                    {t('advancedPayments.excess')}: {(totalPaid - parseFloat(total)).toFixed(3)} TND
                  </p>
                  <div className="mt-4 space-y-2">
                    <label className={cn('flex items-center gap-2 text-sm cursor-pointer', textColors.warning)}>
                      <input
                        type="radio"
                        name="overpayment"
                        value="change"
                        checked={overpaymentHandling === 'change'}
                        onChange={(e) => { setOverpaymentHandling(e.target.value as 'change') }}
                        className={cn(tokens.radio.base, textColors.warningDark)}
                      />
                      <span>{t('advancedPayments.giveChange')}</span>
                    </label>
                    <label className={cn('flex items-center gap-2 text-sm cursor-pointer', textColors.warning)}>
                      <input
                        type="radio"
                        name="overpayment"
                        value="credit"
                        checked={overpaymentHandling === 'credit'}
                        onChange={(e) => { setOverpaymentHandling(e.target.value as 'credit') }}
                        className={cn(tokens.radio.base, textColors.warningDark)}
                      />
                      <span>{t('advancedPayments.addToCredit')}</span>
                    </label>
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Right Panel: Mini Cart + Complete Button (30%) */}
          <div className={cn('flex-[3] flex flex-col shadow-sm', colors.white, borderColors.light, 'border-l')}>
            {/* Mini Cart */}
            <div className="flex-1 overflow-y-auto p-6">
              <h3 className={cn('text-lg font-medium mb-4', textColors.primary)}>
                {t('advancedPayments.cartSummary')}
              </h3>

              <div className="space-y-3">
                {cartItems.map((item) => (
                  <div key={item.id} className="flex justify-between text-sm">
                    <div className="flex-1">
                      <span className={cn('font-medium', textColors.primary)}>{item.product.name}</span>
                      <span className={cn('ml-2', textColors.tertiary)}>×{item.quantity}</span>
                    </div>
                    <span className={cn('font-medium', textColors.primary)}>{item.line_total} TND</span>
                  </div>
                ))}
              </div>

              <div className={cn('mt-6 pt-4 space-y-2', borderColors.light, 'border-t')}>
                <div className="flex justify-between text-sm">
                  <span className={textColors.tertiary}>{t('advancedPayments.subtotal')}:</span>
                  <span className={cn('font-medium', textColors.primary)}>{subtotal} TND</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className={textColors.tertiary}>{t('advancedPayments.tax')}:</span>
                  <span className={cn('font-medium', textColors.primary)}>{tax} TND</span>
                </div>
                {_discount && (
                  <div className={cn('flex justify-between text-sm', textColors.success)}>
                    <span>{t('advancedPayments.discount')}:</span>
                    <span className="font-medium">
                      -{_discount.type === 'percentage' ? `${_discount.value.toString()}%` : `${_discount.value.toString()} TND`}
                    </span>
                  </div>
                )}
                <div className={cn('flex justify-between text-xl font-bold pt-3 mt-3', borderColors.light, 'border-t')}>
                  <span className={textColors.primary}>{t('advancedPayments.total')}:</span>
                  <span className={textColors.primary}>{total} TND</span>
                </div>
              </div>

              {/* Payment Summary */}
              <div className={cn('mt-6 p-4 rounded-lg border', colors.neutral[50], borderColors.light)}>
                <div className="flex justify-between mb-2">
                  <span className={cn('text-sm font-medium', textColors.secondary)}>
                    {t('advancedPayments.totalPaid')}:
                  </span>
                  <span
                    className={cn(
                      'text-sm font-bold',
                      totalPaid < parseFloat(total) ? textColors.error : textColors.success
                    )}
                  >
                    {totalPaid.toFixed(3)} TND
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className={cn('text-base font-medium', textColors.primary)}>
                    {t('advancedPayments.remaining')}:
                  </span>
                  <span className={cn('text-base font-bold', remaining > 0.001 ? textColors.error : textColors.primary)}>
                    {remaining.toFixed(3)} TND
                  </span>
                </div>
              </div>
            </div>

            {/* Complete Button - Using POSButton */}
            <div className={cn('p-6 border-t', borderColors.light, colors.neutral[50])}>
              {/* Validation hints */}
              {!isValid && selectedMethods.length > 0 && !isProcessing && (
                <div className={cn('mb-3 text-xs space-y-1', textColors.error)}>
                  {!allMethodsConfigured && (
                    <p>{t('advancedPayments.hintSelectRepository', { defaultValue: 'Select a repository and enter an amount for each payment method' })}</p>
                  )}
                  {remaining > 0.001 && totalPaid > 0 && (
                    <p>{t('advancedPayments.hintRemainingBalance', { defaultValue: 'Remaining balance must be zero to complete' })}</p>
                  )}
                </div>
              )}
              {!isValid && selectedMethods.length === 0 && !isProcessing && (
                <p className={cn('mb-3 text-xs', textColors.tertiary)}>
                  {t('advancedPayments.hintSelectMethod', { defaultValue: 'Select at least one payment method above' })}
                </p>
              )}
              <POSButton
                variant="success"
                size="lg"
                fullWidth
                onClick={handleComplete}
                disabled={!isValid || isProcessing}
                className="h-16 text-lg"
              >
                {isProcessing ? t('advancedPayments.processing') : t('advancedPayments.completeTransaction')}
              </POSButton>
            </div>
          </div>
          </>
          )}
        </div>
      </div>
    </div>
  )
}
