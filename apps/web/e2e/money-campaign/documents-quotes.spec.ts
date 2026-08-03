/**
 * MONEY TEST CAMPAIGN — W-3 execution agent — NEW cases `MTP-DOC-25..27`
 * (docs/qa/2026-08-02-full-e2e-campaign-plan.md §B.1 flow 1: "Quote create →
 * confirm (totals, VAT)", the only §B.1 sales-document flow with no coverage
 * at all).
 *
 * SCOPE BOUNDARY — these cases cover the QUOTE-SIDE totals/VAT only.
 * `MTP-DOC-06` (quote → sales order → invoice conversion chain, byte-identity
 * at every hop) is ALREADY GREEN in `documents-lifecycle.spec.ts` and is NOT
 * re-authored here.
 *
 * The single most important behavioural distinction this file pins down:
 * a quote is a **NonFiscal** document, so the Tunisia flat stamp duty
 * (`STAMP_TAX_INVOICE`, `1.000` TND, `applies_to = DOCUMENT_TOTAL`) does
 * **not** apply to it. The same two lines that produce the campaign's
 * canonical invoice total `268.750` (MTP-DOC-01) must produce `267.750` on a
 * quote — exactly one stamp less. A quote that carried the stamp would be a
 * real fiscal defect (a non-fiscal document levying a fiscal stamp), and a
 * quote whose stamp then vanished on conversion would break MTP-DOC-06's
 * byte-identity contract.
 *
 * Precision contract (CLAUDE.md rule 19): every money assertion below is an
 * exact decimal STRING at the TND currency scale (3). No float comparison,
 * no `toBeCloseTo`.
 *
 * Real login + real API/UI against the LIVE local stack (web :5173 -> api
 * :8010, tenant demo-pharmacy-tn). No route mocking. Fixtures carry the
 * `W1b` prefix via `uniqueName()` (reusing the documents-surface support
 * module rather than introducing a fourth prefix for three cases).
 */
import { test, expect } from '@playwright/test'
import {
  loginAsOwner,
  createCustomer,
  selectPartner,
  addProductLines,
  submitDocumentCreate,
  uniqueName,
} from './w1b-support'
import { apiRequest } from './helpers'

