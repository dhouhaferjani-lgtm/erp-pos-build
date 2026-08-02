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
  uniqueName,
} from './w1b-support'

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

  test('MTP-DOC-11..14: line-level money/quantity validation ceilings', async ({ page }) => {
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
})
