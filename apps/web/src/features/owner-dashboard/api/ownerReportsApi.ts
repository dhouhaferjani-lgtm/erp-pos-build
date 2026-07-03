import { apiGet } from '@/lib/api'

export interface OwnerDateRangeParams {
  from: string
  to: string
  location_ids?: string[]
  company_ids?: string[]
}

export interface SalesByLocationParams extends OwnerDateRangeParams {
  granularity?: 'hour' | 'day' | 'week' | 'month'
}

export interface TopSkusParams extends OwnerDateRangeParams {
  limit?: number
  sort_by?: 'revenue' | 'quantity'
}

export interface StockAlertsParams {
  company_id?: string
  location_ids?: string[]
  threshold_pct?: number
}

export type CashReconciliationParams = OwnerDateRangeParams

export type SalesByLocationReport = App.Modules.Accounting.Application.DTOs.Reports.SalesByLocationData
export type TopSkuReport = App.Modules.Accounting.Application.DTOs.Reports.TopSkuData
export type CategoryRevenueReport = App.Modules.Accounting.Application.DTOs.Reports.CategoryRevenueData
export type PaymentMethodBreakdownReport = App.Modules.Accounting.Application.DTOs.Reports.PaymentMethodBreakdownData
export type StockAlertReport = App.Modules.Accounting.Application.DTOs.Reports.StockAlertData
export type CashReconciliationReport = App.Modules.Accounting.Application.DTOs.Reports.CashReconciliationData
export type SalesSummaryReport = App.Modules.Accounting.Application.DTOs.Reports.SalesSummaryData

export interface LiveSaleReceipt {
  id: string
  posted_at: string
  location_id: string
  location_name: string
  total: string
  currency: string
  items_count: number
  receipt_number: string
}

export interface LiveSalesReport {
  recent_receipts: LiveSaleReceipt[]
  open_shifts_by_location: Record<string, number>
  generated_at: string
}

export async function fetchSalesByLocation(params: SalesByLocationParams): Promise<SalesByLocationReport[]> {
  return apiGet<SalesByLocationReport[]>(`/reports/sales/by-location?${buildParams(params)}`)
}

export async function fetchTopSkus(params: TopSkusParams): Promise<TopSkuReport[]> {
  return apiGet<TopSkuReport[]>(`/reports/sales/top-skus?${buildParams(params)}`)
}

export async function fetchRevenueByCategory(params: OwnerDateRangeParams): Promise<CategoryRevenueReport[]> {
  return apiGet<CategoryRevenueReport[]>(`/reports/sales/revenue-by-category?${buildParams(params)}`)
}

export async function fetchPaymentMethodBreakdown(params: OwnerDateRangeParams): Promise<PaymentMethodBreakdownReport[]> {
  return apiGet<PaymentMethodBreakdownReport[]>(`/reports/sales/payment-method-breakdown?${buildParams(params)}`)
}

export async function fetchLowStockAlerts(params: StockAlertsParams): Promise<StockAlertReport[]> {
  const query = buildParams(params)

  return apiGet<StockAlertReport[]>(`/reports/stock/alerts${query === '' ? '' : `?${query}`}`)
}

export async function fetchCashRegisterReconciliation(params: CashReconciliationParams): Promise<CashReconciliationReport[]> {
  return apiGet<CashReconciliationReport[]>(`/reports/cash-register/reconciliation?${buildParams(params)}`)
}

export async function fetchSalesSummary(params: OwnerDateRangeParams): Promise<SalesSummaryReport> {
  return apiGet<SalesSummaryReport>(`/reports/sales/summary?${buildParams(params)}`)
}

export async function fetchLiveSales(): Promise<LiveSalesReport> {
  return apiGet<LiveSalesReport>('/reports/sales/live')
}

function buildParams(params: object): string {
  const query = new URLSearchParams()

  Object.entries(params).forEach(([key, rawValue]) => {
    if (!isQueryValue(rawValue) || rawValue === undefined) {
      return
    }

    if (Array.isArray(rawValue)) {
      rawValue.forEach((item) => {
        query.append(`${key}[]`, item)
      })
      return
    }

    query.append(key, String(rawValue))
  })

  return query.toString()
}

function isQueryValue(value: unknown): value is string | number | string[] | undefined {
  if (value === undefined || typeof value === 'string' || typeof value === 'number') {
    return true
  }

  return Array.isArray(value) && value.every((item) => typeof item === 'string')
}
