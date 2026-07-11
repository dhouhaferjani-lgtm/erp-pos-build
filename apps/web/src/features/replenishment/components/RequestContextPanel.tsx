import { useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { LoadingSpinner } from '@/components/atoms/Spinner/Spinner'
import { QueryError } from '@/components/QueryError'
import { getProductStock } from '@/features/products/api/productStock'
import { bccomp } from '@/lib/decimal'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useTranslation } from 'react-i18next'
import type { ReplenishmentLine } from '../types'

export interface RequestContextPanelProps {
  line: ReplenishmentLine
  onClose: () => void
}

export function RequestContextPanel({ line, onClose }: RequestContextPanelProps) {
  const { t } = useTranslation('replenishment')
  const stock = useQuery({
    queryKey: tenantScopedKey(['product-stock', line.product_id]),
    queryFn: () => getProductStock(line.product_id),
  })

  return (
    <aside className={`fixed inset-y-0 end-0 z-40 w-full max-w-md overflow-y-auto border-s p-5 shadow-xl ${borderColors.light} ${colors.white}`}>
      <div className="mb-5 flex items-start justify-between gap-3">
        <div>
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>{t('context.title')}</h2>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>{line.product_name}</p>
        </div>
        <button
          type="button"
          aria-label={t('context.close')}
          onClick={onClose}
          className={`${tokens.button.base} ${tokens.button.ghost} ${tokens.button.sizes.sm}`}
        >
          <X className="h-4 w-4" />
        </button>
      </div>

      {stock.isLoading ? <LoadingSpinner /> : null}
      {stock.error ? <QueryError error={stock.error} onRetry={() => { void stock.refetch() }} compact /> : null}
      {stock.data ? (
        <ul className="space-y-3">
          {stock.data.locations.map((location) => {
            const isRequesting = location.location_id === line.location_id
            const isSurplus = location.max_quantity !== null
              && bccomp(location.available, location.max_quantity) > 0
            const highlight = isRequesting
              ? tokens.alert.warning
              : isSurplus
                ? tokens.alert.success
                : colors.neutral[50]

            return (
              <li key={location.location_id} className={`rounded-lg p-3 ${highlight}`}>
                <div className="flex items-center justify-between gap-2">
                  <span className={`font-medium ${textColors.primary}`}>{location.location_name}</span>
                  {isRequesting ? <span className="text-xs">{t('context.requesting')}</span> : null}
                  {!isRequesting && isSurplus ? <span className="text-xs">{t('context.surplus')}</span> : null}
                </div>
                <dl className={`mt-2 grid grid-cols-3 gap-2 text-xs ${textColors.secondary}`}>
                  <div><dt>{t('context.available')}</dt><dd>{location.available}</dd></div>
                  <div><dt>{t('context.minimum')}</dt><dd>{location.min_quantity ?? '—'}</dd></div>
                  <div><dt>{t('context.maximum')}</dt><dd>{location.max_quantity ?? '—'}</dd></div>
                </dl>
              </li>
            )
          })}
        </ul>
      ) : null}
    </aside>
  )
}
