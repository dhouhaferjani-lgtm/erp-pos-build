import { describe, it, expect } from 'vitest'
import enMessages from '@/locales/en/stock-adjustments.json'
import frMessages from '@/locales/fr/stock-adjustments.json'
import {
  ACKNOWLEDGEABLE_REFUSAL_CODES,
  GENERIC_REFUSAL_MESSAGE_KEY,
  REFUSAL_MESSAGE_KEYS,
  STOCK_ADJUSTMENT_REFUSAL_CODES,
  extractRefusal,
  isAcknowledgeableRefusalCode,
  narrowAvailabilityDetails,
  narrowStaleDetails,
  overrideFlagFor,
  refusalMessageKey,
} from '../api/refusals'

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function resolve(messages: unknown, key: string): unknown {
  return key
    .split('.')
    .reduce<unknown>((node, segment) => (isRecord(node) ? node[segment] : undefined), messages)
}

describe('refusal message map', () => {
  it('covers every code the backend can return, in BOTH locales', () => {
    // Eleven from plan §2's table, plus POST_PERMISSION_REQUIRED — the
    // controller's two-leg check returns it from every authoring surface, and it
    // was missing from the map, so a create-only operator filled the whole form
    // and got the generic fallback.
    expect(STOCK_ADJUSTMENT_REFUSAL_CODES).toHaveLength(12)
    expect(STOCK_ADJUSTMENT_REFUSAL_CODES).toContain('POST_PERMISSION_REQUIRED')

    for (const code of STOCK_ADJUSTMENT_REFUSAL_CODES) {
      const key = REFUSAL_MESSAGE_KEYS[code]
      expect(typeof resolve(enMessages, key)).toBe('string')
      expect(typeof resolve(frMessages, key)).toBe('string')
    }
  })

  it('has a translated generic fallback for an unknown code', () => {
    expect(refusalMessageKey('SOMETHING_NEW')).toBe(GENERIC_REFUSAL_MESSAGE_KEY)
    expect(refusalMessageKey(undefined)).toBe(GENERIC_REFUSAL_MESSAGE_KEY)
    expect(typeof resolve(enMessages, GENERIC_REFUSAL_MESSAGE_KEY)).toBe('string')
    expect(typeof resolve(frMessages, GENERIC_REFUSAL_MESSAGE_KEY)).toBe('string')
  })

  it('maps EACH acknowledgeable code to its OWN flag, and only that one', () => {
    // Sending both flags disables a guard the operator never met AND writes a
    // permanent header claim that they overrode it. Both arms are pinned: only
    // the staleness arm had a test, so the reservations arm could have regressed
    // to the forged pair unnoticed.
    expect(overrideFlagFor('STOCK_MOVED_SINCE_AUTHORING')).toEqual({ acknowledge_stale: true })
    expect(overrideFlagFor('ADJUSTMENT_EXCEEDS_AVAILABLE')).toEqual({ ignore_reservations: true })

    for (const code of ACKNOWLEDGEABLE_REFUSAL_CODES) {
      expect(Object.keys(overrideFlagFor(code))).toHaveLength(1)
    }
  })

  it('marks exactly the two overridable codes as acknowledgeable', () => {
    expect([...ACKNOWLEDGEABLE_REFUSAL_CODES]).toEqual([
      'STOCK_MOVED_SINCE_AUTHORING',
      'ADJUSTMENT_EXCEEDS_AVAILABLE',
    ])
    expect(isAcknowledgeableRefusalCode('USE_BATCH_WRITE_OFF')).toBe(false)
  })
})

describe('extractRefusal', () => {
  it('pulls the typed envelope out of an axios-shaped error', () => {
    const refusal = extractRefusal({
      response: { data: { error: { code: 'BATCH_REQUIRED_FOR_LINE', message: 'x', details: { product_id: 'p' } } } },
    })

    expect(refusal?.code).toBe('BATCH_REQUIRED_FOR_LINE')
    expect(refusal?.message).toBe('x')
  })

  it('returns null for anything that is not that shape', () => {
    expect(extractRefusal(null)).toBeNull()
    expect(extractRefusal(new Error('network'))).toBeNull()
    expect(extractRefusal({ response: { data: {} } })).toBeNull()
  })
})

describe('narrowStaleDetails', () => {
  const line = {
    line_id: null,
    product_id: 'prod-1',
    variant_id: null,
    batch_uuid: null,
    observed_before: '10.0000',
    quantity_before: '15.0000',
    quantity_decimals: 3,
  }

  it('accepts a well-formed payload and preserves a NULL line_id', () => {
    const result = narrowStaleDetails({ lines: [line] })

    expect(result).not.toBeNull()
    // line_id is null on an immediate-post refusal, which persists nothing —
    // the dialog keys on (product, variant, lot) instead.
    expect(result?.[0]?.line_id).toBeNull()
    expect(result?.[0]?.quantity_decimals).toBe(3)
  })

  it('rejects malformed input rather than throwing', () => {
    expect(narrowStaleDetails(undefined)).toBeNull()
    expect(narrowStaleDetails({})).toBeNull()
    expect(narrowStaleDetails({ lines: [] })).toBeNull()
    expect(narrowStaleDetails({ lines: [{ ...line, quantity_decimals: '3' }] })).toBeNull()
    expect(narrowStaleDetails({ lines: [{ ...line, observed_before: 10 }] })).toBeNull()
  })
})

describe('narrowAvailabilityDetails', () => {
  const details = {
    product_id: 'prod-1',
    location_id: 'loc-1',
    quantity_before: '5.0000',
    reserved: '3.0000',
    available: '2.0000',
    delta_quantity: '-4.0000',
    quantity_decimals: 4,
    overridable: true,
  }

  it('accepts the documented payload and surfaces the override flag', () => {
    const result = narrowAvailabilityDetails(details)
    expect(result?.available).toBe('2.0000')
    expect(result?.overridable).toBe(true)
  })

  it('treats a missing overridable flag as NOT overridable', () => {
    const { overridable: _overridable, ...rest } = details
    expect(narrowAvailabilityDetails(rest)?.overridable).toBe(false)
  })

  it('rejects malformed input rather than throwing', () => {
    expect(narrowAvailabilityDetails(null)).toBeNull()
    expect(narrowAvailabilityDetails({ ...details, available: 2 })).toBeNull()
  })
})
