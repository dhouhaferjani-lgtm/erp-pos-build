import { ArrowRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

import { useRebalanceSuggestions } from '@/features/inventory/hooks/useRebalanceSuggestions'
import { formatQuantity } from '@/lib/format'
import { textColors, tokens } from '@/lib/designTokens'

export function RebalanceAlertsWidget() {
  const { t } = useTranslation(['reports'])
  const query = useRebalanceSuggestions()
  const rows = (query.data ?? []).filter((row) => row.deficits.length > 0 && row.surpluses.length > 0).slice(0, 5)

  return (
    <section className={tokens.card.base} aria-labelledby="rebalance-alerts-title">
      <h3 id="rebalance-alerts-title" className={`text-lg font-medium ${textColors.primary}`}>{t('reports:ownerDashboard.rebalanceAlerts.title')}</h3>
      {rows.length === 0 ? (
        <p className={`py-6 text-sm ${textColors.tertiary}`}>{query.isLoading ? t('reports:ownerDashboard.loading') : t('reports:ownerDashboard.rebalanceAlerts.empty')}</p>
      ) : (
        <ul className="mt-4 space-y-2">
          {rows.map((row) => (
            <li key={`${row.product_id}:${row.variant_id ?? ''}`} className="flex items-center justify-between gap-3 text-sm">
              <span className={textColors.secondary}>{row.name}</span>
              <span className={textColors.primary}>{formatQuantity(row.deficits[0]?.available ?? '0')}</span>
            </li>
          ))}
        </ul>
      )}
      <Link to="/inventory/stock-by-location" className={`mt-4 inline-flex items-center gap-1 text-sm ${textColors.brand}`}>
        {t('reports:ownerDashboard.rebalanceAlerts.view')} <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
      </Link>
    </section>
  )
}
