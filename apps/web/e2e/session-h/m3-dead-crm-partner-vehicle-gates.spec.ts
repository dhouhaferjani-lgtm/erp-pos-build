import {
  expect,
  test,
  type APIRequestContext,
  type APIResponse,
  type Browser,
  type Page,
} from '@playwright/test'
import { mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

import {
  API_BASE,
  apiHeaders,
  loginApi,
  loginPage,
  type ApiSession,
  type LoginCredentials,
} from './helpers'

test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5174' })
test.describe.configure({ mode: 'serial' })
test.setTimeout(120_000)

const UI_TIMEOUT = 30_000
const RESTRICTED_CREDENTIALS: LoginCredentials = {
  email: 'viewer@pharmabio.tn',
  password: 'password',
}
const OTOSPEX_CREDENTIALS: LoginCredentials = {
  email: 'admin@demo.local',
  password: 'password',
}
const OTOSPEX_TENANT_SLUG = 'demo-unlimited'
const SCREENSHOT_DIR = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../../../.playwright-mcp/session-h/m3',
)

interface PartnerRow {
  id: string
  name: string
  type: 'customer' | 'supplier' | 'both'
}

interface UserRow {
  id: string
  email: string | null
}

interface RoleRow {
  id: number
  name: string
}

interface AuthMeBody {
  data: {
    permissions: string[]
  }
}

interface LoginSuccess {
  token: string
  user: {
    tenantId: string
  }
}

interface OrganizationSelection {
  requires_org_selection: true
  organizations: Array<{
    tenant_id: string
    slug: string
  }>
}

interface OtospexFixture {
  credentials: LoginCredentials
  customer: PartnerRow
  supplier: PartnerRow
}

type OtospexAvailability =
  | { available: true; fixture: OtospexFixture }
  | { available: false; reason: string }

async function postLogin(
  request: APIRequestContext,
  credentials: LoginCredentials,
): Promise<{ response: APIResponse; text: string }> {
  const data = {
    email: credentials.email,
    password: credentials.password,
    ...(credentials.tenantId === undefined ? {} : { tenant_id: credentials.tenantId }),
  }
  let response = await request.post(`${API_BASE}/auth/login`, {
    data,
    headers: { Accept: 'application/json' },
  })
  for (let attempt = 1; response.status() === 429 && attempt < 3; attempt += 1) {
    const retryAfter = Number(response.headers()['retry-after'] ?? '1')
    const delayMs = (Number.isFinite(retryAfter) ? Math.min(Math.max(retryAfter, 1), 59) : 1) * 1000
    await new Promise((resolve) => setTimeout(resolve, delayMs))
    response = await request.post(`${API_BASE}/auth/login`, {
      data,
      headers: { Accept: 'application/json' },
    })
  }
  return { response, text: await response.text() }
}

function parseLoginData(text: string): LoginSuccess | OrganizationSelection | null {
  try {
    const body = JSON.parse(text) as { data?: LoginSuccess | OrganizationSelection }
    return body.data ?? null
  } catch {
    return null
  }
}

