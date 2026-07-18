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
import { RequirePermission } from '@/components/auth'
import { Link } from 'react-router-dom'
import { ThresholdEditCell } from './ThresholdEditCell'
import type { MatrixCell, MatrixRow } from '../api/stockMatrix'

interface ProductStockLevelsProps {
  productId: string
  costPrice: string | null
  canViewCostPrices: boolean
  currency?: string
  locale?: string
  embedded?: boolean
}

function StockLevelsFrame({
  children,
  embedded,
  title,
}: {
  children: React.ReactNode
  embedded: boolean
  title: string
}) {
  if (embedded) return <div>{children}</div>

  return (
    <div className={`rounded-lg border ${borderColors.light} bg-white`}>
      <div className={`border-b ${borderColors.light} px-6 py-4`}>
        <h2 className={`text-base font-semibold ${textColors.primary}`}>{title}</h2>
      </div>
      {children}
    </div>
  )
}

export function ProductStockLevels({
  productId,
  costPrice,
  canViewCostPrices,
  currency = 'EUR',
  locale = 'en-US',
  embedded = false,
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
      <StockLevelsFrame embedded={embedded} title={t('stock.title')}>
        <div className="space-y-3 p-6">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className={`h-12 animate-pulse rounded ${colors.neutral[100]}`} />
          ))}
        </div>
      </StockLevelsFrame>
    )
  }

  if (!data) {
    return (
      <StockLevelsFrame embedded={embedded} title={t('stock.title')}>
        <div className="px-6 py-8 text-center">
          <p className={`text-sm ${textColors.tertiary}`}>{t('stock.noStock')}</p>
        </div>
      </StockLevelsFrame>
    )
  }

  const { totals, locations } = data

  // Calculate stock value (quantity × WAC)
  const stockValue = canViewCostPrices && costPrice
    ? bcmul(totals.quantity, costPrice, decimals)
    : '0'

  return (
    <StockLevelsFrame embedded={embedded} title={t('stock.title')}>
      {/* Summary Totals */}
      <div className={`divide-y ${borderColors.divideLight}`}>
        {/* Stock Value - Prominent Display */}
        {canViewCostPrices && costPrice && bccomp(totals.quantity, '0') > 0 && (
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

      {/* Per-location stock is always visible so product availability is never hidden. */}
      <section className={`border-t ${borderColors.light}`} aria-labelledby="product-stock-by-location">
        <div className="flex items-center justify-between gap-3 px-6 py-3">
          <h3 id="product-stock-by-location" className={`text-sm font-semibold ${textColors.primary}`}>
            {t('stock.viewByLocation', { count: locations.length })}
          </h3>
        </div>
        <div className="overflow-x-auto px-6 pb-4">
          <table className={`min-w-full divide-y ${borderColors.divideLight} text-sm`}>
            <thead>
              <tr className={textColors.tertiary}>
                <th scope="col" className="py-2 pr-4 text-left font-medium">{t('stock.location')}</th>
                <th scope="col" className="px-2 py-2 text-right font-medium">{t('stock.locationOnHand')}</th>
                <th scope="col" className="px-2 py-2 text-right font-medium">{t('stock.locationReserved')}</th>
                <th scope="col" className="px-2 py-2 text-right font-medium">{t('stock.locationAvailable')}</th>
                <th scope="col" className="px-2 py-2 text-right font-medium">{t('stock.locationIncoming')}</th>
                <th scope="col" className="px-2 py-2 text-left font-medium">{t('stock.thresholds')}</th>
                <th scope="col" className="py-2 pl-2 text-right font-medium">{t('stock.actions')}</th>
              </tr>
            </thead>
            <tbody className={`divide-y ${borderColors.divideLight}`}>
              {locations.map((loc) => {
                const cell: MatrixCell = {
                  on_hand: loc.quantity,
                  reserved: loc.reserved,
                  available: loc.available,
                  min_quantity: loc.min_quantity,
                  max_quantity: loc.max_quantity,
                  incoming: loc.incoming,
                }
                const row: MatrixRow = {
                  product_id: productId,
                  variant_id: null,
                  name: '',
                  sku: '',
                  is_variant_parent: false,
                  cells: { [loc.location_id]: cell },
                }

                return (
                  <tr key={loc.id}>
                    <th scope="row" className={`whitespace-nowrap py-3 pr-4 text-left font-medium ${textColors.primary}`}>
                      {loc.location_name}
                    </th>
                    <td className="px-2 py-3 text-right font-medium">{formatQuantity(loc.quantity)}</td>
                    <td className={`px-2 py-3 text-right font-medium ${textColors.warningDark}`}>{formatQuantity(loc.reserved)}</td>
                    <td className={`px-2 py-3 text-right font-medium ${textColors.success}`}>{formatQuantity(loc.available)}</td>
                    <td className={`px-2 py-3 text-right font-medium ${textColors.brand}`}>{formatQuantity(loc.incoming)}</td>
                    <td className="px-2 py-3"><ThresholdEditCell row={row} locationId={loc.location_id} cell={cell} /></td>
                    <td className="py-3 pl-2 text-right">
                      <RequirePermission permission="inventory.transfers.create">
                        <Link
                          className={`whitespace-nowrap text-sm font-medium ${textColors.brand} underline-offset-2 hover:underline`}
                          to={`/inventory/stock-transfers/new?source_location_id=${encodeURIComponent(loc.location_id)}&product_id=${encodeURIComponent(productId)}`}
                        >
                          {t('stock.transferFromHere')}
                        </Link>
                      </RequirePermission>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          {locations.length === 0 && <p className={`py-4 text-center text-sm ${textColors.tertiary}`}>{t('stock.noLocations')}</p>}
        </div>
      </section>
    </StockLevelsFrame>
  )
}
