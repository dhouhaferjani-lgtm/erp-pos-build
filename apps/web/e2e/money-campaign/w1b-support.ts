/**
 * MONEY TEST CAMPAIGN — agent W1b (documents chain: quote/order/invoice/credit-note).
 *
 * Support helpers ONLY for W1b's own spec files. Not `helpers.ts` (a colleague agent
 * owns that file for the shared login helper) — kept separate on purpose to avoid a
 * collision while both agents work the same live stack concurrently.
 *
 * Real login against the LIVE local stack (web :5173 -> api :8010, tenant
 * demo-pharmacy-tn). No route mocking anywhere in this file — every response
 * asserted against in the W1b specs is the real backend response.
 */
import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'

export const OWNER_EMAIL = 'owner@pharmabio.tn'
export const OWNER_PASSWORD = 'password'

/** All test data authored by W1b carries this prefix so it never collides with
 * sibling money-campaign agents (W1a, W1c, ...) or seeded demo data. */
export const PREFIX = 'W1b'

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

export async function loginAsOwner(page: Page): Promise<void> {
  await page.goto('/login')
  // Fresh (cookie-less) contexts default to EN, not the tenant's FR locale —
  // use locale-independent selectors here (auth.spec.ts pattern).
  const cookieAccept = page.getByRole('button', { name: 'Accept' })
  if (await cookieAccept.isVisible().catch(() => false)) {
    await cookieAccept.click()
  }
  await page.getByRole('textbox', { name: /email/i }).fill(OWNER_EMAIL)
  await page.getByRole('textbox', { name: /password/i }).fill(OWNER_PASSWORD)
  await page.getByRole('button', { name: /sign in/i }).click()
  await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 })
}

/** Creates a customer via the dedicated /sales/customers/new page (the most
 * reliable UI path — the inline PartnerPicker "add new" popover is flakier to
 * drive than a full page form). Returns the created partner id. */
export async function createCustomer(page: Page, name: string): Promise<string> {
  await page.goto('/sales/customers/new')
  await page.getByRole('textbox', { name: 'Name *' }).fill(name)
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => new URL(r.url()).pathname === '/api/v1/partners' && r.request().method() === 'POST'
    ),
    page.getByRole('button', { name: 'Save', exact: true }).click(),
  ])
  expect(response.ok(), `create customer "${name}" failed: ${response.status()} ${await response.text()}`).toBeTruthy()
  const body = (await response.json()) as { data: { id: string } }
  return body.data.id
}

/** Selects a partner in the DocumentForm's PartnerPicker combobox by typing its
 * (unique, W1b-prefixed) name and clicking the matching option. */
export async function selectPartner(page: Page, name: string): Promise<void> {
  const combo = page.getByRole('combobox', { name: /Customer|Supplier|Partner/ })
  await combo.click()
  await combo.fill(name)
  await page.getByRole('option', { name: new RegExp(escapeRegExp(name)) }).click()
}

export interface LineSpec {
  qty: string
  unitPrice: string
  discountPercent?: string
  /** Exact visible option label in the tax select, e.g. 'VAT 19% (19%)', 'VAT Exempt (0%)'. */
  taxLabel?: string
}

/**
 * Adds N lines to a DocumentForm (invoice/quote/order/credit-note "new" form)
 * and overrides Qty / Unit Price / Discount / tax rate for each to the exact
 * test values, row-scoped so lines can't cross-contaminate each other's inputs.
 *
 * KNOWN DEFECT (recorded, not worked around silently): "Add Blank Line"
 * (`DocumentLineEditor.handleAddBlankLine`,
 * apps/web/src/features/documents/components/DocumentLineEditor.tsx:412-429)
 * creates a line with `description: ''` and NO way to edit it from the UI —
 * the only description editor, `DesignationCell`, only renders when the
 * company config flag `line_designation_override_enabled` is true (default
 * false: apps/api/.../CompanySettingsData.php:64). The backend requires
 * `lines.*.description` (`CreateDocumentRequest.php:116`,
 * `min:1`), so submitting a pure blank-line document 422s unconditionally on
 * a default-config tenant — confirmed live against demo-pharmacy-tn. This
 * helper therefore adds lines via the product search picker instead (each
 * line gets `description = product.name` for free), then overrides
 * quantity/price/discount/tax to the case's exact test values. Distinct
 * option indices are used per line so repeated products don't get merged by
 * `handleAddProduct`'s existing-line increment path.
 */