async function discoverOtospexFixture(request: APIRequestContext): Promise<OtospexAvailability> {
  const initial = await postLogin(request, OTOSPEX_CREDENTIALS)
  if (!initial.response.ok()) {
    return {
      available: false,
      reason: `Otospex discovery unavailable: email-first login for ${OTOSPEX_CREDENTIALS.email} returned HTTP ${initial.response.status()} (${initial.text}).`,
    }
  }

  const initialData = parseLoginData(initial.text)
  if (initialData === null) {
    return {
      available: false,
      reason: 'Otospex discovery unavailable: deterministic login returned an unreadable response.',
    }
  }

  let tenantId: string
  let token: string
  if ('requires_org_selection' in initialData) {
    const organization = initialData.organizations.find((row) => row.slug === OTOSPEX_TENANT_SLUG)
    if (organization === undefined) {
      return {
        available: false,
        reason: `Otospex discovery unavailable: login organizations did not include ${OTOSPEX_TENANT_SLUG}.`,
      }
    }
    tenantId = organization.tenant_id
    const explicit = await postLogin(request, { ...OTOSPEX_CREDENTIALS, tenantId })
    if (!explicit.response.ok()) {
      return {
        available: false,
        reason: `Otospex explicit-tenant login unavailable for discovered ${OTOSPEX_TENANT_SLUG}: HTTP ${explicit.response.status()} (${explicit.text}).`,
      }
    }
    const explicitData = parseLoginData(explicit.text)
    if (explicitData === null || 'requires_org_selection' in explicitData) {
      return {
        available: false,
        reason: `Otospex explicit-tenant login unavailable for discovered ${OTOSPEX_TENANT_SLUG}: no authenticated session was returned.`,
      }
    }
    token = explicitData.token
  } else {
    tenantId = initialData.user.tenantId
    token = initialData.token
  }

  const companies = await request.get(`${API_BASE}/user/companies`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  })
  if (!companies.ok()) {
    return {
      available: false,
      reason: `Otospex company discovery unavailable: HTTP ${companies.status()} (${await companies.text()}).`,
    }
  }
  const companyBody = await companies.json() as {
    data: Array<{ id: string; is_primary?: boolean }>
  }
  const company = companyBody.data.find((row) => row.is_primary === true) ?? companyBody.data[0]
  if (company === undefined) {
    return { available: false, reason: 'Otospex company discovery unavailable: no company was returned.' }
  }

  const session: ApiSession = { token, companyId: company.id }
  const [customers, suppliers] = await Promise.all([
    request.get(`${API_BASE}/partners?type=customer&per_page=50`, { headers: apiHeaders(session) }),
    request.get(`${API_BASE}/partners?type=supplier&per_page=50`, { headers: apiHeaders(session) }),
  ])
  if (!customers.ok() || !suppliers.ok()) {
    return {
      available: false,
      reason: `Otospex partner discovery unavailable: customer HTTP ${customers.status()}, supplier HTTP ${suppliers.status()}.`,
    }
  }
  const customerBody = await customers.json() as { data: PartnerRow[] }
  const supplierBody = await suppliers.json() as { data: PartnerRow[] }
  const customer = customerBody.data.find((row) => row.type === 'customer' || row.type === 'both')
  const supplier = supplierBody.data.find((row) => row.type === 'supplier')
  if (customer === undefined || supplier === undefined) {
    return {
      available: false,
      reason: `Otospex partner discovery unavailable: customer fixture=${String(customer !== undefined)}, supplier-only fixture=${String(supplier !== undefined)}.`,
    }
  }

  return {
    available: true,
    fixture: {
      credentials: { ...OTOSPEX_CREDENTIALS, tenantId },
      customer,
      supplier,
    },
  }
}

async function loggedPage(
  browser: Browser,
  credentials?: LoginCredentials,
): Promise<{ close: () => Promise<void>; page: Page }> {
  const context = await browser.newContext()
  const page = await context.newPage()
  await loginPage(page, credentials)
  return {
    close: async () => {
      await page.unrouteAll({ behavior: 'ignoreErrors' })
      await context.close()
    },
    page,
  }
}

async function restrictedPermissions(page: Page): Promise<string[]> {
  const result = await page.evaluate(async () => {
    const persisted = window.localStorage.getItem('autoerp-auth')
    const parsed: unknown = persisted === null ? null : JSON.parse(persisted)
    const token = typeof parsed === 'object' && parsed !== null
      && 'state' in parsed && typeof parsed.state === 'object' && parsed.state !== null
      && 'token' in parsed.state && typeof parsed.state.token === 'string'
      ? parsed.state.token
      : null
    const response = await window.fetch('/api/v1/auth/me', {
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(token === null ? {} : { Authorization: `Bearer ${token}` }),
      },
    })
    return { body: await response.json() as unknown, status: response.status }
  })

  expect(result.status, `restricted /auth/me returned ${result.status}`).toBe(200)
  const body = result.body as AuthMeBody
  expect(body.data.permissions).toContain('contacts.update')
  expect(body.data.permissions).not.toContain('partners.update')
  expect(body.data.permissions).not.toContain('partners.view')
  return body.data.permissions
}

async function typeOptionValues(page: Page): Promise<string[]> {
  return page.getByLabel(/^Type/).evaluate((element) => {
    if (!(element instanceof HTMLSelectElement)) {
      throw new Error('Expected the Type field to be a select element')
    }
    return Array.from(element.options, (option) => option.value)
  })
}

