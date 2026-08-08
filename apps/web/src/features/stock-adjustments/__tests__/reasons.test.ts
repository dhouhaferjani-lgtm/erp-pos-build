import { describe, it, expect } from 'vitest'
import {
  ADJUSTMENT_REASONS,
  isAdjustmentReason,
  isStockAdjustmentStatus,
  reasonDirection,
  reasonsForProduct,
  toStockAdjustmentStatus,
} from '../types'

/**
 * The FRONTEND MIRROR of the backend's MovementReason enum test.
 *
 * The manual four-reason subset exists in two places by necessity — the backend
 * enum and this list — because no meta route exposes it and the endpoint that
 * used to was deleted with the raw writers. Two lists that must agree and have
 * no shared source is exactly how drift reaches users as a 422, so both sides
 * are pinned.
 */
describe('ADJUSTMENT_REASONS', () => {
  it('is exactly the four document reasons, in the backend order', () => {
    expect([...ADJUSTMENT_REASONS]).toEqual([
      'adjustment_positive',
      'adjustment_negative',
      'damage',
      'write_off',
    ])
  })

  it('excludes the reasons the backend deliberately refuses', () => {
    for (const excluded of ['opening_balance', 'expiry', 'consumption', 'count_correction']) {
      expect(ADJUSTMENT_REASONS as readonly string[]).not.toContain(excluded)
      expect(isAdjustmentReason(excluded)).toBe(false)
    }
  })

  it('maps each reason to the sign the DB CHECK enforces', () => {
    expect(reasonDirection('adjustment_positive')).toBe('in')
    expect(reasonDirection('adjustment_negative')).toBe('out')
    expect(reasonDirection('damage')).toBe('out')
    expect(reasonDirection('write_off')).toBe('out')
  })
})

describe('reasonsForProduct', () => {
  it('offers all four on a product that is not lot-tracked', () => {
    expect([...reasonsForProduct(false)]).toEqual([...ADJUSTMENT_REASONS])
  })

  it('drops damage and write_off on a batch-tracked product', () => {
    // The backend REFUSES those two here (they must go through the batch
    // write-off, which posts the COGS entry this document does not), so the
    // picker must not offer them — the operator should meet a better path, not
    // an error.
    expect([...reasonsForProduct(true)]).toEqual(['adjustment_positive', 'adjustment_negative'])
  })
})

describe('status guards', () => {
  it('accepts the three lifecycle states and nothing else', () => {
    for (const status of ['draft', 'posted', 'cancelled']) {
      expect(isStockAdjustmentStatus(status)).toBe(true)
    }
    expect(isStockAdjustmentStatus('in_transit')).toBe(false)
    expect(isStockAdjustmentStatus('')).toBe(false)
  })

  it('returns NULL for an unknown status rather than falling back to draft', () => {
    expect(toStockAdjustmentStatus('posted')).toBe('posted')
    // NOT 'draft': draft is the state that ENABLES Post and Cancel, so mapping
    // an unrecognised backend state onto it would offer two write actions for a
    // document whose real state forbids them.
    expect(toStockAdjustmentStatus('something-new')).toBeNull()
  })
})
