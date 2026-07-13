import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { textColors } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import { CashPositionWidget } from '@/features/treasury/components/CashPositionWidget'
import { BranchLeaderboard } from './components/BranchLeaderboard'
import { CashRegisterReconciliationTable } from './components/CashRegisterReconciliationTable'
import { LiveSalesFeed } from './components/LiveSalesFeed'
import { LowStockAlertsList } from './components/LowStockAlertsList'
import { OwnerDashboardFilters, type OwnerDashboardFiltersValue } from './components/OwnerDashboardFilters'
import { PaymentMethodBreakdownPie } from './components/PaymentMethodBreakdownPie'
import { RevenueByCategoryDonut } from './components/RevenueByCategoryDonut'
import { SalesSummaryCards } from './components/SalesSummaryCards'
import { SalesTrendChart } from './components/SalesTrendChart'
import { TopSkusWidget } from './components/TopSkusWidget'
import {
  useCashRegisterReconciliation,
  useLowStockAlerts,
  usePaymentMethodBreakdown,
  useRevenueByCategory,
  useSalesByLocation,
  useSalesSummary,
  useTopSkus,
} from './hooks/useOwnerReports'

function defaultFilters(): OwnerDashboardFiltersValue {
  const today = formatDateInput(new Date())

  return {
    from: today,
    to: today,
    granularity: 'hour',
    locationIds: [],
  }
}

function formatDateInput(date: Date): string {
  const year = String(date.getFullYear())
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

function shiftDateInput(value: string, days: number): string {
  const date = new Date(`${value}T00:00:00`)
  date.setDate(date.getDate() + days)

  return formatDateInput(date)
}

function isHourlySingleDay(filters: OwnerDashboardFiltersValue): boolean {
  return filters.granularity === 'hour' && filters.from === filters.to
}

function dateRangeIncludesToday(from: string, to: string): boolean {
  const today = formatDateInput(new Date())
  return from <= today && today <= to
}

export function OwnerDashboardPage() {
  const { t } = useTranslation(['reports'])
  const { hasPermission } = usePermissions()
  const [filters, setFilters] = useState<OwnerDashboardFiltersValue>(() => defaultFilters())
  const [topSkuSortBy, setTopSkuSortBy] = useState<'revenue' | 'quantity'>('revenue')
  const [paymentMode, setPaymentMode] = useState<'amount' | 'percentage'>('amount')

  const canViewOwnerDashboard = hasPermission('dashboard.owner')
  const isLiveRange = dateRangeIncludesToday(filters.from, filters.to)

  const dateParams = useMemo(
    () => ({
      from: filters.from,
      to: filters.to,
      ...(filters.locationIds.length > 0 ? { location_ids: filters.locationIds } : {}),
    }),
    [filters.from, filters.to, filters.locationIds],
  )

  const stockParams = useMemo(
    () => ({
      threshold_pct: 100,
      ...(filters.locationIds.length > 0 ? { location_ids: filters.locationIds } : {}),
    }),
    [filters.locationIds],
  )

  const summary = useSalesSummary(dateParams, canViewOwnerDashboard)
  const sales = useSalesByLocation(
    { ...dateParams, granularity: filters.granularity },
    canViewOwnerDashboard,
  )
  const comparisonDateParams = useMemo(
    () => ({
      from: shiftDateInput(filters.from, -7),
      to: shiftDateInput(filters.to, -7),
      ...(filters.locationIds.length > 0 ? { location_ids: filters.locationIds } : {}),
    }),
    [filters.from, filters.to, filters.locationIds],
  )
  const comparisonSales = useSalesByLocation(
    { ...comparisonDateParams, granularity: 'hour' },
    canViewOwnerDashboard && isHourlySingleDay(filters),
  )
  const topSkus = useTopSkus(
    { ...dateParams, limit: 20, sort_by: topSkuSortBy },
    canViewOwnerDashboard,
  )
  const categories = useRevenueByCategory(dateParams, canViewOwnerDashboard)
  const payments = usePaymentMethodBreakdown(dateParams, canViewOwnerDashboard)
  const stockAlerts = useLowStockAlerts(stockParams, canViewOwnerDashboard)
  const cash = useCashRegisterReconciliation(dateParams, canViewOwnerDashboard)

  if (!canViewOwnerDashboard) {
    return null
  }

  return (
    <section className="space-y-4">
      <div>
        <h2 className={`text-xl font-semibold ${textColors.primary}`}>{t('reports:ownerDashboard.title')}</h2>
        <p className={`text-sm ${textColors.tertiary}`}>{t('reports:ownerDashboard.subtitle')}</p>
      </div>
      <OwnerDashboardFilters value={filters} onChange={setFilters} />
      <SalesSummaryCards data={summary.data} isLoading={summary.isLoading} isError={summary.isError} isLive={isLiveRange} />
      <div className="grid gap-4 xl:grid-cols-3">
        <div className="xl:col-span-2">
          <SalesTrendChart
            data={sales.data ?? []}
            comparisonData={comparisonSales.data ?? []}
            granularity={filters.granularity}
            isLoading={sales.isLoading}
            isError={sales.isError}
          />
        </div>
        <BranchLeaderboard canFetch={canViewOwnerDashboard} />
      </div>
      <div className="grid gap-4 xl:grid-cols-3">
        <LiveSalesFeed canFetch={canViewOwnerDashboard} />
        <TopSkusWidget data={topSkus.data ?? []} sortBy={topSkuSortBy} onSortByChange={setTopSkuSortBy} />
        <RevenueByCategoryDonut data={categories.data ?? []} isLoading={categories.isLoading} isError={categories.isError} />
      </div>
      <div className="grid gap-4 xl:grid-cols-3">
        <PaymentMethodBreakdownPie
          data={payments.data ?? []}
          mode={paymentMode}
          onModeChange={setPaymentMode}
          isLoading={payments.isLoading}
          isError={payments.isError}
        />
        <LowStockAlertsList data={stockAlerts.data ?? []} />
        <CashRegisterReconciliationTable data={cash.data ?? []} />
        <CashPositionWidget />
      </div>
    </section>
  )
}
