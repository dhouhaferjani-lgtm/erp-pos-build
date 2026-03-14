import { useQuery } from '@tanstack/react-query'
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

export function useSalesSummary(filters: AnalyticsFilters) {
  return useQuery({
    queryKey: analyticsKeys.summary(filters),
    queryFn: () => fetchSalesSummary(filters),
    enabled: !!filters.from && !!filters.to,
  })
}

export function useSalesByCategory(filters: AnalyticsFilters) {
  return useQuery({
    queryKey: analyticsKeys.salesByCategory(filters),
    queryFn: () => fetchSalesByCategory(filters),
    enabled: !!filters.from && !!filters.to,
  })
}

export function useSalesByProduct(filters: AnalyticsFilters) {
  return useQuery({
    queryKey: analyticsKeys.salesByProduct(filters),
    queryFn: () => fetchSalesByProduct(filters),
    enabled: !!filters.from && !!filters.to,
  })
}

export function useSalesByPeriod(filters: AnalyticsFilters, granularity = 'day') {
  return useQuery({
    queryKey: analyticsKeys.salesByPeriod(filters, granularity),
    queryFn: () => fetchSalesByPeriod(filters, granularity),
    enabled: !!filters.from && !!filters.to,
  })
}

export function useCashierPerformance(filters: AnalyticsFilters) {
  return useQuery({
    queryKey: analyticsKeys.cashiers(filters),
    queryFn: () => fetchCashierPerformance(filters),
    enabled: !!filters.from && !!filters.to,
  })
}

export function useDiscountAnalysis(filters: AnalyticsFilters) {
  return useQuery({
    queryKey: analyticsKeys.discounts(filters),
    queryFn: () => fetchDiscountAnalysis(filters),
    enabled: !!filters.from && !!filters.to,
  })
}

export function useCustomerAnalytics(filters: AnalyticsFilters) {
  return useQuery({
    queryKey: analyticsKeys.customers(filters),
    queryFn: () => fetchCustomerAnalytics(filters),
    enabled: !!filters.from && !!filters.to,
  })
}

export function useFnbMetrics(filters: AnalyticsFilters) {
  return useQuery({
    queryKey: analyticsKeys.fnb(filters),
    queryFn: () => fetchFnbMetrics(filters),
    enabled: !!filters.from && !!filters.to,
  })
}
