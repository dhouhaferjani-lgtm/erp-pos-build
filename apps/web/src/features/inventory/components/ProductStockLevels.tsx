import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { getProductStock } from '@/features/products/api/productStock'
import { formatCurrency, formatQuantity } from '@/lib/format'
import { useCurrency } from '@/hooks/useCurrency'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { colors, textColors, borderColors } from '@/lib/designTokens'
import { bccomp, bcmul } from '@/lib/decimal'

interface ProductStockLevelsProps {
  productId: string
  costPrice: string | null
  currency?: string
  locale?: string
}

export function ProductStockLevels({
  productId,
  costPrice,
  currency = 'EUR',
  locale = 'en-US',
}: ProductStockLevelsProps) {
  const { t } = useTranslation('inventory')
  const { decimals } = useCurrency()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  // Helper to format currency
  const formatAmount = (value: string | null) => {
    if (!value) return '-'
    return formatCurrency(value, { currency, locale })
  }

  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['product-stock', productId]),
    queryFn: () => getProductStock(productId),
    enabled: !!productId && !!tenantId && !!companyId,
  })

  if (isLoading) {
    return (
      <div className={`rounded-lg border ${borderColors.light} bg-white`}>
        <div className={`border-b ${borderColors.light} px-6 py-4`}>
          <div className={`h-5 w-32 animate-pulse rounded ${colors.neutral[200]}`} />
        </div>
        <div className="space-y-3 p-6">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className={`h-12 animate-pulse rounded ${colors.neutral[100]}`} />
          ))}
        </div>
      </div>
    )
  }

  if (!data) {
    return (
      <div className={`rounded-lg border ${borderColors.light} bg-white`}>
        <div className={`border-b ${borderColors.light} px-6 py-4`}>
          <h2 className={`text-base font-semibold ${textColors.primary}`}>{t('stock.title')}</h2>
        </div>
        <div className="px-6 py-8 text-center">
          <p className={`text-sm ${textColors.tertiary}`}>{t('stock.noStock')}</p>
        </div>
      </div>
    )
  }

  const { totals, locations } = data

  // Calculate stock value (quantity × WAC)
  const stockValue = costPrice
    ? bcmul(totals.quantity, costPrice, decimals)
    : '0'

  return (
    <div className={`rounded-lg border ${borderColors.light} bg-white`}>
      <div className={`border-b ${borderColors.light} px-6 py-4`}>
        <h2 className={`text-base font-semibold ${textColors.primary}`}>{t('stock.title')}</h2>
      </div>

      {/* Summary Totals */}
      <div className={`divide-y ${borderColors.divideLight}`}>
        {/* Stock Value - Prominent Display */}
        {costPrice && bccomp(totals.quantity, '0') > 0 && (
          <div className={`${colors.primary[50]} px-6 py-4`}>
            <div className={`text-xs font-medium uppercase tracking-wide ${textColors.brand}`}>
              {t('stock.stockValue')}
            </div>
            <div className={`mt-1 text-xl font-bold ${textColors.brand}`}>
              {formatAmount(stockValue)}
            </div>
            <div className={`mt-1 text-xs ${textColors.brand}`}>
              {/* eslint-disable-next-line local/no-untranslated-literal -- WAC is a canonical accounting acronym, not translatable prose */}
              {formatQuantity(totals.quantity)} × {formatAmount(costPrice)} (WAC)
            </div>
          </div>
        )}

        <div className="grid grid-cols-2 gap-x-6 px-6 py-3">
          <div>
            <div className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
              {t('stock.onHand')}
            </div>
            <div className={`mt-1 text-base font-semibold ${textColors.primary}`}>
              {formatQuantity(totals.quantity)}
            </div>
          </div>
          <div>
            <div className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
              {t('stock.available')}
            </div>
            <div className={`mt-1 text-base font-semibold ${textColors.success}`}>
              {formatQuantity(totals.available)}
            </div>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-x-6 px-6 py-3">
          <div>
            <div className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
              {t('stock.reserved')}
            </div>
            <div className={`mt-1 text-base font-semibold ${textColors.warningDark}`}>
              {formatQuantity(totals.reserved)}
            </div>
          </div>
          <div>
            <div className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
              {t('stock.incoming')}
            </div>
            <div className={`mt-1 text-base font-semibold ${textColors.brand}`}>
              {formatQuantity(totals.incoming)}
            </div>
          </div>
        </div>

        <div className="px-6 py-3">
          <div className={`text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
            {t('stock.projectedAvailable')}
          </div>
          <div className={`mt-1 text-base font-semibold ${textColors.primary}`}>
            {formatQuantity(totals.projected_available)}
          </div>
        </div>
      </div>

      {/* Per-Location Breakdown (Collapsible) */}
      {locations.length > 1 && (
        <details className={`border-t ${borderColors.light}`}>
          <summary className={`cursor-pointer px-6 py-3 text-sm font-medium ${textColors.secondary} ${colors.hover.gray50}`}>
            {t('stock.viewByLocation', { count: locations.length })}
          </summary>
          <div className={`divide-y ${borderColors.divideLight} px-6 pb-4`}>
            {locations.map((loc) => (
              <div key={loc.id} className="py-3">
                <div className={`mb-2 font-medium ${textColors.primary}`}>{loc.location_name}</div>
                <div className="grid grid-cols-3 gap-4 text-sm">
                  <div>
                    <span className={textColors.tertiary}>{t('stock.onHand')}:</span>{' '}
                    <span className="font-medium">{formatQuantity(loc.quantity)}</span>
                  </div>
                  <div>
                    <span className={textColors.tertiary}>{t('stock.available')}:</span>{' '}
                    <span className={`font-medium ${textColors.success}`}>
                      {formatQuantity(loc.available)}
                    </span>
                  </div>
                  <div>
                    <span className={textColors.tertiary}>{t('stock.incoming')}:</span>{' '}
                    <span className={`font-medium ${textColors.brand}`}>
                      {formatQuantity(loc.incoming)}
                    </span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </details>
      )}
    </div>
  )
}
