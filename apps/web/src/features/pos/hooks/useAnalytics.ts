import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  fetchSalesSummary,
  fetchSalesByCategory,
  fetchSalesByProduct,
  fetchSalesByPeriod,
  fetchCashierPerformance,
  fetchDiscountAnalysis,
  fetchCustomerAnalytics,
  fetchFnbMetrics,
  type AnalyticsFilters,
} from '../api/analyticsApi'

export const analyticsKeys = {
  all: ['pos', 'analytics'] as const,
  summary: (filters: AnalyticsFilters) => [...analyticsKeys.all, 'summary', filters] as const,
  salesByCategory: (filters: AnalyticsFilters) =>
    [...analyticsKeys.all, 'sales-by-category', filters] as const,
  salesByProduct: (filters: AnalyticsFilters) =>
    [...analyticsKeys.all, 'sales-by-product', filters] as const,
  salesByPeriod: (filters: AnalyticsFilters, granularity: string) =>
    [...analyticsKeys.all, 'sales-by-period', filters, granularity] as const,
  cashiers: (filters: AnalyticsFilters) => [...analyticsKeys.all, 'cashiers', filters] as const,
  discounts: (filters: AnalyticsFilters) => [...analyticsKeys.all, 'discounts', filters] as const,
  customers: (filters: AnalyticsFilters) => [...analyticsKeys.all, 'customers', filters] as const,
  fnb: (filters: AnalyticsFilters) => [...analyticsKeys.all, 'fnb', filters] as const,
}

function usePosAnalyticsTenantScope(): boolean {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return tenantId !== null && companyId !== null
}

export function useSalesSummary(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...analyticsKeys.summary(filters)]),
    queryFn: () => fetchSalesSummary(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useSalesByCategory(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...analyticsKeys.salesByCategory(filters)]),
    queryFn: () => fetchSalesByCategory(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useSalesByProduct(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...analyticsKeys.salesByProduct(filters)]),
    queryFn: () => fetchSalesByProduct(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useSalesByPeriod(filters: AnalyticsFilters, granularity = 'day') {
  const hasTenantScope = usePosAnalyticsTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...analyticsKeys.salesByPeriod(filters, granularity)]),
    queryFn: () => fetchSalesByPeriod(filters, granularity),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useCashierPerformance(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...analyticsKeys.cashiers(filters)]),
    queryFn: () => fetchCashierPerformance(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useDiscountAnalysis(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...analyticsKeys.discounts(filters)]),
    queryFn: () => fetchDiscountAnalysis(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useCustomerAnalytics(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...analyticsKeys.customers(filters)]),
    queryFn: () => fetchCustomerAnalytics(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useFnbMetrics(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...analyticsKeys.fnb(filters)]),
    queryFn: () => fetchFnbMetrics(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}
