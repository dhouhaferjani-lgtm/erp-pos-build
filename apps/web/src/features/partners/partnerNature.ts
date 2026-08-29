import type { PartnerData } from './types'

export type PartnerB2BRow = Pick<
  PartnerData,
  | 'business_registration_number'
  | 'company_legal_name'
  | 'credit_limit'
  | 'customer_category'
  | 'vat_number'
>

export function shouldShowPartnerB2BFields(row: PartnerB2BRow): boolean {
  if (row.customer_category === 'business') return true
  if (row.customer_category === 'individual') return false

  return [
    row.vat_number,
    row.company_legal_name,
    row.business_registration_number,
    row.credit_limit,
  ].some((value) => value !== null && value.trim() !== '')
}
