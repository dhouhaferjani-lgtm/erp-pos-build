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
})
