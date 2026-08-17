import { expect, test } from '@playwright/test'
import { apiRequest, loginAsRole } from '../money-campaign/helpers'
import { businessDate, receiptRows } from './receipt-reporting-helpers'

interface FilterOptionsBody {
  data?: {
    terminals?: Array<{
      id: string
      v4_refund_authoring_acknowledged_at: string | null
    }>
  }
}

test.describe('POS disjoint receipt registers', () => {
  test('a refund is absent from sales and present in refunds and voids', async ({ page }, testInfo) => {
    await loginAsRole(page, 'accountant')

    const refunds = await receiptRows(page, ['REFUND', 'VOID'])
    const options = await apiRequest(page, 'GET', '/pos/receipts/filter-options')
    expect(options.status).toBe(200)
    const activeTerminalIds = new Set(
      ((options.body as FilterOptionsBody).data?.terminals ?? [])
        .filter((terminal) => terminal.v4_refund_authoring_acknowledged_at !== null)
        .map((terminal) => terminal.id),
    )
    const refund = refunds.find((row) => activeTerminalIds.has(row.terminal_id))
    if (!refund) {
      throw new Error('Live-stack prerequisite missing: no REFUND/VOID receipt belongs to an acknowledged v4 terminal')
    }
    const date = businessDate(refund.posted_at)

    await page.goto(`/pos/receipts?from_date=${date}&to_date=${date}`)
    await expect(page.getByRole('heading', { name: 'Receipts' })).toBeVisible()
    await expect(page.getByText(refund.receipt_number, { exact: true })).toHaveCount(0)

    await page.goto(`/pos/receipts/refunds?from_date=${date}&to_date=${date}`)
    await expect(page.getByRole('heading', { name: 'Refunds & voids' })).toBeVisible()
    await expect(page.getByRole('link', { name: refund.receipt_number, exact: true })).toBeVisible()
    await page.screenshot({ path: testInfo.outputPath('refunds-register-populated.png'), fullPage: true })
  })
})
