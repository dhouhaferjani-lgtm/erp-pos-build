import { expect, type APIRequestContext, type Page } from '@playwright/test'

export const API_BASE = process.env.API_BASE ?? 'http://127.0.0.1:8011/api/v1'
export const DEMO_CREDENTIALS = {
  email: 'owner@pharmabio.tn',
  password: 'password',
} as const

const API_ORIGIN = new URL(API_BASE).origin
const MAX_LOGIN_ATTEMPTS = 3

export interface ApiSession {
  token: string
  companyId: string
}

export function apiHeaders(session: ApiSession): Record<string, string> {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${session.token}`,
    'X-Company-Id': session.companyId,
  }
}

function loginRetryDelayMs(headers: Record<string, string>): number {
  const retryAfterSeconds = Number(headers['retry-after'] ?? '1')
  const boundedSeconds = Number.isFinite(retryAfterSeconds)
    ? Math.min(Math.max(retryAfterSeconds, 1), 59)
    : 1
  return boundedSeconds * 1000
}

export async function loginApi(request: APIRequestContext): Promise<ApiSession> {
  let login = await request.post(`${API_BASE}/auth/login`, {
    headers: { Accept: 'application/json' }, data: DEMO_CREDENTIALS,
  })
  for (let attempt = 1; login.status() === 429 && attempt < MAX_LOGIN_ATTEMPTS; attempt += 1) {
    await new Promise((resolve) => setTimeout(resolve, loginRetryDelayMs(login.headers())))
    login = await request.post(`${API_BASE}/auth/login`, {
      headers: { Accept: 'application/json' }, data: DEMO_CREDENTIALS,
    })
  }
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

export async function loginPage(page: Page): Promise<void> {
  await page.route('**/api/v1/**', async (route) => {
    const sourceUrl = new URL(route.request().url())
    const apiPath = sourceUrl.pathname.replace(/^\/api\/v1/, '')
    const response = await route.fetch({
      url: `${API_BASE}${apiPath}${sourceUrl.search}`,
    })
    expect(new URL(response.url()).origin).toBe(API_ORIGIN)
    await route.fulfill({ response })
  })
  await page.route('**/sanctum/**', async (route) => {
    const sourceUrl = new URL(route.request().url())
    const response = await route.fetch({
      url: `${API_ORIGIN}${sourceUrl.pathname}${sourceUrl.search}`,
    })
    expect(new URL(response.url()).origin).toBe(API_ORIGIN)
    await route.fulfill({ response })
  })
  await page.addInitScript(() => {
    window.localStorage.setItem('autoerp-cookie-consent', 'accepted')
  })
  await page.goto('/login')
  await page.getByLabel(/email address/i).fill(DEMO_CREDENTIALS.email)
  await page.getByLabel(/^password$/i).fill(DEMO_CREDENTIALS.password)
  await expect(page.getByLabel(/email address/i)).toHaveValue(DEMO_CREDENTIALS.email)
  await expect(page.getByLabel(/^password$/i)).toHaveValue(DEMO_CREDENTIALS.password)
  const submitLogin = async () => {
    const loginResponsePromise = page.waitForResponse((response) =>
      response.request().method() === 'POST' && response.url().includes('/api/v1/auth/login'),
    )
    await page.getByRole('button', { name: /sign in/i }).click()
    return loginResponsePromise
  }

  let loginResponse = await submitLogin()
  for (let attempt = 1; loginResponse.status() === 429 && attempt < MAX_LOGIN_ATTEMPTS; attempt += 1) {
    await page.waitForTimeout(loginRetryDelayMs(loginResponse.headers()))
    loginResponse = await submitLogin()
  }
  expect(
    loginResponse.ok(),
    `browser login failed: ${loginResponse.status()} ${await loginResponse.text()}`,
  ).toBeTruthy()
  await expect(page).not.toHaveURL(/\/login/, { timeout: 15_000 })
  await expect(page.getByRole('button', { name: /profile/i })).toBeVisible({ timeout: 15_000 })
}
