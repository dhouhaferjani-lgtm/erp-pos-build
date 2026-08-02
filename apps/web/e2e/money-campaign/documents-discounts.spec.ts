/**
 * MONEY TEST CAMPAIGN — agent W1b — §B `DSC` discounts (MTP-DSC-01, 05, 06).
 *
 * BLOCKED (no UI path — documented, not silently skipped): MTP-DSC-02
 * (discount_percent + discount_amount precedence) and MTP-DSC-03/04
 * (discount_amount-only line discount). The DocumentLineEditor's Discount
 * column is a SINGLE input bound only to `discount_percent`
 * (`apps/web/src/features/documents/components/DocumentLineEditor.tsx:744-764`
 * — `onChange` always sets `discount_amount: null`); there is no second field
 * anywhere in the UI to set `discount_amount`, so a percent+amount precedence
 * test cannot be authored through the web interface at all. This mirrors
 * MTP-DSC-06's own documented absence (whole-document discount) — same class
 * of gap, different field.
 *
 * MTP-DSC-07..18 (promotions/coupons/vouchers) are out of this pass's time
 * budget — they need dedicated Promotions/Coupons/Vouchers admin setup this
 * pass did not reach.
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
