import { useState, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { TrendingUp, AlertCircle, Loader2 } from 'lucide-react'
import { api } from '../../../../lib/api'
import { MarginIndicator } from './MarginIndicator'
import { Button, MoneyInput } from '@/components/atoms'
import { useCurrency } from '@/hooks/useCurrency'
import { bccomp, bcmul } from '@/lib/decimal'
import { formatNumber, formatPercent } from '@/lib/format'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { colors, tokens, textColors } from '@/lib/designTokens'

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
  value: string | number
  onChange: (value: string) => void
  label?: string
  disabled?: boolean
  showSuggestedPrice?: boolean
}

export function PriceInputWithMargin({
  productId,
  value,
  onChange,
  label,
  disabled = false,
  showSuggestedPrice = true,
}: PriceInputWithMarginProps) {
  const { t } = useTranslation('inventory')
  const { currency, decimals } = useCurrency()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [localValue, setLocalValue] = useState(() => value.toString())
  const [debouncedValue, setDebouncedValue] = useState(() => value.toString())

  const resolvedLabel = label ?? t('pricing.salePrice')

  // Debounce the value for API calls
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedValue(localValue.trim() === '' ? '0' : localValue)
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
    enabled: bccomp(debouncedValue, '0') > 0 && !disabled && !!tenantId && !!companyId,
    staleTime: 10000, // Cache for 10 seconds
  })

  const marginInfo = marginData?.data

  const handleChange = (raw: string) => {
    setLocalValue(raw)
    onChange(raw.trim() === '' ? '0' : raw)
  }

  const applySuggestedPrice = () => {
    if (marginInfo?.suggested_price) {
      const suggested = bcmul(marginInfo.suggested_price, '1', decimals)
      setLocalValue(suggested)
      onChange(marginInfo.suggested_price)
    }
  }

  return (
    <div className="space-y-2">
      <label className={tokens.label.base}>
        {resolvedLabel}
      </label>

      {/* Price Input */}
      <MoneyInput
        currency={currency}
        value={localValue}
        onChange={handleChange}
        disabled={disabled}
        placeholder="0.00"
      />

      {/* Margin Indicator */}
      {isLoading && bccomp(debouncedValue, '0') > 0 && (
        <div className={`flex items-center gap-2 text-sm ${textColors.tertiary}`}>
          <Loader2 className={`h-4 w-4 animate-spin ${textColors.brand}`} />
          {t('pricing.checkingMargin')}
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
          <div className={`rounded-lg ${colors.neutral[50]} p-3 text-xs`}>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <span className={textColors.tertiary}>{t('pricing.costPrice')}:</span>
                <span className={`ms-1 font-medium ${textColors.primary}`}>
                  ${formatNumber(marginInfo.cost_price, decimals)}
                </span>
              </div>
              <div>
                <span className={textColors.tertiary}>{t('pricing.margin')}:</span>
                <span className={`ms-1 font-medium ${textColors.primary}`}>
                  {formatPercent(marginInfo.margin_level.percentage)}
                </span>
              </div>
              <div>
                <span className={textColors.tertiary}>{t('pricing.targetMarginShort')}:</span>
                <span className={`ms-1 font-medium ${textColors.primary}`}>
                  {formatPercent(marginInfo.margins.target_margin)}
                </span>
              </div>
              <div>
                <span className={textColors.tertiary}>{t('pricing.minimumMarginShort')}:</span>
                <span className={`ms-1 font-medium ${textColors.primary}`}>
                  {formatPercent(marginInfo.margins.minimum_margin)}
                </span>
              </div>
            </div>
          </div>

          {/* Suggested Price */}
          {showSuggestedPrice && marginInfo.suggested_price && (
            <Button
              type="button"
              variant="ghost"
              onClick={applySuggestedPrice}
              className={`w-full justify-between rounded-lg p-2 ${tokens.alert.info}`}
            >
              <div className="flex items-center gap-2">
                <TrendingUp className="h-4 w-4" />
                <span>{t('pricing.suggestedPriceButton')}</span>
              </div>
              <span className="font-semibold tabular-nums">
                ${formatNumber(marginInfo.suggested_price, decimals)}
              </span>
            </Button>
          )}

          {/* Permission Warning */}
          {!marginInfo.can_sell && (
            <div className={`flex items-start gap-2 rounded-lg p-2 text-xs ${tokens.alert.error}`}>
              <AlertCircle className="h-4 w-4 shrink-0" />
              <span>
                {t('pricing.cannotSellAtPrice')}
              </span>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
