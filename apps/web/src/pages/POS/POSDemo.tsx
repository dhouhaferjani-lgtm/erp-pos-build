import { useState } from 'react'
import { POSPage } from '@/features/pos'
import { AdvancedPaymentsModal, type PaymentData } from '@/features/pos/organisms'
import type { Product, CartItem } from '@/features/pos'

// Mock product data for demo
const mockProducts: Product[] = [
  {
    id: '1',
    name: 'Oil Filter',
    sku: 'OF-1234',
    price: '15.500',
    stock_quantity: 50,
    category: 'Filters',
  },
  {
    id: '2',
    name: 'Air Filter',
    sku: 'AF-5678',
    price: '12.000',
    stock_quantity: 30,
    category: 'Filters',
  },
  {
    id: '3',
    name: 'Brake Pads',
    sku: 'BP-9012',
    price: '45.750',
    stock_quantity: 15,
    category: 'Brakes',
  },
  {
    id: '4',
    name: 'Spark Plugs (Set of 4)',
    sku: 'SP-3456',
    price: '28.000',
    stock_quantity: 25,
    category: 'Engine',
  },
  {
    id: '5',
    name: 'Windshield Wipers',
    sku: 'WW-7890',
    price: '18.500',
    stock_quantity: 40,
    category: 'Accessories',
  },
  {
    id: '6',
    name: 'Engine Oil 5W-30 (5L)',
    sku: 'EO-1122',
    price: '32.900',
    stock_quantity: 60,
    category: 'Fluids',
  },
  {
    id: '7',
    name: 'Transmission Fluid (1L)',
    sku: 'TF-3344',
    price: '14.200',
    stock_quantity: 35,
    category: 'Fluids',
  },
  {
    id: '8',
    name: 'Coolant (2L)',
    sku: 'CL-5566',
    price: '11.500',
    stock_quantity: 45,
    category: 'Fluids',
  },
  {
    id: '9',
    name: 'Battery 12V 60Ah',
    sku: 'BT-7788',
    price: '125.000',
    stock_quantity: 8,
    category: 'Electrical',
  },
  {
    id: '10',
    name: 'Headlight Bulb H7',
    sku: 'HB-9900',
    price: '8.750',
    stock_quantity: 55,
    category: 'Electrical',
  },
  {
    id: '11',
    name: 'Cabin Air Filter',
    sku: 'CF-2233',
    price: '16.300',
    stock_quantity: 28,
    category: 'Filters',
  },
  {
    id: '12',
    name: 'Tire Pressure Gauge',
    sku: 'TG-4455',
    price: '9.500',
    stock_quantity: 20,
    category: 'Tools',
  },
]

export function POSDemo() {
  const [selectedCustomer] = useState(null)
  const [isAdvancedPaymentsOpen, setIsAdvancedPaymentsOpen] = useState(false)
  const [currentCartItems, setCurrentCartItems] = useState<CartItem[]>([])

  const handleQuickCheckout = (items: CartItem[]) => {
    console.log('Quick Checkout - Items:', items)
    const total = items.reduce((sum, item) => sum + parseFloat(item.line_total), 0)
    alert(`Checkout successful! Total: ${total.toFixed(3)} TND\nItems: ${items.length.toString()}`)
  }

  const handleAdvancedPayments = (items: CartItem[]) => {
    console.log('Advanced Payments - Items:', items)
    setCurrentCartItems(items)
    setIsAdvancedPaymentsOpen(true)
  }

  const handleCompletePayment = (paymentData: PaymentData) => {
    console.log('Payment completed:', paymentData)
    const total = currentCartItems.reduce((sum, item) => sum + parseFloat(item.line_total), 0)
    const totalPaid = paymentData.methods.reduce((sum, m) => sum + m.amount, 0)

    alert(
      `Transaction Completed!\n\n` +
        `Total: ${total.toFixed(3)} TND\n` +
        `Total Paid: ${totalPaid.toFixed(3)} TND\n` +
        `Payment Methods: ${paymentData.methods.map((m) => m.methodId).join(', ')}\n` +
        `Items: ${currentCartItems.length.toString()}`
    )

    setIsAdvancedPaymentsOpen(false)
    setCurrentCartItems([])
  }

  const handleProductInfo = (product: Product) => {
    console.log('Product Info:', product)
    alert(
      `Product Details:\n\n` +
        `Name: ${product.name}\n` +
        `SKU: ${product.sku}\n` +
        `Price: ${product.price} TND\n` +
        `Stock: ${product.stock_quantity.toString()} units\n` +
        `Category: ${product.category || 'N/A'}`
    )
  }

  return (
    <>
      <POSPage
        products={mockProducts}
        onQuickCheckout={handleQuickCheckout}
        onAdvancedPayments={handleAdvancedPayments}
        onProductInfo={handleProductInfo}
        selectedCustomer={selectedCustomer}
        touchOptimized={false} // Set to true for tablets
        terminalCode="POS01"
        shiftId="SHIFT-001"
      />

      {/* Advanced Payments Modal */}
      <AdvancedPaymentsModal
        isOpen={isAdvancedPaymentsOpen}
        onClose={() => { setIsAdvancedPaymentsOpen(false) }}
        cartItems={currentCartItems}
        onComplete={handleCompletePayment}
        touchOptimized={false}
      />
    </>
  )
}
