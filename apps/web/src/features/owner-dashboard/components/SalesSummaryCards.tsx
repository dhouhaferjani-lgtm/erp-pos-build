import { useTranslation } from 'react-i18next'
import { ShoppingBag, Receipt, Wallet, Package, Undo2 } from 'lucide-react'
import { StatCard } from '@/components/ui/StatCard'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { formatCurrency, formatQuantity } from '@/lib/decimal'
import type { SalesSummaryReport } from '../api/ownerReportsApi'

interface SalesSummaryCardsProps {
  data: SalesSummaryReport | undefined
  isLoading: boolean
  isError: boolean
}

export function SalesSummaryCards({ data, isLoading, isError }: SalesSummaryCardsProps) {
  const { t } = useTranslation(['reports'])

  if (isLoading) {
    return (
      <div data-testid="kpi-skeleton" className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        {Array.from({ length: 5 }).map((_, i) => (
          <div
            key={i}
            className={`h-28 animate-pulse rounded-lg border ${borderColors.light} ${colors.white}`}
          />
        ))}
      </div>
    )
  }

  if (isError || !data) {
    return (
      <div
        className={`rounded-lg border ${borderColors.light} ${colors.white} p-6 text-sm ${textColors.tertiary}`}
      >
        {t('reports:ownerDashboard.kpi.error')}
      </div>
    )
  }

  const currency = data.currencyCode || 'EUR'
  const pct = (v: string | null) =>
    v === null
      ? undefined
      : {
          value: Number(v),
          label: t('reports:ownerDashboard.kpi.vsPrevious'),
          isPositive: Number(v) >= 0,
        }

  const grossSalesTrend = pct(data.delta.grossSalesPct)
  const salesCountTrend = pct(data.delta.salesCountPct)

  return (
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
      <StatCard
        label={t('reports:ownerDashboard.kpi.totalSales')}
        value={formatCurrency(data.grossSales, true, currency)}
        icon={ShoppingBag}
        {...(grossSalesTrend ? { trend: grossSalesTrend } : {})}
      />
      <StatCard
        label={t('reports:ownerDashboard.kpi.transactions')}
        value={String(data.salesCount)}
        icon={Receipt}
        {...(salesCountTrend ? { trend: salesCountTrend } : {})}
      />
      <StatCard
        label={t('reports:ownerDashboard.kpi.avgBasket')}
        value={data.averageBasket ? formatCurrency(data.averageBasket, true, currency) : '—'}
        icon={Wallet}
      />
      <StatCard
        label={t('reports:ownerDashboard.kpi.itemsSold')}
        value={formatQuantity(data.itemsSold)}
        icon={Package}
      />
      <StatCard
        label={t('reports:ownerDashboard.kpi.returns')}
        value={formatCurrency(data.returnsAmount, true, currency)}
        icon={Undo2}
      />
    </div>
  )
}
