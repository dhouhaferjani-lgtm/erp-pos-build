import { useState, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { DollarSign, TrendingUp, AlertCircle } from 'lucide-react'
import { api } from '../../../../lib/api'
import { MarginIndicator } from './MarginIndicator'
import { useCurrency } from '@/hooks/useCurrency'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

interface MarginCheckResponse {
  data: {
    cost_price: string
    sell_price: number
    margin_level: {
      level: 'green' | 'yellow' | 'orange' | 'red'
      message: string
      percentage: number
    }
    can_sell: boolean
    suggested_price: string
    margins: {
      target_margin: string
      minimum_margin: string
      source: string
    }
  }
}

interface PriceInputWithMarginProps {
  productId: string
  value: number
  onChange: (value: number) => void
  label?: string
  disabled?: boolean
  showSuggestedPrice?: boolean
}

export function PriceInputWithMargin({
  productId,
  value,
  onChange,
  label = 'Sell Price',
  disabled = false,
  showSuggestedPrice = true,
}: PriceInputWithMarginProps) {
  const { decimals } = useCurrency()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [localValue, setLocalValue] = useState(value.toString())
  const [debouncedValue, setDebouncedValue] = useState(value)

  // Debounce the value for API calls
  useEffect(() => {
    const timer = setTimeout(() => {
      const numValue = parseFloat(localValue) || 0
      setDebouncedValue(numValue)
    }, 500)

    return () => { clearTimeout(timer); }
  }, [localValue])

  // Fetch margin check
  const { data: marginData, isLoading } = useQuery({
    queryKey: tenantScopedKey(['margin-check', productId, debouncedValue]),
    queryFn: async () => {
      const response = await api.post<MarginCheckResponse>('/pricing/check-margin', {
        product_id: productId,
        sell_price: debouncedValue,
      })
      return response.data
    },
    enabled: debouncedValue > 0 && !disabled && !!tenantId && !!companyId,
    staleTime: 10000, // Cache for 10 seconds
  })

  const marginInfo = marginData?.data

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value
    setLocalValue(newValue)
    const numValue = parseFloat(newValue) || 0
    onChange(numValue)
  }

  const applySuggestedPrice = () => {
    if (marginInfo?.suggested_price) {
      const suggested = parseFloat(marginInfo.suggested_price)
      setLocalValue(suggested.toFixed(decimals))
      onChange(suggested)
    }
  }

  return (
    <div className="space-y-2">
      <label className="block text-sm font-medium text-gray-700">
        {label}
      </label>

      {/* Price Input */}
      <div className="relative">
        <div className="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
          <DollarSign className="h-4 w-4 text-gray-400" />
        </div>
        <input
          type="number"
          step="0.01"
          min="0"
          value={localValue}
          onChange={handleChange}
          disabled={disabled}
          className={`
            w-full rounded-md border border-gray-300 ps-8 pe-3 py-2 text-sm
            focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500
            ${disabled ? 'cursor-not-allowed bg-gray-100' : ''}
          `}
          placeholder="0.00"
        />
      </div>

      {/* Margin Indicator */}
      {isLoading && debouncedValue > 0 && (
        <div className="flex items-center gap-2 text-sm text-gray-500">
          <div className="h-4 w-4 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600" />
          Checking margin...
        </div>
      )}

      {marginInfo && !isLoading && (
        <div className="space-y-2">
          <MarginIndicator
            level={marginInfo.margin_level.level}
            message={marginInfo.margin_level.message}
            marginPercent={marginInfo.margin_level.percentage}
            size="sm"
          />

          {/* Detailed Info */}
          <div className="rounded-lg bg-gray-50 p-3 text-xs">
            <div className="grid grid-cols-2 gap-2">
              <div>
                <span className="text-gray-600">Cost Price:</span>
                <span className="ms-1 font-medium text-gray-900">
                  ${parseFloat(marginInfo.cost_price).toFixed(decimals)}
                </span>
              </div>
              <div>
                <span className="text-gray-600">Margin:</span>
                <span className="ms-1 font-medium text-gray-900">
                  {marginInfo.margin_level.percentage.toFixed(1)}%
                </span>
              </div>
              <div>
                <span className="text-gray-600">Target:</span>
                <span className="ms-1 font-medium text-gray-900">
                  {parseFloat(marginInfo.margins.target_margin).toFixed(1)}%
                </span>
              </div>
              <div>
                <span className="text-gray-600">Minimum:</span>
                <span className="ms-1 font-medium text-gray-900">
                  {parseFloat(marginInfo.margins.minimum_margin).toFixed(1)}%
                </span>
              </div>
            </div>
          </div>

          {/* Suggested Price */}
          {showSuggestedPrice && marginInfo.suggested_price && (
            <button
              onClick={applySuggestedPrice}
              className="flex w-full items-center justify-between rounded-lg border border-blue-200 bg-blue-50 p-2 text-sm text-blue-700 hover:bg-blue-100"
            >
              <div className="flex items-center gap-2">
                <TrendingUp className="h-4 w-4" />
                <span>Suggested Price (Target Margin)</span>
              </div>
              <span className="font-semibold">
                ${parseFloat(marginInfo.suggested_price).toFixed(decimals)}
              </span>
            </button>
          )}

          {/* Permission Warning */}
          {!marginInfo.can_sell && (
            <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-700">
              <AlertCircle className="h-4 w-4 shrink-0" />
              <span>
                You don't have permission to sell at this price. Contact your manager for approval.
              </span>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
