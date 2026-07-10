import { useState, useMemo, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import {
  X, CheckCircle, Loader2, Trash2, AlertTriangle,
  Banknote, CreditCard, FileText, Building2, Wallet, Tag,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { tokens, textColors, borderColors, colors , semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { Select } from '@/components/atoms'
import { POSButton } from '../../atoms/POSButton'
import { ReceiptPrintButton } from '../../components/ReceiptPrintButton'
import { useCompanySettings } from '../../hooks'
import { useDiscountPermissions } from '../../hooks/useDiscountPermissions'
import { useCurrency } from '@/hooks/useCurrency'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { bcadd, bcsub, bccomp } from '@/lib/decimal'
import { fetchPaymentMethods } from '../../api/paymentMethodApi'
import { fetchPaymentRepositories } from '../../api/paymentRepositoryApi'
import type { CartItem } from '../../molecules/CartLineItem'
import { TransactionDiscountInput, CouponCodeInput } from '../../molecules'
import { LoyaltyRewardSelector } from '../../components/LoyaltyRewardSelector'
import { Modal } from '@/components/organisms/Modal/Modal'
import type { PaymentMethod as PaymentMethodType } from '../../api/paymentMethodApi'
import type { PaymentRepository as PaymentRepositoryType } from '../../api/paymentRepositoryApi'
import { Input } from '@/components/atoms'

/**
 * Return the repository types compatible with a given payment method.
 * Cash-like -> cash_register/safe; Card-like -> bank_account/virtual; Check-like -> safe/bank_account.
 */
function getCompatibleRepositoryTypes(method: PaymentMethodType): PaymentRepositoryType['type'][] {
  if (method.is_physical && !method.has_maturity) {
    return ['cash_register', 'safe']
  }
  if (method.requires_third_party) {
    return ['bank_account', 'virtual']
  }
  if (method.has_maturity) {
    return ['safe', 'bank_account']
  }
  return ['cash_register', 'safe', 'bank_account', 'virtual']
}

const METHOD_ICONS: Record<string, typeof Banknote> = {
  CASH: Banknote,
  ESPECES: Banknote,
  CARD: CreditCard,
  CARTE: CreditCard,
  CB: CreditCard,
  CHECK: FileText,
  CHEQUE: FileText,
  TRANSFER: Building2,
  VIREMENT: Building2,
  MOBILE: Wallet,
}

function getMethodIcon(method: PaymentMethodType) {
  const code = method.code?.toUpperCase() ?? ''
  return METHOD_ICONS[code] ?? Wallet
}

/**
 * Sum amounts from "immediate" (change-safe) payment methods using bcadd.
 * Cash: is_physical && !has_maturity; Card/mobile: requires_third_party.
 * Uses bcadd (not float reduce) to prevent IEEE 754 drift — 0.1 + 0.2 via
 * native float gives 0.30000000000000004; bcadd gives the exact string '0.30'.
 */
export function computeMaxSafeChange(
  payments: ReadonlyArray<{ methodId: string; amount: number }>,
  methods: ReadonlyArray<{ id: string; is_physical: boolean; has_maturity: boolean; requires_third_party: boolean }>,
  decimals: number
): string {
  return payments
    .filter(p => {
      const method = methods.find(m => m.id === p.methodId)
      if (!method) return false
      return (method.is_physical && !method.has_maturity) || method.requires_third_party
    })
    .reduce((sum, p) => bcadd(sum, String(p.amount), decimals), '0')
}

interface PaymentLine {
  id: string
  methodId: string
  methodName: string
  amount: number
  repositoryId: string
  repositoryName: string
  reference: string
  cardLastFour: string
}

export interface AdvancedPaymentsModalProps {
  isOpen: boolean
  onClose: () => void
  cartItems: CartItem[]
  onComplete: (paymentData: PaymentData) => Promise<{ receiptId: string; receiptNumber: string }>
  touchOptimized?: boolean | undefined
  loyaltyEnrollmentId?: string | undefined
  transactionDiscountAmount?: string | undefined
  terminalCode?: string | undefined
  transactionDiscount?: { amount: string; reason?: string } | undefined
  onTransactionDiscountChange?: (discount?: { amount: string; reason?: string }) => void
  couponCode?: string | null | undefined
  onCouponApplied?: (code: string, discountAmount: string, promotionName: string) => void
  onCouponRemoved?: () => void
  selectedCustomerId?: string | undefined
}

export interface OverpaymentHandling {
  changeAmount: number
  creditAmount: number
}

export interface PaymentData {
  methods: PaymentMethodAmount[]
  discount?: DiscountData
  voucherCode?: string
  overpaymentHandling?: OverpaymentHandling
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

export function AdvancedPaymentsModal({
  isOpen,
  onClose,
  cartItems,
  onComplete,
  touchOptimized = false,
  loyaltyEnrollmentId,
  transactionDiscountAmount = '0',
  terminalCode,
  transactionDiscount,
  onTransactionDiscountChange,
  couponCode,
  onCouponApplied,
  onCouponRemoved,
  selectedCustomerId,
}: AdvancedPaymentsModalProps) {
  const { t } = useTranslation('pos')
  const { autoPrintReceipts } = useCompanySettings()
  const { currency, decimals, toFixed: toFixedCurrency } = useCurrency()
  const { permissions } = useDiscountPermissions(terminalCode)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [showDiscountModal, setShowDiscountModal] = useState(false)
  const [completedReceipt, setCompletedReceipt] = useState<{ receiptId: string; receiptNumber: string } | null>(null)
  const [isProcessing, setIsProcessing] = useState(false)

  // Added payments list
  const [addedPayments, setAddedPayments] = useState<PaymentLine[]>([])

  // Entry form state (staging area)
  const [selectedMethodId, setSelectedMethodId] = useState<string | null>(null)
  const [entryAmount, setEntryAmount] = useState('')
  const [entryRepositoryId, setEntryRepositoryId] = useState('')
  const [entryReference, setEntryReference] = useState('')
  const [entryCardLastFour, setEntryCardLastFour] = useState('')

  const { data: paymentMethods = [], isLoading: isLoadingMethods } = useQuery({
    queryKey: tenantScopedKey(['payment-methods']),
    queryFn: fetchPaymentMethods,
    staleTime: 10 * 60 * 1000,
    enabled: tenantId !== null && companyId !== null,
  })

  const { data: paymentRepositories = [], isLoading: isLoadingRepos } = useQuery({
    queryKey: tenantScopedKey(['payment-repositories']),
    queryFn: fetchPaymentRepositories,
    staleTime: 10 * 60 * 1000,
    enabled: tenantId !== null && companyId !== null,
  })

  const isLoadingData = isLoadingMethods || isLoadingRepos

  const { subtotal, discount, tax, total } = useMemo(() => {
    const subtotal = cartItems.reduce(
      (sum, item) => bcadd(sum, item.line_total, decimals),
      '0'
    )
    const discount = transactionDiscountAmount || '0'
    const subtotalAfterDiscount = bccomp(subtotal, discount) > 0 ? bcsub(subtotal, discount, decimals) : toFixedCurrency(0)
    const tax = cartItems.reduce(
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
  }, [cartItems, transactionDiscountAmount, decimals, toFixedCurrency])

  const activePaymentMethods = useMemo(
    () => paymentMethods.filter(m => m.is_active),
    [paymentMethods]
  )

  const totalPaidStr = useMemo(
    () => addedPayments.reduce((sum, l) => bcadd(sum, String(l.amount), decimals), '0'),
    [addedPayments, decimals]
  )
  const totalPaid = parseFloat(totalPaidStr)

  const remaining = bcsub(total, totalPaidStr, decimals)
  const hasOverpayment = bccomp(totalPaidStr, total) > 0

  /**
   * Sum of amounts from "immediate" (change-safe) payment methods.
   * Delegated to the exported pure helper computeMaxSafeChange so the
   * bcadd-vs-float precision guarantee is unit-testable without DOM rendering.
   */
  const maxSafeChange = useMemo(
    () => computeMaxSafeChange(addedPayments, paymentMethods, decimals),
    [addedPayments, paymentMethods, decimals]
  )

  const overpaymentAmount = hasOverpayment ? parseFloat(bcsub(totalPaidStr, total, decimals)) : 0
  /** Maximum cash change that can safely be returned (string decimal) */
  const safeChangeLimit = bccomp(maxSafeChange, total) > 0
    ? bcsub(maxSafeChange, total, decimals)
    : '0'
  /** Whether all overpayment is covered by immediate methods */
  const isFullySafeOverpayment = overpaymentAmount > 0 &&
    bccomp(String(overpaymentAmount), bcadd(safeChangeLimit, '0.001', decimals)) <= 0
  /** Whether some (but not all) overpayment can be returned as change */
  const hasPartialSafeChange = overpaymentAmount > 0 && !isFullySafeOverpayment &&
    bccomp(safeChangeLimit, '0.001') > 0

  // For Scenario B (fully safe): user can choose change vs credit
  const [preferChangeOverCredit, setPreferChangeOverCredit] = useState(true)

  /** Computed overpayment handling based on safety analysis */
  const computedOverpaymentHandling = useMemo((): OverpaymentHandling | undefined => {
    if (!hasOverpayment) return undefined

    if (isFullySafeOverpayment) {
      // Scenario B: user can choose all-change or all-credit
      return preferChangeOverCredit
        ? { changeAmount: overpaymentAmount, creditAmount: 0 }
        : { changeAmount: 0, creditAmount: overpaymentAmount }
    }

    // Scenario C: partially or fully unsafe
    // safeChangeLimit is now a decimal string; pick the lesser of overpayment vs safe limit
    const changeAmount = bccomp(String(overpaymentAmount), safeChangeLimit) <= 0
      ? overpaymentAmount
      : parseFloat(safeChangeLimit)
    const creditAmount = overpaymentAmount - changeAmount
    return { changeAmount, creditAmount }
  }, [hasOverpayment, isFullySafeOverpayment, preferChangeOverCredit, overpaymentAmount, safeChangeLimit])

  const selectedMethod = useMemo(
    () => paymentMethods.find(m => m.id === selectedMethodId),
    [paymentMethods, selectedMethodId]
  )

  const compatibleRepos = useMemo(() => {
    if (!selectedMethod) return []
    const compatibleTypes = getCompatibleRepositoryTypes(selectedMethod)
    return paymentRepositories.filter(
      r => r.is_active && compatibleTypes.includes(r.type)
    )
  }, [selectedMethod, paymentRepositories])

  const handleSelectMethod = useCallback((methodId: string) => {
    setSelectedMethodId(methodId)
    setEntryReference('')
    setEntryCardLastFour('')

    // Auto-select first compatible repository
    const method = paymentMethods.find(m => m.id === methodId)
    if (method) {
      const types = getCompatibleRepositoryTypes(method)
      const repos = paymentRepositories.filter(r => r.is_active && types.includes(r.type))
      setEntryRepositoryId(repos[0]?.id ?? '')
    }

    // Pre-fill with remaining balance
    const currentRemaining = bcsub(total, totalPaidStr, decimals)
    if (bccomp(currentRemaining, '0') > 0) {
      setEntryAmount(currentRemaining)
    } else {
      setEntryAmount('')
    }
  }, [paymentMethods, paymentRepositories, total, totalPaidStr, decimals])

  const handlePayRemaining = useCallback(() => {
    if (bccomp(remaining, '0') > 0) {
      setEntryAmount(remaining)
    }
  }, [remaining])

  const canAddPayment = useMemo(() => {
    const amount = parseFloat(entryAmount || '0')
    return selectedMethodId !== null && amount > 0 && entryRepositoryId !== ''
  }, [selectedMethodId, entryAmount, entryRepositoryId])

  const handleAddPayment = useCallback(() => {
    if (!selectedMethod || !canAddPayment) return

    const repo = paymentRepositories.find(r => r.id === entryRepositoryId)
    const newPayment: PaymentLine = {
      id: crypto.randomUUID(),
      methodId: selectedMethod.id,
      methodName: selectedMethod.name,
      amount: parseFloat(entryAmount || '0'),
      repositoryId: entryRepositoryId,
      repositoryName: repo?.name ?? '',
      reference: entryReference,
      cardLastFour: entryCardLastFour,
    }

    setAddedPayments(prev => [...prev, newPayment])

    // Reset entry form
    setSelectedMethodId(null)
    setEntryAmount('')
    setEntryRepositoryId('')
    setEntryReference('')
    setEntryCardLastFour('')
  }, [selectedMethod, canAddPayment, entryAmount, entryRepositoryId, entryReference, entryCardLastFour, paymentRepositories])

  const handleRemovePayment = useCallback((id: string) => {
    setAddedPayments(prev => prev.filter(p => p.id !== id))
  }, [])

  const allConfigured = addedPayments.length > 0 && addedPayments.every(l =>
    l.methodId && l.amount > 0 && l.repositoryId
  )

  const remainingAbs = bccomp(remaining, '0') >= 0 ? remaining : bcsub('0', remaining, decimals)
  const isValid = (bccomp(remainingAbs, '0.001') < 0 || hasOverpayment) && totalPaid > 0 && allConfigured

  const handleComplete = () => {
    if (!isValid || isProcessing) return

    setIsProcessing(true)

    const methods: PaymentMethodAmount[] = addedPayments
      .filter(l => l.amount > 0)
      .map(l => {
        const entry: PaymentMethodAmount = {
          methodId: l.methodId,
          amount: l.amount,
        }
        if (l.repositoryId) entry.repositoryId = l.repositoryId
        if (l.cardLastFour) entry.cardLastFour = l.cardLastFour
        if (l.reference) entry.transactionReference = l.reference
        return entry
      })

    const paymentData: PaymentData = { methods }
    if (couponCode) paymentData.voucherCode = couponCode
    if (computedOverpaymentHandling) paymentData.overpaymentHandling = computedOverpaymentHandling

    void onComplete(paymentData)
      .then((result) => {
        setCompletedReceipt(result)
      })
      .catch((error: unknown) => {
        console.error('Payment processing failed:', error)
        setIsProcessing(false)
      })
  }

  const handleNewTransaction = () => {
    setCompletedReceipt(null)
    setAddedPayments([])
    setSelectedMethodId(null)
    setEntryAmount('')
    setEntryRepositoryId('')
    setEntryReference('')
    setEntryCardLastFour('')
    setIsProcessing(false)
    onClose()
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className={cn('w-full h-full flex flex-col', colors.neutral[50])}>
        {/* Header */}
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
              'rounded-lg p-2 transition-colors',
              textColors.disabled,
              colors.hover.gray100,
              textColors.hoverSecondary
            )}
            aria-label={t('advancedPayments.close')}
          >
            <X className="h-6 w-6" />
          </button>
        </div>

        {/* Content */}
        <div className="flex flex-1 flex-col overflow-hidden">
          {isLoadingData ? (
            <div className="flex-1 flex items-center justify-center">
              <div className="text-center space-y-4">
                <Loader2 className={cn('w-12 h-12 mx-auto animate-spin', textColors.primary)} />
                <p className={cn('text-lg', textColors.secondary)}>
                  {t('advancedPayments.loadingMethods')}
                </p>
              </div>
            </div>
          ) : completedReceipt ? (
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
              {/* Two-column layout: payments left, order summary right */}
              <div className="flex flex-1 overflow-hidden">

              {/* Left: Payment flow */}
              <div className="flex-1 overflow-y-auto p-6">
                <div className="max-w-3xl mx-auto space-y-6">

                  {/* Payment Method Button Grid */}
                  {activePaymentMethods.length === 0 ? (
                    <div className={cn(tokens.card.base, 'py-8 text-center')}>
                      <p className={cn('text-sm font-medium', textColors.secondary)}>
                        {t('advancedPayments.noPaymentMethods', { defaultValue: 'No payment methods configured' })}
                      </p>
                      <p className={cn('text-xs mt-1', textColors.tertiary)}>
                        {t('advancedPayments.configureInSettings', { defaultValue: 'Configure payment methods in Settings > Treasury' })}
                      </p>
                    </div>
                  ) : (
                    <>
                      <div>
                        <p className={cn('text-sm font-medium mb-3', textColors.secondary)}>
                          {t('advancedPayments.tapToSelect')}
                        </p>
                        <div className="grid grid-cols-3 gap-3">
                          {activePaymentMethods.map((method) => {
                            const Icon = getMethodIcon(method)
                            const isSelected = selectedMethodId === method.id
                            return (
                              <button
                                key={method.id}
                                type="button"
                                onClick={() => { handleSelectMethod(method.id); }}
                                className={cn(
                                  'flex flex-col items-center justify-center gap-2',
                                  'min-h-[72px] rounded-lg border-2 p-3',
                                  'font-medium transition-all duration-150',
                                  'active:scale-95 transform',
                                  touchOptimized && 'min-h-[80px]',
                                  isSelected
                                    ? cn('shadow-md', colors.primary[50], borderColors.primary, textColors.brand)
                                    : cn(
                                      borderColors.light, colors.white, textColors.secondary,
                                      borderColors.hover, colors.hover.gray50
                                    )
                                )}
                              >
                                <Icon className={cn('h-6 w-6', isSelected ? textColors.brand : textColors.tertiary)} />
                                <span className="text-sm leading-tight text-center">{method.name}</span>
                              </button>
                            )
                          })}
                        </div>
                      </div>

                      {/* Configuration Panel */}
                      {selectedMethod && (
                        <div className={cn(
                          'rounded-lg border p-5 space-y-4',
                          colors.white,
                          borderColors.light,
                          'shadow-sm'
                        )}>
                          {/* Amount row */}
                          <div className="flex items-end gap-3">
                            <div className="flex-1">
                              <label className={cn('text-xs font-medium mb-1.5 block', textColors.tertiary)}>
                                {t('advancedPayments.amount')} ({currency})
                              </label>
                              <Input
                                type="number"
                                step="0.001"
                                min="0"
                                value={entryAmount}
                                onChange={(e) => { setEntryAmount(e.target.value); }}
                                placeholder="0.00"
                                className={cn( 'text-2xl font-semibold min-h-[56px]')}
                                autoFocus
                              />
                            </div>
                            <POSButton
                              variant="secondary"
                              size="lg"
                              onClick={handlePayRemaining}
                              disabled={bccomp(remaining, '0') <= 0}
                              className="min-h-[56px] whitespace-nowrap"
                              touchOptimized={touchOptimized}
                            >
                              {t('advancedPayments.payRemaining')}
                            </POSButton>
                          </div>

                          {/* Repository row */}
                          {compatibleRepos.length === 0 ? (
                            <p className={cn('text-sm py-2', textColors.error)}>
                              {t('advancedPayments.noRepositories', { defaultValue: 'No payment repositories configured.' })}
                            </p>
                          ) : (
                            <div>
                              <label className={cn('text-xs font-medium mb-1.5 block', textColors.tertiary)}>
                                {t('advancedPayments.selectRepository')}
                              </label>
                              {compatibleRepos.length === 1 ? (
                                <div className={cn(
                                  'flex items-center justify-between rounded-lg border px-4 py-3',
                                  colors.neutral[50],
                                  borderColors.light
                                )}>
                                  <span className={cn('text-sm font-medium', textColors.primary)}>
                                    {compatibleRepos[0].name}
                                    <span className={cn('ml-2', textColors.tertiary)}>
                                      ({t(`advancedPayments.repositoryType.${compatibleRepos[0].type}`)})
                                    </span>
                                  </span>
                                </div>
                              ) : (
                                <Select
                                  value={entryRepositoryId}
                                  onChange={(e) => { setEntryRepositoryId(e.target.value); }}
                                  className="min-h-[48px]"
                                >
                                  <option value="">
                                    {t('advancedPayments.selectRepositoryPlaceholder')}
                                  </option>
                                  {compatibleRepos.map((repo) => (
                                    <option key={repo.id} value={repo.id}>
                                      {repo.name} ({t(`advancedPayments.repositoryType.${repo.type}`)})
                                    </option>
                                  ))}
                                </Select>
                              )}
                            </div>
                          )}

                          {/* Reference + Card Last 4 row — only for non-cash methods */}
                          {(selectedMethod.has_maturity || selectedMethod.requires_third_party) && (
                            <div className="flex items-start gap-3">
                              <div className="flex-1">
                                <label className={cn('text-xs font-medium mb-1.5 block', textColors.tertiary)}>
                                  {t('advancedPayments.reference')}
                                </label>
                                <Input
                                  type="text"
                                  value={entryReference}
                                  onChange={(e) => { setEntryReference(e.target.value); }}
                                  placeholder={t('advancedPayments.referencePlaceholder')}
                                  className={cn( 'min-h-[48px]')}
                                />
                              </div>

                              {selectedMethod.requires_third_party && (
                                <div className="w-32">
                                  <label className={cn('text-xs font-medium mb-1.5 block', textColors.tertiary)}>
                                    {t('advancedPayments.cardLastFour')}
                                  </label>
                                  <Input
                                    type="text"
                                    maxLength={4}
                                    value={entryCardLastFour}
                                    onChange={(e) => { setEntryCardLastFour(e.target.value.replace(/\D/g, '')); }}
                                    placeholder="0000"
                                    className={cn( 'min-h-[48px]')}
                                  />
                                </div>
                              )}
                            </div>
                          )}

                          {/* Add Payment button */}
                          <div className="flex justify-end pt-1">
                            <POSButton
                              variant="primary"
                              size="lg"
                              onClick={handleAddPayment}
                              disabled={!canAddPayment}
                              touchOptimized={touchOptimized}
                            >
                              {t('advancedPayments.addPaymentButton')}
                            </POSButton>
                          </div>
                        </div>
                      )}
                    </>
                  )}

                  {/* Added Payments List */}
                  {addedPayments.length > 0 && (
                    <div className={cn(
                      'rounded-lg border overflow-hidden',
                      colors.white,
                      borderColors.light
                    )}>
                      <div className={cn('px-4 py-3 border-b', borderColors.light, colors.neutral[50])}>
                        <h3 className={cn('text-sm font-medium', textColors.secondary)}>
                          {t('advancedPayments.addedPayments')}
                        </h3>
                      </div>
                      <div className={`divide-y ${colorTokens.border.dividerSubtle}`}>
                        {addedPayments.map((payment) => {
                          const Icon = (() => {
                            const method = paymentMethods.find(m => m.id === payment.methodId)
                            return method ? getMethodIcon(method) : Wallet
                          })()
                          return (
                            <div key={payment.id} className="flex items-center px-4 py-3 gap-3">
                              <Icon className={cn('h-5 w-5 shrink-0', textColors.tertiary)} />
                              <div className="flex-1 min-w-0">
                                <span className={cn('text-sm font-medium', textColors.primary)}>
                                  {payment.methodName}
                                </span>
                                {payment.repositoryName && (
                                  <span className={cn('text-xs ml-2', textColors.tertiary)}>
                                    {'\u2192'} {payment.repositoryName}
                                  </span>
                                )}
                              </div>
                              <span className={cn('text-sm font-semibold tabular-nums', textColors.primary)}>
                                {toFixedCurrency(payment.amount)} {currency}
                              </span>
                              <button
                                onClick={() => { handleRemovePayment(payment.id); }}
                                className={cn(
                                  'p-2 rounded-md transition-colors shrink-0',
                                  textColors.disabled,
                                  textColors.hoverError, colors.hover.red50
                                )}
                                aria-label={t('advancedPayments.removeLine')}
                              >
                                <Trash2 className="h-4 w-4" />
                              </button>
                            </div>
                          )
                        })}
                      </div>
                    </div>
                  )}

                  {addedPayments.length === 0 && !selectedMethodId && activePaymentMethods.length > 0 && (
                    <p className={cn('text-sm text-center py-4', textColors.tertiary)}>
                      {t('advancedPayments.noPaymentsAdded')}
                    </p>
                  )}

                  {/* Discount / Loyalty Section */}
                  {loyaltyEnrollmentId && (
                    <div className={tokens.card.base}>
                      <h3 className={cn('text-lg font-medium mb-2', textColors.primary)}>
                        {t('advancedPayments.discount')}
                      </h3>
                      <LoyaltyRewardSelector
                        enrollmentId={loyaltyEnrollmentId}
                        onRewardRedeemed={(rewardValue, rewardName, _rewardId) => {
                          void rewardName
                          void rewardValue
                        }}
                      />
                    </div>
                  )}

                  {/* Overpayment Handler */}
                  {hasOverpayment && (
                    <div className={cn(tokens.alert.warning, 'border rounded-lg p-4', borderColors.default)}>
                      <h4 className={cn('font-medium mb-1', textColors.warning)}>
                        {t('advancedPayments.overpaymentDetected')}
                      </h4>
                      <p className={cn('text-sm', textColors.warning)}>
                        {t('advancedPayments.excess')}: {toFixedCurrency(overpaymentAmount)} {currency}
                      </p>

                      {isFullySafeOverpayment ? (
                        /* Scenario B: all overpayment from immediate methods — user can choose */
                        <div className="mt-4 space-y-2">
                          <label className={cn('flex items-center gap-2 text-sm cursor-pointer', textColors.warning)}>
                            <input
                              type="radio"
                              name="overpayment"
                              value="change"
                              checked={preferChangeOverCredit}
                              onChange={() => { setPreferChangeOverCredit(true) }}
                              className={`h-4 w-4 ${colorTokens.border.default} ${colorTokens.intent.warning.textStronger} ${colorTokens.focus.primaryRing}`}
                            />
                            <span>{t('advancedPayments.giveChange')}</span>
                          </label>
                          <label className={cn('flex items-center gap-2 text-sm cursor-pointer', textColors.warning)}>
                            <input
                              type="radio"
                              name="overpayment"
                              value="credit"
                              checked={!preferChangeOverCredit}
                              onChange={() => { setPreferChangeOverCredit(false) }}
                              className={`h-4 w-4 ${colorTokens.border.default} ${colorTokens.intent.warning.textStronger} ${colorTokens.focus.primaryRing}`}
                            />
                            <span>{t('advancedPayments.addToCredit')}</span>
                          </label>
                        </div>
                      ) : hasPartialSafeChange ? (
                        /* Scenario C1: some change possible, rest must go to credit */
                        <div className="mt-4 space-y-2" data-testid="partial-safe-change">
                          <div className={cn('flex items-start gap-2 text-sm', textColors.warning)}>
                            <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" />
                            <span>{t('advancedPayments.changeWarning', { amount: safeChangeLimit, currency })}</span>
                          </div>
                          <p className={cn('text-xs', textColors.warning)}>
                            {t('advancedPayments.changeExplanation')}
                          </p>
                          <div className={cn('mt-2 rounded-md border p-3 space-y-1', borderColors.light, 'bg-white/50')}>
                            <div className="flex justify-between text-sm">
                              <span className={textColors.warning}>{t('advancedPayments.changePortion')}</span>
                              <span className={cn('font-medium', textColors.warning)}>
                                {toFixedCurrency(computedOverpaymentHandling?.changeAmount ?? 0)} {currency}
                              </span>
                            </div>
                            <div className="flex justify-between text-sm">
                              <span className={textColors.warning}>{t('advancedPayments.creditPortion')}</span>
                              <span className={cn('font-medium', textColors.warning)}>
                                {toFixedCurrency(computedOverpaymentHandling?.creditAmount ?? 0)} {currency}
                              </span>
                            </div>
                          </div>
                        </div>
                      ) : (
                        /* Scenario C2: no immediate payment — all to credit */
                        <div className="mt-4 space-y-2" data-testid="full-credit-required">
                          <div className={cn('flex items-start gap-2 text-sm', textColors.warning)}>
                            <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" />
                            <span>{t('advancedPayments.fullCreditRequired')}</span>
                          </div>
                          <p className={cn('text-xs', textColors.warning)}>
                            {t('advancedPayments.noImmediatePayment')}
                          </p>
                          <div className={cn('mt-2 rounded-md border p-3', borderColors.light, 'bg-white/50')}>
                            <div className="flex justify-between text-sm">
                              <span className={textColors.warning}>{t('advancedPayments.creditPortion')}</span>
                              <span className={cn('font-medium', textColors.warning)}>
                                {toFixedCurrency(overpaymentAmount)} {currency}
                              </span>
                            </div>
                          </div>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>

              {/* Right: Order Summary */}
              <div className={cn(
                'w-80 shrink-0 flex flex-col border-l',
                colors.white,
                borderColors.light
              )}>
                <div className={cn('px-4 py-3 border-b', borderColors.light)}>
                  <h3 className={cn('text-sm font-semibold', textColors.primary)}>
                    {t('advancedPayments.cartSummary')}
                  </h3>
                </div>
                <div className="flex-1 overflow-y-auto px-4 py-3 space-y-4">
                  {/* Cart Items */}
                  <div className="space-y-2">
                    {cartItems.map((item) => (
                      <div key={item.id} className="flex justify-between text-sm gap-2">
                        <div className="flex-1 min-w-0">
                          <span className={cn('font-medium', textColors.primary)}>{item.product.name}</span>
                          <span className={cn('ml-1.5', textColors.tertiary)}>{'\u00D7'}{item.quantity}</span>
                        </div>
                        <span className={cn('font-medium tabular-nums shrink-0', textColors.primary)}>
                          {item.line_total}
                        </span>
                      </div>
                    ))}
                  </div>

                  {/* Transaction Discount */}
                  {permissions?.canApplyTransactionDiscounts && onTransactionDiscountChange && (
                    <div className={cn('border-t pt-3', borderColors.light)}>
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-1.5">
                          <Tag className={cn('w-3.5 h-3.5', textColors.tertiary)} />
                          <span className={cn('text-xs font-medium', textColors.secondary)}>
                            {t('cart.transactionDiscount')}
                          </span>
                        </div>
                        {transactionDiscount && parseFloat(transactionDiscount.amount) > 0 ? (
                          <div className="flex items-center gap-2">
                            <span className={cn('text-xs font-semibold tabular-nums', textColors.error)}>
                              -{toFixedCurrency(parseFloat(transactionDiscount.amount))} {currency}
                            </span>
                            <button
                              type="button"
                              onClick={() => { setShowDiscountModal(true) }}
                              className={cn('text-xs px-2 py-0.5 rounded border', borderColors.default, colors.hover.gray50)}
                            >
                              {t('common:edit', { defaultValue: 'Edit' })}
                            </button>
                          </div>
                        ) : (
                          <button
                            type="button"
                            onClick={() => { setShowDiscountModal(true) }}
                            className={cn(
                              'flex items-center gap-1 text-xs px-2 py-1 rounded-md border transition-colors',
                              borderColors.default,
                              colors.hover.gray50,
                              textColors.secondary,
                            )}
                          >
                            <Tag className="w-3 h-3" />
                            {t('cart.addDiscount')}
                          </button>
                        )}
                      </div>
                    </div>
                  )}

                  {/* Coupon Code */}
                  {onCouponApplied && onCouponRemoved && (
                    <div className={cn('border-t pt-3', borderColors.light)}>
                      <CouponCodeInput
                        subtotal={subtotal}
                        {...(selectedCustomerId ? { customerId: selectedCustomerId } : {})}
                        couponCode={couponCode ?? null}
                        onCouponApplied={onCouponApplied}
                        onCouponRemoved={onCouponRemoved}
                      />
                    </div>
                  )}
                </div>
                <div className={cn('px-4 py-3 border-t space-y-1.5', borderColors.light, colors.neutral[50])}>
                  <div className="flex justify-between text-xs">
                    <span className={textColors.tertiary}>{t('advancedPayments.subtotal')}</span>
                    <span className={cn('font-medium', textColors.primary)}>{subtotal} {currency}</span>
                  </div>
                  <div className="flex justify-between text-xs">
                    <span className={textColors.tertiary}>{t('advancedPayments.tax')}</span>
                    <span className={cn('font-medium', textColors.primary)}>{tax} {currency}</span>
                  </div>
                  {parseFloat(discount) > 0 && (
                    <div className="flex justify-between text-xs">
                      <span className={textColors.tertiary}>{t('advancedPayments.discount')}</span>
                      <span className={cn('font-medium tabular-nums', textColors.error)}>
                        -{discount} {currency}
                      </span>
                    </div>
                  )}
                  <div className={cn('flex justify-between text-base font-bold pt-2 mt-1 border-t', borderColors.light)}>
                    <span className={textColors.primary}>{t('advancedPayments.total')}</span>
                    <span className={textColors.primary}>{total} {currency}</span>
                  </div>
                </div>
              </div>

              </div>

              {/* Fixed Bottom: Balance Bar + Complete Button */}
              <div className={cn(
                'border-t shadow-[0_-2px_8px_rgba(0,0,0,0.06)]',
                colors.white,
                borderColors.light
              )}>
                {/* Balance Bar */}
                <div className={cn('grid grid-cols-3 gap-4 px-6 py-3', borderColors.light, 'border-b')}>
                  <div className="text-center">
                    <p className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>
                      {t('advancedPayments.totalDue')}
                    </p>
                    <p className={cn('text-xl font-bold mt-0.5', textColors.primary)}>
                      {total} {currency}
                    </p>
                  </div>
                  <div className="text-center">
                    <p className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>
                      {t('advancedPayments.totalEntered')}
                    </p>
                    <p className={cn('text-xl font-bold mt-0.5', textColors.primary)}>
                      {toFixedCurrency(totalPaid)} {currency}
                    </p>
                  </div>
                  <div className="text-center">
                    <p className={cn('text-xs font-medium uppercase tracking-wide', textColors.tertiary)}>
                      {t('advancedPayments.remaining')}
                    </p>
                    <p className={cn(
                      'text-xl font-bold mt-0.5',
                      bccomp(remainingAbs, '0.001') < 0 ? textColors.success : textColors.error
                    )}>
                      {remaining} {currency}
                    </p>
                  </div>
                </div>

                {/* Complete Button */}
                <div className="px-6 py-4">
                  <POSButton
                    variant="success"
                    size="lg"
                    fullWidth
                    onClick={handleComplete}
                    disabled={!isValid || isProcessing}
                    className="h-16 text-lg"
                    touchOptimized={touchOptimized}
                  >
                    {isProcessing ? t('advancedPayments.processing') : t('advancedPayments.completeTransaction')}
                  </POSButton>
                </div>
              </div>
            </>
          )}
        </div>
      </div>

      {/* Transaction Discount Modal */}
      {showDiscountModal && permissions && onTransactionDiscountChange && (
        <Modal
          isOpen={showDiscountModal}
          onClose={() => { setShowDiscountModal(false) }}
          title={t('cart.transactionDiscount')}
          size="md"
        >
          <div className="p-4">
            <TransactionDiscountInput
              currentAmount={transactionDiscount?.amount ?? ''}
              currentReason={transactionDiscount?.reason ?? ''}
              subtotal={subtotal}
              effectiveLimit={permissions.effectiveLimit}
              requiresReason={permissions.requiresReason}
              onApply={(amount, reason) => {
                onTransactionDiscountChange({ amount, ...(reason ? { reason } : {}) })
                setShowDiscountModal(false)
              }}
              onClear={() => {
                onTransactionDiscountChange(undefined)
                setShowDiscountModal(false)
              }}
              touchOptimized={touchOptimized}
            />
          </div>
        </Modal>
      )}
    </div>
  )
}
