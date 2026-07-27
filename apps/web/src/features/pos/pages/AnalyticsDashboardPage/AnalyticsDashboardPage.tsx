import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useCompanyConfig } from '@/contexts'
import type { AnalyticsFilters } from '../../api/analyticsApi'
import {
  useSalesSummary,
  useSalesByCategory,
  useSalesByProduct,
  useSalesByPeriod,
  useCashierPerformance,
  useDiscountAnalysis,
  useCustomerAnalytics,
  useFnbMetrics,
} from '../../hooks/useAnalytics'
import { AnalyticsDateFilter } from '../../organisms/Analytics/AnalyticsDateFilter'
import { SalesSummaryCards } from '../../organisms/Analytics/SalesSummaryCards'
import { SalesTimeSeriesChart } from '../../organisms/Analytics/SalesTimeSeriesChart'
import { SalesByCategoryChart } from '../../organisms/Analytics/SalesByCategoryChart'
import { SalesByProductTable } from '../../organisms/Analytics/SalesByProductTable'
import { CashierComparisonChart } from '../../organisms/Analytics/CashierComparisonChart'
import { DiscountBreakdownChart } from '../../organisms/Analytics/DiscountBreakdownChart'
import { CustomerInsightsPanel } from '../../organisms/Analytics/CustomerInsightsPanel'
import { FnbMetricsPanel } from '../../organisms/Analytics/FnbMetricsPanel'
import { cn } from '@/lib/utils'
import { textColors, borderColors } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { useViewScope } from '@/features/locations/hooks/useViewScope'

type Tab = 'summary' | 'products' | 'cashiers' | 'discounts' | 'customers' | 'fnb'

const FNB_VERTICALS = ['restaurant', 'coffee_shop']

function getDefaultFilters(): AnalyticsFilters {
  const today = new Date()
  const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1)
  return {
    from: startOfMonth.toISOString().split('T')[0],
    to: today.toISOString().split('T')[0],
  }
}

export function AnalyticsDashboardPage() {
  const { t } = useTranslation(['pos'])
  const { config } = useCompanyConfig()
  const isFnb = FNB_VERTICALS.includes(config?.vertical ?? '')
  const tabs: Tab[] = useMemo(
    () => isFnb
      ? ['summary', 'products', 'cashiers', 'discounts', 'customers', 'fnb']
      : ['summary', 'products', 'cashiers', 'discounts', 'customers'],
    [isFnb],
  )
  const [activeTab, setActiveTab] = useState<Tab>('summary')
  const [filters, setFilters] = useState<AnalyticsFilters>(getDefaultFilters)
  const { effectiveLocationIds } = useViewScope()
  const scopedFilters = useMemo(
    () => ({ ...filters, location_ids: effectiveLocationIds }),
    [filters, effectiveLocationIds],
  )

  const summaryQuery = useSalesSummary(scopedFilters)
  const periodQuery = useSalesByPeriod(scopedFilters)
  const categoryQuery = useSalesByCategory(scopedFilters)
  const productQuery = useSalesByProduct(scopedFilters)
  const cashierQuery = useCashierPerformance(scopedFilters)
  const discountQuery = useDiscountAnalysis(scopedFilters)
  const customerQuery = useCustomerAnalytics(scopedFilters)
  const fnbQuery = useFnbMetrics(scopedFilters)

  const tabContent = useMemo(() => {
    switch (activeTab) {
      case 'summary':
        return (
          <div className="space-y-6">
            {summaryQuery.data && <SalesSummaryCards data={summaryQuery.data} />}
            {periodQuery.data && <SalesTimeSeriesChart data={periodQuery.data} />}
            {summaryQuery.isLoading && <LoadingPlaceholder />}
          </div>
        )
      case 'products':
        return (
          <div className="grid gap-6 lg:grid-cols-2">
            <div>{categoryQuery.data && <SalesByCategoryChart data={categoryQuery.data} />}</div>
            <div>{productQuery.data && <SalesByProductTable data={productQuery.data} />}</div>
            {categoryQuery.isLoading && <LoadingPlaceholder />}
          </div>
        )
      case 'cashiers':
        return cashierQuery.data ? (
          <CashierComparisonChart data={cashierQuery.data} />
        ) : cashierQuery.isLoading ? (
          <LoadingPlaceholder />
        ) : null
      case 'discounts':
        return discountQuery.data ? (
          <DiscountBreakdownChart data={discountQuery.data} />
        ) : discountQuery.isLoading ? (
          <LoadingPlaceholder />
        ) : null
      case 'customers':
        return customerQuery.data ? (
          <CustomerInsightsPanel data={customerQuery.data} />
        ) : customerQuery.isLoading ? (
          <LoadingPlaceholder />
        ) : null
      case 'fnb':
        return fnbQuery.data ? (
          <FnbMetricsPanel data={fnbQuery.data} />
        ) : fnbQuery.isLoading ? (
          <LoadingPlaceholder />
        ) : null
    }
  }, [activeTab, summaryQuery, periodQuery, categoryQuery, productQuery, cashierQuery, discountQuery, customerQuery, fnbQuery])

  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <PageHeaderTitle className="text-2xl font-bold">{t('pos:analytics.title')}</PageHeaderTitle>
        <AnalyticsDateFilter filters={filters} onChange={setFilters} />
      </div>

      <div className={cn('border-b', borderColors.light)}>
        <nav className="-mb-px flex gap-4 overflow-x-auto">
          {tabs.map((tab) => (
            <button
              key={tab}
              type="button"
              onClick={() => { setActiveTab(tab); }}
              className={cn(
                'whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                activeTab === tab
                  ? cn(borderColors.primary, textColors.brand)
                  : cn('border-transparent', textColors.tertiary, textColors.hoverPrimary),
              )}
            >
              {t(`pos:analytics.tabs.${tab}`)}
            </button>
          ))}
        </nav>
      </div>

      {tabContent}
    </div>
  )
}

function LoadingPlaceholder() {
  return (
    <div className="flex items-center justify-center py-12">
      <div className="border-primary h-8 w-8 animate-spin rounded-full border-2 border-t-transparent" />
    </div>
  )
}
