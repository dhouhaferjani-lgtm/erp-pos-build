import { DollarSign, TrendingUp, Shield, Calendar } from 'lucide-react'
import { MarginBadge } from './MarginIndicator'
import { useCurrency } from '@/hooks/useCurrency'

interface Product {
  id: string
  name: string
  sku: string
  cost_price?: string
  list_price?: string
  target_margin_override?: string
  minimum_margin_override?: string
  last_purchase_cost?: string
  cost_updated_at?: string
}

interface ProductPricingCardProps {
  product: Product
  defaultTargetMargin?: number
  defaultMinimumMargin?: number
  canViewCosts?: boolean
}

export function ProductPricingCard({
  product,
  defaultTargetMargin = 30,
  defaultMinimumMargin = 15,
  canViewCosts = true,
}: ProductPricingCardProps) {
  const { decimals } = useCurrency()
  const costPrice = parseFloat(product.cost_price || '0')
  const listPrice = parseFloat(product.list_price || '0')
  const targetMargin = parseFloat(product.target_margin_override || String(defaultTargetMargin))
  const minimumMargin = parseFloat(product.minimum_margin_override || String(defaultMinimumMargin))
  const lastPurchaseCost = parseFloat(product.last_purchase_cost || '0')

  // Calculate current margin if we have both cost and list price
  const currentMargin = costPrice > 0 && listPrice > 0
    ? ((listPrice - costPrice) / costPrice) * 100
    : 0

  // Determine margin level
  const getMarginLevel = (): 'green' | 'yellow' | 'orange' | 'red' => {
    if (currentMargin < 0) return 'red'
    if (currentMargin < minimumMargin) return 'orange'
    if (currentMargin < targetMargin) return 'yellow'
    return 'green'
  }

  const marginLevel = getMarginLevel()

  // Calculate suggested price based on target margin
  const suggestedPrice = costPrice > 0
    ? costPrice / (1 - targetMargin / 100)
    : 0

  const formatDate = (dateString?: string) => {
    if (!dateString) return 'Never'
    const date = new Date(dateString)
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
  }

  return (
    <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
      {/* Header */}
      <div className="border-b border-gray-200 bg-gray-50 px-4 py-3">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-sm font-semibold text-gray-900">{product.name}</h3>
            <p className="text-xs text-gray-500">{product.sku}</p>
          </div>
          <MarginBadge level={marginLevel} />
        </div>
      </div>

      {/* Pricing Grid */}
      <div className="grid grid-cols-2 gap-4 p-4">
        {/* Cost Price */}
        {canViewCosts && (
          <div>
            <div className="flex items-center gap-1 text-xs text-gray-600">
              <Shield className="h-3 w-3" />
              <span>Cost Price</span>
            </div>
            <p className="mt-1 text-lg font-semibold text-gray-900">
              ${costPrice.toFixed(decimals)}
            </p>
            {lastPurchaseCost > 0 && lastPurchaseCost !== costPrice && (
              <p className="text-xs text-gray-500">
                Last: ${lastPurchaseCost.toFixed(decimals)}
              </p>
            )}
          </div>
        )}

        {/* List Price */}
        <div>
          <div className="flex items-center gap-1 text-xs text-gray-600">
            <DollarSign className="h-3 w-3" />
            <span>List Price</span>
          </div>
          <p className="mt-1 text-lg font-semibold text-gray-900">
            ${listPrice.toFixed(decimals)}
          </p>
          {currentMargin > 0 && (
            <p className="text-xs text-gray-500">
              Margin: {currentMargin.toFixed(1)}%
            </p>
          )}
        </div>

        {/* Target Margin */}
        {canViewCosts && (
          <div>
            <div className="flex items-center gap-1 text-xs text-gray-600">
              <TrendingUp className="h-3 w-3" />
              <span>Target Margin</span>
            </div>
            <p className="mt-1 text-lg font-semibold text-gray-900">
              {targetMargin.toFixed(1)}%
            </p>
            <p className="text-xs text-gray-500">
              Min: {minimumMargin.toFixed(1)}%
            </p>
          </div>
        )}

        {/* Suggested Price */}
        {canViewCosts && suggestedPrice > 0 && (
          <div>
            <div className="flex items-center gap-1 text-xs text-gray-600">
              <TrendingUp className="h-3 w-3" />
              <span>Suggested Price</span>
            </div>
            <p className="mt-1 text-lg font-semibold text-blue-600">
              ${suggestedPrice.toFixed(decimals)}
            </p>
            <p className="text-xs text-gray-500">
              @ {targetMargin.toFixed(1)}% margin
            </p>
          </div>
        )}
      </div>

      {/* Footer */}
      {canViewCosts && product.cost_updated_at && (
        <div className="border-t border-gray-200 bg-gray-50 px-4 py-2">
          <div className="flex items-center gap-1 text-xs text-gray-600">
            <Calendar className="h-3 w-3" />
            <span>Cost last updated: {formatDate(product.cost_updated_at)}</span>
          </div>
        </div>
      )}

      {/* Margin Details */}
      {canViewCosts && costPrice > 0 && listPrice > 0 && (
        <div className="border-t border-gray-200 px-4 py-3">
          <div className="space-y-2 text-xs">
            <div className="flex justify-between">
              <span className="text-gray-600">Markup:</span>
              <span className="font-medium text-gray-900">
                ${(listPrice - costPrice).toFixed(decimals)}
              </span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-600">Margin %:</span>
              <span className={`font-semibold ${
                marginLevel === 'green' ? 'text-green-600' :
                marginLevel === 'yellow' ? 'text-yellow-600' :
                marginLevel === 'orange' ? 'text-orange-600' :
                'text-red-600'
              }`}>
                {currentMargin.toFixed(2)}%
              </span>
            </div>
            {currentMargin < targetMargin && (
              <div className="rounded-md bg-yellow-50 p-2 text-yellow-800">
                <span className="font-medium">Below target:</span> Consider raising price to ${suggestedPrice.toFixed(decimals)}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
