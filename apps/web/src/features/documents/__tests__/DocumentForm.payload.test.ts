import { describe, expect, it } from 'vitest'

import type { DocumentLine } from '@/components/documents/DocumentLineEditor'

import { buildLinePayload } from '../DocumentForm'

function makeLine(overrides: Partial<DocumentLine> = {}): DocumentLine {
  return {
    id: 'line-1',
    product_id: 'prod-1',
    product_name: 'Test Product',
    description: 'Test Product',
    quantity: '2.0000',
    unit_price: '10.000',
    tax_rate: '19.00',
    line_total: '20.000',
    free_quantity: '0',
    price_entry_mode: 'unit',
    ...overrides,
  }
}

describe('buildLinePayload — free_quantity module gate (bug: 422 "lines.0.free_quantity est interdit")', () => {
  it('omits free_quantity entirely when the line has no bonus quantity (default "0")', () => {
    const payload = buildLinePayload(makeLine({ free_quantity: '0' }))
    expect(payload).not.toHaveProperty('free_quantity')
  })

  it.each([null, undefined, '', '  ', '0.0000', '0.00', '00', 0])(
    'omits free_quantity for zero-ish value %j',
    (value) => {
      const payload = buildLinePayload(makeLine({ free_quantity: value } as Partial<DocumentLine>))
      expect(payload).not.toHaveProperty('free_quantity')
    },
  )

  it('keeps a real non-zero free_quantity exactly as entered (purchase bonus flow)', () => {
    const payload = buildLinePayload(makeLine({ free_quantity: '3.0000' }))
    expect(payload.free_quantity).toBe('3.0000')
  })

  it('keeps non-zero values with leading zeros or fractional bonus quantities', () => {
    expect(buildLinePayload(makeLine({ free_quantity: '0.5000' })).free_quantity).toBe('0.5000')
    expect(buildLinePayload(makeLine({ free_quantity: '01' })).free_quantity).toBe('01')
  })

  it('still maps the other line payload fields', () => {
    const payload = buildLinePayload(
      makeLine({
        free_quantity: '0',
        discount_percent: '5.00',
        discount_amount: null,
      }),
    )
    expect(payload).toEqual({
      product_id: 'prod-1',
      quantity: '2.0000',
      unit_price: '10.000',
      line_total: '20.000',
      price_entry_mode: 'unit',
      discount_percent: '5.00',
      discount_amount: null,
    })
  })

  it('defaults price_entry_mode to "unit" and discounts to null when absent', () => {
    const line = makeLine()
    const bag = line as unknown as Record<string, unknown>
    delete bag['price_entry_mode']
    delete bag['discount_percent']
    delete bag['discount_amount']
    const payload = buildLinePayload(line)
    expect(payload.price_entry_mode).toBe('unit')
    expect(payload.discount_percent).toBeNull()
    expect(payload.discount_amount).toBeNull()
  })
})
