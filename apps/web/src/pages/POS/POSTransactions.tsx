import { useState, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
// Import directly from the NEW feature-rich POS (191 tests)
import { POSPage } from '@/features/pos/pages/POSPage/POSPage'
import { AdvancedPaymentsModal, type PaymentData } from '@/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal'
import { ProductInfoModal } from '@/features/pos/organisms/ProductInfoModal'
import { OpenShiftModal } from '@/features/pos/components/OpenShiftModal'
import { CheckoutSuccessDialog } from '@/features/pos/components/CheckoutSuccessDialog'
import { CashTenderedModal } from '@/features/pos/components/CashTenderedModal'
import type { Product } from '@/features/pos/molecules/ProductCard/ProductCard'
import type { CartItem } from '@/features/pos/molecules/CartLineItem/CartLineItem'
import { usePOSProducts } from '@/features/pos/hooks/usePOSProducts'
import { useFnBProducts } from '@/features/pos/hooks/useFnBProducts'
import { getCurrentShift } from '@/features/pos/api/shiftApi'
import { createReceipt, processReceiptPayments } from '@/features/pos/api/receiptApi'
import { fetchPaymentMethods } from '@/features/pos/api/paymentMethodApi'
import { fetchPaymentRepositories } from '@/features/pos/api/paymentRepositoryApi'
import { getOrCreateWebTerminal } from '@/features/pos/api/terminalApi'
import { lookupMember, earnPoints, type LoyaltyMember, type LoyaltyEnrollment } from '@/features/pos/api/loyaltyApi'
import { PartnerSearchSelect } from '@/components/ui/PartnerSearchSelect'
import { QuickAddCustomerModal } from '@/features/pos/components/QuickAddCustomerModal'
import { isApiError, getErrorMessage } from '@/lib/api'
import { useLocation } from '@/hooks/useLocation'
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import type { ConsumptionMode } from '@/features/pos/atoms/ConsumptionModeToggle/ConsumptionModeToggle'
import { Loader2, MapPin, X } from 'lucide-react'
import { toast } from 'sonner'

/**
 * Extract a human-readable error message from API errors.
 * Handles both custom error format ({error: {message}}) and
 * Laravel validation format ({message, errors: {field: [msg]}}).
 */
function extractApiErrorMessage(err: unknown, fallback: string): string {
  if (isApiError(err)) {
    return getErrorMessage(err)
  }
  if (axios.isAxiosError(err) && err.response?.data) {
    const data = err.response.data as { message?: string; errors?: Record<string, string[]> }
    if (data.errors) {
      return Object.values(data.errors).flat().join('. ')
    }
    if (data.message) {
      return data.message
    }
  }
  if (err instanceof Error) {
    return err.message
  }
  return fallback
}

export function POSTransactions() {
  const { t } = useTranslation(['pos', 'common'])
  const queryClient = useQueryClient()
  const { currentLocationId, currentLocation: _currentLocation, isLoading: isLocationLoading } = useLocation()
  const { hasModule } = useCompanyConfig()
  const isFnBVertical = hasModule('Menu')

  const [selectedCustomer, setSelectedCustomer] = useState<{
    id: string
    name: string
    phone?: string
  } | null>(null)
  const [showCustomerSearch, setShowCustomerSearch] = useState(false)
  const [showQuickAddCustomer, setShowQuickAddCustomer] = useState(false)
  const [customerSearchId, setCustomerSearchId] = useState('')
  const [isAdvancedPaymentsOpen, setIsAdvancedPaymentsOpen] = useState(false)
  const [currentCartItems, setCurrentCartItems] = useState<CartItem[]>([])
  const [showOpenShift, setShowOpenShift] = useState(false)
  const [productInfoId, setProductInfoId] = useState<string | null>(null)
  const [_isQuickCheckoutPending, setIsQuickCheckoutPending] = useState(false)
  const [quickCheckoutResult, setQuickCheckoutResult] = useState<{
    receiptId: string
    receiptNumber: string
    total: string
    changeDue?: number
  } | null>(null)
  const [cashTenderedState, setCashTenderedState] = useState<{
    receiptId: string
    receiptNumber: string
    total: string
    cashMethodId: string
    cashRegisterId: string
    items: CartItem[]
  } | null>(null)
  const [isCashPaymentProcessing, setIsCashPaymentProcessing] = useState(false)
  const [cartVersion, setCartVersion] = useState(0)
  const [transactionDiscount, setTransactionDiscount] = useState<{
    amount: string
    reason?: string
  } | undefined>(undefined)
  const [loyaltyMember, setLoyaltyMember] = useState<LoyaltyMember | null>(null)
  const [loyaltyEnrollment, setLoyaltyEnrollment] = useState<LoyaltyEnrollment | null>(null)
  const [consumptionMode, setConsumptionMode] = useState<ConsumptionMode>('SUR_PLACE')
  const [couponCode, setCouponCode] = useState<string | null>(null)
  const [loyaltyRewardValue, setLoyaltyRewardValue] = useState<string | null>(null)
  const [loyaltyRewardId, setLoyaltyRewardId] = useState<string | null>(null)
  const [selectedTableId, setSelectedTableId] = useState<string | null>(null)

  // Auto-resolve web terminal for the active location
  const webTerminalMutation = useMutation({
    mutationFn: (locationId: string) => getOrCreateWebTerminal(locationId),
  })

  const webTerminal = webTerminalMutation.data

  // Trigger web terminal resolution when location changes
  useEffect(() => {
    if (currentLocationId && !isLocationLoading) {
      webTerminalMutation.mutate(currentLocationId)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currentLocationId, isLocationLoading])

  const terminalCode = webTerminal?.code ?? null

  // Fetch current shift for this terminal
  const {
    data: shift,
    isLoading: isLoadingShift,
    error: shiftError,
  } = useQuery({
    queryKey: ['pos', 'shift', terminalCode],
    queryFn: () => getCurrentShift(terminalCode!),
    refetchInterval: 30000,
    enabled: !!terminalCode,
  })

  // Fetch products: retail uses /products, F&B uses /active-menu
  const retailProducts = usePOSProducts({ limit: 500, enabled: !isFnBVertical })
  const fnbProducts = useFnBProducts({ enabled: isFnBVertical })

  const products: Product[] = isFnBVertical ? (fnbProducts.data ?? []) : (retailProducts.data ?? [])
  const fnbCategories = isFnBVertical ? fnbProducts.categories : undefined
  const isLoadingProducts = isFnBVertical ? fnbProducts.isLoading : retailProducts.isLoading
  const productsError = isFnBVertical ? fnbProducts.error : retailProducts.error

  // Fetch payment methods + repositories for quick checkout
  const { data: paymentMethods = [] } = useQuery({
    queryKey: ['pos', 'payment-methods'],
    queryFn: fetchPaymentMethods,
    staleTime: 10 * 60 * 1000,
    enabled: !!terminalCode,
  })

  const { data: paymentRepositories = [] } = useQuery({
    queryKey: ['pos', 'payment-repositories'],
    queryFn: fetchPaymentRepositories,
    staleTime: 10 * 60 * 1000,
    enabled: !!terminalCode,
  })

  // Show OpenShiftModal if no shift exists
  useEffect(() => {
    if (terminalCode && !isLoadingShift && !shift) {
      setShowOpenShift(true)
    } else if (shift) {
      setShowOpenShift(false)
    }
  }, [isLoadingShift, shift, terminalCode])

  /**
   * Select a customer and trigger loyalty lookup.
   */
  const selectCustomer = useCallback((customer: { id: string; name: string; phone?: string }) => {
    setSelectedCustomer(customer)
    setCustomerSearchId(customer.id)
    // Look up loyalty enrollment by phone
    if (customer.phone) {
      void lookupMember(customer.phone).then((result) => {
        if (result) {
          setLoyaltyMember(result.member)
          setLoyaltyEnrollment(result.enrollments[0] ?? null)
        } else {
          setLoyaltyMember(null)
          setLoyaltyEnrollment(null)
        }
      })
    } else {
      setLoyaltyMember(null)
      setLoyaltyEnrollment(null)
    }
  }, [])

  const handleAdvancedPayments = useCallback((items: CartItem[]) => {
    setCurrentCartItems(items)
    setIsAdvancedPaymentsOpen(true)
  }, [])

  const handleProductInfo = useCallback((product: Product) => {
    setProductInfoId(product.id)
  }, [])

  // If no location is selected, show a message
  if (!isLocationLoading && !currentLocationId) {
    return (
      <div className="flex items-center justify-center h-screen bg-gray-50">
        <div className="text-center max-w-md">
          <MapPin className="h-16 w-16 text-gray-300 mx-auto mb-4" />
          <h2 className="text-xl font-bold text-gray-900 mb-2">
            {t('pos:transactions.noLocation')}
          </h2>
          <p className="text-gray-500">
            {t('pos:transactions.noLocationDescription')}
          </p>
        </div>
      </div>
    )
  }

  // Web terminal is being resolved
  if (isLocationLoading || webTerminalMutation.isPending || !webTerminal) {
    // Show error if terminal creation failed
    if (webTerminalMutation.isError) {
      return (
        <div className="flex items-center justify-center h-screen bg-gray-50">
          <div className="text-center max-w-md">
            <p className="text-red-600 mb-4">{t('pos:transactions.errors.terminalCreation')}</p>
            <p className="text-gray-600 text-sm">
              {webTerminalMutation.error instanceof Error
                ? webTerminalMutation.error.message
                : t('pos:transactions.errors.unknown')}
            </p>
            <button
              type="button"
              className="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
              onClick={() => {
                if (currentLocationId) {
                  webTerminalMutation.mutate(currentLocationId)
                }
              }}
            >
              {t('common:retry')}
            </button>
          </div>
        </div>
      )
    }

    return (
      <div className="flex items-center justify-center h-screen bg-gray-50">
        <div className="text-center">
          <Loader2 className="h-12 w-12 animate-spin text-blue-600 mx-auto mb-4" />
          <p className="text-gray-600">{t('pos:transactions.loading.terminal')}</p>
        </div>
      </div>
    )
  }

  const isLoading = isLoadingShift || isLoadingProducts
  const error = shiftError || productsError

  /**
   * Convert cart items to receipt creation payload (polymorphic: product or composite item)
   */
  const buildReceiptPayload = (items: CartItem[]) => {
    if (!shift?.terminal_id) {
      throw new Error(t('pos:transactions.errors.noShiftOrTerminal'))
    }

    return {
      terminal_id: shift.terminal_id,
      ...(selectedCustomer?.id ? { customer_id: selectedCustomer.id } : {}),
      lines: items.map((item) => {
        const isComposite = item.product.sellableType === 'composite_item'
        const modifiers = item.product.selectedModifiers?.map((m) => ({
          modifier_id: m.modifier_id,
          modifier_group_id: m.modifier_group_id,
          price_adjustment: m.price_adjustment,
        }))
        return {
          ...(isComposite
            ? { composite_item_id: item.product.id }
            : { product_id: item.product.id }),
          quantity: item.quantity,
          unit_price: item.unit_price,
          ...(modifiers && modifiers.length > 0 ? { modifiers } : {}),
          ...(item.discount_type ? { discount_type: item.discount_type } : {}),
          ...(item.discount_percent ? { discount_percent: item.discount_percent } : {}),
          ...(item.discount_amount ? { discount_amount: item.discount_amount } : {}),
          ...(item.discount_reason ? { discount_reason: item.discount_reason } : {}),
        }
      }),
      ...(transactionDiscount?.amount ? { transaction_discount_amount: transactionDiscount.amount } : {}),
      ...(transactionDiscount?.reason ? { transaction_discount_reason: transactionDiscount.reason } : {}),
      ...(couponCode ? { coupon_code: couponCode } : {}),
      ...(loyaltyRewardValue ? { loyalty_discount_amount: loyaltyRewardValue } : {}),
      ...(loyaltyRewardId ? { loyalty_reward_id: loyaltyRewardId } : {}),
      ...(isFnBVertical ? { consumption_mode: consumptionMode } : {}),
      ...(selectedTableId ? { table_id: selectedTableId } : {}),
    }
  }

  /**
   * Create receipt and process payment in sequence.
   * Returns the receipt info for the success screen.
   */
  const createReceiptAndPay = async (
    items: CartItem[],
    paymentData: PaymentData,
  ): Promise<{ receiptId: string; receiptNumber: string; total: string }> => {
    // 1. Create receipt (stock decrement + fiscal hash)
    const payload = buildReceiptPayload(items)
    const receipt = await createReceipt(payload)

    // 2. Process payment(s) against the receipt
    await processReceiptPayments(receipt.id, {
      payments: paymentData.methods
        .filter((m) => m.amount > 0)
        .map((m) => ({
          payment_method_id: m.methodId,
          amount: Number(m.amount.toFixed(3)),
          repository_id: m.repositoryId ?? '',
          card_last_four: m.cardLastFour,
          transaction_reference: m.transactionReference,
        })),
      customer_id: selectedCustomer?.id,
    })

    return {
      receiptId: receipt.id,
      receiptNumber: receipt.receipt_number,
      total: receipt.total,
    }
  }

  /**
   * Quick Checkout - creates receipt then opens cash tendered modal
   */
  const handleQuickCheckout = async (items: CartItem[]) => {
    // Find default cash payment method
    const cashMethod = paymentMethods.find(
      (m) => m.code === 'CASH' || m.name.toLowerCase().includes('cash') || m.name.toLowerCase().includes('espèces')
    )
    if (!cashMethod) {
      toast.error(t('pos:transactions.errors.noCashMethod'))
      setCurrentCartItems(items)
      setIsAdvancedPaymentsOpen(true)
      return
    }

    // Find default cash register repository
    const cashRegister = paymentRepositories.find(
      (r) => r.type === 'cash_register' && r.is_active
    )
    if (!cashRegister) {
      toast.error(t('pos:transactions.errors.noCashRegister'))
      setCurrentCartItems(items)
      setIsAdvancedPaymentsOpen(true)
      return
    }

    setIsQuickCheckoutPending(true)
    try {
      // Step 1: Create receipt to get the authoritative backend total
      const payload = buildReceiptPayload(items)
      const receipt = await createReceipt(payload)

      // Step 2: Open cash tendered modal with backend-computed total
      setCashTenderedState({
        receiptId: receipt.id,
        receiptNumber: receipt.receipt_number,
        total: receipt.total,
        cashMethodId: cashMethod.id,
        cashRegisterId: cashRegister.id,
        items,
      })
    } catch (err) {
      toast.error(extractApiErrorMessage(err, t('pos:transactions.errors.quickCheckoutFailed')))
    } finally {
      setIsQuickCheckoutPending(false)
    }
  }

  /**
   * Handle cash tendered confirmation — process payment with the tendered amount
   */
  const handleCashTenderedConfirm = async (tenderedAmount: number) => {
    if (!cashTenderedState) return

    const { receiptId, receiptNumber, total, cashMethodId, cashRegisterId, items } = cashTenderedState

    setIsCashPaymentProcessing(true)
    try {
      const paymentResult = await processReceiptPayments(receiptId, {
        payments: [{
          payment_method_id: cashMethodId,
          amount: tenderedAmount,
          repository_id: cashRegisterId,
        }],
        customer_id: selectedCustomer?.id,
      })

      toast.success(t('pos:transactions.toasts.transactionCompleted', { number: receiptNumber }))

      // Invalidate shift data
      void queryClient.invalidateQueries({ queryKey: ['pos', 'shift'] })

      // Earn loyalty points if member is enrolled
      if (loyaltyEnrollment) {
        void earnPoints(
          loyaltyEnrollment.id,
          receiptId,
          total,
          items.map((item) => ({
            product_id: item.product.id,
            quantity: item.quantity,
            price: parseFloat(item.unit_price),
          })),
        ).catch(() => {
          // Loyalty earning failure should not disrupt checkout flow
        })
      }

      const changeDue = parseFloat(paymentResult.change_due)

      // Close cash tendered modal and show success
      setCashTenderedState(null)
      setQuickCheckoutResult({
        receiptId,
        receiptNumber,
        total,
        ...(changeDue > 0 ? { changeDue } : {}),
      })
      setCurrentCartItems([])
      setTransactionDiscount(undefined)
      setCouponCode(null)
      setLoyaltyRewardValue(null)
      setLoyaltyRewardId(null)
      setSelectedTableId(null)
      setCartVersion((v) => v + 1)
    } catch (err) {
      toast.error(extractApiErrorMessage(err, t('pos:transactions.errors.quickCheckoutFailed')))
    } finally {
      setIsCashPaymentProcessing(false)
    }
  }

  const handleCompletePayment = async (
    paymentData: PaymentData,
  ): Promise<{ receiptId: string; receiptNumber: string }> => {
    const result = await createReceiptAndPay(currentCartItems, paymentData)

    toast.success(t('pos:transactions.toasts.transactionCompleted', { number: result.receiptNumber }))

    // Invalidate shift data
    void queryClient.invalidateQueries({ queryKey: ['pos', 'shift'] })

    // Earn loyalty points if member is enrolled
    if (loyaltyEnrollment) {
      void earnPoints(
        loyaltyEnrollment.id,
        result.receiptId,
        result.total,
        currentCartItems.map((item) => ({
          product_id: item.product.id,
          quantity: item.quantity,
          price: parseFloat(item.unit_price),
        })),
      ).catch(() => {
        // Loyalty earning failure should not disrupt checkout flow
      })
    }

    // DON'T close modal — let the success screen show inside it.
    // The modal's "New Transaction" button calls onClose() after the user has seen the receipt.
    setCurrentCartItems([])
    setTransactionDiscount(undefined)
    setCouponCode(null)
    setLoyaltyRewardValue(null)
    setLoyaltyRewardId(null)
    setSelectedTableId(null)
    setCartVersion((v) => v + 1)

    return result
  }

  // Loading state
  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-screen bg-gray-50">
        <div className="text-center">
          <Loader2 className="h-12 w-12 animate-spin text-blue-600 mx-auto mb-4" />
          <p className="text-gray-600">
            {isLoadingShift ? t('pos:transactions.loading.shift') : t('pos:transactions.loading.products')}
          </p>
        </div>
      </div>
    )
  }

  // Error state
  if (error) {
    return (
      <div className="flex items-center justify-center h-screen bg-gray-50">
        <div className="text-center max-w-md">
          <p className="text-red-600 mb-4">
            {shiftError ? t('pos:transactions.errors.loadingShift') : t('pos:transactions.errors.loadingProducts')}
          </p>
          <p className="text-gray-600 text-sm">{error.message}</p>
        </div>
      </div>
    )
  }

  // No shift - show open shift modal
  if (!shift) {
    return (
      <OpenShiftModal
        isOpen={showOpenShift}
        onClose={() => {
          // No-op: modal has inline warning banner explaining shift must be opened
        }}
        terminalId={terminalCode ?? ''}
        onSuccess={() => {
          // Refetch shift data after opening
          queryClient.invalidateQueries({ queryKey: ['pos', 'shift', terminalCode] })
        }}
      />
    )
  }

  // Render POS only when shift is active
  return (
    <>
      <POSPage
        key={cartVersion}
        products={products}
        categories={fnbCategories}
        onQuickCheckout={handleQuickCheckout}
        onAdvancedPayments={handleAdvancedPayments}
        onProductInfo={handleProductInfo}
        selectedCustomer={selectedCustomer}
        onChangeCustomer={() => { setShowCustomerSearch(true) }}
        touchOptimized={false}
        terminalCode={terminalCode ?? undefined}
        transactionDiscount={transactionDiscount}
        onTransactionDiscountChange={(d) => { setTransactionDiscount(d as typeof transactionDiscount) }}
        loyaltyMember={loyaltyMember}
        loyaltyEnrollment={loyaltyEnrollment}
        consumptionMode={isFnBVertical ? consumptionMode : undefined}
        onConsumptionModeChange={isFnBVertical ? (mode: ConsumptionMode) => {
          setConsumptionMode(mode)
          if (mode !== 'SUR_PLACE') {
            setSelectedTableId(null)
          }
        } : undefined}
        selectedTableId={isFnBVertical ? selectedTableId : undefined}
        onSelectedTableIdChange={isFnBVertical ? setSelectedTableId : undefined}
        couponCode={couponCode}
        onCouponApplied={(code) => { setCouponCode(code) }}
        onCouponRemoved={() => { setCouponCode(null) }}
        onLoyaltyRewardRedeemed={(rewardValue, _rewardName, rewardId) => {
          setLoyaltyRewardValue(rewardValue)
          setLoyaltyRewardId(rewardId)
        }}
      />

      {/* Advanced Payments Modal */}
      <AdvancedPaymentsModal
        isOpen={isAdvancedPaymentsOpen}
        onClose={() => {
          setIsAdvancedPaymentsOpen(false)
        }}
        cartItems={currentCartItems}
        onComplete={handleCompletePayment}
        touchOptimized={false}
        loyaltyEnrollmentId={loyaltyEnrollment?.id}
        transactionDiscountAmount={transactionDiscount?.amount}
        terminalCode={terminalCode ?? undefined}
        transactionDiscount={transactionDiscount}
        onTransactionDiscountChange={setTransactionDiscount}
        couponCode={couponCode}
        onCouponApplied={(code) => { setCouponCode(code) }}
        onCouponRemoved={() => { setCouponCode(null) }}
        selectedCustomerId={selectedCustomer?.id}
      />

      {/* Cash Tendered Modal */}
      {cashTenderedState && (
        <CashTenderedModal
          isOpen={!!cashTenderedState}
          onClose={() => { setCashTenderedState(null); }}
          onConfirm={handleCashTenderedConfirm}
          total={cashTenderedState.total}
          isProcessing={isCashPaymentProcessing}
        />
      )}

      {/* Quick Checkout Success Dialog */}
      {quickCheckoutResult && (
        <CheckoutSuccessDialog
          isOpen={!!quickCheckoutResult}
          onClose={() => { setQuickCheckoutResult(null); }}
          receiptNumber={quickCheckoutResult.receiptNumber}
          total={quickCheckoutResult.total}
          changeDue={quickCheckoutResult.changeDue}
          receiptId={quickCheckoutResult.receiptId}
        />
      )}

      {/* Customer Search Modal */}
      {showCustomerSearch && !showQuickAddCustomer && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-semibold text-gray-900">
                {t('pos:cart.selectCustomer')}
              </h3>
              <button
                type="button"
                onClick={() => { setShowCustomerSearch(false) }}
                className="p-1 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <PartnerSearchSelect
              value={customerSearchId}
              onChange={(partnerId) => {
                setCustomerSearchId(partnerId)
              }}
              partnerType="customer"
              placeholder={t('pos:cart.selectCustomer')}
              onAddNew={() => { setShowQuickAddCustomer(true) }}
            />
            <div className="flex gap-3 mt-6">
              {selectedCustomer && (
                <button
                  type="button"
                  onClick={() => {
                    setSelectedCustomer(null)
                    setCustomerSearchId('')
                    setLoyaltyMember(null)
                    setLoyaltyEnrollment(null)
                    setShowCustomerSearch(false)
                  }}
                  className="flex-1 px-4 py-2 text-sm font-medium text-red-600 border border-red-200 rounded-lg hover:bg-red-50"
                >
                  {t('pos:cart.clearCustomer')}
                </button>
              )}
              <button
                type="button"
                onClick={() => {
                  if (customerSearchId) {
                    // Fetch partner details to get name
                    void import('@/lib/api').then(({ apiGet }) => {
                      void apiGet<{ id: string; name: string; phone?: string }>(`/partners/${customerSearchId}`).then(
                        (partner) => {
                          selectCustomer(partner)
                          setShowCustomerSearch(false)
                        }
                      )
                    })
                  } else {
                    setShowCustomerSearch(false)
                  }
                }}
                disabled={!customerSearchId}
                className="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {t('pos:cart.customerSelected')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Quick Add Customer Modal */}
      <QuickAddCustomerModal
        isOpen={showQuickAddCustomer}
        onClose={() => { setShowQuickAddCustomer(false) }}
        onCustomerCreated={(customer) => {
          selectCustomer(customer)
          setShowQuickAddCustomer(false)
          setShowCustomerSearch(false)
          toast.success(t('pos:cart.customerCreated', { name: customer.name }))
        }}
      />

      {/* Product Info Modal */}
      {productInfoId && (
        <ProductInfoModal
          isOpen={!!productInfoId}
          onClose={() => { setProductInfoId(null); }}
          productId={productInfoId}
          touchOptimized={false}
        />
      )}
    </>
  )
}
