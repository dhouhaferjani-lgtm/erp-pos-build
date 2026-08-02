/**
 * MONEY TEST CAMPAIGN — agent W1b — §B `DOC` credit notes (MTP-DOC-16..24).
 *
 * Credit notes can only be created from a POSTED invoice via the inline modal
 * (`DocumentActionBar.canCreateCreditNote = document.type==='invoice' &&
 * document.status==='posted'` — apps/web/.../DocumentActionBar.tsx:145), so
 * every case here confirms+posts its invoice first (real fiscal sealing).
 *
 * NOTE: the source invoices in this file now carry the 1.000 TND stamp duty
 * from DRAFT onward (W1b defect 2 fix) — 225.000 net -> 268.750 at every stage.
 *
 * MTP-DOC-21 (stamp_duty_amount = 0.600 on a TN credit note, vs 1.000 on an
 * invoice) is recorded BLOCKED: neither `DocumentData` (the API response DTO,
 * apps/api/.../DocumentData.php) nor the FE `Document` type expose
 * `stamp_duty_amount` at all, so this decomposition cannot be verified from
 * the web surface — DB access would be required, which is out of this
 * Playwright-only campaign's reach.
 *
 * NEW P0 FINDING (2026-08-02, W-1 reconciliation re-run — discovered while
 * re-running this file, NOT caused by these spec edits): EVERY credit note
 * creation through `CreditNoteController::store()` (amount-based,
 * line-based, AND standalone) is currently PERMANENTLY BROKEN on this
 * tenant. `CreditNoteService::generateCreditNoteNumber()`
 * (CreditNoteService.php:504-518) computes the next number with the regex
 * `/CN-(\d+)/` against the MOST RECENT credit note's `document_number`,
 * ordered by `created_at DESC` with no secondary tiebreaker. A separate,
 * newer numbering path — `DocumentNumberingService::generateForKeyOnce()`
 * (DocumentNumberingService.php:42-66), used by `InvoiceToCreditNoteConverter`
 * via `CopiesDocumentData::createTargetDocument()` — produces a DIFFERENT
 * format: `CN-{year}-{seq}` (e.g. `CN-2026-0006`). The legacy regex matches
 * the FIRST digit group of that format and extracts the YEAR (`2026`), not
 * the real sequence, computes `nextNumber = 2027`, and reformats it back
 * into the OLD `CN-%05d` shape as `CN-02027` — a document_number that
 * ALREADY EXISTS from a session over a day earlier (confirmed live via
 * direct DB read: `CN-02027` created 2026-08-01 21:50:40; the most recent
 * credit note in the tenant is `CN-2026-0006`, created 2026-08-02 14:38:57).
 * Every subsequent `POST /credit-notes` attempt (any mode) recomputes the
 * SAME colliding number and 500s on the `documents_tenant_id_type_document_number_unique`
 * constraint — deterministically, forever, until fixed (confirmed via two
 * independent full-suite runs, byte-identical collision both times). This
 * BLOCKS MTP-DOC-16/17/18, MTP-DOC-19, MTP-DOC-20, and MTP-DOC-23 below —
 * their assertions are correctly written; the underlying product call they
 * exercise cannot succeed today. NOT fixed here (spec-only reconciliation
 * pass, no product code touched) — recorded in the results ledger for
 * ticketing.
 */
import { test, expect } from '@playwright/test'
import {
  loginAsOwner,
  createCustomer,
  createInvoice,
  confirmInvoice,
  postInvoice,
  createCreditNoteFromInvoice,
  confirmAndPostCreditNote,
  selectPartner,
  addProductLines,
  submitDocumentCreate,
  getInvoice,
  uniqueName,
} from './w1b-support'

