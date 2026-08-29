import {
  expect,
  test,
  type APIRequestContext,
  type APIResponse,
  type Browser,
  type Page,
} from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

import {
  API_BASE,
  apiHeaders,
  classifyOtospexAuthentication,
  loginApi,
  loginPage,
  requireOtospexCompany,
  requireOtospexPartners,
  type ApiSession,
  type LoginCredentials,
  type OtospexPartnerRow,
} from './helpers'

test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5174' })
test.describe.configure({ mode: 'serial' })
test.setTimeout(120_000)

const UI_TIMEOUT = 30_000
const OTOSPEX_CREDENTIALS: LoginCredentials = {
  email: 'admin@demo.local',
  password: 'password',
}
const OTOSPEX_TENANT_SLUG = 'demo-unlimited'
const SCREENSHOT_DIR = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../../../.playwright-mcp/session-h/m3',
)
const API_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../api')

type PartnerRow = OtospexPartnerRow

interface AuthMeBody {
  data: {
    email: string
    id: string
    permissions: string[]
  }
}

interface UserRow {
  id: string
  email: string | null
  name: string
}

interface RoleRow {
  id: number
  name: string
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
  const initialAuth = classifyOtospexAuthentication({
    data: parseLoginData(initial.text),
    label: `email-first login for ${OTOSPEX_CREDENTIALS.email}`,
    ok: initial.response.ok(),
    status: initial.response.status(),
    text: initial.text,
  })
  if (!initialAuth.available) return initialAuth
  const initialData = initialAuth.data

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
    const explicitAuth = classifyOtospexAuthentication({
      data: parseLoginData(explicit.text),
      label: `explicit-tenant login for discovered ${OTOSPEX_TENANT_SLUG}`,
      ok: explicit.response.ok(),
      status: explicit.response.status(),
      text: explicit.text,
    })
    if (!explicitAuth.available) return explicitAuth
    const explicitData = explicitAuth.data
    if ('requires_org_selection' in explicitData) {
      throw new Error(
        `Otospex explicit-tenant login for discovered ${OTOSPEX_TENANT_SLUG} returned another organization selection instead of an authenticated session.`,
      )
    }
    token = explicitData.token
  } else {
    tenantId = initialData.user.tenantId
    token = initialData.token
  }

  const companies = await request.get(`${API_BASE}/user/companies`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  })
  const companyRows = companies.ok()
    ? (await companies.json() as { data: Array<{ id: string; is_primary?: boolean }> }).data
    : []
  const companyId = requireOtospexCompany({
    data: companyRows,
    ok: companies.ok(),
    status: companies.status(),
    text: companies.ok() ? '' : await companies.text(),
  })

  const session: ApiSession = { token, companyId }
  const [customers, suppliers] = await Promise.all([
    request.get(`${API_BASE}/partners?type=customer&per_page=50`, { headers: apiHeaders(session) }),
    request.get(`${API_BASE}/partners?type=supplier&per_page=50`, { headers: apiHeaders(session) }),
  ])
  const customerRows = customers.ok()
    ? (await customers.json() as { data: PartnerRow[] }).data
    : []
  const supplierRows = suppliers.ok()
    ? (await suppliers.json() as { data: PartnerRow[] }).data
    : []
  const { customer, supplier } = requireOtospexPartners(
    {
      data: customerRows,
      ok: customers.ok(),
      status: customers.status(),
      text: customers.ok() ? '' : await customers.text(),
    },
    {
      data: supplierRows,
      ok: suppliers.ok(),
      status: suppliers.status(),
      text: suppliers.ok() ? '' : await suppliers.text(),
    },
  )

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

function bootstrapDisposablePassword(input: {
  password: string
  tenantId: string
  userId: string
}): void {
  const encodedInput = Buffer.from(JSON.stringify(input), 'utf8').toString('base64')
  const script = [
    `if (! app()->environment(['local', 'testing'])) { throw new RuntimeException('M3 password bootstrap is test-only.'); }`,
    `$fixture = json_decode(base64_decode('${encodedInput}'), true, flags: JSON_THROW_ON_ERROR);`,
    `$tenant = App\\Modules\\Tenant\\Domain\\Tenant::query()->findOrFail($fixture['tenantId']);`,
    `tenancy()->initialize($tenant);`,
    `try {`,
    `  $user = App\\Modules\\Identity\\Domain\\User::query()->where('tenant_id', $fixture['tenantId'])->findOrFail($fixture['userId']);`,
    `  $user->forceFill(['password' => Illuminate\\Support\\Facades\\Hash::make($fixture['password'])])->saveOrFail();`,
    `} finally { tenancy()->end(); }`,
  ].join(' ')

  execFileSync('php', ['artisan', 'tinker', `--execute=${script}`], {
    cwd: API_ROOT,
    stdio: 'pipe',
  })
}

