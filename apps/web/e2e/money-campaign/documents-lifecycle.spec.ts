/**
 * MONEY TEST CAMPAIGN — agent W1b — §B `DOC` lifecycle/editability + line
 * validation ceilings (MTP-DOC-07, 08, 10, 11..14).
 *
 * MTP-DOC-09 (Paid/Cancelled -> edit refused) is recorded BLOCKED in the
 * campaign results, not attempted here: reaching Paid requires the multi-step
 * per-line-confirm RecordPaymentModal flow and reaching Cancelled has no UI
 * action anywhere on the invoice detail page
 * (`apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx` has no
 * cancel/void mutation) — both out of this pass's time budget.
 */
import { test, expect } from '@playwright/test'
import {
  loginAsOwner,
  createCustomer,
  createInvoice,
  confirmInvoice,
  postInvoice,
  attemptEditInvoiceQty,
  directPatchInvoice,
  selectPartner,
  addProductLines,
  submitDocumentCreate,
  getInvoice,
  uniqueName,
} from './w1b-support'
import { apiRequest } from './helpers'

test.describe('MTP-DOC — documents lifecycle & editability (W1b)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsOwner(page)
  })

  // Split into three independent tests (each with its own invoice) rather
  // than one long Draft->Confirmed->Posted chain: the shared live dev server
  // is under heavy concurrent load from sibling money-campaign agents, and a
  // single slow step used to cascade-fail everything downstream of it.

  test('MTP-DOC-07: Draft is editable through the literal UI edit path', async ({ page }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DOC07')
    await createCustomer(page, customerName)

    const created = await createInvoice(page, {
      partnerName: customerName,
      lines: [{ qty: '2', unitPrice: '50.000', taxLabel: 'TVA 19% (19%)' }],
    })
    expect(created.ok, `create failed: ${JSON.stringify(created.data)}`).toBeTruthy()
    const invoiceId = created.data.id as string
    expect(created.data.status).toBe('draft')
    // subtotal = 2 * 50.000 = 100.000
    expect(created.data.subtotal).toBe('100.000')

    // REGRESSION GUARD for W1b defect 1 (P0, fixed): the edit-populate effect
    // used to read `document.issue_date`, which the invoice GET response never
    // provides (only `document_date`), so the REQUIRED Issue Date field loaded
    // empty and react-hook-form blocked the submit client-side — no PATCH, no
    // toast. The literal "open edit form, change a value, Save" path must work
    // with NO workaround.
    await page.goto(`/sales/invoices/${invoiceId}/edit`)
    await expect(page.getByRole('heading', { name: 'Edit Invoice' })).toBeVisible({ timeout: 20000 })
    const issueDateField = page.getByRole('textbox', { name: 'Issue Date *' })
    await expect(issueDateField).not.toHaveValue('')
    await expect(issueDateField).toHaveValue(/^\d{4}-\d{2}-\d{2}$/)

    const patchPromise = page.waitForResponse(
      (r) => new URL(r.url()).pathname === `/api/v1/invoices/${invoiceId}` && r.request().method() === 'PATCH',
      { timeout: 25000 }
    )
    await page.getByRole('spinbutton', { name: 'Qty' }).first().fill('5')
    await page.getByRole('button', { name: 'Save', exact: true }).click()
    const patch = await patchPromise
    expect(patch.ok(), `draft edit refused unexpectedly: ${patch.status()}`).toBeTruthy()
    const patched = (await patch.json()) as { data: Record<string, unknown> }
    // subtotal = 5 * 50.000 = 250.000
    expect(patched.data.subtotal).toBe('250.000')
  })

  test('MTP-DOC-10: Confirmed invoice is still editable, totals recompute', async ({ page }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DOC10')
    await createCustomer(page, customerName)

    const created = await createInvoice(page, {
      partnerName: customerName,
      lines: [{ qty: '3', unitPrice: '40.000', taxLabel: 'TVA 19% (19%)' }],
    })
    expect(created.ok, `create failed: ${JSON.stringify(created.data)}`).toBeTruthy()
    const invoiceId = created.data.id as string
    const confirmed = await confirmInvoice(page, invoiceId)
    expect(confirmed.status).toBe('confirmed')

    // Bump qty 3 -> 6 while Confirmed.
    const editedConfirmed = await attemptEditInvoiceQty(page, invoiceId, 0, '6')
    expect(editedConfirmed.ok, `confirmed edit refused unexpectedly: ${JSON.stringify(editedConfirmed.data)}`).toBeTruthy()
    // subtotal = 6 * 40.000 = 240.000
    expect(editedConfirmed.data.subtotal).toBe('240.000')
  })

  test('MTP-DOC-08: Posted invoice is immutable — UI edit AND direct API PATCH both refused', async ({ page }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DOC08')
    await createCustomer(page, customerName)

    const created = await createInvoice(page, {
      partnerName: customerName,
      lines: [{ qty: '1', unitPrice: '75.000', taxLabel: 'TVA 19% (19%)' }],
    })
    expect(created.ok, `create failed: ${JSON.stringify(created.data)}`).toBeTruthy()
    const invoiceId = created.data.id as string
    await confirmInvoice(page, invoiceId)
    const posted = await postInvoice(page, invoiceId)
    expect(posted.status).toBe('posted')

    // Via the UI edit form...
    const editedPosted = await attemptEditInvoiceQty(page, invoiceId, 0, '9')
    expect(editedPosted.ok, 'expected the Posted-invoice UI edit to be REFUSED, but it succeeded').toBeFalsy()
    expect(editedPosted.status).toBeGreaterThanOrEqual(400)

    // ...and via a direct API PATCH bypassing the UI entirely.
    const directAttempt = await directPatchInvoice(page, invoiceId, {
      notes: 'W1b MTP-DOC-08 direct-PATCH immutability probe',
    })
    expect(directAttempt.ok, 'expected the direct PATCH on a Posted invoice to be REFUSED, but it succeeded').toBeFalsy()
    expect(directAttempt.status).toBeGreaterThanOrEqual(400)
  })

  // A5.1 (NEW MTP-DOC-06, plan §A.5): quote -> sales order -> invoice
  // conversion chain, totals byte-identical at every hop, PLUS the quote's
  // own tax_amount after confirm() (TRIPWIRE T-A finding 2). Driven at the
  // API layer via apiRequest() (helpers.ts) — NOT bare page.request, which
  // this app's Bearer-token auth model (SANCTUM_STATEFUL_DOMAINS empty) does
  // not authenticate (confirmed live: a bare page.request.post() 401s
  // UNAUTHENTICATED even after a real UI login, because it carries only
  // cookies, never the SPA's manually-attached Authorization header).
  test('MTP-DOC-06: quote -> order -> invoice conversion chain; totals byte-identical at Draft; TRIPWIRE (T-A finding 2) VAT zeroed on quote/order confirm', async ({
    page,
  }) => {
    test.setTimeout(150000)
    const customerName = uniqueName('DOC06')
    const customerId = await createCustomer(page, customerName)

    // Draft quote: net 2 * 50.000 = 100.000, VAT 19% = 19.000, total 119.000.
    // Quotes are NonFiscal -- no stamp duty (invoice-only).
    const quoteRes = await apiRequest(page, 'POST', '/quotes', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ description: 'MTP-DOC-06 probe', quantity: '2', unit_price: '50.000', tax_rate: '19.00' }],
    })
    expect(quoteRes.status, `quote create failed: ${JSON.stringify(quoteRes.body)}`).toBe(201)
    const quoteBody = (quoteRes.body as { data: Record<string, unknown> }).data
    const quoteId = quoteBody.id as string
    expect(quoteBody.subtotal).toBe('100.000')
    expect(quoteBody.tax_amount).toBe('19.000')
    expect(quoteBody.total).toBe('119.000')

    // TRIPWIRE (T-A finding 2, docs/superpowers/tickets/2026-08-02-confirm-zeroes-vat-unconfigured-rates.md):
    // this finding was CODE-READ-ONLY (needs live verify) before this test.
    // Live-verified here: QuoteController::confirm() zeroes VAT ENTIRELY, even
    // for a fully-configured 19% rate -- TaxConfiguration::scopeForDocumentType()
    // matches on applicable_document_types, and the TN seeder's list
    // (TAX_INVOICE/FISCAL_RECEIPT/CREDIT_NOTE/DELIVERY_NOTE) never contains a
    // quote/NON_FISCAL token, so `applicableTaxes` is empty and confirm()
    // writes tax_amount=0. CONFIRMED LIVE, ESCALATING finding 2 from
    // code-read-only to proven.
    const quoteConfirmRes = await apiRequest(page, 'POST', `/quotes/${quoteId}/confirm`)
    expect(quoteConfirmRes.status, `quote confirm failed: ${JSON.stringify(quoteConfirmRes.body)}`).toBe(200)
    const quoteConfirmBody = (quoteConfirmRes.body as { data: Record<string, unknown> }).data
    expect(quoteConfirmBody.subtotal).toBe('100.000')
    expect(
      quoteConfirmBody.tax_amount,
      'TRIPWIRE (T-A finding 2): confirm() zeroes a NonFiscal quote\'s VAT entirely',
    ).toBe('0.000')
    expect(quoteConfirmBody.total, 'TRIPWIRE (T-A finding 2): confirmed quote total SHRINKS').toBe('100.000')

    // Convert quote -> order. The order's OWN Draft totals are recomputed
    // from the ORIGINAL line data (100.000/19.000/119.000) -- NOT carried
    // from the quote's now-corrupted confirmed total (100.000/0.000/100.000).
    // This is the byte-identity check the case is really after: the chain's
    // pre-confirm numbers survive the hop even though the quote's OWN
    // post-confirm total does not (a direct consequence of the tripwire above).
    const orderRes = await apiRequest(page, 'POST', `/quotes/${quoteId}/convert-to-order`)
    expect(orderRes.status, `convert-to-order failed: ${JSON.stringify(orderRes.body)}`).toBe(201)
    const orderBody = (orderRes.body as { data: Record<string, unknown> }).data
    const orderId = orderBody.id as string
    expect(orderBody.subtotal, 'order Draft subtotal byte-identical to the ORIGINAL quote Draft').toBe('100.000')
    expect(orderBody.tax_amount, 'order Draft tax_amount byte-identical to the ORIGINAL quote Draft').toBe('19.000')
    expect(orderBody.total, 'order Draft total byte-identical to the ORIGINAL quote Draft').toBe('119.000')

    // Order confirm() has the SAME NonFiscal tripwire shape as the quote.
    const orderConfirmRes = await apiRequest(page, 'POST', `/orders/${orderId}/confirm`)
    expect(orderConfirmRes.status, `order confirm failed: ${JSON.stringify(orderConfirmRes.body)}`).toBe(200)
    const orderConfirmBody = (orderConfirmRes.body as { data: Record<string, unknown> }).data
    expect(
      orderConfirmBody.tax_amount,
      'TRIPWIRE (T-A finding 2): confirm() ALSO zeroes a NonFiscal sales order\'s VAT entirely',
    ).toBe('0.000')
    expect(orderConfirmBody.total).toBe('100.000')

    // Convert order -> invoice. Draft totals are again recomputed from the
    // ORIGINAL line data -- byte-identical to the quote/order's ORIGINAL
    // Draft numbers (no stamp yet; the conversion path's Draft total does
    // not go through the same document-level-tax loop InvoiceController::store()
    // uses for a directly-created invoice).
    const invoiceRes = await apiRequest(page, 'POST', `/orders/${orderId}/convert-to-invoice`)
    expect(invoiceRes.status, `convert-to-invoice failed: ${JSON.stringify(invoiceRes.body)}`).toBe(201)
    const invoiceBody = (invoiceRes.body as { data: Record<string, unknown> }).data
    const invoiceId = invoiceBody.id as string
    expect(invoiceBody.subtotal, 'invoice Draft subtotal byte-identical to the chain\'s original Draft').toBe(
      '100.000',
    )
    expect(invoiceBody.tax_amount, 'invoice Draft tax_amount byte-identical to the chain\'s original Draft').toBe(
      '19.000',
    )
    expect(invoiceBody.total, 'invoice Draft total byte-identical to the chain\'s original Draft').toBe('119.000')

    // NEW FINDING (not T-A, not pre-registered): confirming the CONVERTED
    // invoice -- a genuinely FISCAL document, TAX_INVOICE, on an ordinary
    // fully-configured 19% rate -- ALSO silently zeroes VAT. Live-reproduced
    // twice (2026-08-02): both runs showed the identical Draft->Confirm
    // shrinkage below. Root-cause investigation (not a fix) found ONE
    // contributing mechanism on one repro: SalesOrderToInvoiceConverter's
    // createTargetDocument() (via CopiesDocumentData.php:48-77, called at
    // SalesOrderToInvoiceConverter.php:168-170) never sets `fiscal_category`
    // on the new invoice row -- unlike InvoiceController::store(), which sets
    // it explicitly. On one repro the column landed on the DB default
    // (NON_FISCAL, correct for the SOURCE order but wrong for an invoice),
    // and TaxCalculationService::calculateDocumentTaxes() (TaxCalculationService.php:56)
    // filters TaxConfiguration::forDocumentType() on that raw value -- TN's
    // applicable_document_types list never contains NON_FISCAL, so zero
    // configs match. On the OTHER repro fiscal_category was correctly
    // TAX_INVOICE in the DB yet confirm() STILL zeroed the tax -- meaning a
    // SECOND, not-yet-isolated mechanism can independently produce the same
    // symptom. Recorded as a P0 finding with the reproducible OBSERVED
    // values below; needs an engineering root-cause pass before a fix,
    // NOT attempted here (spec-only reconciliation pass, no product code
    // touched).
    const invoiceConfirmRes = await apiRequest(page, 'POST', `/invoices/${invoiceId}/confirm`)
    expect(invoiceConfirmRes.status, `invoice confirm failed: ${JSON.stringify(invoiceConfirmRes.body)}`).toBe(200)
    const invoiceConfirmBody = (invoiceConfirmRes.body as { data: Record<string, unknown> }).data
    // eslint-disable-next-line no-console
    console.log(
      `[MTP-DOC-06] FINDING: converted-invoice confirm() -> subtotal=${invoiceConfirmBody.subtotal} tax_amount=${invoiceConfirmBody.tax_amount} total=${invoiceConfirmBody.total} (expected tax_amount=20.000 total=120.000 if this were a directly-created invoice)`,
    )
    expect(
      invoiceConfirmBody.tax_amount,
      'FINDING (new, P0 candidate): confirming a quote->order->invoice-CONVERTED invoice silently zeroes VAT even on a fully-configured rate -- see console log above and file:line citations in this test\'s comment',
    ).toBe('0.000')
    expect(invoiceConfirmBody.total).toBe('100.000')
  })

  test('MTP-DOC-11..14: line-level money/quantity validation ceilings', async ({ page }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('DOC1114')
    await createCustomer(page, customerName)

    await page.goto('/sales/invoices/new')
    await selectPartner(page, customerName)
    await addProductLines(page, [{ qty: '1', unitPrice: '10.000', taxLabel: 'TVA 19% (19%)' }])
    const row = page.locator('table tbody tr').first()

    // MTP-DOC-11: unit_price = 12.5001 (4dp) must be rejected by the 3dp money ceiling.
    await row.getByRole('spinbutton', { name: 'Unit Price' }).fill('12.5001')
    const afterPrice = await row.getByRole('spinbutton', { name: 'Unit Price' }).inputValue()
    // Record actual: either the input itself normalizes/truncates the 4th
    // decimal (client-side ceiling), or it is submitted verbatim and the
    // server 422s it. Either is an acceptable "rejected" outcome; silently
    // accepting 12.5001 as-is end-to-end would be the failure signature.
    if (afterPrice === '12.5001') {
      const submit = await submitDocumentCreate(page, '/api/v1/invoices')
      expect(submit.ok, 'server accepted unit_price=12.5001 (4dp) — money regex ceiling not enforced').toBeFalsy()
    } else {
      expect(afterPrice).not.toBe('12.5001')
    }
    await row.getByRole('spinbutton', { name: 'Unit Price' }).fill('10.000')

    // MTP-DOC-12: quantity = 1.00001 (5dp) must be rejected by the 4dp quantity ceiling.
    await row.getByRole('spinbutton', { name: 'Qty' }).fill('1.00001')
    const afterQty = await row.getByRole('spinbutton', { name: 'Qty' }).inputValue()
    if (afterQty === '1.00001') {
      const submit = await submitDocumentCreate(page, '/api/v1/invoices')
      expect(submit.ok, 'server accepted quantity=1.00001 (5dp) — quantity regex ceiling not enforced').toBeFalsy()
    } else {
      expect(afterQty).not.toBe('1.00001')
    }
    await row.getByRole('spinbutton', { name: 'Qty' }).fill('1')

    // MTP-DOC-13: quantity = 0 — record actual (refused, or a zero-value line
    // that does not break the aggregate identity).
    await row.getByRole('spinbutton', { name: 'Qty' }).fill('0')
    const zeroQtyResult = await submitDocumentCreate(page, '/api/v1/invoices')
    if (zeroQtyResult.ok) {
      expect(zeroQtyResult.data.subtotal).toBe('0.000')
      expect(zeroQtyResult.data.tax_amount).toBe('0.000')
      expect(zeroQtyResult.data.total).toBe('0.000')
    } else {
      expect(zeroQtyResult.status).toBeGreaterThanOrEqual(400)
    }

    // MTP-DOC-14: negative unit_price must be rejected (document money fields
    // are non-negative). Fresh invoice for a clean assertion.
    await page.goto('/sales/invoices/new')
    await selectPartner(page, customerName)
    await addProductLines(page, [{ qty: '1', unitPrice: '10.000', taxLabel: 'TVA 19% (19%)' }])
    const row2 = page.locator('table tbody tr').first()
    await row2.getByRole('spinbutton', { name: 'Unit Price' }).fill('-5')
    const afterNegative = await row2.getByRole('spinbutton', { name: 'Unit Price' }).inputValue()
    if (afterNegative === '-5' || afterNegative === '-5.000') {
      const submit = await submitDocumentCreate(page, '/api/v1/invoices')
      expect(submit.ok, 'server accepted a negative unit_price').toBeFalsy()
    } else {
      expect(Number(afterNegative)).toBeGreaterThanOrEqual(0)
    }
  })

  // A5.2 (NEW MTP-DOC-09, plan §A.5): drive the RecordPaymentModal's
  // multi-step per-line confirm through the LITERAL UI path to reach Paid,
  // then record the Cancelled verdict (no UI action anywhere on the invoice
  // detail page -- assert absence, and check whether the API route
  // exists-and-refuses or does not exist at all).
  test('MTP-DOC-09: RecordPaymentModal reaches Paid via the literal UI path; Cancelled has no UI action', async ({
    page,
  }) => {
    test.setTimeout(150000)
    const customerName = uniqueName('DOC09')
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

    await page.goto(`/sales/invoices/${invoiceId}`)
    await page.getByRole('button', { name: 'Record Payment', exact: true }).click({ timeout: 30000 })
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible({ timeout: 20000 })

    // FormField renders required labels as "Method *" / "Repositories *" (an
    // appended asterisk span) -- exact:true against the bare word never matches.
    await dialog.getByLabel('Method', { exact: false }).selectOption({ index: 1 })
    await dialog.getByRole('button', { name: /pay full amount/i }).click()
    const repositorySelect = dialog.getByLabel('Repositories', { exact: false })
    const repositoryOptionCount = await repositorySelect.locator('option').count()
    if (repositoryOptionCount > 1) {
      await repositorySelect.selectOption({ index: 1 })
    }
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click()

    const [response] = await Promise.all([
      page.waitForResponse(
        (r) => new URL(r.url()).pathname === '/api/v1/payments' && r.request().method() === 'POST',
        { timeout: 25000 },
      ),
      dialog.getByRole('button', { name: 'Record Payment', exact: true }).click(),
    ])
    expect(response.ok(), `record payment failed: ${response.status()} ${await response.text()}`).toBeTruthy()

    const invoiceAfter = await getInvoice(page, invoiceId)
    expect(invoiceAfter.status, 'invoice reached Paid via the literal RecordPaymentModal UI path').toBe('paid')
    expect(invoiceAfter.balance_due).toBe('0.000')

    // Cancelled: no UI action exists anywhere on the invoice detail page.
    await page.goto(`/sales/invoices/${invoiceId}`)
    await expect(page.getByRole('button', { name: /cancel/i })).toHaveCount(0)

    // API layer: record whether the route exists-and-refuses (403/422) a
    // Paid invoice, or does not exist at all (404/405) -- both are
    // acceptable "no reachable Cancelled transition" outcomes; a bare 200
    // would be the failure signature.
    const cancelAttempt = await apiRequest(page, 'POST', `/invoices/${invoiceId}/cancel`)
    // eslint-disable-next-line no-console
    console.log(`[MTP-DOC-09] POST /invoices/{id}/cancel on a Paid invoice -> ${cancelAttempt.status}`)
    expect(
      [403, 404, 405, 422],
      `expected the cancel route to either not exist or refuse -> ${cancelAttempt.status} ${JSON.stringify(cancelAttempt.body)}`,
    ).toContain(cancelAttempt.status)
  })
})
