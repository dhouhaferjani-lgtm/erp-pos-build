export interface ProductPrefill {
  name?: string
  sale_price?: string
  cost?: string
  tax_rate?: string
}

const PRODUCT_PREFILL_KEYS = ['name', 'sale_price', 'cost', 'tax_rate'] as const

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function readProductPrefill(state: unknown): ProductPrefill | null {
  if (!isRecord(state)) { return null }
  const candidate = state['productPrefill']
  if (!isRecord(candidate)) { return null }
  const result: ProductPrefill = {}
  for (const key of PRODUCT_PREFILL_KEYS) {
    const value = candidate[key]
    if (typeof value !== 'string') { continue }
    const trimmed = value.trim()
    if (trimmed.length === 0) { continue }
    result[key] = trimmed
  }
  return Object.keys(result).length > 0 ? result : null
}
