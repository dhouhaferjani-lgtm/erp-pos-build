import { apiGet } from '../../../lib/api'

/**
 * Tax detail for a single tax rate
 */
export interface TaxDetail {
  tax_type: 'percentage' | 'fixed_amount'
  tax_name: string
  tax_rate: string | null
  tax_base: string
  tax_amount: string
}

/**
 * Complete tax breakdown for a document
 */
export interface TaxBreakdown {
  subtotal: string
  discount: string
  line_tax_amount: string
  stamp_duty_amount: string
  total_tax_amount: string
  total: string
  tax_details: TaxDetail[]
}

/**
 * Fetch tax breakdown for a document
 *
 * @param documentId - The document UUID
 * @returns Tax breakdown with all calculations
 */
export async function fetchTaxBreakdown(documentId: string): Promise<TaxBreakdown> {
  return apiGet<TaxBreakdown>(`/documents/${documentId}/tax-breakdown`)
}