async function restrictedPermissions(
  page: Page,
  expectedUserId: string,
  expectedEmail: string,
): Promise<string[]> {
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
  expect(body.data.id).toBe(expectedUserId)
  expect(body.data.email).toBe(expectedEmail)
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
  let restrictedApiSession: ApiSession | null = null
  let restrictedCredentials: LoginCredentials | null = null
  let restrictedUserId: string | null = null
  let temporaryRoleAssigned = false
  let temporaryRoleId: number | null = null
  let temporaryRoleName: string | null = null

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

    const ownerMe = await request.get(`${API_BASE}/auth/me`, {
      headers: apiHeaders(ownerSession),
    })
    expect(ownerMe.ok(), `owner auth/me failed: ${ownerMe.status()} ${await ownerMe.text()}`).toBeTruthy()
    const ownerMeBody = await ownerMe.json() as { data: { tenantId: string } }

    const fixtureId = Date.now().toString(36)
    temporaryRoleName = `session-h-m3-contacts-only-${fixtureId}`
    const role = await request.post(`${API_BASE}/roles`, {
      data: { name: temporaryRoleName, permissions: ['contacts.update'] },
      headers: apiHeaders(ownerSession),
    })
    expect(role.status(), `temporary role create failed: ${role.status()} ${await role.text()}`).toBe(201)
    temporaryRoleId = (await role.json() as { data: RoleRow }).data.id

    const createdUser = await request.post(`${API_BASE}/users`, {
      data: {
        email: `session-h-m3-${fixtureId}@example.test`,
        name: 'Session H M3 disposable restricted user',
        role: 'cashier',
      },
      headers: apiHeaders(ownerSession),
    })
    expect(createdUser.status(), `temporary user create failed: ${createdUser.status()} ${await createdUser.text()}`).toBe(201)
    const createdUserRow = (await createdUser.json() as { data: UserRow }).data
    restrictedUserId = createdUserRow.id
    expect(createdUserRow.email).not.toBeNull()
    const password = `Session-H-M3-${fixtureId}-aA1!`
    restrictedCredentials = {
      email: createdUserRow.email!,
      password,
      tenantId: ownerMeBody.data.tenantId,
    }

    bootstrapDisposablePassword({
      password,
      tenantId: ownerMeBody.data.tenantId,
      userId: restrictedUserId,
    })

    const activated = await request.post(`${API_BASE}/users/${restrictedUserId}/activate`, {
      headers: apiHeaders(ownerSession),
    })
    expect(activated.ok(), `temporary user activation failed: ${activated.status()} ${await activated.text()}`).toBeTruthy()

    const assigned = await request.post(`${API_BASE}/users/${restrictedUserId}/roles`, {
      data: { role: temporaryRoleName },
      headers: apiHeaders(ownerSession),
    })
    expect(assigned.ok(), `temporary role assign failed: ${assigned.status()} ${await assigned.text()}`).toBeTruthy()
    temporaryRoleAssigned = true

    const removedCashier = await request.delete(`${API_BASE}/users/${restrictedUserId}/roles`, {
      data: { role: 'cashier' },
      headers: apiHeaders(ownerSession),
    })
    expect(removedCashier.ok(), `cashier bootstrap role removal failed: ${removedCashier.status()} ${await removedCashier.text()}`).toBeTruthy()

    const login = await postLogin(request, restrictedCredentials)
    expect(login.response.ok(), `temporary user login failed: ${login.response.status()} ${login.text}`).toBeTruthy()
    const loginData = parseLoginData(login.text)
    expect(loginData).not.toBeNull()
    expect(loginData).not.toHaveProperty('requires_org_selection')
    const authenticated = loginData as LoginSuccess
    restrictedApiSession = {
      companyId: ownerSession.companyId,
      token: authenticated.token,
    }

    const restrictedMe = await request.get(`${API_BASE}/auth/me`, {
      headers: apiHeaders(restrictedApiSession),
    })
    expect(restrictedMe.ok(), `temporary user auth/me failed: ${restrictedMe.status()} ${await restrictedMe.text()}`).toBeTruthy()
    const restrictedMeBody = await restrictedMe.json() as AuthMeBody
    expect(restrictedMeBody.data.id).toBe(restrictedUserId)
    expect(restrictedMeBody.data.email).toBe(restrictedCredentials.email)
    expect(restrictedMeBody.data.permissions).toContain('contacts.update')
    expect(restrictedMeBody.data.permissions).not.toContain('partners.update')
    expect(restrictedMeBody.data.permissions).not.toContain('partners.view')
  })

  test.afterAll(async ({ request }) => {
    if (ownerSession === null) return

    const cleanupFailures: string[] = []
    const record = async (label: string, response: APIResponse): Promise<void> => {
      if (!response.ok()) cleanupFailures.push(`${label}: ${response.status()} ${await response.text()}`)
    }

    if (temporaryRoleAssigned && restrictedUserId !== null && temporaryRoleName !== null) {
      await record('remove temporary role', await request.delete(`${API_BASE}/users/${restrictedUserId}/roles`, {
        data: { role: temporaryRoleName },
        headers: apiHeaders(ownerSession),
      }))
    }
    if (restrictedUserId !== null) {
      await record('delete disposable user', await request.delete(`${API_BASE}/users/${restrictedUserId}`, {
        headers: apiHeaders(ownerSession),
      }))
    }
    if (restrictedApiSession !== null) {
      const revoked = await request.get(`${API_BASE}/auth/me`, {
        headers: apiHeaders(restrictedApiSession),
      })
      if (revoked.status() !== 401) {
        cleanupFailures.push(`revoke disposable sessions: expected 401, received ${revoked.status()} ${await revoked.text()}`)
      }
    }
    if (temporaryRoleId !== null) {
      await record('delete temporary role', await request.delete(`${API_BASE}/roles/${temporaryRoleId}`, {
        headers: apiHeaders(ownerSession),
      }))
    }

    expect(cleanupFailures, 'every Session H M3 external fixture cleanup must succeed').toEqual([])
  })

  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' })
  })

  test('redirects retired /crm/companies to customers and removes the Companies sidebar entry', async ({ page }) => {
    await loginPage(page)
    await page.goto('/crm/companies')

    // The page is retired, but the bookmark still resolves: customers is the
    // surviving surface for the company/partner concept (lane commit G1.9).
    // The list page normalises its own query string (?sort_by=&type=), so the
    // path is the assertion, not the full URL.
    await expect(page).toHaveURL(/\/sales\/customers(\?|$)/, { timeout: UI_TIMEOUT })
    await expect(page.locator('aside a[href="/crm/companies"]')).toHaveCount(0)
    await page.screenshot({
      fullPage: true,
      path: path.join(SCREENSHOT_DIR, 'm3-retired-companies.png'),
    })
  })

  test('owner opens partner edit while contacts.update-only disposable user is blocked', async ({ browser }) => {
    expect(customer).not.toBeNull()
    expect(restrictedCredentials).not.toBeNull()
    expect(restrictedUserId).not.toBeNull()
    const owner = await loggedPage(browser)
    const restricted = await loggedPage(browser, restrictedCredentials!)
    try {
      await owner.page.goto(`/sales/customers/${customer!.id}/edit`)
      await expect(owner.page.getByLabel(/^Name/)).toHaveValue(customer!.name, { timeout: UI_TIMEOUT })
      await owner.page.locator('form').screenshot({
        path: path.join(SCREENSHOT_DIR, 'm3-owner-partner-edit.png'),
      })

      await restrictedPermissions(restricted.page, restrictedUserId!, restrictedCredentials!.email)
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
    expect(restrictedCredentials).not.toBeNull()
    expect(restrictedUserId).not.toBeNull()
    const restricted = await loggedPage(browser, restrictedCredentials!)
    const owner = await loggedPage(browser)
    try {
      await restrictedPermissions(restricted.page, restrictedUserId!, restrictedCredentials!.email)
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
