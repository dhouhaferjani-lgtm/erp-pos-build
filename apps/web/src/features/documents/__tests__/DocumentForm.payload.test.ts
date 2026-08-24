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
      // N-1: buildLinePayload now owns the tax fields too (the two call sites
      // used to append tax_rate themselves, and had drifted apart doing it).
      // This line carries no configuration, so it states its rate.
      tax_rate: '19.00',
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

/**
 * Campaign defect N-1 (P0) — the frontend half.
 *
 * `DocumentLineTaxResolver` prefers the tax CONFIGURATION over any denormalised
 * rate, but its very first branch short-circuits on an explicit
 * `lines.*.tax_rate`. The form used to send `tax_rate` on every line and never
 * `tax_configuration_id`, so the configuration branch was unreachable and a
 * 7 %-band product was invoiced at the company's 19 % default.
 *
 * The contract: when the line knows which configuration it is on, that id is
 * what goes on the wire and the echoed rate stays behind. A line with no
 * configuration (a free-text service line, a document loaded from the server
 * before configurations were tracked) still sends its rate, so nothing that
 * worked before regresses.
 */
describe('buildLinePayload — tax configuration wins over the echoed rate (N-1)', () => {
  it('sends tax_configuration_id when the line carries one', () => {
    const payload = buildLinePayload(
      makeLine({ tax_configuration_id: 'cfg-tva-7', tax_rate: '19.00' }),
    )
    expect(payload.tax_configuration_id).toBe('cfg-tva-7')
  })

  it('omits tax_rate when a configuration is sent, so the resolver reads the configuration', () => {
    const payload = buildLinePayload(
      makeLine({ tax_configuration_id: 'cfg-tva-7', tax_rate: '19.00' }),
    )
    expect(payload).not.toHaveProperty('tax_rate')
  })

  it('falls back to tax_rate when the line has no configuration', () => {
    const payload = buildLinePayload(makeLine({ tax_configuration_id: null, tax_rate: '13.00' }))
    expect(payload).not.toHaveProperty('tax_configuration_id')
    expect(payload.tax_rate).toBe('13.00')
  })

  it.each([null, '', '   '])('treats a blank tax_configuration_id (%j) as absent', (value) => {
    const payload = buildLinePayload(makeLine({ tax_configuration_id: value }))
    expect(payload).not.toHaveProperty('tax_configuration_id')
    expect(payload.tax_rate).toBe('19.00')
  })

  it('treats a line with no tax_configuration_id key at all as absent', () => {
    // `exactOptionalPropertyTypes` makes "key missing" a distinct case from
    // "key present and undefined"; a document loaded from the server before
    // configurations were tracked on lines is exactly this shape.
    const payload = buildLinePayload(makeLine({}))
    expect(payload).not.toHaveProperty('tax_configuration_id')
    expect(payload.tax_rate).toBe('19.00')
  })

  it('omits both when the line states neither, letting the backend resolve from the product', () => {
    const payload = buildLinePayload(
      makeLine({ tax_configuration_id: null, tax_rate: '' } as Partial<DocumentLine>),
    )
    expect(payload).not.toHaveProperty('tax_configuration_id')
    expect(payload).not.toHaveProperty('tax_rate')
  })

  it('sends a zero rate rather than dropping it — 0 % is a stated exemption, not silence', () => {
    const payload = buildLinePayload(makeLine({ tax_configuration_id: null, tax_rate: '0.00' }))
    expect(payload.tax_rate).toBe('0.00')
  })
})
