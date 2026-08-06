/**
 * MONEY TEST CAMPAIGN — §B `DSC` line discounts (MTP-DSC-01..06).
 *
 * MTP-DSC-01, 05, 06 authored by agent W1b (UI-driven).
 * MTP-DSC-02, 03, 04 authored by agent W-3 (API-driven) — see below.
 *
 * WHY 02/03/04 ARE API-DRIVEN (orchestrator ruling C-6(a),
 * docs/qa/2026-08-02-full-e2e-campaign-plan.md §C): there is NO UI path for
 * `discount_amount` at all. The DocumentLineEditor's Discount column is a
 * SINGLE input bound only to `discount_percent`
 * (`apps/web/src/features/documents/components/DocumentLineEditor.tsx:748-764`
 * — its `onChange` unconditionally sets `discount_amount: null`), and no
 * second control exists anywhere in the documents UI. W1b therefore recorded
 * 02/03/04 as BLOCKED. Per ruling C-6(a) the percent-vs-amount precedence is
 * a REAL backend rule (`DocumentLine::computeLineTotal()`,
 * apps/api/app/Modules/Document/Domain/DocumentLine.php:272-290) that must be
 * covered regardless of the UI, so they are now authored through the house
 * `apiRequest` pattern against the same live backend. The product half of the
 * ruling — C-6(b), "is the missing control a UX gap?" — is filed as
 * `docs/superpowers/tickets/2026-08-03-w3-line-discount-amount-no-ui-and-negative-net.md`.
 *
 * Precision contract (CLAUDE.md rule 19): every money assertion is an exact
 * decimal STRING at the TND currency scale (3).
 *
 * MTP-DSC-07..18 (promotions/coupons/vouchers) remain out of scope — they
 * need the dedicated Promotions/Coupons/Vouchers admin fixture of debt item
 * C-5.
 */
import { test, expect } from '@playwright/test'
import {
  loginAsOwner,
  createCustomer,
  createInvoice,
  confirmInvoice,
  selectPartner,
  addProductLines,
  submitDocumentCreate,
  uniqueName,
} from './w1b-support'
import { apiRequest } from './helpers'

