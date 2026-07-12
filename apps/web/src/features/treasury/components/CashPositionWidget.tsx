import { ArrowDownLeft, ArrowRight, ArrowUpRight, WalletCards } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import { usePermissions } from '@/hooks/usePermissions'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { type CashPositionRepositoryType, useCashPosition } from '../hooks/useCashPosition'

const REPOSITORY_TYPES: readonly {
  type: CashPositionRepositoryType
  labelKey: string
}[] = [
  { type: 'cash_register', labelKey: 'cashWidget.types.cashRegisters' },
  { type: 'bank_account', labelKey: 'cashWidget.types.bankAccounts' },
  { type: 'safe', labelKey: 'cashWidget.types.safes' },
]

function CashPositionWidgetContent() {
  const { t } = useTranslation('treasury')
  const positionQuery = useCashPosition({ flowsWindow: 7 })
  const position = positionQuery.data

  return (
    <section className={tokens.card.base} aria-labelledby="cash-position-widget-title">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 id="cash-position-widget-title" className={`text-lg font-medium ${textColors.primary}`}>
            {t('cashWidget.title')}
          </h2>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>{t('cashWidget.subtitle')}</p>
        </div>
        <WalletCards className={`h-5 w-5 shrink-0 ${textColors.brand}`} aria-hidden="true" />
      </div>

      {position === undefined ? (
        <p className={`py-8 text-center text-sm ${textColors.tertiary}`}>
          {positionQuery.isError ? t('cashWidget.unavailable') : t('cashWidget.loading')}
        </p>
      ) : (
        <>
          <p className={`mt-5 text-3xl font-semibold ${textColors.primary}`}>
            {formatCurrency(position.grand_total, { currency: position.currency })}
          </p>

          <div className={`mt-5 divide-y ${borderColors.divideLight}`}>
            {REPOSITORY_TYPES.map(({ type, labelKey }) => {
              const group = position.groups.find((candidate) => candidate.type === type)

              return (
                <div key={type} className="flex items-center justify-between gap-3 py-2 text-sm">
                  <span className={textColors.secondary}>
                    {t(labelKey, { count: group?.repositories.length ?? 0 })}
                  </span>
                  <span className={`font-medium ${textColors.primary}`}>
                    {formatCurrency(group?.total ?? '0', { currency: position.currency })}
                  </span>
                </div>
              )
            })}
          </div>

          {position.flows ? (
            <div className={`mt-4 border-t pt-4 ${borderColors.light}`}>
              <p className={`mb-2 text-xs font-medium uppercase tracking-wide ${textColors.tertiary}`}>
                {t('cashWidget.window', { days: position.flows.window_days })}
              </p>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <span className={`flex items-center gap-1 text-xs ${textColors.tertiary}`}>
                    <ArrowDownLeft className={`h-3.5 w-3.5 ${textColors.success}`} aria-hidden="true" />
                    {t('cashWidget.in')}
                  </span>
                  <p className={`mt-1 text-sm font-semibold ${textColors.success}`}>
                    {formatCurrency(position.flows.in, { currency: position.currency })}
                  </p>
                </div>
                <div>
                  <span className={`flex items-center gap-1 text-xs ${textColors.tertiary}`}>
                    <ArrowUpRight className={`h-3.5 w-3.5 ${textColors.error}`} aria-hidden="true" />
                    {t('cashWidget.out')}
                  </span>
                  <p className={`mt-1 text-sm font-semibold ${textColors.error}`}>
                    {formatCurrency(position.flows.out, { currency: position.currency })}
                  </p>
                </div>
              </div>
            </div>
          ) : null}
        </>
      )}

      <div className={`mt-5 flex flex-wrap gap-x-4 gap-y-2 border-t pt-4 ${borderColors.light}`}>
        <Link to="/finance/overview" className={`inline-flex items-center gap-1 text-sm font-medium ${textColors.brand} ${textColors.hoverBrand}`}>
          {t('cashWidget.viewOverview')}
          <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
        </Link>
        <Link to="/finance/cash-movements" className={`inline-flex items-center gap-1 text-sm font-medium ${textColors.brand} ${textColors.hoverBrand}`}>
          {t('cashWidget.viewMovements')}
          <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
        </Link>
      </div>
    </section>
  )
}

export function CashPositionWidget() {
  const { canAccessModule } = usePermissions()
  const { hasModule } = useCompanyConfig()

  if (!canAccessModule('treasury') || !hasModule('Treasury')) {
    return null
  }

  return <CashPositionWidgetContent />
}
