/**
 * MONEY TEST CAMPAIGN — agent W1b — §B `TAX` VAT decomposition (MTP-TAX-01, 08).
 *
 * BLOCKED (not attempted, shared-tenant safety): MTP-TAX-02 (NON_REGISTERED
 * tax status), MTP-TAX-03 (deactivate stamp duty config), MTP-TAX-06 (change
 * the 19% VAT rate after posting). All three require mutating COMPANY-WIDE or
 * COUNTRY-WIDE fiscal configuration (`companies.tax_status`,
 * `TaxConfiguration.is_active`, `TaxConfiguration.percentage_rate`) on the
 * live shared demo-pharmacy-tn tenant that sibling money-campaign agents
 * (W1a, W1c, ...) are concurrently running money-math assertions against —
 * changing the VAT rate or tax status mid-campaign would corrupt every other
 * agent's expected numbers. Also BLOCKED for the same reason: MTP-TAX-07/09/
 * 10/11/12 (VAT period open/close/reopen/file — a global fiscal-period
 * action). These need a dedicated, isolated tenant/window and are left for a
 * follow-up pass; recorded in the campaign results table, not silently
 * dropped.
 */
import { test, expect } from '@playwright/test'
import { loginAsOwner, createCustomer, createInvoice, confirmInvoice, uniqueName } from './w1b-support'
import { apiRequest } from './helpers'

test.describe('MTP-TAX — VAT decomposition (W1b)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsOwner(page)
  })

  test('MTP-TAX-01: 0% VAT line — line_tax 0, stamp applies from Draft onward', async ({ page }) => {
    test.setTimeout(120000)
    const customerName = uniqueName('TAX01')
    await createCustomer(page, customerName)

    const created = await createInvoice(page, {
      partnerName: customerName,
      lines: [{ qty: '1', unitPrice: '50.000', taxLabel: 'Exonéré TVA (0%)' }],
    })
    expect(created.ok, `create failed: ${JSON.stringify(created.data)}`).toBeTruthy()
    expect(created.data.subtotal).toBe('50.000')
    // No VAT (0% line) -> tax_amount is stamp duty ONLY, already at Draft.
    expect(created.data.tax_amount).toBe('1.000')
    expect(created.data.total).toBe('51.000')

    const confirmed = await confirmInvoice(page, created.data.id as string)
    expect(confirmed.tax_amount).toBe('1.000')
    expect(confirmed.total).toBe('51.000')
    // total == subtotal + stamp
    expect((Number(created.data.subtotal) + Number(confirmed.tax_amount)).toFixed(3)).toBe(confirmed.total)
  })

  test('MTP-TAX-08: eco-tax is schema-only — never present in the line response, never in tax_amount', async ({ page }) => {
    const customerName = uniqueName('TAX08')
    await createCustomer(page, customerName)

    const created = await createInvoice(page, {
      partnerName: customerName,
      lines: [{ qty: '1', unitPrice: '77.000', taxLabel: 'TVA 19% (19%)' }],
    })
    expect(created.ok, `create failed: ${JSON.stringify(created.data)}`).toBeTruthy()

    const lines = created.data.lines as Array<Record<string, unknown>>
    expect(lines).toHaveLength(1)
    // Structural absence check against the LIVE response, not just a code
    // read: DocumentLineData (apps/api/.../DocumentLineData.php) has no
    // eco_tax_amount/eco_tax_rate/eco_tax_category fields at all.
    expect(Object.keys(lines[0])).not.toContain('eco_tax_amount')
    expect(Object.keys(lines[0])).not.toContain('eco_tax_rate')
    expect(Object.keys(lines[0])).not.toContain('eco_tax_category')

    // line VAT = subtotal(77.000) * 0.19 = 14.630 exactly, + 1.000 stamp duty
    // = 15.630 — no eco-tax residue anywhere in the decomposition.
    expect(created.data.tax_amount).toBe('15.630')
    expect(created.data.total).toBe('92.630')
  })

  // A4.1 (NEW MTP-TAX-13, plan §A.4). Live-verified BEFORE authoring: the
  // plan's illustrative "13.00, no TN config row" is WRONG for this tenant --
  // TunisiaTaxConfigurationSeeder.php seeds 19/13/7/0 as ACTIVE LINE_ITEMS
  // TaxConfiguration rows, confirmed live (13.00 confirms cleanly, tax_amount
  // unchanged Draft->Confirmed). 21.00 is a genuinely unconfigured rate for
  // TN. The line-editor's tax <select> only lists CONFIGURED rates
  // (19/13/7/0), so an unconfigured rate cannot be selected through the
  // literal UI form -- a direct API call is the only way to author it, same
  // pattern as MTP-DOC-24's cross-partner probe.
  //
  // FIXED (2026-08-02, W-1 documents-defects lane defect 3 -- was TRIPWIRE
  // T-A finding 1, docs/superpowers/tickets/2026-08-02-confirm-zeroes-vat-unconfigured-rates.md):
  // TaxCalculationService STEP 1 used to contribute ZERO for any line rate
  // with no matching active TaxConfiguration row, so confirm() silently
  // zeroed a genuinely unconfigured 21% rate that the Draft had correctly
  // priced. Per the orchestrator ruling, confirm() now honours the explicit
  // line rate directly when no config row matches -- draft and confirmed
  // totals are identical again.
  test('MTP-TAX-13: confirm() honours an explicit line rate with no active TaxConfiguration (not silently zeroed)', async ({
    page,
  }) => {
    test.setTimeout(60000)
    const customerName = uniqueName('TAX13')
    const customerId = await createCustomer(page, customerName)

    const created = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ description: 'MTP-TAX-13 probe line', quantity: '1', unit_price: '100.000', tax_rate: '21.00' }],
    })
    expect(created.status, `create failed: ${JSON.stringify(created.body)}`).toBe(201)
    const createdBody = (created.body as { data: Record<string, unknown> }).data
    const invoiceId = createdBody.id as string

    // Draft: the line's own tax_rate * subtotal is applied directly (not via
    // TaxConfiguration matching) -- 21.000 line VAT + 1.000 stamp = 22.000.
    expect(createdBody.subtotal).toBe('100.000')
    expect(
      createdBody.tax_amount,
      'Draft: line VAT computed from the raw tax_rate, unaffected by TaxConfiguration matching',
    ).toBe('22.000')
    expect(createdBody.total).toBe('122.000')

    // FIX: confirm() recomputes tax via TaxCalculationService::
    // calculateDocumentTaxes(); STEP 1 no longer drops an unmatched rate --
    // it falls back to the line's own explicit rate. Draft and confirmed
    // totals must now match exactly (the draft==confirm identity 18e61a554
    // established, extended to unconfigured rates).
    const confirmed = await apiRequest(page, 'POST', `/invoices/${invoiceId}/confirm`)
    expect(confirmed.status, `confirm failed: ${JSON.stringify(confirmed.body)}`).toBe(200)
    const confirmedBody = (confirmed.body as { data: Record<string, unknown> }).data
    expect(confirmedBody.subtotal).toBe('100.000')
    expect(
      confirmedBody.tax_amount,
      'FIX: confirm() must honour the unconfigured 21% line VAT, not silently zero it',
    ).toBe('22.000')
    expect(confirmedBody.total, 'FIX: draft and confirmed totals must match').toBe('122.000')
  })
})
