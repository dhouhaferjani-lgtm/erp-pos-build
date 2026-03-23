export type VatPeriodStatus = 'OPEN' | 'CLOSED' | 'FILED'
export type VatPeriodType = 'MONTHLY' | 'QUARTERLY' | 'ANNUAL'
export type VatDirection = 'OUTPUT' | 'INPUT'

export interface VatPeriod {
  id: string
  label: string
  period_type: VatPeriodType
  period_start: string
  period_end: string
  status: VatPeriodStatus
  total_output_vat: string | null
  total_input_vat: string | null
  net_vat: string | null
  credit_brought_forward: string
  credit_carried_forward: string
  amount_payable: string
  closed_at: string | null
  filed_at: string | null
  filing_reference: string | null
}

export interface VatRateBreakdown {
  tax_rate: string
  base_amount: string
  vat_amount: string
  document_count: number
  is_recoverable: boolean
}

export interface VatDirectionSummary {
  total_base: string
  total_vat: string
  breakdowns: VatRateBreakdown[]
}

export interface VatReportSummary {
  period: VatPeriod
  output_vat: VatDirectionSummary
  input_vat: VatDirectionSummary
  net_vat: string
  credit_brought_forward: string
  credit_carried_forward: string
  amount_payable: string
  special_items: Record<string, unknown>
  declaration: Record<string, unknown>
}

export interface VatExportFormat {
  format: string
  label: string
}

export interface VatPeriodsFilters {
  year?: number
  status?: VatPeriodStatus
}
