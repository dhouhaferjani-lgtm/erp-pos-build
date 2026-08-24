export type VatPeriodStatus = 'OPEN' | 'CLOSED' | 'FILED'
export type VatPeriodType = 'MONTHLY' | 'QUARTERLY' | 'ANNUAL'
export type VatDirection = 'OUTPUT' | 'INPUT'

export interface VatPeriod {
  id: string
  /** ISO country code the declaration is filed under — drives the special-items panel. */
  country_code: string
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

/**
 * One per-rate row of a VAT summary.
 *
 * N-4: this mirrors `VatAggregation::toArray()` EXACTLY. The CLOSED/FILED
 * snapshot branch of `VatReportController::periodSummary()` used to emit `rate`
 * here instead of `tax_rate` and omit `tax_configuration_id`; both branches now
 * emit these keys and only these, pinned by
 * `apps/api/tests/Feature/Taxation/VatReportSummaryContractTest.php`.
 */
export interface VatRateBreakdown {
  direction: VatDirection
  tax_rate: string
  base_amount: string
  vat_amount: string
  document_count: number
  is_recoverable: boolean
  tax_configuration_id: string | null
}

export interface VatDirectionSummary {
  total_base: string
  total_vat: string
  breakdowns: VatRateBreakdown[]
}

/**
 * The payload of `GET /vat/reports/{periodId}/summary`.
 *
 * N-4 (campaign report 2026-08-23 §N-4): there is NO `period` key here and there
 * never has been — this type used to declare one, so VatReportPage read
 * `report.period.label` and crashed the whole declaration screen. The period
 * header (id / label / status) comes from `GET /vat/periods/{id}`.
 */
export interface VatReportSummary {
  output_vat: VatDirectionSummary
  input_vat: VatDirectionSummary
  net_vat: string
  credit_brought_forward: string
  credit_carried_forward: string
  amount_payable: string
  special_items: Record<string, string | number | null>
  /**
   * `VatDeclarationData::toArray()` — a form reference and the country's field
   * block. It carries NO country code: read that from the period.
   */
  declaration: {
    form_reference: string
    fields: Record<string, string>
  }
}

export interface VatExportFormat {
  format: string
  label: string
}

export interface VatPeriodsFilters {
  year?: number
  status?: VatPeriodStatus
}
