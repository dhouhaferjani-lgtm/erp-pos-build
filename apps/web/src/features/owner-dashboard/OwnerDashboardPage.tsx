import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { textColors } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import { CashRegisterReconciliationTable } from './components/CashRegisterReconciliationTable'
import { LowStockAlertsList } from './components/LowStockAlertsList'
import { OwnerDashboardFilters, type OwnerDashboardFiltersValue } from './components/OwnerDashboardFilters'
import { PaymentMethodBreakdownPie } from './components/PaymentMethodBreakdownPie'
import { RevenueByCategoryDonut } from './components/RevenueByCategoryDonut'
import { SalesByLocationChart } from './components/SalesByLocationChart'
import { TopSkusWidget } from './components/TopSkusWidget'
import {
  useCashRegisterReconciliation,
  useLowStockAlerts,
  usePaymentMethodBreakdown,
  useRevenueByCategory,
  useSalesByLocation,
  useTopSkus,
} from './hooks/useOwnerReports'

function defaultFilters(): OwnerDashboardFiltersValue {
  const to = new Date()
  const from = new Date()
  from.setDate(to.getDate() - 29)

  return {
    from: from.toISOString().slice(0, 10),
    to: to.toISOString().slice(0, 10),
    granularity: 'day',
  }
}

export function OwnerDashboardPage() {
  const { t } = useTranslation(['reports'])
  const { hasPermission } = usePermissions()
  const [filters, setFilters] = useState<OwnerDashboardFiltersValue>(() => defaultFilters())
  const [topSkuSortBy, setTopSkuSortBy] = useState<'revenue' | 'quantity'>('revenue')
  const [paymentMode, setPaymentMode] = useState<'amount' | 'percentage'>('amount')

  const canViewOwnerDashboard = hasPermission('dashboard.owner')
  const dateParams = useMemo(() => ({ from: filters.from, to: filters.to }), [filters.from, filters.to])
  const sales = useSalesByLocation({ ...dateParams, granularity: filters.granularity }, canViewOwnerDashboard)
  const topSkus = useTopSkus({ ...dateParams, limit: 20, sort_by: topSkuSortBy }, canViewOwnerDashboard)
  const categories = useRevenueByCategory(dateParams, canViewOwnerDashboard)
  const payments = usePaymentMethodBreakdown(dateParams, canViewOwnerDashboard)
  const stockAlerts = useLowStockAlerts({ threshold_pct: 100 }, canViewOwnerDashboard)
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
      <div className="grid gap-4 xl:grid-cols-2">
        <SalesByLocationChart data={sales.data ?? []} isLoading={sales.isLoading} isError={sales.isError} />
        <RevenueByCategoryDonut data={categories.data ?? []} isLoading={categories.isLoading} isError={categories.isError} />
        <PaymentMethodBreakdownPie
          data={payments.data ?? []}
          mode={paymentMode}
          onModeChange={setPaymentMode}
          isLoading={payments.isLoading}
          isError={payments.isError}
        />
        <TopSkusWidget data={topSkus.data ?? []} sortBy={topSkuSortBy} onSortByChange={setTopSkuSortBy} />
        <LowStockAlertsList data={stockAlerts.data ?? []} />
        <CashRegisterReconciliationTable data={cash.data ?? []} />
      </div>
    </section>
  )
}
