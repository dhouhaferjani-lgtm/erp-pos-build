import { expect, test, type APIRequestContext, type Page } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5174' })
test.describe.configure({ mode: 'serial' })
test.setTimeout(120_000)

const API_BASE = process.env.API_BASE ?? 'http://127.0.0.1:8011/api/v1'
const DEMO_CREDENTIALS = {
  email: 'session-h-demo@demo.local',
  password: 'SessionH1!Demo',
} as const
const SCREENSHOT_DIR = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../../../.playwright-mcp/session-h/m1',
)

interface ApiSession {
  token: string
  companyId: string
}

interface PartnerRow {
  id: string
  name: string
  vat_number: string | null
  street_address: string | null
  city: string | null
  country_code: string | null
}

function apiHeaders(session: ApiSession): Record<string, string> {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${session.token}`,
    'X-Company-Id': session.companyId,
  }
}

async function loginApi(request: APIRequestContext): Promise<ApiSession> {
  const login = await request.post(`${API_BASE}/auth/login`, {
    headers: { Accept: 'application/json' },
    data: DEMO_CREDENTIALS,
  })
  expect(login.ok(), `API login failed: ${login.status()} ${await login.text()}`).toBeTruthy()
  const loginBody = await login.json() as { data: { token: string } }

  const companies = await request.get(`${API_BASE}/user/companies`, {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${loginBody.data.token}`,
    },
  })
  expect(companies.ok(), `company lookup failed: ${companies.status()} ${await companies.text()}`).toBeTruthy()
  const companyBody = await companies.json() as { data: Array<{ id: string; is_primary?: boolean }> }
  const company = companyBody.data.find((row) => row.is_primary === true) ?? companyBody.data[0]
  expect(company, 'demo tenant must expose a company').toBeDefined()

  return { token: loginBody.data.token, companyId: company!.id }
}

async function loginPage(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.localStorage.setItem('autoerp-cookie-consent', 'accepted')
  })
  await page.goto('/login')
  await page.getByLabel(/email address/i).fill(DEMO_CREDENTIALS.email)
  await page.getByLabel(/^password$/i).fill(DEMO_CREDENTIALS.password)
  await page.getByRole('button', { name: /sign in/i }).click()
  await expect(page).not.toHaveURL(/\/login/, { timeout: 15_000 })
  await expect(page.getByRole('button', { name: /profile/i })).toBeVisible({ timeout: 15_000 })
}

async function ensureVatPartner(request: APIRequestContext, session: ApiSession): Promise<PartnerRow> {
  const existing = await request.get(`${API_BASE}/partners?type=customer&per_page=100`, {
    headers: apiHeaders(session),
  })
  expect(existing.ok(), `partner lookup failed: ${existing.status()} ${await existing.text()}`).toBeTruthy()
  const existingBody = await existing.json() as { data: PartnerRow[] }
  const vatPartner = existingBody.data.find((partner) => partner.vat_number !== null)
  if (vatPartner !== undefined) return vatPartner

  const created = await request.post(`${API_BASE}/partners`, {
    headers: apiHeaders(session),
    data: {
      name: 'Session H VAT Demo Partner',
      type: 'customer',
      country_code: 'TN',
      vat_number: '7654321AM999',
    },
  })
  expect(created.ok(), `VAT partner setup failed: ${created.status()} ${await created.text()}`).toBeTruthy()
  return (await created.json() as { data: PartnerRow }).data
}

