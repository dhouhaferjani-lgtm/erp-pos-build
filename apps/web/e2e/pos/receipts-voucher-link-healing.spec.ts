import { expect, test, type Page } from '@playwright/test'
import { apiRequest, loginAsRole } from '../money-campaign/helpers'

interface VoucherRow {
  id: string
  code: string
}

interface VoucherListBody {
  data?: VoucherRow[]
}

interface VoucherDetailBody {
  data?: {
    id: string
    code: string
    provenance?: {
      source_receipt_id?: string | null
      source_receipt_number?: string | null
    }
  }
}

async function voucherWithReceiptProvenance(page: Page): Promise<{
  id: string
  code: string
  receiptId: string
  receiptNumber: string
}> {
  for (const source of ['refund', 'exchange_surplus']) {
    const list = await apiRequest(page, 'GET', `/vouchers?source=${source}&per_page=100`)
    if (list.status !== 200) continue
    for (const row of (list.body as VoucherListBody).data ?? []) {
      const detail = await apiRequest(page, 'GET', `/vouchers/${row.id}`)
      if (detail.status !== 200) continue
      const voucher = (detail.body as VoucherDetailBody).data
      const receiptId = voucher?.provenance?.source_receipt_id
      const receiptNumber = voucher?.provenance?.source_receipt_number
      if (voucher && receiptId && receiptNumber) {
        return { id: voucher.id, code: voucher.code, receiptId, receiptNumber }
      }
    }
  }

  throw new Error('Live-stack prerequisite missing: no refund or exchange-surplus voucher has receipt provenance')
}

test.describe('POS voucher receipt link healing', () => {
  test('voucher provenance navigates to the registered receipt detail route', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const fixture = await voucherWithReceiptProvenance(page)

    await page.goto(`/pos/vouchers/${fixture.id}`)
    await expect(page.getByRole('heading', { name: fixture.code })).toBeVisible()
    await page.getByRole('link', { name: fixture.receiptNumber, exact: true }).click()

    await expect(page).toHaveURL(new RegExp(`/pos/receipts/${fixture.receiptId}$`))
    await expect(page.getByRole('heading', { name: fixture.receiptNumber })).toBeVisible()
  })
})
