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
  isLive?: boolean
}

export function SalesSummaryCards({ data, isLoading, isError, isLive = false }: SalesSummaryCardsProps) {
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

  // O-28 (owner ruling 2026-08-21): the headline figure is NET, EXCLUDING REFUNDS —
  // and so is its trend badge. `grossSales`/`delta.grossSalesPct` stay on the DTO for
  // surfaces that report gross explicitly; this tile must not blend the two.
  const netSalesTrend = pct(data.delta.netSalesPct)
  const salesCountTrend = pct(data.delta.salesCountPct)

  return (
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
      <div className="relative">
        <StatCard
          label={t('reports:ownerDashboard.kpi.netSales')}
          value={formatCurrency(data.netSales, true, currency)}
          icon={ShoppingBag}
          {...(netSalesTrend ? { trend: netSalesTrend } : {})}
        />
        {isLive && (
          <span className={`absolute end-4 top-4 inline-flex items-center gap-1 text-xs font-medium ${textColors.success}`}>
            <span className={`h-2 w-2 animate-pulse rounded-full ${colors.success[600]}`} />
            {t('reports:ownerDashboard.kpi.live')}
          </span>
        )}
      </div>
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