test.describe('Session H M3 dead CRM and partner/vehicle gates', () => {
  let ownerSession: ApiSession | null = null
  let customer: PartnerRow | null = null
  let restrictedUserId: string | null = null
  let temporaryRoleId: number | null = null
  let temporaryRoleName: string | null = null
  let temporaryRoleAssigned = false
  let viewerRoleRemoved = false

  test.beforeAll(async ({ request }) => {
    mkdirSync(SCREENSHOT_DIR, { recursive: true })
    ownerSession = await loginApi(request)

    const customers = await request.get(`${API_BASE}/partners?type=customer&per_page=20`, {
      headers: apiHeaders(ownerSession),
    })
    expect(customers.ok(), `customer fixture lookup failed: ${customers.status()} ${await customers.text()}`).toBeTruthy()
    const customerBody = await customers.json() as { data: PartnerRow[] }
    customer = customerBody.data.find((row) => row.type === 'customer' || row.type === 'both') ?? null
    expect(customer, 'demo pharmacy must expose an existing customer for edit-route evidence').not.toBeNull()

    const users = await request.get(`${API_BASE}/users?search=${encodeURIComponent(RESTRICTED_CREDENTIALS.email)}`, {
      headers: apiHeaders(ownerSession),
    })
    expect(users.ok(), `restricted user lookup failed: ${users.status()} ${await users.text()}`).toBeTruthy()
    const userBody = await users.json() as { data: UserRow[] }
    restrictedUserId = userBody.data.find((row) => row.email === RESTRICTED_CREDENTIALS.email)?.id ?? null
    expect(restrictedUserId, 'seeded viewer donor must exist').not.toBeNull()

    temporaryRoleName = `session-h-m3-contacts-only-${Date.now()}`
    const role = await request.post(`${API_BASE}/roles`, {
      data: { name: temporaryRoleName, permissions: ['contacts.update'] },
      headers: apiHeaders(ownerSession),
    })
    expect(role.status(), `temporary role create failed: ${role.status()} ${await role.text()}`).toBe(201)
    const roleBody = await role.json() as { data: RoleRow }
    temporaryRoleId = roleBody.data.id

    const assigned = await request.post(`${API_BASE}/users/${restrictedUserId}/roles`, {
      data: { role: temporaryRoleName },
      headers: apiHeaders(ownerSession),
    })
    expect(assigned.ok(), `temporary role assign failed: ${assigned.status()} ${await assigned.text()}`).toBeTruthy()
    temporaryRoleAssigned = true

    const removedViewer = await request.delete(`${API_BASE}/users/${restrictedUserId}/roles`, {
      data: { role: 'viewer' },
      headers: apiHeaders(ownerSession),
    })
    expect(removedViewer.ok(), `viewer role removal failed: ${removedViewer.status()} ${await removedViewer.text()}`).toBeTruthy()
    viewerRoleRemoved = true
  })

  test.afterAll(async ({ request }) => {
    if (ownerSession === null) return

    const cleanupFailures: string[] = []
    const record = async (label: string, response: Awaited<ReturnType<APIRequestContext['post']>>): Promise<void> => {
      if (!response.ok()) {
        cleanupFailures.push(`${label}: ${response.status()} ${await response.text()}`)
      }
    }

    if (viewerRoleRemoved && restrictedUserId !== null) {
      const restored = await request.post(`${API_BASE}/users/${restrictedUserId}/roles`, {
        data: { role: 'viewer' },
        headers: apiHeaders(ownerSession),
      })
      await record('restore viewer role', restored)
    }

    if (temporaryRoleAssigned && restrictedUserId !== null && temporaryRoleName !== null) {
      const removed = await request.delete(`${API_BASE}/users/${restrictedUserId}/roles`, {
        data: { role: temporaryRoleName },
        headers: apiHeaders(ownerSession),
      })
      await record('remove temporary role from viewer', removed)
    }

    if (temporaryRoleId !== null) {
      const deleted = await request.delete(`${API_BASE}/roles/${temporaryRoleId}`, {
        headers: apiHeaders(ownerSession),
      })
      await record('delete temporary role', deleted)
    }

    expect(cleanupFailures, 'every Session H M3 external fixture cleanup must succeed').toEqual([])
  })

  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' })
  })

  test('retires /crm/companies and removes the Companies sidebar entry', async ({ page }) => {
    await loginPage(page)
    await page.goto('/crm/companies')

    await expect(page).toHaveURL(/\/dashboard$/, { timeout: UI_TIMEOUT })
    await expect(page.locator('aside a[href="/crm/companies"]')).toHaveCount(0)
    await page.screenshot({
      fullPage: true,
      path: path.join(SCREENSHOT_DIR, 'm3-retired-companies.png'),
    })
  })

  test('owner opens partner edit while contacts.update-only user is blocked', async ({ browser }) => {
    expect(customer).not.toBeNull()
    const owner = await loggedPage(browser)
    const restricted = await loggedPage(browser, RESTRICTED_CREDENTIALS)
    try {
      await owner.page.goto(`/sales/customers/${customer!.id}/edit`)
      await expect(owner.page.getByLabel(/^Name/)).toHaveValue(customer!.name, { timeout: UI_TIMEOUT })
      await owner.page.locator('form').screenshot({
        path: path.join(SCREENSHOT_DIR, 'm3-owner-partner-edit.png'),
      })

      await restrictedPermissions(restricted.page)
      await restricted.page.goto(`/sales/customers/${customer!.id}/edit`)
      await expect(restricted.page).toHaveURL(/\/dashboard$/, { timeout: UI_TIMEOUT })
      await restricted.page.screenshot({
        fullPage: true,
        path: path.join(SCREENSHOT_DIR, 'm3-contacts-only-partner-edit-blocked.png'),
      })
    } finally {
      await owner.close()
      await restricted.close()
    }
  })

  test('suppliers list is blocked without partners.view and opens for owner', async ({ browser }) => {
    const restricted = await loggedPage(browser, RESTRICTED_CREDENTIALS)
    const owner = await loggedPage(browser)
    try {
      await restrictedPermissions(restricted.page)
      await restricted.page.goto('/purchases/suppliers')
      await expect(restricted.page).toHaveURL(/\/dashboard$/, { timeout: UI_TIMEOUT })

      await owner.page.goto('/purchases/suppliers')
      await expect(owner.page.getByRole('heading', { name: /^Suppliers$/i })).toBeVisible({ timeout: UI_TIMEOUT })
      await owner.page.screenshot({
        fullPage: true,
        path: path.join(SCREENSHOT_DIR, 'm3-owner-suppliers-list.png'),
      })
    } finally {
      await restricted.close()
      await owner.close()
    }
  })

  test('create forms expose only their route-scoped Type choices', async ({ page }) => {
    await loginPage(page)

    await page.goto('/sales/customers/new')
    await expect(page.getByLabel(/^Type/)).toBeVisible({ timeout: UI_TIMEOUT })
    expect(await typeOptionValues(page)).toEqual(['', 'customer', 'both'])
    await page.locator('form').screenshot({
      path: path.join(SCREENSHOT_DIR, 'm3-customer-create-types.png'),
    })

    await page.goto('/purchases/suppliers/new')
    await expect(page.getByLabel(/^Type/)).toBeVisible({ timeout: UI_TIMEOUT })
    expect(await typeOptionValues(page)).toEqual(['', 'supplier', 'both'])
    await page.locator('form').screenshot({
      path: path.join(SCREENSHOT_DIR, 'm3-supplier-create-types.png'),
    })
  })

  test('vehicle owner search returns customers and excludes supplier-only partners on Otospex', async ({ browser, request }) => {
    const availability = await discoverOtospexFixture(request)
    test.skip(!availability.available, availability.available ? undefined : availability.reason)
    if (!availability.available) return

    const { credentials, customer: otospexCustomer, supplier } = availability.fixture
    const otospex = await loggedPage(browser, credentials)
    try {
      await otospex.page.goto('/vehicles/new')
      const ownerPicker = otospex.page.getByTestId('vehicle-owner-picker')
      const ownerSearch = ownerPicker.getByRole('combobox')
      await expect(ownerSearch).toBeVisible({ timeout: UI_TIMEOUT })

      const customerResponse = otospex.page.waitForResponse((response) => {
        const url = new URL(response.url())
        return url.pathname.endsWith('/partners')
          && url.searchParams.get('type') === 'customer'
          && url.searchParams.get('search') === otospexCustomer.name
      })
      await ownerSearch.fill(otospexCustomer.name)
      expect((await customerResponse).ok()).toBeTruthy()
      await expect(ownerPicker.getByRole('option').filter({ hasText: otospexCustomer.name })).toBeVisible()

      const supplierResponse = otospex.page.waitForResponse((response) => {
        const url = new URL(response.url())
        return url.pathname.endsWith('/partners')
          && url.searchParams.get('type') === 'customer'
          && url.searchParams.get('search') === supplier.name
      })
      await ownerSearch.fill(supplier.name)
      expect((await supplierResponse).ok()).toBeTruthy()
      await expect(ownerPicker.getByRole('option').filter({ hasText: supplier.name })).toHaveCount(0)
    } finally {
      await otospex.close()
    }
  })
})
