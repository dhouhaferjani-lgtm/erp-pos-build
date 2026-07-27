import { apiGet } from '@/lib/api'

export interface AnalyticsFilters {
  from: string
  to: string
  location_ids?: string[]
}

export interface PaymentBreakdownItem {
  payment_type: string
  total: string
  count: number
}

export interface SalesSummary {
  receipt_count: number
  gross_sales: string
  net_sales: string
  tax_total: string
  average_ticket: string
  refund_count: number
  refund_total: string
  voided_count: number
  payment_breakdown: PaymentBreakdownItem[]
}

export interface CategorySales {
  category_name: string
  total: string
  count: number
}

export interface ProductSales {
  product_name: string
  total: string
  quantity: string
}

export interface PeriodSales {
  period: string
  total: string
  count: number
}

export interface CashierPerformance {
  cashier_id: string
  cashier_name: string
  receipt_count: number
  total_sales: string
  average_ticket: string
}

export interface DiscountAnalysis {
  total_discount_amount: string
  discount_count: number
  by_reason: Array<{ reason: string; total_amount: string; count: number }>
  top_discounted_products: Array<{ product_name: string; discount_amount: string; quantity: number }>
}

export interface CustomerAnalytics {
  unique_customers: number
  returning_count: number
  returning_rate: string
  top_customers: Array<{
    partner_id: string
    customer_name: string
    total_spent: string
    receipt_count: number
  }>
}

export interface FnbMetrics {
  avg_table_time_minutes: string
  avg_items_per_order: string
  peak_hours: Array<{ hour: number; order_count: number }>
  orders_by_mode: Array<{ mode: string; count: number }>
}

function buildParams(filters: AnalyticsFilters, extra?: Record<string, string>): string {
  const params = new URLSearchParams({ from: filters.from, to: filters.to })
  for (const id of filters.location_ids ?? []) params.append('location_ids[]', id)
  if (extra) {
    Object.entries(extra).forEach(([k, v]) => { params.set(k, v); })
  }
  return params.toString()
}

export async function fetchSalesSummary(filters: AnalyticsFilters): Promise<SalesSummary> {
  return apiGet<SalesSummary>(`/pos/analytics/summary?${buildParams(filters)}`)
}

export async function fetchSalesByCategory(filters: AnalyticsFilters): Promise<CategorySales[]> {
  return apiGet<CategorySales[]>(`/pos/analytics/sales-by-category?${buildParams(filters)}`)
}

export async function fetchSalesByProduct(
  filters: AnalyticsFilters,
  limit = 20,
): Promise<ProductSales[]> {
  return apiGet<ProductSales[]>(
    `/pos/analytics/sales-by-product?${buildParams(filters, { limit: String(limit) })}`,
  )
}

export async function fetchSalesByPeriod(
  filters: AnalyticsFilters,
  granularity = 'day',
): Promise<PeriodSales[]> {
  return apiGet<PeriodSales[]>(
    `/pos/analytics/sales-by-period?${buildParams(filters, { granularity })}`,
  )
}

export async function fetchCashierPerformance(
  filters: AnalyticsFilters,
): Promise<CashierPerformance[]> {
  return apiGet<CashierPerformance[]>(`/pos/analytics/cashiers?${buildParams(filters)}`)
}

export async function fetchDiscountAnalysis(filters: AnalyticsFilters): Promise<DiscountAnalysis> {
  return apiGet<DiscountAnalysis>(`/pos/analytics/discounts?${buildParams(filters)}`)
}

export async function fetchCustomerAnalytics(
  filters: AnalyticsFilters,
): Promise<CustomerAnalytics> {
  return apiGet<CustomerAnalytics>(`/pos/analytics/customers?${buildParams(filters)}`)
}

export async function fetchFnbMetrics(filters: AnalyticsFilters): Promise<FnbMetrics> {
  return apiGet<FnbMetrics>(`/pos/analytics/fnb?${buildParams(filters)}`)
}
