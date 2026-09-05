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
import {
  buildCreditNotePayload,
  type CreditNoteLinePayload,
  type CreditNoteManualLinePayload,
  type CreditNoteSourceLine,
} from '../creditNotePayload'
import { findBlankPriceLineIds } from '../linePayload'
import type { DocumentLine } from '@/components/documents/DocumentLineEditor'

const invoiceLine = (over: Partial<CreditNoteSourceLine> = {}): CreditNoteSourceLine => ({
  id: 'line-1',
  product_id: 'prod-1',
  description: 'Widget',
  quantity: '3',
  unit_price: '100.000',
  tax_rate: '20',
  ...over,
})

/**
 * Narrow a wire line to the standalone (manual) arm. The payload's `lines` is a
 * union — an invoice-linked line carries no money at all — so a test that reads
 * `unit_price` must say which arm it expects instead of indexing a bag of
 * `unknown` (that was BLOCKER-1 of gate r1: `Record<string, unknown>` forced
 * TS4111 index-signature access and turned `pnpm typecheck` red).
 */
function manualLine(line: CreditNoteLinePayload | undefined): CreditNoteManualLinePayload {
  if (line === undefined || !('unit_price' in line)) {
    throw new Error('expected a standalone (manual) credit-note line')
  }
  return line
}

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
    const lines = [invoiceLine({ id: 'l1', quantity: '3' }), invoiceLine({ id: 'l2', quantity: '5' })]

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
      { line_id: 'l1', quantity: '3' },
      { line_id: 'l2', quantity: '5' },
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

  it('customer mode emits unit_price as a decimal STRING (rule 19), passed through unrounded', () => {
    const lines = [invoiceLine({ id: 'l1', unit_price: '100.125', tax_rate: '20' })]

    const payload = buildCreditNotePayload({
      ...base,
      creditMode: 'customer',
      lineMode: 'all',
      lines,
      selectedLineIds: new Set(),
      lineQuantities: new Map(),
    })

    const first = manualLine(payload.lines?.[0])
    expect(typeof first.unit_price).toBe('string')
    // The API's own decimal string, byte for byte — no parseFloat round-trip.
    expect(first.unit_price).toBe('100.125')
    expect(payload.source_invoice_id).toBeUndefined()
  })

  /**
   * Gate r1 IMPORTANT-3: the pre-fix builder emitted `unit_price: ''` for an
   * unpriced line, a value `CreditNoteController::store()` refuses
   * (`lines.*.unit_price` is `required|string|regex`). The real contract is a
   * REFUSAL, not a blank on the wire — the page blocks the submit with
   * `findBlankPriceLineIds` (the same guard DocumentForm uses) and the builder
   * never serialises the line.
   */
  it('customer mode refuses an unpriced line: it is flagged client-side and never serialised', () => {
    const blank: DocumentLine = {
      id: 'l1',
      product_id: 'prod-1',
      product_name: 'Widget',
      description: 'Widget',
      quantity: '1',
      unit_price: '',
      tax_rate: '20',
      line_total: '',
    }

    expect(findBlankPriceLineIds([blank])).toEqual(['l1'])

    const payload = buildCreditNotePayload({
      ...base,
      creditMode: 'customer',
      lineMode: 'all',
      lines: [blank, invoiceLine({ id: 'l2', unit_price: '50.000' })],
      selectedLineIds: new Set(),
      lineQuantities: new Map(),
    })

    expect(payload.lines).toHaveLength(1)
    expect(manualLine(payload.lines?.[0]).unit_price).toBe('50.000')
  })
})
