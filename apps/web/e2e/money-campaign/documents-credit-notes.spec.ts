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
 * FIXED (2026-08-02, W-1 documents-defects lane, defect 1): every credit
 * note creation through `CreditNoteController::store()` (amount-based,
 * line-based, AND standalone) was PERMANENTLY BROKEN on this tenant.
 * `CreditNoteService::generateCreditNoteNumber()` computed the next number
 * with the regex `/CN-(\d+)/` against the MOST RECENT credit note's
 * `document_number`, ordered by `created_at DESC`. A separate, newer
 * numbering path — `DocumentNumberingService::generateForKeyOnce()`, used by
 * `InvoiceToCreditNoteConverter` via `CopiesDocumentData::createTargetDocument()`
 * — produces a DIFFERENT format: `CN-{year}-{seq}` (e.g. `CN-2026-0006`). The
 * legacy regex greedily matched the FIRST digit group of that format (the
 * YEAR), computed `nextNumber = year + 1`, and reformatted it back into the
 * OLD `CN-%05d` shape — a `document_number` that already existed, 500ing on
 * the unique constraint on every subsequent create. Fixed by unifying
 * `CreditNoteService` onto `DocumentNumberingService` (the same atomic,
 * `lockForUpdate`-guarded `document_sequences` counter every other document
 * type already uses) instead of parsing `document_number` strings at all —
 * this also closes the underlying race condition the old MAX-by-regex
 * approach had. Regression test: `CreditNoteServiceTest::it_generates_collision_free_numbers_when_a_legacy_format_row_exists`.
 * This previously BLOCKED MTP-DOC-16/17/18, MTP-DOC-19, MTP-DOC-20, and
 * MTP-DOC-23 below — all now exercise the real product call end-to-end.
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
  // — confirm -> post the credit note through the REAL detail-page buttons
  // (confirmAndPostCreditNote, w1b-support.ts — drives CreditNoteDetailPage's
  // own Confirm/Post actions now that they hit the real /credit-notes routes,
  // W-1 documents-defects lane defect 2 fix), then assert the source
  // invoice's balance_due actually drops by the credited amount.
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
    // The credited amount is the credit note's POSTED total, not the raw
    // amount typed in the modal: CreditNoteController::confirm() runs the
    // document-tax pipeline, which adds the 0.600 TND STAMP_CREDIT_NOTE duty
    // on TN tenants (50.000 entered -> 50.600 posted). Assert relationally
    // against the posted total so the case stays correct across tax configs.
    // Scale-3 integer-millime arithmetic -- exact within float range.
    const toMillimes = (v: string): number => Math.round(Number(v) * 1000)
    const expectedBalance = (
      (toMillimes(balanceBefore) - toMillimes(postedCreditNote.total as string)) / 1000
    ).toFixed(3)
    expect(
      invoiceAfter.balance_due,
      'posting the credit note reduces the source invoice balance_due by the posted credit-note total (allocateCreditNote())',
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
    // RECONCILED (2026-08-02, W-1 documents-defects lane re-run): the T-B
    // finding (docs/superpowers/tickets/2026-08-02-credit-note-draft-stamp-and-scale4-totals.md)
    // predicted `CreditNoteService::createLineBasedCreditNote()`'s raw scale-4
    // PHP computation (bcmul/bcadd at scale 4, CreditNoteService.php ~275-291,
    // never explicitly reformatted to currency scale 3) would leak a
    // '95.2000' total through the API. Live-verified here: it does NOT --
    // `documents.total` is `decimal(15,3)`, so the database column itself
    // truncates the raw scale-4 string to scale 3 on write, and the API
    // response is correctly '95.200'. This assertion was BLOCKED entirely by
    // the credit-note numbering collision (defect 1, now fixed) in every
    // prior run, so this is the first live observation of the actual value.
    // Exact-string assertion kept (not `toBeCloseTo`) so any future
    // regression to the raw scale-4 value is still falsifiable.
    expect(body.data.total).toBe('95.200')
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
    // RECONCILED (2026-08-02, W-1 documents-defects lane re-run — see
    // MTP-DOC-20 above for the full citation): `CreditNoteService::
    // createStandaloneCreditNote()` ALSO computes at scale 4
    // (CreditNoteService.php ~432-439) and writes the raw string straight
    // through, but `documents.subtotal`/`total` are `decimal(15,3)`, so the
    // database truncates to scale 3 on write. Live-verified correctly
    // formatted; was BLOCKED by the numbering collision (defect 1, now
    // fixed) in every prior run.
    expect(result.data.subtotal).toBe('60.000')
    expect(result.data.total).toBe('71.400')
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
