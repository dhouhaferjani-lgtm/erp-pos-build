import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  fetchCashRegisterReconciliation,
  fetchLowStockAlerts,
  fetchPaymentMethodBreakdown,
  fetchRevenueByCategory,
  fetchSalesByLocation,
  fetchSalesSummary,
  fetchTopSkus,
  type CashReconciliationParams,
  type OwnerDateRangeParams,
  type SalesByLocationParams,
  type StockAlertsParams,
  type TopSkusParams,
} from '../api/ownerReportsApi'

export const ownerReportKeys = {
  all: ['owner-reports'] as const,
  salesByLocation: (params: SalesByLocationParams) => [...ownerReportKeys.all, 'sales-by-location', params] as const,
  topSkus: (params: TopSkusParams) => [...ownerReportKeys.all, 'top-skus', params] as const,
  revenueByCategory: (params: OwnerDateRangeParams) => [...ownerReportKeys.all, 'revenue-by-category', params] as const,
  paymentMethods: (params: OwnerDateRangeParams) => [...ownerReportKeys.all, 'payment-methods', params] as const,
  stockAlerts: (params: StockAlertsParams) => [...ownerReportKeys.all, 'stock-alerts', params] as const,
  cashReconciliation: (params: CashReconciliationParams) => [...ownerReportKeys.all, 'cash-reconciliation', params] as const,
  salesSummary: (params: OwnerDateRangeParams) => [...ownerReportKeys.all, 'sales-summary', params] as const,
}

function useOwnerReportsEnabled(): boolean {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId)

  return tenantId !== null && companyId !== null
}

export function useSalesByLocation(params: SalesByLocationParams, canFetch = true) {
  const enabled = useOwnerReportsEnabled() && canFetch

  return useQuery({
    queryKey: tenantScopedKey(ownerReportKeys.salesByLocation(params)),
    queryFn: () => fetchSalesByLocation(params),
    enabled,
  })
}

export function useTopSkus(params: TopSkusParams, canFetch = true) {
  const enabled = useOwnerReportsEnabled() && canFetch

  return useQuery({
    queryKey: tenantScopedKey(ownerReportKeys.topSkus(params)),
    queryFn: () => fetchTopSkus(params),
    enabled,
  })
}

export function useRevenueByCategory(params: OwnerDateRangeParams, canFetch = true) {
  const enabled = useOwnerReportsEnabled() && canFetch

  return useQuery({
    queryKey: tenantScopedKey(ownerReportKeys.revenueByCategory(params)),
    queryFn: () => fetchRevenueByCategory(params),
    enabled,
  })
}

export function usePaymentMethodBreakdown(params: OwnerDateRangeParams, canFetch = true) {
  const enabled = useOwnerReportsEnabled() && canFetch

  return useQuery({
    queryKey: tenantScopedKey(ownerReportKeys.paymentMethods(params)),
    queryFn: () => fetchPaymentMethodBreakdown(params),
    enabled,
  })
}

export function useLowStockAlerts(params: StockAlertsParams, canFetch = true) {
  const enabled = useOwnerReportsEnabled() && canFetch

  return useQuery({
    queryKey: tenantScopedKey(ownerReportKeys.stockAlerts(params)),
    queryFn: () => fetchLowStockAlerts(params),
    enabled,
  })
}

export function useCashRegisterReconciliation(params: CashReconciliationParams, canFetch = true) {
  const enabled = useOwnerReportsEnabled() && canFetch

  return useQuery({
    queryKey: tenantScopedKey(ownerReportKeys.cashReconciliation(params)),
    queryFn: () => fetchCashRegisterReconciliation(params),
    enabled,
  })
}

export function useSalesSummary(params: OwnerDateRangeParams, canFetch = true) {
  const enabled = useOwnerReportsEnabled() && canFetch

  return useQuery({
    queryKey: tenantScopedKey(ownerReportKeys.salesSummary(params)),
    queryFn: () => fetchSalesSummary(params),
    enabled,
  })
}
