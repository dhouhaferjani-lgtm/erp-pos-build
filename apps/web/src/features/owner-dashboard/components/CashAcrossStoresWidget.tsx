import { ArrowRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

import { useCashPosition } from '@/features/treasury/hooks/useCashPosition'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'

export function CashAcrossStoresWidget() {
  const { t } = useTranslation(['reports'])
  const query = useCashPosition({ flowsWindow: 7 })
  const position = query.data

  return (
    <section className={tokens.card.base} aria-labelledby="cash-across-stores-title">
      <h3 id="cash-across-stores-title" className={`text-lg font-medium ${textColors.primary}`}>{t('reports:ownerDashboard.cashAcrossStores.title')}</h3>
      {position === undefined ? (
        <p className={`py-6 text-sm ${textColors.tertiary}`}>{query.isError ? t('reports:ownerDashboard.error') : t('reports:ownerDashboard.loading')}</p>
      ) : (
        <>
          <div className={`mt-4 divide-y ${borderColors.divideLight}`}>
            {position.groups_by_location?.map((group) => (
              <div key={group.location_id ?? 'unattributed'} className="flex items-center justify-between gap-3 py-2 text-sm">
                <span className={textColors.secondary}>{group.location_name}</span>
                <span className={textColors.primary}>{formatCurrency(group.total, { currency: position.currency })}</span>
              </div>
            ))}
          </div>
          <p className={`mt-4 border-t pt-3 text-sm font-semibold ${borderColors.light} ${textColors.primary}`}>
            {t('reports:ownerDashboard.cashAcrossStores.total')}: {formatCurrency(position.grand_total, { currency: position.currency })}
          </p>
        </>
      )}
      <Link to="/finance/overview" className={`mt-4 inline-flex items-center gap-1 text-sm ${textColors.brand}`}>
        {t('reports:ownerDashboard.cashAcrossStores.view')} <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
      </Link>
    </section>
  )
}