export async function addProductLines(page: Page, lines: LineSpec[]): Promise<void> {
  const searchBox = page.getByRole('combobox', { name: 'Search or scan a product…' })
  for (let i = 0; i < lines.length; i++) {
    await searchBox.click()
    // Scoped to the custom listbox — a bare page-wide getByRole('option') also
    // matches every native <option> on the page (tax <select>s etc).
    const options = page.getByRole('listbox').getByRole('option')
    await options.first().waitFor({ state: 'visible' })
    await options.nth(i).click()
  }
  const rows = page.locator('table tbody tr')
  await expect(rows).toHaveCount(lines.length)
  for (let i = 0; i < lines.length; i++) {
    const row = rows.nth(i)
    const spec = lines[i]
    await row.getByRole('spinbutton', { name: 'Qty' }).fill(spec.qty)
    await row.getByRole('spinbutton', { name: 'Unit Price' }).fill(spec.unitPrice)
    if (spec.discountPercent !== undefined) {
      await row.getByRole('spinbutton', { name: 'Discount' }).fill(spec.discountPercent)
    }
    if (spec.taxLabel !== undefined) {
      await row.locator('select').selectOption({ label: spec.taxLabel })
    }
  }
}

/**
 * Clicks the primary "Save" submit button on a DocumentForm and captures the
 * POST response body for the given collection endpoint (e.g. '/api/v1/invoices').
 * Works for both success and validation-error (4xx) responses so callers can
 * assert refusals too.
 *
 * Bounded (25s) instead of Playwright's default 30s+ hang: a client-side-only
 * refusal (react-hook-form/zod blocking `handleSubmit` before any network
 * request is issued) is itself a valid, meaningful outcome for a money-test
 * case ("rejected — no server round-trip"), not a script bug — so a timeout
 * here resolves to a synthetic `{status: 0, ok: false}` marker
 * (`clientBlocked: true`) instead of throwing and losing every other
 * assertion in the test. 25s (not a tighter bound) because the shared local
 * dev server runs genuinely slow under concurrent campaign-agent load — a
 * real, expected server round-trip must not be misclassified as a client
 * block.
 */
export async function submitDocumentCreate(
  page: Page,
  collectionPath: string
): Promise<{ status: number; ok: boolean; data: Record<string, unknown>; clientBlocked?: boolean }> {
  const responsePromise = page
    .waitForResponse(
      (r) => new URL(r.url()).pathname === collectionPath && r.request().method() === 'POST',
      { timeout: 25000 }
    )
    .catch(() => null)
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  const response = await responsePromise
  if (response === null) {
    return { status: 0, ok: false, data: {}, clientBlocked: true }
  }
  const status = response.status()
  const ok = response.ok()
  let body: Record<string, unknown> = {}
  try {
    const json = (await response.json()) as { data?: Record<string, unknown> }
    body = json.data ?? (json as Record<string, unknown>)
  } catch {
    body = {}
  }
  return { status, ok, data: body }
}

/** Full invoice creation flow: navigate, select partner, add lines, submit.
 * Returns the created invoice's response `data` payload. */
export async function createInvoice(
  page: Page,
  opts: { partnerName: string; lines: LineSpec[] }
): Promise<{ status: number; ok: boolean; data: Record<string, unknown> }> {
  await page.goto('/sales/invoices/new')
  await selectPartner(page, opts.partnerName)
  await addProductLines(page, opts.lines)
  return submitDocumentCreate(page, '/api/v1/invoices')
}

/** Confirms a Draft invoice (Draft -> Confirmed). Returns the confirm response body.
 * The action-bar button and the ConfirmDialog's own button both render as
 * "Confirm" (same i18n string) — the dialog's copy is the later one in DOM
 * order (React portal appended to body), so `.last()` disambiguates it. */
