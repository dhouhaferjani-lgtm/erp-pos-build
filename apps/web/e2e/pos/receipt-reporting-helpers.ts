import type { Page } from '@playwright/test'
import { apiRequest } from '../money-campaign/helpers'

export interface ReceiptRow {
  id: string
  receipt_number: string
  posted_at: string
  invoice_type_code: string
  terminal_id: string
}

interface ReceiptListBody {
  data?: {
    data?: ReceiptRow[]
  }
}

export async function receiptRows(page: Page, invoiceTypeCodes: string[]): Promise<ReceiptRow[]> {
  const types = invoiceTypeCodes
    .map((code) => `invoice_type_codes%5B%5D=${encodeURIComponent(code)}`)
    .join('&')
  const response = await apiRequest(page, 'GET', `/pos/receipts?${types}&per_page=100`)
  if (response.status !== 200) {
    throw new Error(`Receipt fixture lookup failed with HTTP ${String(response.status)}`)
  }

  return (response.body as ReceiptListBody).data?.data ?? []
}

export function businessDate(instant: string, timeZone = 'Africa/Tunis'): string {
  const parts = new Intl.DateTimeFormat('en', {
    day: '2-digit',
    month: '2-digit',
    timeZone,
    year: 'numeric',
  }).formatToParts(new Date(instant))
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((value) => value.type === type)?.value
  const year = part('year')
  const month = part('month')
  const day = part('day')
  if (!year || !month || !day) throw new Error(`Could not derive the business date for ${instant}`)
  return `${year}-${month}-${day}`
}

export async function currentCompanyId(page: Page): Promise<string> {
  const companyId = await page.evaluate(() => {
    const raw = window.localStorage.getItem('autoerp-company')
    if (!raw) return null
    try {
      const parsed = JSON.parse(raw) as { state?: { currentCompanyId?: unknown } }
      return typeof parsed.state?.currentCompanyId === 'string' ? parsed.state.currentCompanyId : null
    } catch {
      return null
    }
  })
  if (!companyId) throw new Error('The signed-in live fixture has no selected company')
  return companyId
}
