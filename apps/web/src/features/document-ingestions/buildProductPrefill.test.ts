import { describe, expect, it } from 'vitest'
import { buildProductPrefill } from './buildProductPrefill'
import type { ExtractedField, ExtractedLine } from './types'

function f(value: string): ExtractedField {
  return { value, confidence: 0.9, sourceBbox: null }
}

function line(overrides: Partial<ExtractedLine>): ExtractedLine {
  return {
    description: null, supplierRef: null, quantity: null, unitPrice: null,
    taxRate: null, lineTotal: null, batchNumber: null, expiryDate: null,
    ...overrides,
  } as ExtractedLine
}

describe('buildProductPrefill', () => {
  it('maps description → name, unitPrice → sale_price AND cost, taxRate → tax_rate (strings preserved)', () => {
    expect(
      buildProductPrefill(line({ description: f(' Doliprane 1g '), unitPrice: f('4.850'), taxRate: f('7') })),
    ).toEqual({ name: 'Doliprane 1g', sale_price: '4.850', cost: '4.850', tax_rate: '7' })
  })

  it('drops non-numeric price/tax values but keeps the name', () => {
    expect(
      buildProductPrefill(line({ description: f('Widget'), unitPrice: f('N/A'), taxRate: f('unknown') })),
    ).toEqual({ name: 'Widget' })
  })

  it('returns {} for a line with no usable fields', () => {
    expect(buildProductPrefill(line({ description: f('   ') }))).toEqual({})
  })
})
