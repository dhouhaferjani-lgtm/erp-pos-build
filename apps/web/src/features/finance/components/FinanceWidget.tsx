import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useFinanceSummary } from '../hooks/useFinanceSummary'
import { formatCurrency } from '@/lib/format'
import { textColors, borderColors } from '@/lib/designTokens'

/** Pick the signed-amount tone token (success when >= 0, error otherwise). */
function netIncomeToneClass(value: string | null | undefined): string {
  return parseFloat(value ?? '0') >= 0 ? textColors.success : textColors.error
}

export function FinanceWidget() {
  const { t } = useTranslation(['finance', 'common'])
  const { data, isLoading } = useFinanceSummary()

  if (isLoading) {
    return (
      <div className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
        <h3 className="mb-4 text-lg font-semibold">{t('finance:widget.title')}</h3>
        <div>{t('common:common.loading')}</div>
      </div>
    )
  }

  return (
    <div className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
      <div className="mb-4 flex items-center justify-between">
        <h3 className="text-lg font-semibold">{t('finance:widget.title')}</h3>
        <Link
          to="/finance/balance-sheet"
          className={`text-sm ${textColors.brand} ${textColors.hoverPrimary}`}
        >
          {t('finance:widget.viewReports')}
        </Link>
      </div>

      <div className="grid grid-cols-2 gap-4">
        {/* Total Assets */}
        <div className={`rounded border ${borderColors.light} p-4`}>
          <div className={`text-sm ${textColors.tertiary}`}>{t('finance:widget.totalAssets')}</div>
          <div className={`mt-1 text-right text-2xl font-bold tabular-nums ${textColors.primary}`}>
            {formatCurrency(data?.total_assets ?? '0')}
          </div>
        </div>

        {/* Total Liabilities */}
        <div className={`rounded border ${borderColors.light} p-4`}>
          <div className={`text-sm ${textColors.tertiary}`}>{t('finance:widget.totalLiabilities')}</div>
          <div className={`mt-1 text-right text-2xl font-bold tabular-nums ${textColors.primary}`}>
            {formatCurrency(data?.total_liabilities ?? '0')}
          </div>
        </div>

        {/* Net Income MTD */}
        <div className={`rounded border ${borderColors.light} p-4`}>
          <div className={`text-sm ${textColors.tertiary}`}>{t('finance:widget.netIncomeMtd')}</div>
          <div className={`mt-1 text-right text-2xl font-bold tabular-nums ${netIncomeToneClass(data?.net_income_mtd)}`}>
            {formatCurrency(data?.net_income_mtd ?? '0')}
          </div>
        </div>

        {/* Net Income YTD */}
        <div className={`rounded border ${borderColors.light} p-4`}>
          <div className={`text-sm ${textColors.tertiary}`}>{t('finance:widget.netIncomeYtd')}</div>
          <div className={`mt-1 text-right text-2xl font-bold tabular-nums ${netIncomeToneClass(data?.net_income_ytd)}`}>
            {formatCurrency(data?.net_income_ytd ?? '0')}
          </div>
        </div>

        {/* Accounts Receivable */}
        <div className={`rounded border ${borderColors.light} p-4`}>
          <div className={`text-sm ${textColors.tertiary}`}>{t('finance:widget.accountsReceivable')}</div>
          <div className={`mt-1 text-right text-2xl font-bold tabular-nums ${textColors.brand}`}>
            {formatCurrency(data?.accounts_receivable ?? '0')}
          </div>
        </div>

        {/* Accounts Payable */}
        <div className={`rounded border ${borderColors.light} p-4`}>
          <div className={`text-sm ${textColors.tertiary}`}>{t('finance:widget.accountsPayable')}</div>
          <div className={`mt-1 text-right text-2xl font-bold tabular-nums ${textColors.warningDark}`}>
            {formatCurrency(data?.accounts_payable ?? '0')}
          </div>
        </div>
      </div>
    </div>
  )
}