test.describe('MTP-DSC — line discounts (W1b)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsOwner(page)
  })

  test('MTP-DSC-01: 10% line discount — net/VAT/total recompute off the discounted subtotal', async ({ page }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DSC01')
    await createCustomer(page, customerName)

    // qty 10 @ 12.500 = 125.000 gross ; 10% discount = 12.500 ; net 112.500
    const created = await createInvoice(page, {
      partnerName: customerName,
      lines: [{ qty: '10', unitPrice: '12.500', discountPercent: '10', taxLabel: 'TVA 19% (19%)' }],
    })
    expect(created.ok, `create failed: ${JSON.stringify(created.data)}`).toBeTruthy()
    expect(created.data.subtotal).toBe('112.500')
    // VAT = 112.500 * 0.19 = 21.375, + 1.000 stamp = 22.375 (already at Draft)
    expect(created.data.tax_amount).toBe('22.375')
    expect(created.data.total).toBe('134.875')

    const confirmed = await confirmInvoice(page, created.data.id as string)
    expect(confirmed.tax_amount).toBe('22.375')
    expect(confirmed.total).toBe('134.875')
  })

  test('MTP-DSC-02 (EDGE, P0): discount_percent + discount_amount on the SAME line — PERCENT WINS (net 112.500, not 75.000)', async ({
    page,
  }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DSC02')
    const customerId = await createCustomer(page, customerName)

    // qty 10 @ 12.500 = 125.000 gross.
    //   percent 10.00  -> discount 12.500 -> net 112.500   <- MUST WIN
    //   amount  50.000 -> discount 50.000 -> net  75.000   <- must be IGNORED
    // Getting this precedence backwards silently over-discounts every line
    // that carries both fields (`computeLineTotal()`'s `if percent … elseif
    // amount` chain, DocumentLine.php:282-287).
    const created = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [
        {
          description: 'DSC-02 percent-vs-amount precedence',
          quantity: '10',
          unit_price: '12.500',
          discount_percent: '10.00',
          discount_amount: '50.000',
          tax_rate: '19.00',
        },
      ],
    })
    expect(created.status, `create failed: ${JSON.stringify(created.body)}`).toBe(201)
    const body = (created.body as { data: Record<string, unknown> }).data

    const lines = body.lines as Array<Record<string, unknown>>
    expect(lines).toHaveLength(1)
    expect(lines[0].line_total, 'percent (10% of 125.000) wins over the flat 50.000 amount').toBe('112.500')
    // Both raw fields are still persisted verbatim — precedence is applied at
    // calculation time, the ignored field is NOT nulled out on the way in.
    expect(lines[0].discount_percent).toBe('10.00')
    expect(lines[0].discount_amount).toBe('50.000')

    expect(body.subtotal).toBe('112.500')
    // VAT 112.500 * 0.19 = 21.375, + 1.000 stamp = 22.375
    expect(body.tax_amount).toBe('22.375')
    expect(body.total, 'identical to MTP-DSC-01 — the amount field changed nothing').toBe('134.875')

    // ...and confirm() (which re-runs TaxCalculationService from scratch) must
    // reach the same precedence decision, not silently flip to the amount.
    const confirmed = await apiRequest(page, 'POST', `/invoices/${body.id as string}/confirm`)
    expect(confirmed.status, `confirm failed: ${JSON.stringify(confirmed.body)}`).toBe(200)
    const confirmedBody = (confirmed.body as { data: Record<string, unknown> }).data
    expect(confirmedBody.subtotal).toBe('112.500')
    expect(confirmedBody.tax_amount).toBe('22.375')
    expect(confirmedBody.total).toBe('134.875')
  })

  test('MTP-DSC-03 (HAPPY, P1): absolute discount_amount with no percent — net 100.000, VAT 19.000, total 120.000', async ({
    page,
  }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DSC03')
    const customerId = await createCustomer(page, customerName)

    // qty 10 @ 12.500 = 125.000 gross, flat 25.000 off -> net 100.000.
    const created = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [
        {
          description: 'DSC-03 flat amount discount',
          quantity: '10',
          unit_price: '12.500',
          discount_amount: '25.000',
          tax_rate: '19.00',
        },
      ],
    })
    expect(created.status, `create failed: ${JSON.stringify(created.body)}`).toBe(201)
    const body = (created.body as { data: Record<string, unknown> }).data

    const lines = body.lines as Array<Record<string, unknown>>
    expect(lines[0].line_total).toBe('100.000')
    expect(lines[0].discount_percent, 'percent left unset — the elseif branch is the one that fires').toBeNull()
    expect(lines[0].discount_amount).toBe('25.000')

    expect(body.subtotal).toBe('100.000')
    // VAT 100.000 * 0.19 = 19.000, + 1.000 stamp = 20.000
    expect(body.tax_amount).toBe('20.000')
    expect(body.total).toBe('120.000')

    const confirmed = await apiRequest(page, 'POST', `/invoices/${body.id as string}/confirm`)
    expect(confirmed.status, `confirm failed: ${JSON.stringify(confirmed.body)}`).toBe(200)
    const confirmedBody = (confirmed.body as { data: Record<string, unknown> }).data
    expect(confirmedBody.subtotal).toBe('100.000')
    expect(confirmedBody.tax_amount).toBe('20.000')
    expect(confirmedBody.total).toBe('120.000')
  })

  test('MTP-DSC-04 (EDGE, P1): discount_amount ABOVE the line subtotal — REFUSED with a 422, the line net never goes negative', async ({
    page,
  }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DSC04')
    const customerId = await createCustomer(page, customerName)

    // qty 10 @ 12.500 = 125.000 gross, flat 200.000 off.
    // Plan expectation: "Refused, or the line net floors at 0.000 — never
    // negative. Record actual."
    //
    // FIXED (W-3, 2026-08-03 finding closed 2026-08-06) —
    //   docs/superpowers/tickets/2026-08-03-w3-line-discount-amount-no-ui-and-negative-net.md
    // Owner ruling: Option A, "reject-then-floor" — both guards, not one.
    // `lines.*.discount_amount` is now compared against the line's own gross
    // (qty x unit_price) at the request-validation boundary
    // (LineDiscountAmountWithinGross, wired into CreateDocumentRequest,
    // UpdateDocumentRequest, and AppliesDiscountToleranceRule) and rejected
    // with a 422 naming `lines.0.discount_amount`. `computeLineTotal()`
    // (DocumentLine.php:272-...) also floors the discounted subtotal at zero
    // as defence-in-depth, so no other write path can reproduce a negative
    // net. This test was a DELIBERATE TRIPWIRE on the old (accepted,
    // negative-net) behaviour — updated here to the fixed contract per the
    // ticket's own instruction ("update it to the new 422 contract, do not
    // delete it"), not deleted.
    //
    // No cleanup needed: a 422 never persists a document, so there is
    // nothing to leak into the shared demo-pharmacy-tn tenant's later reads.
    const created = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [
        {
          description: 'DSC-04 over-discount probe',
          quantity: '10',
          unit_price: '12.500',
          discount_amount: '200.000',
          tax_rate: '19.00',
        },
      ],
    })
    expect(
      created.status,
      `an over-line discount_amount must be refused with a 422 — got ${created.status} ${JSON.stringify(created.body)}`,
    ).toBe(422)
    expect(JSON.stringify(created.body), 'the 422 must name lines.0.discount_amount').toContain(
      'lines.0.discount_amount',
    )
  })

  test('MTP-DSC-05: discount_percent ceilings — 100.01 (>max) and 10.001 (>2dp) both rejected', async ({ page }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DSC05')
    await createCustomer(page, customerName)

    await page.goto('/sales/invoices/new')
    await selectPartner(page, customerName)
    await addProductLines(page, [{ qty: '1', unitPrice: '20.000', taxLabel: 'TVA 19% (19%)' }])
    const row = page.locator('table tbody tr').first()

    // > max:100
    await row.getByRole('spinbutton', { name: 'Discount' }).fill('100.01')
    const afterOverMax = await row.getByRole('spinbutton', { name: 'Discount' }).inputValue()
    if (afterOverMax === '100.01') {
      const submit = await submitDocumentCreate(page, '/api/v1/invoices')
      expect(submit.ok, 'server accepted discount_percent=100.01 (> max:100)').toBeFalsy()
    } else {
      expect(afterOverMax).not.toBe('100.01')
    }

    // > 2dp ceiling
    await row.getByRole('spinbutton', { name: 'Discount' }).fill('10.001')
    const afterExtraDp = await row.getByRole('spinbutton', { name: 'Discount' }).inputValue()
    if (afterExtraDp === '10.001') {
      const submit = await submitDocumentCreate(page, '/api/v1/invoices')
      expect(submit.ok, 'server accepted discount_percent=10.001 (3dp, ceiling is 2dp)').toBeFalsy()
    } else {
      expect(afterExtraDp).not.toBe('10.001')
    }

    // --- server-path probe (REQUIRED, review fix I-2) ---
    // The two branches above are UI-shape-dependent: a browser-native
    // number input with max="100" may clamp or reject the keystroke, in which
    // case the else-branch (`inputValue() !== '100.01'`) passes WITHOUT the
    // server rule ever being exercised — a tautology dressed up as a
    // rejection. The verdict "both rejected" must be true of the BACKEND
    // regardless of which branch the widget takes, so both ceilings are also
    // POSTed directly (same C-6(a) API-driven pattern as DSC-02..04) and the
    // 422 + the exact failing field asserted.
    const probeCustomerId = await createCustomer(page, uniqueName('DSC05-api'))
    const today = new Date().toISOString().slice(0, 10)
    const probe = async (discountPercent: string): Promise<{ status: number; body: unknown }> =>
      apiRequest(page, 'POST', '/invoices', {
        partner_id: probeCustomerId,
        document_date: today,
        lines: [
          {
            description: `DSC-05 server probe ${discountPercent}`,
            quantity: '1',
            unit_price: '20.000',
            discount_percent: discountPercent,
            tax_rate: '19.00',
          },
        ],
      })

    // `max:100` — CreateDocumentRequest.php:129
    const overMax = await probe('100.01')
    expect(
      overMax.status,
      `server MUST reject discount_percent=100.01 (max:100) — got ${overMax.status} ${JSON.stringify(overMax.body)}`,
    ).toBe(422)
    expect(JSON.stringify(overMax.body), 'the 422 must name lines.0.discount_percent').toContain(
      'lines.0.discount_percent',
    )

    // 2-dp regex ceiling — same rule, `regex:/^\d+(\.\d{1,2})?$/`
    const extraDp = await probe('10.001')
    expect(
      extraDp.status,
      `server MUST reject discount_percent=10.001 (3dp, ceiling is 2dp) — got ${extraDp.status} ${JSON.stringify(extraDp.body)}`,
    ).toBe(422)
    expect(JSON.stringify(extraDp.body), 'the 422 must name lines.0.discount_percent').toContain(
      'lines.0.discount_percent',
    )

    // Control: the same payload at a LEGAL 2-dp percent succeeds, proving the
    // two 422s above are the ceiling firing and not an unrelated payload fault.
    const legal = await probe('10.00')
    expect(legal.status, `control payload must be accepted: ${JSON.stringify(legal.body)}`).toBe(201)
    const legalBody = (legal.body as { data: Record<string, unknown> }).data
    expect((legalBody.lines as Array<Record<string, unknown>>)[0].line_total).toBe('18.000')
  })

  test('MTP-DSC-06: no whole-document discount field exists anywhere in the invoice create UI', async ({ page }) => {
    await page.goto('/sales/invoices/new')
    // Before any line exists, zero "discount"-labeled controls anywhere on
    // the page — proves there is no document-level discount field alongside
    // Customer/Issue Date/Due Date/Notes in the Details card.
    await expect(page.getByRole('heading', { name: 'Details' })).toBeVisible({ timeout: 15000 })
    expect(await page.getByLabel(/discount/i).count()).toBe(0)

    // Once a line exists, exactly ONE "Discount" control appears — the
    // per-line column — confirming the only discount surface is line-level.
    const customerName = uniqueName('DSC06')
    await createCustomer(page, customerName)
    await page.goto('/sales/invoices/new')
    await selectPartner(page, customerName)
    await addProductLines(page, [{ qty: '1', unitPrice: '10.000' }])
    await expect(page.getByRole('spinbutton', { name: 'Discount' })).toHaveCount(1)
  })
})
