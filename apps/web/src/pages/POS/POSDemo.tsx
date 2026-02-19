import { useState, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
// Import directly from the NEW feature-rich POS (191 tests)
import { POSPage } from '@/features/pos/pages/POSPage/POSPage'
import { AdvancedPaymentsModal, type PaymentData } from '@/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal'
import { ProductInfoModal } from '@/features/pos/organisms/ProductInfoModal'
import { OpenShiftModal } from '@/features/pos/components/OpenShiftModal'
import { CheckoutSuccessDialog } from '@/features/pos/components/CheckoutSuccessDialog'
import type { Product } from '@/features/pos/molecules/ProductCard/ProductCard'
import type { CartItem } from '@/features/pos/molecules/CartLineItem/CartLineItem'
import { usePOSProducts } from '@/features/pos/hooks/usePOSProducts'
import { getCurrentShift } from '@/features/pos/api/shiftApi'
import { createReceipt, processReceiptPayments } from '@/features/pos/api/receiptApi'
import { fetchPaymentMethods } from '@/features/pos/api/paymentMethodApi'
import { fetchPaymentRepositories } from '@/features/pos/api/paymentRepositoryApi'
import { getOrCreateWebTerminal } from '@/features/pos/api/terminalApi'
import { useLocation } from '@/hooks/useLocation'
import { Loader2, MapPin } from 'lucide-react'
import { toast } from 'sonner'

export function POSDemo() {
  const { t } = useTranslation(['pos', 'common'])
  const queryClient = useQueryClient()
  const { currentLocationId, currentLocation, isLoading: isLocationLoading } = useLocation()

  const [selectedCustomer] = useState(null)
  const [isAdvancedPaymentsOpen, setIsAdvancedPaymentsOpen] = useState(false)
  const [currentCartItems, setCurrentCartItems] = useState<CartItem[]>([])
  const [showOpenShift, setShowOpenShift] = useState(false)
  const [productInfoId, setProductInfoId] = useState<string | null>(null)
  const [isQuickCheckoutPending, setIsQuickCheckoutPending] = useState(false)
  const [quickCheckoutResult, setQuickCheckoutResult] = useState<{
    receiptId: string
    receiptNumber: string
  } | null>(null)
  const [cartVersion, setCartVersion] = useState(0)

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

  // Fetch products from API (automatically filtered by company vertical)
  const {
    data: products = [],
    isLoading: isLoadingProducts,
    error: productsError,
  } = usePOSProducts({ limit: 500 })

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
            {t('pos:demo.noLocation')}
          </h2>
          <p className="text-gray-500">
            {t('pos:demo.noLocationDescription')}
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
            <p className="text-red-600 mb-4">{t('pos:demo.errors.terminalCreation')}</p>
            <p className="text-gray-600 text-sm">
              {webTerminalMutation.error instanceof Error
                ? webTerminalMutation.error.message
                : t('pos:demo.errors.unknown')}
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
          <p className="text-gray-600">{t('pos:demo.loading.terminal')}</p>
        </div>
      </div>
    )
  }

  const isLoading = isLoadingShift || isLoadingProducts
  const error = shiftError || productsError

  /**
   * Convert cart items to receipt creation payload
   */
  const buildReceiptPayload = (items: CartItem[]) => {
    if (!shift?.terminal_id) {
      throw new Error(t('pos:demo.errors.noShiftOrTerminal'))
    }

    return {
      terminal_id: shift.terminal_id,
      lines: items.map((item) => ({
        product_id: item.product.id,
        quantity: item.quantity,
        unit_price: item.unit_price,
        discount_amount: item.discount_amount ?? undefined,
        discount_reason: item.discount_reason ?? undefined,
      })),
    }
  }

  /**
   * Create receipt and process payment in sequence.
   * Returns the receipt info for the success screen.
   */
  const createReceiptAndPay = async (
    items: CartItem[],
    paymentData: PaymentData,
  ): Promise<{ receiptId: string; receiptNumber: string }> => {
    // 1. Create receipt (stock decrement + fiscal hash)
    const payload = buildReceiptPayload(items)
    const receipt = await createReceipt(payload)

    // 2. Process payment(s) against the receipt
    await processReceiptPayments(receipt.id, {
      payments: paymentData.methods
        .filter((m) => m.amount > 0)
        .map((m) => ({
          payment_method_id: m.methodId,
          amount: m.amount,
          repository_id: m.repositoryId ?? '',
          card_last_four: m.cardLastFour,
          transaction_reference: m.transactionReference,
        })),
    })

    return {
      receiptId: receipt.id,
      receiptNumber: receipt.receipt_number,
    }
  }

  /**
   * Quick Checkout - one-tap cash payment (no modal)
   */
  const handleQuickCheckout = async (items: CartItem[]) => {
    // Find default cash payment method
    const cashMethod = paymentMethods.find(
      (m) => m.code === 'CASH' || m.name.toLowerCase().includes('cash') || m.name.toLowerCase().includes('espèces')
    )
    if (!cashMethod) {
      toast.error(t('pos:demo.errors.noCashMethod'))
      // Fall back to advanced payments
      setCurrentCartItems(items)
      setIsAdvancedPaymentsOpen(true)
      return
    }

    // Find default cash register repository
    const cashRegister = paymentRepositories.find(
      (r) => r.type === 'cash_register' && r.is_active
    )
    if (!cashRegister) {
      toast.error(t('pos:demo.errors.noCashRegister'))
      // Fall back to advanced payments
      setCurrentCartItems(items)
      setIsAdvancedPaymentsOpen(true)
      return
    }

    // Calculate total from items
    const total = items.reduce((sum, item) => sum + parseFloat(item.line_total), 0)

    setIsQuickCheckoutPending(true)
    try {
      const result = await createReceiptAndPay(items, {
        methods: [{
          methodId: cashMethod.id,
          amount: total,
          repositoryId: cashRegister.id,
        }],
      })

      toast.success(t('pos:demo.toasts.transactionCompleted', { number: result.receiptNumber }))

      // Invalidate shift data
      void queryClient.invalidateQueries({ queryKey: ['pos', 'shift'] })

      // Show success dialog and clear cart
      setQuickCheckoutResult(result)
      setCurrentCartItems([])
      setCartVersion((v) => v + 1)
    } catch (err) {
      const message = err instanceof Error ? err.message : t('pos:demo.errors.quickCheckoutFailed')
      toast.error(message)
    } finally {
      setIsQuickCheckoutPending(false)
    }
  }

  const handleCompletePayment = async (
    paymentData: PaymentData,
  ): Promise<{ receiptId: string; receiptNumber: string }> => {
    const result = await createReceiptAndPay(currentCartItems, paymentData)

    toast.success(t('pos:demo.toasts.transactionCompleted', { number: result.receiptNumber }))

    // Invalidate shift data
    void queryClient.invalidateQueries({ queryKey: ['pos', 'shift'] })

    // Close modal and clear cart
    setIsAdvancedPaymentsOpen(false)
    setCurrentCartItems([])
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
            {isLoadingShift ? t('pos:demo.loading.shift') : t('pos:demo.loading.products')}
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
            {shiftError ? t('pos:demo.errors.loadingShift') : t('pos:demo.errors.loadingProducts')}
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
          // Can't close modal without opening shift
          toast.warning(t('pos:demo.warnings.mustOpenShift'))
        }}
        terminalId={terminalCode}
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
        onQuickCheckout={handleQuickCheckout}
        onAdvancedPayments={handleAdvancedPayments}
        onProductInfo={handleProductInfo}
        selectedCustomer={selectedCustomer}
        touchOptimized={false}
        terminalCode={terminalCode}
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
      />

      {/* Quick Checkout Success Dialog */}
      {quickCheckoutResult && (
        <CheckoutSuccessDialog
          isOpen={!!quickCheckoutResult}
          onClose={() => setQuickCheckoutResult(null)}
          receiptNumber={quickCheckoutResult.receiptNumber}
          total=""
          receiptId={quickCheckoutResult.receiptId}
        />
      )}

      {/* Product Info Modal */}
      {productInfoId && (
        <ProductInfoModal
          isOpen={!!productInfoId}
          onClose={() => setProductInfoId(null)}
          productId={productInfoId}
          touchOptimized={false}
        />
      )}
    </>
  )
}