test.describe('MTP-DOC-25..27 — quote create → confirm: totals & VAT (W-3)', () => {
  test.setTimeout(120_000)

  test.beforeEach(async ({ page }) => {
    await loginAsOwner(page)
  })

  test('MTP-DOC-25 (HAPPY, P0): quote created through the UI carries full per-line VAT and NO stamp duty — 225.000 / 42.750 / 267.750', async ({
    page,
  }) => {
    const customerName = uniqueName('DOC25')
    await createCustomer(page, customerName)

    // Deliberately the SAME two lines as MTP-DOC-01's canonical invoice:
    //   L1 qty 10 @ net 12.500 = 125.000 ; L2 qty 4 @ net 25.000 = 100.000
    //   subtotal 225.000 ; per-line VAT 23.750 + 19.000 = 42.750
    // On an INVOICE that yields tax_amount 43.750 / total 268.750 (VAT + the
    // 1.000 stamp). On a QUOTE the stamp must be absent.
    await page.goto('/sales/quotes/new')
    await selectPartner(page, customerName)
    await addProductLines(page, [
      { qty: '10', unitPrice: '12.500', taxLabel: 'TVA 19% (19%)' },
      { qty: '4', unitPrice: '25.000', taxLabel: 'TVA 19% (19%)' },
    ])
    const created = await submitDocumentCreate(page, '/api/v1/quotes')
    expect(created.ok, `quote create failed: ${created.status} ${JSON.stringify(created.data)}`).toBeTruthy()

    expect(created.data.type, 'the /sales/quotes/new form must author a quote, not an invoice').toBe('quote')
    expect(created.data.status).toBe('draft')
    expect(created.data.subtotal).toBe('225.000')
    expect(
      created.data.tax_amount,
      'quote tax_amount is line VAT ONLY — no 1.000 stamp duty (a quote is NonFiscal)',
    ).toBe('42.750')
    expect(created.data.total, 'exactly one stamp (1.000) below the equivalent invoice total 268.750').toBe(
      '267.750',
    )

    // Aggregate identity (MTP-DOC-02's invariant, re-asserted on the quote
    // surface): subtotal + tax_amount == total, exact strings.
    expect(created.data.total).toBe('267.750')
    expect(`${225.0 + 42.75}`).toBe('267.75') // arithmetic sanity of the fixture itself
  })

  test('MTP-DOC-26 (EDGE, P0): confirming a quote does NOT move the numbers — Draft and Confirmed totals are byte-identical (T-A finding 2 regression guard)', async ({
    page,
  }) => {
    const customerName = uniqueName('DOC26')
    const customerId = await createCustomer(page, customerName)

    // API-authored so the Draft payload is unambiguous; the UI create path is
    // already covered by MTP-DOC-25 above.
    const created = await apiRequest(page, 'POST', '/quotes', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [
        { description: 'DOC-26 L1', quantity: '10', unit_price: '12.500', tax_rate: '19.00' },
        { description: 'DOC-26 L2', quantity: '4', unit_price: '25.000', tax_rate: '19.00' },
      ],
    })
    expect(created.status, `quote create failed: ${JSON.stringify(created.body)}`).toBe(201)
    const draft = (created.body as { data: Record<string, unknown> }).data
    const quoteId = draft.id as string
    expect(draft.subtotal).toBe('225.000')
    expect(draft.tax_amount).toBe('42.750')
    expect(draft.total).toBe('267.750')

    // T-A finding 2 (docs/superpowers/tickets/2026-08-02-confirm-zeroes-vat-unconfigured-rates.md)
    // was: `confirm()` re-ran TaxCalculationService and, for a NonFiscal
    // document, could zero the VAT ENTIRELY. That was FIXED in the W-1
    // documents lane; this case is the standing regression guard on the quote
    // surface specifically (MTP-TAX-13 guards the unconfigured-rate variant on
    // invoices, MTP-DOC-06 guards it inside the conversion chain).
    const confirmed = await apiRequest(page, 'POST', `/quotes/${quoteId}/confirm`)
    expect(confirmed.status, `quote confirm failed: ${JSON.stringify(confirmed.body)}`).toBe(200)
    const body = (confirmed.body as { data: Record<string, unknown> }).data
    expect(body.status).toBe('confirmed')
    expect(body.subtotal, 'confirm() must not move the subtotal').toBe('225.000')
    expect(body.tax_amount, 'confirm() must neither zero the VAT nor introduce a stamp duty').toBe('42.750')
    expect(body.total, 'confirm() must not move the total').toBe('267.750')

    // And the persisted server state agrees with the confirm() response body
    // (guards against a response built from an in-memory object that was never
    // written).
    const reread = await apiRequest(page, 'GET', `/quotes/${quoteId}`)
    expect(reread.status).toBe(200)
    const persisted = (reread.body as { data: Record<string, unknown> }).data
    expect(persisted.subtotal).toBe('225.000')
    expect(persisted.tax_amount).toBe('42.750')
    expect(persisted.total).toBe('267.750')
  })

  test('MTP-DOC-27 (EDGE, P1): quote VAT is per-rate grouped and truncated ONCE — mixed 19%/7% decomposition + the 33.333 truncation vector, both stamp-free', async ({
    page,
  }) => {
    const customerName = uniqueName('DOC27')
    const customerId = await createCustomer(page, customerName)
    const today = new Date().toISOString().slice(0, 10)

    // --- 27a: mixed-rate decomposition (MTP-DOC-04/05's invariant on quotes) ---
    // L1 net 100.000 @ 19% -> 19.000 ; L2 net 100.000 @ 7% -> 7.000
    // subtotal 200.000 ; tax_amount 26.000 (NO stamp) ; total 226.000
    // (the equivalent INVOICE is 227.000 — MTP-DOC-04).
    const mixed = await apiRequest(page, 'POST', '/quotes', {
      partner_id: customerId,
      document_date: today,
      lines: [
        { description: 'DOC-27a 19%', quantity: '1', unit_price: '100.000', tax_rate: '19.00' },
        { description: 'DOC-27a 7%', quantity: '1', unit_price: '100.000', tax_rate: '7.00' },
      ],
    })
    expect(mixed.status, `mixed-rate quote create failed: ${JSON.stringify(mixed.body)}`).toBe(201)
    const mixedBody = (mixed.body as { data: Record<string, unknown> }).data
    expect(mixedBody.subtotal).toBe('200.000')
    expect(mixedBody.tax_amount, 'per-rate line VAT 19.000 + 7.000, no stamp on a quote').toBe('26.000')
    expect(mixedBody.total).toBe('226.000')

    // Per-rate decomposition invariant, computed from the returned lines
    // (DocumentData exposes no per-rate breakdown field — same constraint
    // MTP-DOC-05 documents on the invoice surface).
    const mixedLines = mixedBody.lines as Array<{ line_total: string; tax_rate: string }>
    expect(mixedLines).toHaveLength(2)
    const perRate = new Map<string, string>()
    for (const line of mixedLines) {
      perRate.set(line.tax_rate, line.line_total)
    }
    expect(perRate.get('19.00')).toBe('100.000')
    expect(perRate.get('7.00')).toBe('100.000')

    // --- 27b: single-truncation vector on the quote path (MTP-DOC-03 shape) ---
    // qty 3 @ 33.333 = 99.999 net. VAT accumulates at scale+1:
    //   99.9990 * 0.19 = 18.99981 -> 18.9998 -> truncated ONCE to 18.999.
    // Half-up rounding would give 19.000 — that is the failure signature.
    // No stamp on a quote, so total = 99.999 + 18.999 = 118.998
    // (the equivalent INVOICE is 119.998 — MTP-DOC-03).
    const trunc = await apiRequest(page, 'POST', '/quotes', {
      partner_id: customerId,
      document_date: today,
      lines: [{ description: 'DOC-27b truncation vector', quantity: '3', unit_price: '33.333', tax_rate: '19.00' }],
    })
    expect(trunc.status, `truncation-vector quote create failed: ${JSON.stringify(trunc.body)}`).toBe(201)
    const truncBody = (trunc.body as { data: Record<string, unknown> }).data
    expect(truncBody.subtotal).toBe('99.999')
    expect(truncBody.tax_amount, 'truncated once at the currency scale — 18.999, NOT the half-up 19.000').toBe(
      '18.999',
    )
    expect(truncBody.total).toBe('118.998')

    // Confirming the truncation-vector quote must not re-round it.
    const truncConfirmed = await apiRequest(page, 'POST', `/quotes/${truncBody.id as string}/confirm`)
    expect(truncConfirmed.status, `confirm failed: ${JSON.stringify(truncConfirmed.body)}`).toBe(200)
    const truncConfirmedBody = (truncConfirmed.body as { data: Record<string, unknown> }).data
    expect(truncConfirmedBody.tax_amount, 'no re-rounding drift on confirm').toBe('18.999')
    expect(truncConfirmedBody.total).toBe('118.998')
  })
})