test.describe('MTP-DOC — credit notes (W1b)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsOwner(page)
  })

  test('MTP-DOC-16 / 17 / 18: amount-based credit note create, balance-reduction-only-after-post finding, over-credit refusal', async ({ page }) => {
    test.setTimeout(150000)
    const customerName = uniqueName('DOC161819')
    await createCustomer(page, customerName)

    // Same recipe as MTP-DOC-01: subtotal 225.000, post-confirm total 268.750.
    const created = await createInvoice(page, {
      partnerName: customerName,
      lines: [
        { qty: '10', unitPrice: '12.500', taxLabel: 'TVA 19% (19%)' },
        { qty: '4', unitPrice: '25.000', taxLabel: 'TVA 19% (19%)' },
      ],
    })
    expect(created.ok, `create failed: ${JSON.stringify(created.data)}`).toBeTruthy()
    const invoiceId = created.data.id as string
    await confirmInvoice(page, invoiceId)
    const posted = await postInvoice(page, invoiceId)
    expect(posted.status).toBe('posted')
    expect(posted.total).toBe('268.750')
    expect(posted.balance_due).toBe('268.750') // unpaid

    // MTP-DOC-16: amount-based credit of 100.000.
    const cn1 = await createCreditNoteFromInvoice(page, invoiceId, { amount: '100.000', reason: 'Product Return' })
    expect(cn1.ok, `credit note create failed: ${JSON.stringify(cn1.data)}`).toBeTruthy()
    // Stored POSITIVE (no negation).
    // TRIPWIRE (T-B, docs/superpowers/tickets/2026-08-02-credit-note-draft-stamp-and-scale4-totals.md,
    // finding 3): this amount-based DRAFT credit note carries NO 0.600
    // STAMP_CREDIT_NOTE — total equals the raw submitted amount exactly, with
    // no document-level tax applied at Draft (mirrors the invoice-side W1b
    // defect 2 the stamp fix already covers for invoices, but T-B shows
    // credit notes were never wired into that fix). When T-B lands this
    // becomes '100.600' and MTP-DOC-18's remaining-amount arithmetic below
    // shifts accordingly — update both together, deliberately, not silently.
    expect(cn1.data.total).toBe('100.000')
    expect(Number(cn1.data.subtotal)).toBeGreaterThan(0)
    expect(Number(cn1.data.tax_amount)).toBeGreaterThanOrEqual(0)
    expect(cn1.data.status).toBe('draft')

    // MTP-DOC-17 (A3.3, plan): RE-WORDED per the reconciliation pass — this is
    // DESIGNED BEHAVIOUR UNDER REVIEW, not a defect the W1b lane fixed.
    // `allocateCreditNote()` only runs inside `CreditNoteController::post()`
    // (apps/api/.../CreditNoteController.php:349-355), a SEPARATE step from
    // creation — a Draft credit note intentionally does NOT touch the source
    // invoice's balance_due (correct accounting: an unconfirmed credit
    // shouldn't move real balances). See MTP-DOC-19 below for the post-flow
    // half of this same case (confirm -> post -> balance_due DOES drop).
    // Confirmed live: balance_due stays at the full 268.750 while cn1 is Draft.
    const invoiceAfterCn1Draft = await getInvoice(page, invoiceId)
    expect(
      invoiceAfterCn1Draft.balance_due,
      'a Draft credit note must not move the source invoice balance — only post() does (see MTP-DOC-19)'
    ).toBe('268.750')

    // MTP-DOC-18: even while cn1 is still Draft, a further credit note of
    // 200.000 must be REFUSED — `CreditNoteService::createCreditNote()`'s
    // remaining-amount guard sums `invoice.creditNotes()->sum('total')`
    // (apps/api/.../CreditNoteService.php:68-71) regardless of credit-note
    // status, so this check does not depend on cn1 having been posted.
    // remaining = 268.750 - 100.000 (cn1, uncapped by status) = 168.750 < 200.000.
    const overCredit = await createCreditNoteFromInvoice(page, invoiceId, { amount: '200.000', reason: 'Product Return' })
    expect(overCredit.ok, 'expected the over-credit (200.000 > remaining 168.750) to be REFUSED, but it succeeded').toBeFalsy()
  })

  // A3.4 (NEW MTP-DOC-19, plan §A.3): the post-flow half of MTP-DOC-17 above
  // — confirm -> post the credit note (repaired confirmAndPostCreditNote
  // helper, see w1b-support.ts for the routing-defect finding), then assert
  // the source invoice's balance_due actually drops by the credited amount.
  test('MTP-DOC-19: posting a credit note reduces the source invoice balance_due by the credited amount', async ({ page }) => {
    test.setTimeout(150000)
    const customerName = uniqueName('DOC19')
    await createCustomer(page, customerName)

    const created = await createInvoice(page, {
      partnerName: customerName,
      lines: [{ qty: '1', unitPrice: '99.000', taxLabel: 'TVA 19% (19%)' }],
    })
    expect(created.ok, `create failed: ${JSON.stringify(created.data)}`).toBeTruthy()
    const invoiceId = created.data.id as string
    await confirmInvoice(page, invoiceId)
    const posted = await postInvoice(page, invoiceId)
    expect(posted.status).toBe('posted')
    const balanceBefore = posted.balance_due as string
    expect(balanceBefore, 'unpaid at Post time').toBe(posted.total as string)

    const cn = await createCreditNoteFromInvoice(page, invoiceId, { amount: '50.000', reason: 'Product Return' })
    expect(cn.ok, `credit note create failed: ${JSON.stringify(cn.data)}`).toBeTruthy()
    const creditNoteId = cn.data.id as string
    expect(cn.data.status).toBe('draft')

    const postedCreditNote = await confirmAndPostCreditNote(page, creditNoteId)
    expect(postedCreditNote.status, `credit note did not reach posted: ${JSON.stringify(postedCreditNote)}`).toBe(
      'posted',
    )

    const invoiceAfter = await getInvoice(page, invoiceId)
    const expectedBalance = (Number(balanceBefore) - 50).toFixed(3)
    expect(
      invoiceAfter.balance_due,
      'posting the credit note reduces the source invoice balance_due by the credited amount (allocateCreditNote())',
    ).toBe(expectedBalance)
    // This invoice was never paid (balance_due == total throughout), so
    // there is no Paid status to revert here -- it stays Posted. A
    // Paid -> Posted revert specifically on credit-note post is a distinct,
    // unfixtured case (would need a fully-paid invoice first).
    expect(invoiceAfter.status).toBe('posted')
  })

  test('MTP-DOC-20: line-based credit note saves the selected lines', async ({ page }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DOC20')
    await createCustomer(page, customerName)

    // L1: qty 2 @ 40.000 (19%) ; L2: qty 3 @ 20.000 (19%)
    const created = await createInvoice(page, {
      partnerName: customerName,
      lines: [
        { qty: '2', unitPrice: '40.000', taxLabel: 'TVA 19% (19%)' },
        { qty: '3', unitPrice: '20.000', taxLabel: 'TVA 19% (19%)' },
      ],
    })
    expect(created.ok, `create failed: ${JSON.stringify(created.data)}`).toBeTruthy()
    const invoiceId = created.data.id as string
    await confirmInvoice(page, invoiceId)
    await postInvoice(page, invoiceId)

    await page.goto(`/sales/invoices/${invoiceId}`)
    await page.getByRole('button', { name: 'Create Credit Note', exact: true }).click({ timeout: 30000 })
    const dialog = page.getByRole('dialog')
    await expect(dialog.locator('#reason')).toBeVisible({ timeout: 20000 })
    await dialog.getByRole('button', { name: 'Line-Based' }).click()
    // Check only the FIRST line's checkbox (L1: qty 2 @ 40.000 -> net 80.000, VAT 19% = 15.200, line total = 95.200).
    await dialog.locator('tbody tr').first().locator('input[type="checkbox"]').check()
    await dialog.locator('#reason').selectOption({ label: 'Product Return' })

    // REGRESSION GUARD for W1b defect 4 (P1, fixed): `CreateCreditNoteForm`
    // used to validate with `zodResolver(amountBasedSchema)` in BOTH modes.
    // `amount` is `min(1)`-required there but the `#amount` input only exists
    // in the DOM when `creditMode === 'amount'`, so Line-Based mode could never
    // satisfy the schema and Save silently no-opped (react-hook-form blocked
    // `handleSubmit`; zero network requests). The resolver is now mode-aware.
    const responsePromise = page
      .waitForResponse(
        (r) => new URL(r.url()).pathname === '/api/v1/credit-notes' && r.request().method() === 'POST',
        { timeout: 25000 }
      )
      .catch(() => null)
    await dialog.getByRole('button', { name: 'Save', exact: true }).click()
    const response = await responsePromise
    expect(
      response,
      'line-based credit note Save produced NO POST — the mode-aware resolver regressed'
    ).not.toBeNull()
    expect(response?.ok(), `line-based credit note refused: ${await response?.text()}`).toBeTruthy()
    const body = (await response?.json()) as { data: Record<string, unknown> }
    // L1 credited in full: net 2 * 40.000 = 80.000, VAT 19% = 15.200 -> 95.200.
    // TRIPWIRE (T-B, docs/superpowers/tickets/2026-08-02-credit-note-draft-stamp-and-scale4-totals.md,
    // finding 4): `CreditNoteService::createLineBasedCreditNote()` computes
    // subtotal/tax/total via bcmul/bcadd at scale 4 (CreditNoteService.php
    // ~275-291) and writes the raw 4dp string straight to `total` — it is
    // never reformatted to the currency scale (3) the rest of this app uses
    // everywhere else. A `toBeCloseTo(95.2, 3)` numeric compare MASKS this
    // entirely (95.2000 and 95.200 are numerically equal); asserted here as
    // an exact STRING so the fix is falsifiable — when T-B lands this must
    // flip to '95.200' and be updated deliberately, not silently re-pass.
    expect(body.data.total).toBe('95.2000')
    expect(body.data.status).toBe('draft')
  })

  test('MTP-DOC-22: both credit-note create routes load', async ({ page }) => {
    await page.goto('/sales/credit-notes/new')
    await expect(page.getByRole('heading', { name: 'Add Credit Note' })).toBeVisible({ timeout: 15000 })
    await page.goto('/sales/credit-notes/create')
    await expect(page.getByRole('heading', { name: 'New Credit Note' })).toBeVisible({ timeout: 15000 })
  })

  test('MTP-DOC-23: standalone credit note via /new collects a reason and succeeds', async ({ page }) => {
    const customerName = uniqueName('DOC23')
    await createCustomer(page, customerName)

    await page.goto('/sales/credit-notes/new')
    await selectPartner(page, customerName)
    await addProductLines(page, [{ qty: '2', unitPrice: '30.000', taxLabel: 'TVA 19% (19%)' }])

    // REGRESSION GUARD for W1b defect 5 (P1, fixed): `CreditNoteController`
    // requires `reason` server-side (`['required', new Enum(CreditNoteReason::class)]`)
    // but the generic `DocumentForm` used at `/sales/credit-notes/new` had no
    // reason field anywhere in its UI, so this route could only ever 422
    // ("The reason field is required."). The field is now rendered for
    // type=credit_note, reusing the invoice-linked modal's options.
    const reasonSelect = page.getByLabel('Reason', { exact: false })
    await expect(reasonSelect).toBeVisible({ timeout: 15000 })
    await reasonSelect.selectOption({ label: 'Product Return' })

    const result = await submitDocumentCreate(page, '/api/v1/credit-notes')
    expect(result.ok, `standalone credit note refused: ${JSON.stringify(result.data)}`).toBeTruthy()
    // net 2 * 30.000 = 60.000, VAT 19% = 11.400 -> 71.400.
    // TRIPWIRE (T-B, finding 4 — see MTP-DOC-20 above for the full citation):
    // `CreditNoteService::createStandaloneCreditNote()` ALSO computes at
    // scale 4 (CreditNoteService.php ~432-439) and writes the raw string
    // straight through — same defect, different entry point. Exact-string
    // assertion so the fix (scale-3 currency formatting) is falsifiable.
    expect(result.data.subtotal).toBe('60.0000')
    expect(result.data.total).toBe('71.4000')
  })

  test('MTP-DOC-23b: the standalone /new form still refuses to submit with no reason', async ({ page }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DOC23b')
    await createCustomer(page, customerName)

    await page.goto('/sales/credit-notes/new')
    await selectPartner(page, customerName)
    await addProductLines(page, [{ qty: '1', unitPrice: '10.000', taxLabel: 'TVA 19% (19%)' }])

    // Reason left empty -> the client-side required rule must block the submit
    // AND surface a visible message (no silent no-op).
    const result = await submitDocumentCreate(page, '/api/v1/credit-notes')
    expect(result.ok).toBeFalsy()
    expect(result.clientBlocked, 'expected a client-side block, not a server round-trip').toBeTruthy()
    await expect(page.getByText('This field is required').first()).toBeVisible()
  })

  test('MTP-DOC-24: credit note against an invoice belonging to a DIFFERENT partner is refused', async ({ page }) => {
    test.setTimeout(120000)
    const customerAName = uniqueName('DOC24A')
    await createCustomer(page, customerAName)

    const created = await createInvoice(page, {
      partnerName: customerAName,
      lines: [{ qty: '1', unitPrice: '50.000', taxLabel: 'TVA 19% (19%)' }],
    })
    expect(created.ok, `create failed: ${JSON.stringify(created.data)}`).toBeTruthy()
    const invoiceId = created.data.id as string
    await confirmInvoice(page, invoiceId)
    await postInvoice(page, invoiceId)

    // The inline modal has no partner override (it always uses the source
    // invoice's own partner), so the only way to attempt a cross-partner
    // credit note is a direct API call — same authenticated session, real
    // server-side refusal check.
    const bCustomerId = await createCustomer(page, uniqueName('DOC24B'))
    const attempt = await page.request.post('/api/v1/credit-notes', {
      data: {
        source_invoice_id: invoiceId,
        partner_id: bCustomerId,
        amount: '10.000',
        reason: 'return',
      },
    })
    expect(attempt.ok(), 'expected a cross-partner credit note to be REFUSED, but it succeeded').toBeFalsy()
  })
})
