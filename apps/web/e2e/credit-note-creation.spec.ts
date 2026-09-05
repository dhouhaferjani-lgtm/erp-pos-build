import { test, expect } from './fixtures'

/**
 * F-STG-4 — credit-note creation end-to-end (browser, mocked API).
 *
 * Proves the Path A fix in a real browser: selecting an invoice and creating a
 * full ("all lines") credit note now posts a LINE-BASED payload the backend
 * accepts, instead of the old amount-less request that 422'd with
 * "montant obligatoire". The picker also surfaces a fully-PAID invoice
 * (the opt-in `creditable=1` filter — a validated boolean since gate r1
 * IMPORTANT-1; `creditable=true` is now a 422).
 *
 * The standalone customer-mode `unit_price`-as-string contract is pinned by the
 * pure-unit test `src/features/documents/__tests__/creditNotePayload.test.ts`.
 */

const paidInvoice = {
  id: 'inv-paid-1',
  number: 'INV-2026-0001',
  document_date: '2026-09-01',
  total: '1200.000',
  balance: '0.000',
  currency: 'TND',
  status: 'paid',
  partner_id: 'partner-1',
  partner: { id: 'partner-1', name: 'Acme Corp' },
  lines: [
    {
      id: 'il-1',
      product_id: 'p-1',
      product_code: 'P1',
      product_name: 'Widget',
      description: 'Widget',
      quantity: 3,
      unit_price: '100.000',
      tax_rate: '20.00',
      total: '360.000',
    },
    {
      id: 'il-2',
      product_id: 'p-2',
      product_code: 'P2',
      product_name: 'Gadget',
      description: 'Gadget',
      quantity: 2,
      unit_price: '150.000',
      tax_rate: '20.00',
      total: '360.000',
    },
  ],
}

test.describe('Credit note creation (F-STG-4)', () => {
  test("'all' mode credits a paid invoice via a line-based payload", async ({ authenticatedPage: page }) => {
    // Invoice endpoints — detail is checked first so the list glob doesn't swallow it.
    await page.route('**/api/v1/invoices**', (route) => {
      const url = route.request().url()
      const body = url.includes(`/invoices/${paidInvoice.id}`)
        ? { data: paidInvoice }
        : { data: [paidInvoice], meta: { per_page: 10, has_more: false }, links: {} }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) })
    })

    await page.route('**/api/v1/partners**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [paidInvoice.partner], meta: {}, links: {} }),
      }),
    )

    let creditNoteBody: Record<string, unknown> | null = null
    await page.route('**/api/v1/credit-notes', (route) => {
      if (route.request().method() === 'POST') {
        creditNoteBody = route.request().postDataJSON() as Record<string, unknown>
        return route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({ data: { id: 'cn-1' } }),
        })
      }
      return route.fallback()
    })

    await page.goto('/sales/credit-notes/create')
    await expect(page.getByRole('heading', { name: 'New Credit Note' })).toBeVisible()

    // Open the source-invoice picker and pick the paid invoice.
    await page.locator('[aria-haspopup="listbox"]').first().click()
    await page.getByRole('button', { name: new RegExp(paidInvoice.number) }).click()

    // 'all' is the default line mode — the whole invoice is credited.
    await page.getByRole('button', { name: 'Create Credit Note' }).click()

    await expect.poll(() => creditNoteBody).not.toBeNull()
    const sent = creditNoteBody as unknown as {
      source_invoice_id?: string
      amount?: unknown
      lines?: Array<{ line_id?: string }>
    }
    expect(sent.source_invoice_id).toBe(paidInvoice.id)
    // The fix: a line-based payload (not a bare amount-only request).
    expect(sent.amount).toBeUndefined()
    expect(Array.isArray(sent.lines)).toBe(true)
    expect(sent.lines?.length).toBe(paidInvoice.lines.length)
    expect(sent.lines?.[0]?.line_id).toBe('il-1')
  })
})
