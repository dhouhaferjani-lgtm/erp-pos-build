/**
 * MONEY TEST CAMPAIGN — agent W1b — §B `DOC`/`TAX` totals pipeline (MTP-DOC-01..05).
 *
 * Live local stack, real login, real backend. Every expected figure below is
 * computed independently (bc-style, TND scale 3) in the comments BEFORE the
 * assertion — never derived from what the screen/response shows.
 *
 * FIXED (was W1b defect 2, P0): `InvoiceController::store()`/`update()` used to
 * compute `subtotal`/`tax_amount`/`total` with a manual per-line loop that
 * never applied document-level taxes (Tunisia stamp duty,
 * `applies_to=DOCUMENT_TOTAL`), so a Draft's total was short by exactly the
 * 1.000 TND stamp until `POST /invoices/{id}/confirm` ran
 * `TaxCalculationService::calculateDocumentTaxes()`. Both draft paths now run
 * that same pipeline, so each totals case here asserts the plan's stated
 * numbers AT DRAFT and re-asserts that confirm() does not move them.
 */
import { test, expect } from '@playwright/test'
import {
  loginAsOwner,
  createCustomer,
  createInvoice,
  confirmInvoice,
  uniqueName,
} from './w1b-support'

test.describe('MTP-DOC/TAX — documents totals pipeline (W1b)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsOwner(page)
  })

  test('MTP-DOC-01 / MTP-DOC-02: two-line 19% invoice — subtotal/tax/total + aggregate identity', async ({ page }) => {
    const customerName = uniqueName('DOC01')
    await createCustomer(page, customerName)

    // L1: qty 10 @ net 12.500 -> 125.000 ; L2: qty 4 @ net 25.000 -> 100.000
    // subtotal = 125.000 + 100.000 = 225.000
    // line VAT (19%) = 225.000 * 0.19 = 42.750 (exact, no truncation drift)
    const result = await createInvoice(page, {
      partnerName: customerName,
      lines: [
        { qty: '10', unitPrice: '12.500', taxLabel: 'TVA 19% (19%)' },
        { qty: '4', unitPrice: '25.000', taxLabel: 'TVA 19% (19%)' },
      ],
    })
    expect(result.ok, `create invoice failed: ${JSON.stringify(result.data)}`).toBeTruthy()

    const subtotal = result.data.subtotal as string
    const taxAmount = result.data.tax_amount as string
    const total = result.data.total as string

    // MTP-DOC-01, Draft (as-created): line VAT 42.750 + 1.000 TND stamp duty.
    expect(subtotal).toBe('225.000')
    expect(taxAmount).toBe('43.750')
    expect(total).toBe('268.750')

    // MTP-DOC-02: aggregate identity holds at every stage, Draft included.
    const bcAddDraft = (a: string, b: string) => (Number(a) * 1000 + Number(b) * 1000) / 1000
    expect(bcAddDraft(subtotal, taxAmount).toFixed(3)).toBe(total)

    // Confirming must NOT move the numbers — the draft already ran the same
    // document-tax pipeline confirm() runs.
    const invoiceId = result.data.id as string
    const confirmed = await confirmInvoice(page, invoiceId)
    expect(confirmed.tax_amount).toBe('43.750')
    expect(confirmed.total).toBe('268.750')
    // Identity still holds post-confirm (subtotal is untouched by confirm()).
    expect(bcAddDraft(subtotal, confirmed.tax_amount as string).toFixed(3)).toBe(confirmed.total)
  })

  test('MTP-DOC-03: single-line truncation vector (qty 3 @ 33.333, 19%) — truncate not half-up', async ({ page }) => {
    const customerName = uniqueName('DOC03')
    await createCustomer(page, customerName)

    // subtotal = 3 * 33.333 = 99.999
    // line VAT = 99.999 * 0.19 = 18.99981 -> truncate to 3dp = 18.999
    //   (half-up would give 19.000 — that is the documented failure signature)
    const result = await createInvoice(page, {
      partnerName: customerName,
      lines: [{ qty: '3', unitPrice: '33.333', taxLabel: 'TVA 19% (19%)' }],
    })
    expect(result.ok, `create invoice failed: ${JSON.stringify(result.data)}`).toBeTruthy()

    expect(result.data.subtotal).toBe('99.999')
    // 18.999 line VAT (truncation, not 19.000) + 1.000 stamp = 19.999.
    expect(result.data.tax_amount).toBe('19.999')
    expect(result.data.total).toBe('119.998')

    const confirmed = await confirmInvoice(page, result.data.id as string)
    expect(confirmed.tax_amount).toBe('19.999')
    expect(confirmed.total).toBe('119.998')
  })

  test('MTP-DOC-04 / MTP-DOC-05: mixed 19%/7% rates — per-rate decomposition invariant', async ({ page }) => {
    const customerName = uniqueName('DOC04')
    await createCustomer(page, customerName)

    // L1: net 100.000 @ 19% -> VAT 19.000 ; L2: net 100.000 @ 7% -> VAT 7.000
    // subtotal = 200.000 ; line VAT = 26.000
    const result = await createInvoice(page, {
      partnerName: customerName,
      lines: [
        { qty: '1', unitPrice: '100.000', taxLabel: 'TVA 19% (19%)' },
        { qty: '1', unitPrice: '100.000', taxLabel: 'TVA 7% (7%)' },
      ],
    })
    expect(result.ok, `create invoice failed: ${JSON.stringify(result.data)}`).toBeTruthy()

    expect(result.data.subtotal).toBe('200.000')
    expect(result.data.tax_amount).toBe('27.000') // 26.000 line VAT + 1.000 stamp
    expect(result.data.total).toBe('227.000')

    // MTP-DOC-05 decomposition invariant, computed from the returned lines
    // (per-rate net/VAT are NOT exposed as separate API fields — DocumentData
    // has no line_tax_amount/stamp_duty_amount — so the invariant is verified
    // by summing the lines[] array, not by trusting a document-level field).
    const lines = result.data.lines as Array<{ line_total: string; tax_rate: string }>
    expect(lines).toHaveLength(2)
    let sumNet = 0
    let sumVat = 0
    const perRate = new Map<string, { net: number; vat: number }>()
    for (const line of lines) {
      const net = Number(line.line_total)
      const rate = Number(line.tax_rate)
      const vat = Math.trunc(net * rate * 10) / 1000 // truncate to 3dp, same as backend bcmul(scale=3)
      sumNet += net
      sumVat += vat
      const bucket = perRate.get(line.tax_rate) ?? { net: 0, vat: 0 }
      bucket.net += net
      bucket.vat += vat
      perRate.set(line.tax_rate, bucket)
    }
    expect(sumNet.toFixed(3)).toBe(result.data.subtotal as string)
    // Per-rate line VAT sums to 26.000; the document-level stamp (1.000) is the
    // only other component of tax_amount.
    expect(sumVat.toFixed(3)).toBe('26.000')
    expect((sumVat + 1).toFixed(3)).toBe(result.data.tax_amount as string)
    expect(perRate.get('19.00')?.net.toFixed(3)).toBe('100.000')
    expect(perRate.get('19.00')?.vat.toFixed(3)).toBe('19.000')
    expect(perRate.get('7.00')?.net.toFixed(3)).toBe('100.000')
    expect(perRate.get('7.00')?.vat.toFixed(3)).toBe('7.000')

    const confirmed = await confirmInvoice(page, result.data.id as string)
    expect(confirmed.tax_amount).toBe('27.000')
    expect(confirmed.total).toBe('227.000')
  })
})
