/**
 * F-STG-4 — the credit-note payload contract.
 *
 * Path A (from invoice, 'all' mode) previously sent neither `amount` nor
 * `lines`, so the backend's amount-based branch 422'd with "montant
 * obligatoire". Path B (from customer) sent `unit_price` as a NUMBER while the
 * backend requires a decimal STRING (precision contract, rule 19). These tests
 * pin the corrected payload for both paths.
 */

import { describe, it, expect } from 'vitest'
import { buildCreditNotePayload, type CreditNoteSourceLine } from '../creditNotePayload'

const invoiceLine = (over: Partial<CreditNoteSourceLine> = {}): CreditNoteSourceLine => ({
  id: 'line-1',
  product_id: 'prod-1',
  description: 'Widget',
  quantity: 3,
  unit_price: 100,
  tax_rate: 20,
  ...over,
})

const base = {
  data: {
    partner_id: 'partner-1',
    issue_date: '2026-09-04',
    reason: 'return',
    notes: 'note',
  },
}

describe('buildCreditNotePayload', () => {
  it("invoice mode 'all' credits every line via the line-based path (no bare amount-only request)", () => {
    const lines = [invoiceLine({ id: 'l1', quantity: 3 }), invoiceLine({ id: 'l2', quantity: 5 })]

    const payload = buildCreditNotePayload({
      ...base,
      data: { ...base.data, source_invoice_id: 'inv-1' },
      creditMode: 'invoice',
      lineMode: 'all',
      lines,
      selectedLineIds: new Set(['l1', 'l2']),
      lineQuantities: new Map(),
    })

    expect(payload.source_invoice_id).toBe('inv-1')
    expect(payload.amount).toBeUndefined()
    expect(payload.lines).toEqual([
      { line_id: 'l1', quantity: 3 },
      { line_id: 'l2', quantity: 5 },
    ])
  })

  it("invoice mode 'partial' sends only the selected lines with their chosen quantities", () => {
    const lines = [invoiceLine({ id: 'l1' }), invoiceLine({ id: 'l2' })]

    const payload = buildCreditNotePayload({
      ...base,
      data: { ...base.data, source_invoice_id: 'inv-1' },
      creditMode: 'invoice',
      lineMode: 'partial',
      lines,
      selectedLineIds: new Set(['l2']),
      lineQuantities: new Map([['l2', 2]]),
    })

    expect(payload.lines).toEqual([{ line_id: 'l2', quantity: 2 }])
  })

  it('customer mode emits unit_price as a decimal STRING (rule 19)', () => {
    const lines = [invoiceLine({ id: 'l1', unit_price: 100, tax_rate: 20 })]

    const payload = buildCreditNotePayload({
      ...base,
      creditMode: 'customer',
      lineMode: 'all',
      lines,
      selectedLineIds: new Set(),
      lineQuantities: new Map(),
    })

    const first = payload.lines?.[0]
    expect(typeof first?.unit_price).toBe('string')
    expect(first?.unit_price).toBe('100')
    expect(payload.source_invoice_id).toBeUndefined()
  })

  it('customer mode keeps a blank-price line as an (empty) string, never a number', () => {
    const lines = [invoiceLine({ id: 'l1', unit_price: '' })]

    const payload = buildCreditNotePayload({
      ...base,
      creditMode: 'customer',
      lineMode: 'all',
      lines,
      selectedLineIds: new Set(),
      lineQuantities: new Map(),
    })

    const first = payload.lines?.[0]
    expect(typeof first?.unit_price).toBe('string')
    expect(first?.unit_price).toBe('')
  })
})
