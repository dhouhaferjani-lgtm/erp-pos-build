import { useQuery } from '@tanstack/react-query'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
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

function useAnalyticsScope(): 'all' | string[] {
  return useViewScope().scope
}

export function useSalesSummary(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()
  const scope = useAnalyticsScope()

  return useQuery({
    queryKey: locationScopedKey([...analyticsKeys.summary(filters)], scope),
    queryFn: () => fetchSalesSummary(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useSalesByCategory(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()
  const scope = useAnalyticsScope()

  return useQuery({
    queryKey: locationScopedKey([...analyticsKeys.salesByCategory(filters)], scope),
    queryFn: () => fetchSalesByCategory(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useSalesByProduct(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()
  const scope = useAnalyticsScope()

  return useQuery({
    queryKey: locationScopedKey([...analyticsKeys.salesByProduct(filters)], scope),
    queryFn: () => fetchSalesByProduct(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useSalesByPeriod(filters: AnalyticsFilters, granularity = 'day') {
  const hasTenantScope = usePosAnalyticsTenantScope()
  const scope = useAnalyticsScope()

  return useQuery({
    queryKey: locationScopedKey([...analyticsKeys.salesByPeriod(filters, granularity)], scope),
    queryFn: () => fetchSalesByPeriod(filters, granularity),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useCashierPerformance(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()
  const scope = useAnalyticsScope()

  return useQuery({
    queryKey: locationScopedKey([...analyticsKeys.cashiers(filters)], scope),
    queryFn: () => fetchCashierPerformance(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useDiscountAnalysis(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()
  const scope = useAnalyticsScope()

  return useQuery({
    queryKey: locationScopedKey([...analyticsKeys.discounts(filters)], scope),
    queryFn: () => fetchDiscountAnalysis(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useCustomerAnalytics(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()
  const scope = useAnalyticsScope()

  return useQuery({
    queryKey: locationScopedKey([...analyticsKeys.customers(filters)], scope),
    queryFn: () => fetchCustomerAnalytics(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}

export function useFnbMetrics(filters: AnalyticsFilters) {
  const hasTenantScope = usePosAnalyticsTenantScope()
  const scope = useAnalyticsScope()

  return useQuery({
    queryKey: locationScopedKey([...analyticsKeys.fnb(filters)], scope),
    queryFn: () => fetchFnbMetrics(filters),
    enabled: !!filters.from && !!filters.to && hasTenantScope,
  })
}
