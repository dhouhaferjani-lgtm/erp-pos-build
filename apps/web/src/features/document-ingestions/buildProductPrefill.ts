import type { ProductPrefill } from '@/features/products/productPrefill'
import type { ExtractedField, ExtractedLine } from './types'

// Matches plain decimal strings only — prefill values feed form fields as
// strings (precision contract: no float parsing on money).
const NUMERIC_PATTERN = /^-?\d+(\.\d+)?$/

function fieldValue(field: ExtractedField | null): string | undefined {
  if (!field || typeof field.value !== 'string') { return undefined }
  const trimmed = field.value.trim()
  return trimmed.length > 0 ? trimmed : undefined
}

function numericFieldValue(field: ExtractedField | null): string | undefined {
  const value = fieldValue(field)
  if (value === undefined || !NUMERIC_PATTERN.test(value)) { return undefined }
  return value
}

export function buildProductPrefill(line: ExtractedLine): ProductPrefill {
  const result: ProductPrefill = {}
  const name = fieldValue(line.description)
  if (name !== undefined) { result.name = name }
  const unitPrice = numericFieldValue(line.unitPrice)
  if (unitPrice !== undefined) {
    result.sale_price = unitPrice
    result.cost = unitPrice
  }
  const taxRate = numericFieldValue(line.taxRate)
  if (taxRate !== undefined) { result.tax_rate = taxRate }
  return result
}
