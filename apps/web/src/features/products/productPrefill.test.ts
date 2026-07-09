import { describe, expect, it } from 'vitest'
import { readProductPrefill } from './productPrefill'

describe('readProductPrefill', () => {
  it('returns null for non-object state', () => {
    expect(readProductPrefill(null)).toBeNull()
    expect(readProductPrefill('x')).toBeNull()
    expect(readProductPrefill([])).toBeNull()
  })

  it('returns null when productPrefill key is missing or not an object', () => {
    expect(readProductPrefill({})).toBeNull()
    expect(readProductPrefill({ productPrefill: 'nope' })).toBeNull()
  })

  it('keeps only whitelisted, non-empty string keys and trims them', () => {
    expect(
      readProductPrefill({
        productPrefill: {
          name: '  Paracetamol 500mg  ',
          sale_price: '12.500',
          cost: '9.000',
          tax_rate: '19',
          sku: 'DROP-ME',
          sale_priceX: 'DROP-ME',
          empty: '   ',
          num: 12,
        },
      }),
    ).toEqual({ name: 'Paracetamol 500mg', sale_price: '12.500', cost: '9.000', tax_rate: '19' })
  })

  it('returns null when nothing valid survives', () => {
    expect(readProductPrefill({ productPrefill: { name: '   ', sale_price: 5 } })).toBeNull()
  })
})
