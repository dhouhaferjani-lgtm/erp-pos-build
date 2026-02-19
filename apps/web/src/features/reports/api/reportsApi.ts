import { apiGet } from '@/lib/api'

/**
 * Aged Receivables Report Response
 */
export interface AgedReceivablesReport {
  as_of_date: string
  total_outstanding: string
  summary: {
    current: string
    days_1_30: string
    days_31_60: string
    days_61_90: string
    days_over_90: string
  }
  by_partner: Array<{
    partner_id: string
    partner_name: string
    total_outstanding: string
    current: string
    days_1_30: string
    days_31_60: string
    days_61_90: string
    days_over_90: string
    invoices: Array<{
      id: string
      document_number: string
      document_date: string
      due_date: string | null
      days_overdue: number
      total: string
      outstanding: string
      aging_bucket: string
    }>
  }>
}

/**
 * Customer Statement Response
 */
export interface CustomerStatement {
  partner_id: string
  partner_name: string
  from_date: string
  to_date: string
  opening_balance: string
  closing_balance: string
  transactions: Array<{
    date: string
    type: string
    document_number: string
    description: string
    debit: string
    credit: string
    balance: string
  }>
}

/**
 * Overdue Summary Response
 */
export interface OverdueSummary {
  total_overdue: string
  count: number
  by_severity: {
    critical: { count: number; amount: string } // 90+ days
    high: { count: number; amount: string } // 60-89 days
    medium: { count: number; amount: string } // 30-59 days
    low: { count: number; amount: string } // 1-29 days
  }
}

/**
 * Fetch aged receivables report
 */
export async function fetchAgedReceivables(params?: {
  partner_id?: string
  as_of_date?: string
}): Promise<AgedReceivablesReport> {
  const queryParams = new URLSearchParams()
  if (params?.partner_id) queryParams.append('partner_id', params.partner_id)
  if (params?.as_of_date) queryParams.append('as_of_date', params.as_of_date)

  const url = `/reports/aged-receivables${queryParams.toString() ? `?${queryParams.toString()}` : ''}`
  return apiGet<AgedReceivablesReport>(url)
}

/**
 * Fetch customer statement
 */
export async function fetchCustomerStatement(
  partnerId: string,
  fromDate: string,
  toDate: string
): Promise<CustomerStatement> {
  const queryParams = new URLSearchParams({
    from_date: fromDate,
    to_date: toDate,
  })
  return apiGet<CustomerStatement>(`/reports/customer-statement/${partnerId}?${queryParams.toString()}`)
}

/**
 * Fetch overdue summary
 */
export async function fetchOverdueSummary(): Promise<OverdueSummary> {
  return apiGet<OverdueSummary>('/reports/overdue-summary')
}
