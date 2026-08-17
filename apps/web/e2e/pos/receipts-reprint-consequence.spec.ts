import { expect, test, type Page } from '@playwright/test'
import { apiRequest, loginAsRole } from '../money-campaign/helpers'
import { currentCompanyId, receiptRows } from './receipt-reporting-helpers'

interface ReprintEntry {
  receipt_id: string
  copy_number: number
  print_method: string
}

interface ReprintLogBody {
  data?: ReprintEntry[]
}

async function reprintEntries(page: Page, companyId: string, receiptId: string): Promise<ReprintEntry[]> {
  const response = await apiRequest(
    page,
    'GET',
    `/compliance/nf525/reprint-log?company_id=${companyId}&per_page=100`,
  )
  if (response.status !== 200) {
    throw new Error(`Reprint audit lookup failed with HTTP ${String(response.status)}`)
  }
  return ((response.body as ReprintLogBody).data ?? []).filter((entry) => entry.receipt_id === receiptId)
}

test.describe('POS receipt reprint consequence', () => {
  test('print requires confirmation, sends one PDF request, and appends the audit copy', async ({ page }, testInfo) => {
    await loginAsRole(page, 'accountant')
    const receipt = (await receiptRows(page, ['SALE']))[0]
    if (!receipt) throw new Error('Live-stack prerequisite missing: no SALE receipt is available for reprint')
    const companyId = await currentCompanyId(page)
    const before = await reprintEntries(page, companyId, receipt.id)
    const previousCopy = Math.max(0, ...before.map((entry) => entry.copy_number))
    const pdfPath = `/api/v1/pos/receipts/${receipt.id}/pdf`
    let pdfRequests = 0
    page.on('request', (request) => {
      if (new URL(request.url()).pathname === pdfPath) pdfRequests += 1
    })

    await page.goto(`/pos/receipts/${receipt.id}`)
    await expect(page.getByRole('heading', { name: receipt.receipt_number })).toBeVisible()
    expect(pdfRequests).toBe(0)

    await page.getByRole('button', { name: 'Print duplicate' }).click()
    await expect(page.getByRole('heading', { name: 'Create a duplicate ticket?' })).toBeVisible()
    await expect(page.getByText(/recorded in the fiscal reprint log/i)).toBeVisible()
    await page.screenshot({ path: testInfo.outputPath('receipt-reprint-confirm.png'), fullPage: true })
    await page.getByRole('button', { name: 'Cancel' }).click()
    expect(pdfRequests).toBe(0)

    await page.getByRole('button', { name: 'Print duplicate' }).click()
    const pdfResponse = page.waitForResponse((response) => (
      new URL(response.url()).pathname === pdfPath && response.request().method() === 'GET'
    ))
    await page.getByTestId('confirm-dialog-confirm').click()
    expect((await pdfResponse).status()).toBe(200)
    expect(pdfRequests).toBe(1)

    const expectedCopy = previousCopy + 1
    await expect.poll(async () => {
      const entries = await reprintEntries(page, companyId, receipt.id)
      return entries.some((entry) => entry.copy_number === expectedCopy && entry.print_method === 'pdf')
    }).toBe(true)

    await page.goto('/settings/compliance/export')
    const auditRow = page.getByRole('row').filter({ hasText: receipt.receipt_number }).first()
    await expect(auditRow).toBeVisible()
    await expect(auditRow.getByRole('cell').nth(4)).toHaveText(String(expectedCopy))
    await expect(auditRow).toContainText('PDF')
  })
})
