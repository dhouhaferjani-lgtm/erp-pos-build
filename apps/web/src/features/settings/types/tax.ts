export type TaxType = 'PERCENTAGE' | 'FIXED_AMOUNT'
export type TaxApplicationLevel = 'LINE_ITEMS' | 'DOCUMENT_TOTAL'
// Mirrors the backend StackingBehavior enum (App\Modules\Taxation\Domain\Enums)
// and the `in:SUBTOTAL,TOTAL_INCLUDING_PREVIOUS` rule the controller validates.
// The previous 'BASE_AMOUNT' | 'SUBTOTAL_PLUS_PREVIOUS_TAXES' pair matched
// neither the enum, the seeders, nor the column default, so every create from
// the UI 422'd (F-2, 2026-08-10 tenancy gate).
export type StackingBehavior = 'SUBTOTAL' | 'TOTAL_INCLUDING_PREVIOUS'
export type CompanyTaxStatus = 'REGISTERED' | 'NON_REGISTERED'
export type PartnerTaxStatus = 'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT'

export interface TaxConfiguration {
  id: string
  country_code: string
  name: string
  code: string
  tax_type: TaxType
  percentage_rate: string | null
  fixed_amount: string | null
  applies_to: TaxApplicationLevel
  sequence_order: number
  stacks_on: StackingBehavior
  applicable_document_types: string[]
  is_default: boolean
  is_active: boolean
  is_stamp_duty: boolean
  is_recoverable: boolean
  effective_from: string | null
  effective_to: string | null
  created_at: string
  updated_at: string
}

export interface TaxConfigurationFormData {
  name: string
  code?: string
  tax_type: TaxType
  percentage_rate?: string
  fixed_amount?: string
  applies_to: TaxApplicationLevel
  sequence_order?: number
  stacks_on: StackingBehavior
  applicable_document_types: string[]
  is_active: boolean
  is_recoverable?: boolean
  is_stamp_duty?: boolean
  is_default?: boolean
  effective_from?: string | null
  effective_to?: string | null
}

export interface DocumentType {
  value: string
  label: string
}

export interface TaxConfigurationCapabilities {
  supports_stamp_duty: boolean
}

export interface TaxExemptionWarning {
  type: 'missing_certificate' | 'expired_certificate' | 'expiring_soon'
  message: string
  severity: 'error' | 'warning'
}

export interface PartnerTaxInfo {
  tax_status: PartnerTaxStatus
  tax_status_label: string
  has_valid_exemption: boolean
  warnings: TaxExemptionWarning[]
  exemption_reason: string | null
  exemption_valid_until: string | null
}

export interface CalculatedTax {
  configuration_id: string
  code: string
  name: string
  type: TaxType
  rate: string | null
  fixed_amount: string | null
  base: string
  amount: string
  sequence_order: number
  is_stamp_duty: boolean
}

export interface TaxCalculationResult {
  taxes: CalculatedTax[]
  subtotal: string
  line_items_tax_total: string
  document_tax_total: string
  total_tax: string
  total: string
  exemption_info: {
    status: string
    reason: string | null
    hasValidCertificate: boolean
    warnings: TaxExemptionWarning[]
  } | null
}
