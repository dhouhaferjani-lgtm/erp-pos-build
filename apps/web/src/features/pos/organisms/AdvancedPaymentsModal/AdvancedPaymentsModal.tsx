import { useState, useMemo } from 'react'
import { X, CreditCard, Banknote, FileText, Building2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { CartItem } from '../../molecules/CartLineItem'

// Mock payment methods (will be fetched from API in production)
const MOCK_PAYMENT_METHODS = [
  { id: 'cash', name: 'Cash', icon: Banknote },
  { id: 'card', name: 'Card', icon: CreditCard },
  { id: 'check', name: 'Check', icon: FileText },
  { id: 'bank', name: 'Bank Transfer', icon: Building2 },
]

export interface AdvancedPaymentsModalProps {
  isOpen: boolean
  onClose: () => void
  cartItems: CartItem[]
  onComplete: (paymentData: PaymentData) => void
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
 */
export function AdvancedPaymentsModal({
  isOpen,
  onClose,
  cartItems,
  onComplete,
  touchOptimized = false,
}: AdvancedPaymentsModalProps) {
  const [selectedMethods, setSelectedMethods] = useState<string[]>([])
  const [payments, setPayments] = useState<Record<string, string>>({})
  const [_discount, setDiscount] = useState<DiscountData | null>(null)
  const [_voucherCode, setVoucherCode] = useState('')
  const [overpaymentHandling, setOverpaymentHandling] = useState<'change' | 'credit'>('change')

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
  const isValid = Math.abs(remaining) < 0.001 && totalPaid > 0
  const hasOverpayment = totalPaid > parseFloat(total) + 0.001

  const handleComplete = () => {
    if (!isValid) return

    const paymentData: PaymentData = {
      methods: selectedMethods.map((methodId) => ({
        methodId,
        amount: parseFloat(payments[methodId] || '0'),
      })),
      discount: _discount || undefined,
      voucherCode: _voucherCode || undefined,
      overpaymentHandling: hasOverpayment ? overpaymentHandling : undefined,
    }

    onComplete(paymentData)
  }

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="w-full h-full bg-gray-50 flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between bg-white border-b border-gray-200 px-6 py-4">
          <h2 className={cn('font-bold text-gray-900', touchOptimized ? 'text-2xl' : 'text-xl')}>
            Advanced Payments
          </h2>
          <button
            onClick={onClose}
            className="p-2 rounded-lg hover:bg-gray-100 transition-colors"
            aria-label="Close"
          >
            <X className="h-6 w-6 text-gray-600" />
          </button>
        </div>

        {/* Content */}
        <div className="flex flex-1 overflow-hidden">
          {/* Left Panel: Payment Configuration (70%) */}
          <div className="flex-[7] overflow-y-auto p-6">
            <div className="max-w-4xl mx-auto space-y-6">
              {/* Payment Method Selector */}
              <div className="bg-white rounded-lg border border-gray-300 p-6">
                <h3 className="text-lg font-medium text-gray-900 mb-4">Payment Methods</h3>
                <div className="grid grid-cols-2 gap-3">
                  {MOCK_PAYMENT_METHODS.map((method) => {
                    const Icon = method.icon
                    const isSelected = selectedMethods.includes(method.id)
                    return (
                      <button
                        key={method.id}
                        onClick={() => {
                          if (isSelected) {
                            setSelectedMethods(selectedMethods.filter((id) => id !== method.id))
                            const { [method.id]: _, ...newPayments } = payments
                            setPayments(newPayments)
                          } else {
                            setSelectedMethods([...selectedMethods, method.id])
                          }
                        }}
                        className={cn(
                          'p-4 rounded-lg border-2 transition-all flex items-center gap-3',
                          isSelected
                            ? 'border-blue-500 bg-blue-50'
                            : 'border-gray-300 hover:border-gray-400'
                        )}
                      >
                        <Icon className={cn('h-6 w-6', isSelected ? 'text-blue-600' : 'text-gray-600')} />
                        <span className={cn('font-medium', isSelected ? 'text-blue-900' : 'text-gray-900')}>
                          {method.name}
                        </span>
                      </button>
                    )
                  })}
                </div>
              </div>

              {/* Split Payment Inputs */}
              {selectedMethods.length > 0 && (
                <div className="bg-white rounded-lg border border-gray-300 p-6">
                  <h3 className="text-lg font-medium text-gray-900 mb-4">Payment Distribution</h3>
                  <div className="space-y-4">
                    {selectedMethods.map((methodId) => {
                      const method = MOCK_PAYMENT_METHODS.find((m) => m.id === methodId)
                      if (!method) return null

                      return (
                        <div key={methodId} className="flex items-center gap-4">
                          <label className="w-32 font-medium text-gray-700">{method.name}:</label>
                          <input
                            type="number"
                            step="0.001"
                            min="0"
                            value={payments[methodId] || ''}
                            onChange={(e) => {
                              setPayments({ ...payments, [methodId]: e.target.value })
                            }}
                            placeholder="0.000"
                            className="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                          />
                          <button
                            onClick={() => {
                              setPayments({ ...payments, [methodId]: remaining.toFixed(3) })
                            }}
                            disabled={remaining <= 0}
                            className="px-4 py-2 bg-gray-100 hover:bg-gray-200 disabled:bg-gray-50 disabled:text-gray-400 rounded-lg text-sm font-medium transition-colors"
                          >
                            Use Remaining
                          </button>
                          <span className="w-24 text-right font-medium">TND</span>
                        </div>
                      )
                    })}
                  </div>
                </div>
              )}

              {/* Discount Section (Placeholder) */}
              <div className="bg-white rounded-lg border border-gray-300 p-6">
                <h3 className="text-lg font-medium text-gray-900 mb-4">Discount (Optional)</h3>
                <p className="text-sm text-gray-500">Discount functionality coming soon</p>
              </div>

              {/* Progressive Disclosure: Overpayment Handler */}
              {hasOverpayment && (
                <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                  <h4 className="font-medium text-yellow-900">Overpayment Detected</h4>
                  <p className="text-sm text-yellow-700 mt-1">
                    Excess: {(totalPaid - parseFloat(total)).toFixed(3)} TND
                  </p>
                  <div className="mt-4 space-y-2">
                    <label className="flex items-center gap-2">
                      <input
                        type="radio"
                        name="overpayment"
                        value="change"
                        checked={overpaymentHandling === 'change'}
                        onChange={(e) => { setOverpaymentHandling(e.target.value as 'change') }}
                      />
                      <span>Give change to customer</span>
                    </label>
                    <label className="flex items-center gap-2">
                      <input
                        type="radio"
                        name="overpayment"
                        value="credit"
                        checked={overpaymentHandling === 'credit'}
                        onChange={(e) => { setOverpaymentHandling(e.target.value as 'credit') }}
                      />
                      <span>Add to customer account credit</span>
                    </label>
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Right Panel: Mini Cart + Complete Button (30%) */}
          <div className="flex-[3] bg-white border-l border-gray-300 flex flex-col">
            {/* Mini Cart */}
            <div className="flex-1 overflow-y-auto p-6">
              <h3 className="text-lg font-medium mb-4">Cart Summary</h3>

              <div className="space-y-2">
                {cartItems.map((item) => (
                  <div key={item.id} className="flex justify-between text-sm">
                    <div className="flex-1">
                      <span className="font-medium">{item.product.name}</span>
                      <span className="text-gray-500 ml-2">×{item.quantity}</span>
                    </div>
                    <span className="font-medium">{item.line_total} TND</span>
                  </div>
                ))}
              </div>

              <div className="mt-6 pt-6 border-t border-gray-300 space-y-2">
                <div className="flex justify-between">
                  <span>Subtotal:</span>
                  <span>{subtotal} TND</span>
                </div>
                <div className="flex justify-between text-sm text-gray-600">
                  <span>Tax:</span>
                  <span>{tax} TND</span>
                </div>
                {_discount && (
                  <div className="flex justify-between text-sm text-green-600">
                    <span>Discount:</span>
                    <span>
                      -{_discount.type === 'percentage' ? `${_discount.value.toString()}%` : `${_discount.value.toString()} TND`}
                    </span>
                  </div>
                )}
                <div className="flex justify-between text-xl font-bold border-t pt-2">
                  <span>Total:</span>
                  <span>{total} TND</span>
                </div>
              </div>

              {/* Payment Summary */}
              <div className="mt-6 p-4 bg-gray-50 rounded-lg">
                <div className="flex justify-between mb-2">
                  <span className="font-medium">Total Paid:</span>
                  <span
                    className={cn(
                      'font-bold',
                      totalPaid < parseFloat(total) ? 'text-red-600' : 'text-green-600'
                    )}
                  >
                    {totalPaid.toFixed(3)} TND
                  </span>
                </div>
                <div className="flex justify-between text-lg">
                  <span className="font-medium">Remaining:</span>
                  <span className={cn('font-bold', remaining > 0.001 ? 'text-red-600' : 'text-gray-900')}>
                    {remaining.toFixed(3)} TND
                  </span>
                </div>
              </div>
            </div>

            {/* Complete Button */}
            <div className="p-6 border-t border-gray-300 bg-gray-50">
              <button
                onClick={handleComplete}
                disabled={!isValid}
                className={cn(
                  'w-full h-16 rounded-lg font-bold text-lg transition-colors',
                  isValid
                    ? 'bg-green-600 hover:bg-green-700 text-white'
                    : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                )}
              >
                Complete Transaction
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