test.describe('Session H M1 data-shape drift gate', () => {
  let apiSession: ApiSession

  test.beforeAll(async ({ request }) => {
    apiSession = await loginApi(request)
  })

  test('customer list renders the API vat_number in the Tax ID column', async ({ page, request }) => {
    const partner = await ensureVatPartner(request, apiSession)
    await loginPage(page)
    await page.goto('/sales/customers')
    await page.getByPlaceholder(/search/i).fill(partner.name)

    const row = page.getByRole('row', { name: new RegExp(partner.name) })
    await expect(row).toContainText(partner.vat_number!, { timeout: 30_000 })
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'm1-customer-vat-list.png'), fullPage: true })
  })

  test('quote inline partner modal persists VAT and named address fields', async ({ page, request }) => {
    await loginPage(page)
    await page.goto('/sales/quotes/new')

    const uniqueName = `Session H Inline Partner ${Date.now()}`
    const current = await request.get(`${API_BASE}/partners?type=customer&per_page=100`, {
      headers: apiHeaders(apiSession),
    })
    expect(current.ok()).toBeTruthy()
    const usedVat = new Set(
      (await current.json() as { data: PartnerRow[] }).data.flatMap((partner) =>
        partner.vat_number === null ? [] : [partner.vat_number],
      ),
    )
    const suffix = Array.from({ length: 1000 }, (_, index) => String(index).padStart(3, '0'))
      .find((candidate) => !usedVat.has(`7654321AM${candidate}`))
    expect(suffix, 'demo tenant exhausted the test VAT suffix space').toBeDefined()
    const vatNumber = `7654321AM${suffix}`

    const picker = page.getByTestId('partner-picker').getByRole('combobox')
    await picker.fill(uniqueName)
    await page.getByRole('button', { name: /add new customer/i }).click()

    const dialog = page.getByRole('dialog')
    await dialog.locator('#partner-name').fill(uniqueName)
    await dialog.locator('#partner-tax-id').fill(vatNumber)
    await dialog.locator('#partner-address').fill('17 Avenue de Carthage')
    await dialog.locator('#partner-city').fill('Tunis')
    await expect(dialog.locator('#partner-country option[value="TN"]')).toBeAttached()
    await dialog.locator('#partner-country').selectOption('TN')

    const createdResponsePromise = page.waitForResponse((response) =>
      response.request().method() === 'POST' && response.url().includes('/api/v1/partners'),
    )
    await dialog.getByRole('button', { name: /create/i }).click()
    const createdResponse = await createdResponsePromise
    expect(createdResponse.status()).toBe(201)
    const created = await createdResponse.json() as { data: PartnerRow }

    const persistedResponse = await request.get(`${API_BASE}/partners/${created.data.id}`, {
      headers: apiHeaders(apiSession),
    })
    expect(persistedResponse.ok(), `partner readback failed: ${persistedResponse.status()}`).toBeTruthy()
    const persisted = (await persistedResponse.json() as { data: PartnerRow }).data
    expect(persisted.vat_number).toBe(vatNumber)
    expect(persisted.street_address).toBe('17 Avenue de Carthage')
    expect(persisted.city).toBe('Tunis')
    expect(persisted.country_code).toBe('TN')
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'm1-inline-partner-created.png'), fullPage: true })
  })

  test('Parties import records a 51-character code as a code field validation error', async ({ request }) => {
    const response = await request.post(`${API_BASE}/imports`, {
      headers: apiHeaders(apiSession),
      multipart: {
        type: 'parties',
        file: {
          name: 'session-h-parties-code.csv',
          mimeType: 'text/csv',
          buffer: Buffer.from(`name,type,code\nBoundary Partner,customer,${'A'.repeat(51)}\n`),
        },
      },
    })

    // Row-level import validation intentionally returns a validated job (201)
    // whose failed_rows count points to the public /errors resource. Only
    // envelope/header validation uses 422 on POST /imports.
    expect(response.status()).toBe(201)
    const upload = await response.json() as { data: { id: string; failed_rows: number } }
    expect(upload.data.failed_rows).toBe(1)

    const errors = await request.get(`${API_BASE}/imports/${upload.data.id}/errors`, {
      headers: apiHeaders(apiSession),
    })
    expect(errors.ok(), `import errors lookup failed: ${errors.status()} ${await errors.text()}`).toBeTruthy()
    const errorBody = await errors.json() as {
      data: Array<{ errors: Record<string, string[]> }>
    }
    expect(errorBody.data[0]?.errors['code']?.[0]).toContain('50 characters')
  })
})
