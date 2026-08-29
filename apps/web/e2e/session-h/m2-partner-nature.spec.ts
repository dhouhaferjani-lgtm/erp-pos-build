import { expect, test, type APIRequestContext } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { API_BASE, apiHeaders, loginApi, loginPage, type ApiSession } from './helpers'

test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5174' })
test.describe.configure({ mode: 'serial' })
test.setTimeout(120_000)
const UI_TIMEOUT = 30_000

const SCREENSHOT_DIR = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../../../.playwright-mcp/session-h/m2',
)

interface PartnerRow {
  id: string
  name: string
  customer_category: 'individual' | 'business' | null
  vat_number: string | null
  company_legal_name: string | null
  business_registration_number: string | null
  credit_limit: string | null
}

interface PartnerPage {
  data: PartnerRow[]
  meta: {
    current_page: number
    last_page: number
  }
}

function isNonEmpty(value: string | null): boolean {
  return value !== null && value.trim() !== ''
}

async function listAllCustomerPartners(
  request: APIRequestContext,
  session: ApiSession,
): Promise<PartnerRow[]> {
  const partners: PartnerRow[] = []
  let page = 1

  while (true) {
    const response = await request.get(
      `${API_BASE}/partners?type=customer&per_page=100&page=${page}`,
      { headers: apiHeaders(session) },
    )
    expect(response.ok(), `partner lookup failed: ${response.status()} ${await response.text()}`).toBeTruthy()
    const body = await response.json() as PartnerPage
    partners.push(...body.data)
    if (body.meta.current_page >= body.meta.last_page) return partners
    page += 1
  }
}

test.describe('Session H M2 partner Nature gate', () => {
  let apiSession: ApiSession
  let legacyCompany: PartnerRow
  let legacyWalkIn: PartnerRow

  test.beforeAll(async ({ request }) => {
    apiSession = await loginApi(request)
    const partners = await listAllCustomerPartners(request, apiSession)

    const company = partners.find((partner) =>
      partner.customer_category === null && isNonEmpty(partner.vat_number),
    )
    expect(company, 'demo seeder must expose a null-category customer with a VAT number').toBeDefined()
    legacyCompany = company!

    const walkIn = partners.find((partner) =>
      partner.customer_category === null
      && [
        partner.vat_number,
        partner.company_legal_name,
        partner.business_registration_number,
        partner.credit_limit,
      ].every((value) => !isNonEmpty(value)),
    )
    expect(walkIn, 'demo seeder must expose a null-category customer with no B2B signals').toBeDefined()
    legacyWalkIn = walkIn!
  })

  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' })
  })

  test('customer create requires Nature, then creates an Individual without revealing B2B fields', async ({ page }) => {
    let createRequestCount = 0
    page.on('request', (request) => {
      if (request.method() === 'POST' && request.url().includes('/api/v1/partners')) {
        createRequestCount += 1
      }
    })

    await loginPage(page)
    await page.goto('/sales/customers/new')

    const uniqueName = `Session H M2 Individual ${Date.now()}`
    await page.getByLabel(/^Name/).fill(uniqueName)
    await page.getByLabel(/^Phone$/).fill('+21650000000')
    await expect(page.getByText('B2B Information')).toBeHidden()

    await page.getByRole('button', { name: /^save$/i }).click()
    await expect(page.getByText('Nature is required')).toBeVisible()
    expect(createRequestCount).toBe(0)

    await page.getByLabel(/^Nature/).selectOption('individual')
    await expect(page.getByText('B2B Information')).toBeHidden()

    const createdResponsePromise = page.waitForResponse((response) =>
      response.request().method() === 'POST' && response.url().includes('/api/v1/partners'),
    )
    await page.getByRole('button', { name: /^save$/i }).click()
    const createdResponse = await createdResponsePromise
    expect(createdResponse.status()).toBe(201)
    expect(createRequestCount).toBe(1)
    await expect(page).toHaveURL(/\/sales\/customers$/)
  })

  test('supplier create defaults Nature to Company and shows B2B fields', async ({ page }) => {
    await loginPage(page)
    await page.goto('/purchases/suppliers/new')

    await expect(page.getByLabel(/^Nature/)).toHaveValue('business', { timeout: UI_TIMEOUT })
    await expect(page.getByText('B2B Information')).toBeVisible({ timeout: UI_TIMEOUT })
  })

  test('legacy null-category company remains B2B-visible while a null walk-in stays hidden', async ({ page }) => {
    await loginPage(page)

    await page.goto(`/sales/customers/${legacyCompany.id}/edit`)
    await expect(page.getByLabel(/^Name/)).toHaveValue(legacyCompany.name, { timeout: UI_TIMEOUT })
    await expect(page.getByText('B2B Information')).toBeVisible()
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, 'm2-legacy-company-visible.png'),
      fullPage: true,
    })

    await page.goto(`/sales/customers/${legacyWalkIn.id}/edit`)
    await expect(page.getByLabel(/^Name/)).toHaveValue(legacyWalkIn.name, { timeout: UI_TIMEOUT })
    await expect(page.getByText('B2B Information')).toBeHidden()
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, 'm2-legacy-walk-in-hidden.png'),
      fullPage: true,
    })
  })
})