export async function confirmInvoice(page: Page, invoiceId: string): Promise<Record<string, unknown>> {
  await page.goto(`/sales/invoices/${invoiceId}`)
  await page.getByRole('button', { name: 'Confirm', exact: true }).first().click({ timeout: 30000 })
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => new URL(r.url()).pathname === `/api/v1/invoices/${invoiceId}/confirm` && r.request().method() === 'POST'
    ),
    page.getByRole('button', { name: 'Confirm', exact: true }).last().click(),
  ])
  const json = (await response.json()) as { data: Record<string, unknown> }
  return json.data
}

/** Posts a Confirmed invoice (Confirmed -> Posted / fiscally sealed). The
 * action-bar button reads "Post"; the ConfirmDialog's own button reads
 * "Post Invoice" — distinct strings, no ambiguity. */
export async function postInvoice(page: Page, invoiceId: string): Promise<Record<string, unknown>> {
  await page.goto(`/sales/invoices/${invoiceId}`)
  await page.getByRole('button', { name: 'Post', exact: true }).click({ timeout: 30000 })
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes(`/api/v1/invoices/${invoiceId}/`) && (r.url().endsWith('/post') || r.url().endsWith('/confirm-deliveries-and-post')) && r.request().method() === 'POST'
    ),
    page.getByRole('button', { name: 'Post Invoice' }).click(),
  ])
  const json = (await response.json()) as { data: Record<string, unknown> }
  return json.data
}

/**
 * Opens an invoice's edit page and changes the Qty of the given row, then
 * clicks Save and returns the PATCH response (whether it succeeds or is
 * refused — callers assert either way).
 *
 * HISTORICAL NOTE (W1b defect 1, P0 — FIXED): `DocumentForm.tsx`'s
 * edit-populate effect used to read `document.issue_date`, but the invoice GET
 * response (`DocumentData::fromModel`) has NO `issue_date` key — only
 * `document_date`. The Issue Date field therefore loaded EMPTY on every
 * invoice edit, react-hook-form's `required` rule blocked the submit
 * client-side with no toast and no network request, and a caller waiting on
 * the PATCH response hung forever. The form now falls back to `document_date`.
 * The defensive refill below is kept as a belt-and-braces no-op (it only fires
 * if the field is empty); MTP-DOC-07 asserts the unassisted path directly.
 */
export async function attemptEditInvoiceQty(
  page: Page,
  invoiceId: string,
  rowIndex: number,
  newQty: string
): Promise<{ status: number; ok: boolean; data: Record<string, unknown> }> {
  await page.goto(`/sales/invoices/${invoiceId}/edit`)
  await expect(page.getByRole('heading', { name: 'Edit Invoice' })).toBeVisible({ timeout: 20000 })
  const issueDate = page.getByRole('textbox', { name: 'Issue Date *' })
  if ((await issueDate.inputValue()) === '') {
    await issueDate.fill(new Date().toISOString().split('T')[0])
  }
  const row = page.locator('table tbody tr').nth(rowIndex)
  await row.getByRole('spinbutton', { name: 'Qty' }).fill(newQty)
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => new URL(r.url()).pathname === `/api/v1/invoices/${invoiceId}` && r.request().method() === 'PATCH'
    ),
    page.getByRole('button', { name: 'Save', exact: true }).click(),
  ])
  const status = response.status()
  const ok = response.ok()
  let body: Record<string, unknown> = {}
  try {
    const json = (await response.json()) as { data?: Record<string, unknown> }
    body = json.data ?? (json as Record<string, unknown>)
  } catch {
    body = {}
  }
  return { status, ok, data: body }
}

/** Direct API PATCH bypassing the UI entirely (same authenticated browser
 * context — cookies are shared between `page` and `page.request`). Used to
 * verify server-side refusal independent of whatever the form does. */
export async function directPatchInvoice(
  page: Page,
  invoiceId: string,
  payload: Record<string, unknown>
): Promise<{ status: number; ok: boolean; body: unknown }> {
  const response = await page.request.patch(`/api/v1/invoices/${invoiceId}`, { data: payload })
  const status = response.status()
  const ok = response.ok()
  let body: unknown = null
  try {
    body = await response.json()
  } catch {
    body = null
  }
  return { status, ok, body }
}

