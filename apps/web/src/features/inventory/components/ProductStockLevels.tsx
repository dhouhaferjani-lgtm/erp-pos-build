import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { getProductStock } from '@/features/products/api/productStock'
import { formatCurrency } from '@/lib/format'
import { useCurrency } from '@/hooks/useCurrency'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

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
    return formatCurrency(parseFloat(value), { currency, locale })
  }

  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['product-stock', productId]),
    queryFn: () => getProductStock(productId),
    enabled: !!productId && !!tenantId && !!companyId,
  })

  if (isLoading) {
    return (
      <div className="rounded-lg border border-gray-200 bg-white">
        <div className="border-b border-gray-200 px-6 py-4">
          <div className="h-5 w-32 animate-pulse rounded bg-gray-200" />
        </div>
        <div className="space-y-3 p-6">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="h-12 animate-pulse rounded bg-gray-100" />
          ))}
        </div>
      </div>
    )
  }

  if (!data) {
    return (
      <div className="rounded-lg border border-gray-200 bg-white">
        <div className="border-b border-gray-200 px-6 py-4">
          <h2 className="text-base font-semibold text-gray-900">{t('stock.title')}</h2>
        </div>
        <div className="px-6 py-8 text-center">
          <p className="text-sm text-gray-500">{t('stock.noStock')}</p>
        </div>
      </div>
    )
  }

  const { totals, locations } = data

  // Calculate stock value (quantity × WAC)
  const stockValue = costPrice
    ? parseFloat(totals.quantity) * parseFloat(costPrice)
    : 0

  return (
    <div className="rounded-lg border border-gray-200 bg-white">
      <div className="border-b border-gray-200 px-6 py-4">
        <h2 className="text-base font-semibold text-gray-900">{t('stock.title')}</h2>
      </div>

      {/* Summary Totals */}
      <div className="divide-y divide-gray-100">
        {/* Stock Value - Prominent Display */}
        {costPrice && parseFloat(totals.quantity) > 0 && (
          <div className="bg-blue-50 px-6 py-4">
            <div className="text-xs font-medium uppercase tracking-wide text-blue-700">
              {t('stock.stockValue')}
            </div>
            <div className="mt-1 text-xl font-bold text-blue-900">
              {formatAmount(stockValue.toFixed(decimals))}
            </div>
            <div className="mt-1 text-xs text-blue-600">
              {parseFloat(totals.quantity).toFixed(decimals)} × {formatAmount(costPrice)} (WAC)
            </div>
          </div>
        )}

        <div className="grid grid-cols-2 gap-x-6 px-6 py-3">
          <div>
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">
              {t('stock.onHand')}
            </div>
            <div className="mt-1 text-base font-semibold text-gray-900">
              {parseFloat(totals.quantity).toFixed(decimals)}
            </div>
          </div>
          <div>
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">
              {t('stock.available')}
            </div>
            <div className="mt-1 text-base font-semibold text-green-600">
              {parseFloat(totals.available).toFixed(decimals)}
            </div>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-x-6 px-6 py-3">
          <div>
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">
              {t('stock.reserved')}
            </div>
            <div className="mt-1 text-base font-semibold text-orange-600">
              {parseFloat(totals.reserved).toFixed(decimals)}
            </div>
          </div>
          <div>
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">
              {t('stock.incoming')}
            </div>
            <div className="mt-1 text-base font-semibold text-blue-600">
              {parseFloat(totals.incoming).toFixed(decimals)}
            </div>
          </div>
        </div>

        <div className="px-6 py-3">
          <div className="text-xs font-medium uppercase tracking-wide text-gray-500">
            {t('stock.projectedAvailable')}
          </div>
          <div className="mt-1 text-base font-semibold text-gray-900">
            {parseFloat(totals.projected_available).toFixed(decimals)}
          </div>
        </div>
      </div>

      {/* Per-Location Breakdown (Collapsible) */}
      {locations.length > 1 && (
        <details className="border-t border-gray-200">
          <summary className="cursor-pointer px-6 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
            {t('stock.viewByLocation', { count: locations.length })}
          </summary>
          <div className="divide-y divide-gray-100 px-6 pb-4">
            {locations.map((loc) => (
              <div key={loc.id} className="py-3">
                <div className="mb-2 font-medium text-gray-900">{loc.location_name}</div>
                <div className="grid grid-cols-3 gap-4 text-sm">
                  <div>
                    <span className="text-gray-500">{t('stock.onHand')}:</span>{' '}
                    <span className="font-medium">{parseFloat(loc.quantity).toFixed(decimals)}</span>
                  </div>
                  <div>
                    <span className="text-gray-500">{t('stock.available')}:</span>{' '}
                    <span className="font-medium text-green-600">
                      {parseFloat(loc.available).toFixed(decimals)}
                    </span>
                  </div>
                  <div>
                    <span className="text-gray-500">{t('stock.incoming')}:</span>{' '}
                    <span className="font-medium text-blue-600">
                      {parseFloat(loc.incoming).toFixed(decimals)}
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
