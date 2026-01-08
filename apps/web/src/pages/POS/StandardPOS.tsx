import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useCompanyConfig } from '../../contexts'
import { Search, ShoppingCart, Trash2 } from 'lucide-react'

interface CartItem {
  id: string
  name: string
  price: number
  quantity: number
}

/**
 * StandardPOS - Universal Point of Sale Component (Phase 1)
 *
 * This component provides a standard POS interface that adapts to different
 * business verticals through configuration rather than specialized variants.
 *
 * Phase 1 Verticals Using StandardPOS:
 * - retail, fashion, coffee_shop, parts_retailer
 * - car_glass, tire_shop, service_station, parapharmacy
 * - mechanic (for parts counter sales)
 * - pharmacy (basic sales, specialized variant in Phase 2)
 * - restaurant (basic sales, specialized variant in Phase 2)
 *
 * The component adapts to vertical requirements through:
 * - CompanyConfigContext providing vertical configuration
 * - Styling variations based on vertical
 * - Feature toggling for vertical-specific functionality
 */
export function StandardPOS() {
  const { t } = useTranslation(['common', 'sales'])
  const { config, isLoading, error } = useCompanyConfig()
  const [searchQuery, setSearchQuery] = useState('')
  const [cart, setCart] = useState<CartItem[]>([])

  // Loading state
  if (isLoading) {
    return null
  }

  // Error state
  if (error || !config) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className="text-center">
          <p className="text-lg text-red-600">{t('common:error')}</p>
          <p className="text-sm text-gray-600">
            {error?.message || t('common:error.generic')}
          </p>
        </div>
      </div>
    )
  }

  const cartTotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  const cartItemCount = cart.reduce((sum, item) => sum + item.quantity, 0)

  const handleClearCart = () => {
    setCart([])
  }

  return (
    <div
      className="flex h-screen flex-col bg-gray-50"
      data-testid="standard-pos"
      data-vertical={config.vertical}
    >
      {/* Header */}
      <header className="bg-white shadow-sm">
        <div className="flex items-center justify-between px-6 py-4">
          <div className="flex items-center gap-4">
            <ShoppingCart className="h-6 w-6 text-blue-600" />
            <h1 className="text-xl font-semibold text-gray-900">
              {t('sales:pos.title', { defaultValue: 'Point of Sale' })}
            </h1>
            <span className="rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800">
              {config.vertical.replace('_', ' ')}
            </span>
          </div>
          <div className="flex items-center gap-2 text-sm text-gray-600">
            <span>{config.locale}</span>
            <span>•</span>
            <span>{config.currency}</span>
          </div>
        </div>
      </header>

      {/* Main Content */}
      <div className="flex flex-1 overflow-hidden">
        {/* Product Search & Selection Area */}
        <div className="flex-1 overflow-y-auto p-6">
          {/* Search Box */}
          <div className="mb-6">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
              <input
                type="text"
                placeholder={t('sales:pos.searchProducts', {
                  defaultValue: 'Search products...',
                })}
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full rounded-lg border border-gray-300 py-3 pl-10 pr-4 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>
          </div>

          {/* Product Grid - Placeholder */}
          <div className="grid grid-cols-3 gap-4">
            {/* Product cards would go here */}
            <div className="rounded-lg border-2 border-dashed border-gray-300 p-8 text-center text-gray-500">
              {t('sales:pos.noProducts', { defaultValue: 'No products to display' })}
            </div>
          </div>
        </div>

        {/* Cart Area */}
        <div className="w-96 border-l border-gray-200 bg-white">
          <div className="flex h-full flex-col">
            {/* Cart Header */}
            <div className="border-b border-gray-200 p-4">
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold text-gray-900">
                  {t('sales:pos.cart', { defaultValue: 'Cart' })}
                </h2>
                {cart.length > 0 && (
                  <button
                    onClick={handleClearCart}
                    className="flex items-center gap-1 text-sm text-red-600 hover:text-red-700"
                  >
                    <Trash2 className="h-4 w-4" />
                    {t('common.clear')}
                  </button>
                )}
              </div>
              <p className="mt-1 text-sm text-gray-600">
                {cartItemCount} {t('sales:pos.items', { defaultValue: 'items' })}
              </p>
            </div>

            {/* Cart Items */}
            <div className="flex-1 overflow-y-auto p-4">
              {cart.length === 0 ? (
                <div className="flex h-full items-center justify-center">
                  <div className="text-center text-gray-500">
                    <ShoppingCart className="mx-auto mb-2 h-12 w-12 text-gray-400" />
                    <p className="text-sm">
                      {t('sales:pos.emptyCart', { defaultValue: 'Empty cart' })}
                    </p>
                    <p className="mt-1 text-xs">
                      {t('sales:pos.noItems', { defaultValue: 'No items in cart' })}
                    </p>
                  </div>
                </div>
              ) : (
                <div className="space-y-3">
                  {cart.map((item) => (
                    <div
                      key={item.id}
                      className="rounded-lg border border-gray-200 p-3"
                    >
                      <div className="flex justify-between">
                        <span className="font-medium text-gray-900">{item.name}</span>
                        <span className="text-gray-700">
                          {config.currency} {(item.price * item.quantity).toFixed(2)}
                        </span>
                      </div>
                      <div className="mt-1 flex items-center justify-between">
                        <span className="text-sm text-gray-600">
                          {item.quantity} × {config.currency} {item.price.toFixed(2)}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Cart Total */}
            <div className="border-t border-gray-200 p-4" data-testid="cart-total">
              <div className="space-y-2">
                <div className="flex justify-between text-sm text-gray-600">
                  <span>{t('sales:pos.subtotal', { defaultValue: 'Subtotal' })}</span>
                  <span>
                    {config.currency} {cartTotal.toFixed(2)}
                  </span>
                </div>
                <div className="flex justify-between border-t border-gray-200 pt-2 text-lg font-semibold text-gray-900">
                  <span>{t('sales:pos.total', { defaultValue: 'Total' })}</span>
                  <span>
                    {config.currency} {cartTotal.toFixed(2)}
                  </span>
                </div>
              </div>

              {/* Checkout Button */}
              <button
                disabled={cart.length === 0}
                className="mt-4 w-full rounded-lg bg-blue-600 px-4 py-3 font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500"
              >
                {t('sales:pos.checkout', { defaultValue: 'Checkout' })}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