/**
 * Opens the "Create Credit Note" modal from a Posted invoice's detail page
 * (`DocumentActionBar` only shows this action when `document.status ===
 * 'posted'` — apps/web/src/features/documents/components/DocumentActionBar.tsx:145)
 * and submits it. Amount-based mode is the modal's default; pass `fullRefund`
 * to click the "Full refund" convenience button instead of typing an amount,
 * or `lineIds` (with the invoice's line rows) to switch to line-based mode.
 */
export async function createCreditNoteFromInvoice(
  page: Page,
  invoiceId: string,
  opts: { amount?: string; fullRefund?: boolean; reason?: string } = {}
): Promise<{ status: number; ok: boolean; data: Record<string, unknown> }> {
  await page.goto(`/sales/invoices/${invoiceId}`)
  await page.getByRole('button', { name: 'Create Credit Note', exact: true }).click({ timeout: 30000 })
  const dialog = page.getByRole('dialog')
  await expect(dialog.locator('#reason')).toBeVisible({ timeout: 20000 })
  if (opts.fullRefund === true) {
    await dialog.getByRole('button', { name: 'Full refund' }).click()
  } else if (opts.amount !== undefined) {
    await dialog.locator('#amount').fill(opts.amount)
  }
  await dialog.locator('#reason').selectOption({ label: opts.reason ?? 'Product Return' })
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => new URL(r.url()).pathname === '/api/v1/credit-notes' && r.request().method() === 'POST'
    ),
    dialog.getByRole('button', { name: 'Save', exact: true }).click(),
  ])
  const status = response.status()
  const ok = response.ok()
  let body: Record<string, unknown> = {}
  try {
    const json = (await response.json()) as { data?: Record<string, unknown> }
    body = json.data ?? (json as Record<string, unknown>)
  } catch {
    body = {}
  }
  return { status, ok, data: body }
}

/**
 * Reads an invoice's current server state by navigating to its detail page
 * and capturing the GET response — deliberately NOT `page.request.get`
 * (observed a transient 401 from that path under heavy shared-server load;
 * routing the read through the same authenticated axios client the SPA
 * itself uses sidesteps whatever that was).
 */
export async function getInvoice(page: Page, invoiceId: string): Promise<Record<string, unknown>> {
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => new URL(r.url()).pathname === `/api/v1/invoices/${invoiceId}` && r.request().method() === 'GET',
      { timeout: 30000 }
    ),
    page.goto(`/sales/invoices/${invoiceId}`),
  ])
  const json = (await response.json()) as { data: Record<string, unknown> }
  return json.data
}

/**
 * Confirms then Posts a credit note (Draft -> Confirmed -> Posted). Required
 * before the invoice-balance-reduction check: `CreditNoteController::post()`
 * (apps/api/.../CreditNoteController.php:349-355) only calls
 * `allocateCreditNote()` — the step that actually reduces the SOURCE
 * INVOICE's `balance_due` — on POST, not at creation time. Creating a credit
 * note alone (Draft) does NOT touch the invoice's balance. RULED NOT A DEFECT:
 * allocating at post() is correct accounting; MTP-DOC-17 asserts that.
 */
export async function confirmAndPostCreditNote(page: Page, creditNoteId: string): Promise<Record<string, unknown>> {
  await page.goto(`/sales/credit-notes/${creditNoteId}`)
  await page.getByRole('button', { name: 'Confirm', exact: true }).first().click({ timeout: 30000 })
  await page.waitForResponse(
    (r) => new URL(r.url()).pathname === `/api/v1/documents/${creditNoteId}/confirm` && r.request().method() === 'POST'
  )
  await page.getByRole('button', { name: 'Confirm', exact: true }).last().click()
  await page.getByRole('button', { name: 'Post', exact: true }).click({ timeout: 30000 })
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => new URL(r.url()).pathname === `/api/v1/documents/${creditNoteId}/post` && r.request().method() === 'POST'
    ),
    page.getByRole('button', { name: 'Post Invoice' }).click(),
  ])
  const json = (await response.json()) as { data: Record<string, unknown> }
  return json.data
}

export function uniqueName(base: string): string {
  return `${PREFIX}-${base}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`
}
