import { expect, test } from '@playwright/test'
import { apiRequest, loginAsRole } from '../money-campaign/helpers'

test.describe('POS receipt reporting permissions', () => {
  test('accountant reaches receipt and compliance reads without terminal management', async ({ page }) => {
    await loginAsRole(page, 'accountant')

    const me = await apiRequest(page, 'GET', '/auth/me')
    expect(me.status).toBe(200)
    const permissions = (me.body as { data?: { permissions?: string[] } }).data?.permissions ?? []
    expect(permissions).toEqual(expect.arrayContaining([
      'pos.view_receipts',
      'pos.view_reports',
      'deliveries.view',
    ]))
    expect(permissions).not.toContain('pos.manage_terminals')

    await page.getByRole('button', { name: 'Point of Sale' }).click()
    const receiptLink = page.locator('a[href="/pos/receipts"]')
    await expect(receiptLink).toBeVisible()
    await expect(page.locator('a[href="/pos/terminals"]')).toHaveCount(0)

    await receiptLink.click()
    await expect(page).toHaveURL(/\/pos\/receipts(?:\?|$)/)
    await expect(page.getByRole('heading', { name: 'Receipts' })).toBeVisible()
    expect((await apiRequest(page, 'GET', '/pos/receipts')).status).toBe(200)

    const complianceLink = page.locator('a[href="/settings/compliance/export"]')
    await expect(complianceLink).toBeVisible()
    await complianceLink.click()
    await expect(page).toHaveURL(/\/settings\/compliance\/export$/)

    await page.goto('/pos/terminals')
    await expect(page).toHaveURL(/\/dashboard$/)
    expect((await apiRequest(page, 'GET', '/pos/terminals')).status).toBe(403)
  })
})
