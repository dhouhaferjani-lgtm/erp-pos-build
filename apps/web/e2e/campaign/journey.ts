import { execFile } from 'node:child_process'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { promisify } from 'node:util'
import { expect, type Page, type TestInfo } from '@playwright/test'
import { apiRoutes, campaignSelectors, routes } from './selectors'

const MONEY_SCALE = 3
const API_URL = process.env['CAMPAIGN_API_URL'] || 'http://127.0.0.1:8010'
const WEB_URL = process.env['CAMPAIGN_WEB_URL'] || 'http://localhost:5173'
const COUNTRY = process.env['CAMPAIGN_COUNTRY'] || 'TN'
const KEEP_TENANT = process.env['CAMPAIGN_KEEP_TENANT'] === '1'
// Reuse mode (debugging / staging triage): log into an existing campaign tenant instead of registering.
const REUSE_EMAIL = process.env['CAMPAIGN_REUSE_EMAIL'] ?? ''
const REUSE_PASSWORD = process.env['CAMPAIGN_REUSE_PASSWORD'] ?? ''
export const reuseMode = REUSE_EMAIL !== '' && REUSE_PASSWORD !== ''
const execFileAsync = promisify(execFile)

export type LegStatus = 'PASS' | 'FAIL' | 'SKIPPED' | 'NOT_SCRIPTABLE'
export type LegDrive = 'UI' | 'API-contract' | 'API-contract (device-authored chain)' | 'network-free'

export interface CampaignCredentials {
  email: string
  name: string
  password: string
}

export interface ApiResult {
  body: unknown
  request: {
    body?: unknown
    companyId: string | null
    method: ApiMethod
    path: string
  }
  status: number
  timestamp: string
}

export interface ProductFinding {
  evidence: unknown
  leg: string
  tenantId: string | null
  timestamp: string
  what: string
  where: string
}

interface LedgerEntry {
  drive: LegDrive
  evidence: string[]
  finishedAt: string | null
  leg: string
  reason: string | null
  result: LegStatus | 'PENDING'
  title: string
}

interface CampaignLedger {
  apiUrl: string
  credentials: CampaignCredentials | null
  finishedAt: string | null
  findings: ProductFinding[]
  legs: LedgerEntry[]
  runId: string
  startedAt: string
  tenantId: string | null
  webUrl: string
}

type ApiMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

const LEG_DEFINITIONS: ReadonlyArray<Omit<LedgerEntry, 'evidence' | 'finishedAt' | 'reason' | 'result'>> = [
  { leg: 'L0a', title: 'golden vector', drive: 'network-free' },
  { leg: 'L0', title: 'health + register', drive: 'UI' },
  { leg: 'L1', title: 'parties import with balances', drive: 'UI' },
  { leg: 'L2', title: 'products import with opening stock', drive: 'UI' },
  { leg: 'L3', title: 'opening lots with expiries', drive: 'UI' },
  { leg: 'L4', title: 'GL / bank / cash-float openings', drive: 'API-contract' },
  { leg: 'L5', title: 'lock', drive: 'API-contract' },
  { leg: 'L5b', title: 'session open', drive: 'API-contract (device-authored chain)' },
  { leg: 'L6', title: 'POS sale', drive: 'API-contract (device-authored chain)' },
  { leg: 'L7', title: 'refund', drive: 'API-contract (device-authored chain)' },
  { leg: 'L8', title: 'payment allocation vs historical invoice', drive: 'API-contract' },
  { leg: 'L9', title: 'cash count + Z', drive: 'API-contract (device-authored chain)' },
  { leg: 'L10', title: 'findings gate', drive: 'network-free' },
]

export const runId = process.env['CAMPAIGN_RUN_ID'] || createRunId()
export const campaignOutputDir = resolve(process.cwd(), 'test-results', `campaign-${runId}`)
export const ledgerPath = resolve(campaignOutputDir, 'ledger.json')

export const journeyState: {
  companyId?: string
  credentials?: CampaignCredentials
  customerDocumentId?: string
  customerId?: string
  cashMethodCode?: string
  cashMethodId?: string
  cashRepositoryId?: string
  cashRepositoryCode?: string
  cashGlAccountCode?: string
  companyName?: string
  locationId?: string
  openingBatchId?: string
  productId?: string
  productSku?: string
  productName?: string
  productOpeningQuantity?: string
  customerReceivableAccountCode?: string
  salesReturnAccountCode?: string
  salesRevenueAccountCode?: string
  runDirectory?: string
  saleEventId?: string
  saleEventTimeDevice?: string
  saleHash?: string
  saleReceiptUuid?: string
  saleSequenceNumber?: number
  sessionId?: string
  sessionOpenEventId?: string
  sessionOpenEventTimeDevice?: string
  sessionOpenHash?: string
  sessionOpenSequenceNumber?: number
  supplierId?: string
  terminalGenesisSeed?: string
  terminalCode?: string
  terminalId?: string
  bankRepositoryCode?: string
  terminalLabel?: string
  refundEventId?: string
  refundEventTimeDevice?: string
  refundHash?: string
  refundSequenceNumber?: number
  persistedAuth?: string
  vatCollectedAccountCode?: string
  tenantId?: string
  userId?: string
} = {}

const ledger: CampaignLedger = {
  apiUrl: API_URL,
  credentials: null,
  finishedAt: null,
  findings: [],
  legs: LEG_DEFINITIONS.map((definition) => ({
    ...definition,
    evidence: [],
    finishedAt: null,
    reason: null,
    result: 'PENDING',
  })),
  runId,
  startedAt: new Date().toISOString(),
  tenantId: null,
  webUrl: WEB_URL,
}

export function campaignCountry(): string {
  return COUNTRY
}

export function currencyForCountry(country: string): string {
  const currencies: Record<string, string> = {
    DZ: 'DZD',
    FR: 'EUR',
    GB: 'GBP',
    IT: 'EUR',
    MA: 'MAD',
    TN: 'TND',
    US: 'USD',
  }
  const currency = currencies[country]
  if (!currency) {
    throw new Error(`Unsupported CAMPAIGN_COUNTRY: ${country}`)
  }
  return currency
}

export function currencyScaleForCountry(country: string): number {
  return country === 'TN' ? 3 : 2
}

export function normalizeMoney(value: string): string {
  const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(value.trim())
  if (!match) {
    throw new Error(`Invalid money string: ${value}`)
  }

  const sign = match[1] ?? ''
  const integer = (match[2] ?? '0').replace(/^0+(?=\d)/, '')
  const fraction = match[3] ?? ''
  const beyondScale = fraction.slice(MONEY_SCALE)
  if (beyondScale.replace(/0/g, '') !== '') {
    throw new Error(`Money value exceeds scale 3: ${value}`)
  }

  return `${sign}${integer}.${fraction.slice(0, MONEY_SCALE).padEnd(MONEY_SCALE, '0')}`
}

export function assertMoneyEqual(actual: string, expected: string): void {
  expect(normalizeMoney(actual)).toBe(normalizeMoney(expected))
}

export async function initializeCampaignLedger(): Promise<void> {
  await mkdir(campaignOutputDir, { recursive: true })
  await persistLedger()
}

export async function recordTestResult(testInfo: TestInfo): Promise<void> {
  const legName = /^L\d+[a-z]?/.exec(testInfo.title)?.[0]
  if (!legName) return

  const entry = ledger.legs.find((candidate) => candidate.leg === legName)
  if (!entry || entry.result === 'NOT_SCRIPTABLE') return

  entry.finishedAt = new Date().toISOString()
  if (testInfo.status === 'passed') {
    // A leg that recorded a PRODUCT FINDING stays FAIL even when its Playwright
    // assertions passed (the finding is the failure; the leg may continue so
    // downstream legs still get exercised). L10 turns the run red.
    if (!(entry.result === 'FAIL' && entry.reason?.startsWith('PRODUCT FINDING'))) {
      entry.result = 'PASS'
      entry.reason = null
    }
  } else if (testInfo.status === 'skipped') {
    entry.result = 'SKIPPED'
    entry.reason = 'serial dependency did not pass'
  } else {
    entry.result = 'FAIL'
    entry.reason = testInfo.error?.message ?? `Playwright status: ${testInfo.status}`
  }
  await persistLedger()
}

export async function finalizeCampaignLedger(): Promise<void> {
  const finishedAt = new Date().toISOString()
  for (const entry of ledger.legs) {
    if (entry.result === 'PENDING') {
      entry.result = 'SKIPPED'
      entry.reason = 'serial dependency did not pass'
      entry.finishedAt = finishedAt
    }
    if (entry.finishedAt === null) entry.finishedAt = finishedAt
  }
  ledger.finishedAt = finishedAt
  await persistLedger()
  if (KEEP_TENANT && journeyState.credentials) {
    console.log(`CAMPAIGN_KEEP_TENANT=1 credentials: ${JSON.stringify(journeyState.credentials)}`)
  }
}

export async function addLedgerEvidence(leg: string, evidence: string): Promise<void> {
  const entry = requireLedgerEntry(leg)
  entry.evidence.push(evidence)
  await persistLedger()
}

export function ledgerFindings(): ReadonlyArray<ProductFinding> {
  return ledger.findings
}

export async function recordProductFinding(finding: Omit<ProductFinding, 'tenantId' | 'timestamp'>): Promise<void> {
  const entry = ledger.legs.find((candidate) => candidate.leg === finding.leg)
  if (entry && entry.result !== 'NOT_SCRIPTABLE') {
    entry.result = 'FAIL'
    entry.reason = `PRODUCT FINDING: ${finding.what}`
  }
  ledger.findings.push({
    ...finding,
    tenantId: journeyState.tenantId ?? null,
    timestamp: new Date().toISOString(),
  })
  await persistLedger()
}

export async function dismissCookieConsent(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.localStorage.setItem('autoerp-cookie-consent', 'accepted')
  })
}

export async function registerFreshTenant(page: Page): Promise<CampaignCredentials> {
  const selectors = campaignSelectors(page)
  if (reuseMode) {
    const reused: CampaignCredentials = { email: REUSE_EMAIL, name: 'reused tenant', password: REUSE_PASSWORD }
    await loginAs(page, reused)
    const session = await readBrowserSession(page)
    journeyState.credentials = reused
    journeyState.tenantId = session.tenantId
    journeyState.userId = session.userId
    ledger.tenantId = session.tenantId
    await addLedgerEvidence('L0', `REUSE MODE: logged into existing tenant ${session.tenantId} (${REUSE_EMAIL}); registration + second-company census skipped`)
    await persistLedger()
    return reused
  }
  const suffix = runId.toLowerCase().replace(/[^a-z0-9]/g, '').slice(-20)
  const credentials: CampaignCredentials = {
    email: `campaign+${suffix}@test.otospex.dev`,
    name: `Campaign Owner ${suffix}`,
    password: 'Campaign!2026Safe',
  }

  await dismissCookieConsent(page)
  await page.goto(routes.register)
  await selectors.register.name.fill(credentials.name)
  await selectors.register.email.fill(credentials.email)
  await selectors.register.password.fill(credentials.password)
  // Step 1 has no confirm-password field (AccountStep.tsx); the step button is "Continue".
  await selectors.register.next.click()
  await selectors.register.country.selectOption(COUNTRY)
  const hasCompatibleVertical = await selectors.register.vertical
    .waitFor({ state: 'visible', timeout: 5_000 })
    .then(() => true, () => false)
  if (!hasCompatibleVertical) {
    await recordProductFinding({
      evidence: {
        request: { method: 'UI', path: routes.register, required_vertical: 'parapharmacy' },
        response: 'The target product build does not offer the Parapharmacy registration option',
      },
      leg: 'L0',
      what: 'Target registration has no batch-expiry-compatible vertical',
      where: 'Registration business-vertical step',
    })
  }
  await expect(selectors.register.vertical, 'Parapharmacy is required for the expiry campaign leg').toBeVisible()
  await selectors.register.vertical.click()
  await selectors.register.next.click()
  await selectors.register.companyName.fill(`Campaign ${COUNTRY} ${suffix}`)
  await selectors.register.next.click()
  // Step 4 (ReviewStep) carries the single terms checkbox and the Create Account button.
  await selectors.register.terms.check()
  const [registerResponse] = await Promise.all([
    page.waitForResponse((response) => response.url().includes('/api/v1/auth/register'), { timeout: 300_000 }), // registration provisions 576 migrations; the P0-2 seam allows 300 s
    selectors.register.createAccount.click(),
  ])
  const registerBody = await registerResponse.text()
  const bodyIsJson = registerBody.trimStart().startsWith('{')
  if (registerResponse.status() === 429) {
    const retryAfter = registerResponse.headers()['retry-after'] ?? 'unknown'
    throw new Error(`register throttled (HTTP 429, Retry-After: ${retryAfter}s) — the target rate-limits registrations per IP; re-run later or from another client`)
  }
  if (registerResponse.status() === 500 && /Maximum execution time/i.test(registerBody)) {
    // P0-2: synchronous in-request provisioning crossed the PHP/fpm time limit — the tenant is
    // left half-provisioned (orphan). Recorded as a finding, then the leg fails (nothing to log into).
    await recordProductFinding({
      evidence: { request: { method: 'POST', path: '/api/v1/auth/register' }, response: { status: 500, body_head: registerBody.slice(0, 300) } },
      leg: 'L0',
      what: 'Registration exceeds the request time limit (synchronous tenant provisioning) — 500 + orphan tenant',
      where: 'POST /api/v1/auth/register (TenantProvisioningService → MigrateDatabase in-request)',
    })
  }
  if (registerResponse.status() !== 201) {
    throw new Error(`register failed: HTTP ${registerResponse.status()} — ${registerBody.slice(0, 300)}`)
  }
  if (!bodyIsJson) {
    // P0 (2026-08-29): tenant migrations run synchronously inside the request
    // (TenantProvisioningService → MigrateDatabase) and several migrations
    // `echo` their census, so the 201 body is prefixed with plain text the
    // client cannot parse — the user is stranded on step 4 with a tenant
    // silently created. Record it, then continue via login so the rest of the
    // journey is still exercised; L10 keeps the run red.
    await recordProductFinding({
      evidence: {
        request: { method: 'POST', path: '/api/v1/auth/register' },
        response: { status: registerResponse.status(), body_head: registerBody.slice(0, 600) },
      },
      leg: 'L0',
      what: 'Registration response body is not JSON — migration census lines leak into the HTTP body (client stranded on step 4)',
      where: 'POST /api/v1/auth/register (TenantProvisioningService runs MigrateDatabase in-request; migrations echo)',
    })
    await loginAs(page, credentials)
  } else {
    // The post-auth landing route varies by vertical/role (/reports for the
    // owner dashboard, /dashboard elsewhere): pin "left /register" + the shell.
    await expect(page).not.toHaveURL(/\/(?:register|login)(?:[/?#]|$)/, { timeout: 45_000 })
    await expect(selectors.shell.profile).toBeVisible({ timeout: 45_000 })
  }

  const session = await readBrowserSession(page)
  journeyState.credentials = credentials
  journeyState.tenantId = session.tenantId
  journeyState.userId = session.userId
  ledger.tenantId = session.tenantId
  ledger.credentials = KEEP_TENANT ? credentials : null
  await persistLedger()
  return credentials
}

/**
 * Every Playwright test gets a fresh browser context, so legs after L0 must
 * re-establish the session: seed the cookie consent + the persisted company
 * selection (the app stores the bare company id under 'autoerp-company-selection',
 * companyStore.ts) so the UI wizard runs on the journey company, then log in.
 */
export async function ensureSession(page: Page): Promise<void> {
  if (!page.url().startsWith('about:blank')) return
  const credentials = journeyState.credentials
  const companyId = journeyState.companyId
  if (!credentials || !companyId) throw new Error('ensureSession: L0 must run first (credentials/company missing)')
  const persistedAuth = journeyState.persistedAuth
  await page.addInitScript(({ selectedCompanyId, auth }) => {
    window.localStorage.setItem('autoerp-cookie-consent', 'accepted')
    window.localStorage.setItem('autoerp-company-selection', selectedCompanyId)
    if (auth) window.localStorage.setItem('autoerp-auth', auth)
  }, { selectedCompanyId: companyId, auth: persistedAuth ?? null })
  if (persistedAuth) {
    // Re-inject the L0 session token instead of logging in again: the login route is
    // throttled perMinute(5) and a login-per-leg journey would trip it.
    await page.goto('/')
    await expect(page).not.toHaveURL(/\/login(?:[/?#]|$)/, { timeout: 30_000 })
    await expect(campaignSelectors(page).shell.profile).toBeVisible({ timeout: 30_000 })
    return
  }
  await loginAs(page, credentials)
}

export async function loginAs(page: Page, credentials: CampaignCredentials): Promise<void> {
  const selectors = campaignSelectors(page)
  await dismissCookieConsent(page)
  await page.goto(routes.login)
  await selectors.login.email.fill(credentials.email)
  await selectors.login.password.fill(credentials.password)
  await selectors.login.submit.click()
  await expect(page).not.toHaveURL(/\/login(?:[/?#]|$)/, { timeout: 30_000 })
  await expect(selectors.shell.profile).toBeVisible({ timeout: 30_000 })
}

/**
 * Browser-token API transport. Census and where-did-it-land reads use:
 * GET /user/companies, /locations, /payment-methods, /payment-repositories,
 * /uom/units, /partners/{id}, /documents, /stock-levels, /batches,
 * /journal-entries and /reports/trial-balance (module routes.php authorities).
 */
export async function apiRequest(
  page: Page,
  method: ApiMethod,
  path: string,
  body?: unknown,
  companyId?: string,
): Promise<ApiResult> {
  const request = await page.evaluate(
    async ({ apiUrl, body: requestBody, companyId: companyOverride, method: requestMethod, path: requestPath }) => {
      function readPersisted(key: string): unknown {
        const raw = localStorage.getItem(key)
        if (!raw) return null
        try {
          return (JSON.parse(raw) as { state?: unknown }).state ?? null
        } catch {
          return null
        }
      }

      const auth = readPersisted('autoerp-auth') as { token?: string } | null
      const company = readPersisted('autoerp-company') as { currentCompanyId?: string } | null
      const xsrfMatch = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
      const effectiveCompanyId = companyOverride ?? company?.currentCompanyId ?? null
      const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      }
      if (auth?.token) headers['Authorization'] = `Bearer ${auth.token}`
      if (effectiveCompanyId) headers['X-Company-Id'] = effectiveCompanyId
      if (xsrfMatch) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfMatch[1] ?? '')

      const response = await fetch(`${apiUrl}/api/v1${requestPath}`, {
        ...(requestBody === undefined ? {} : { body: JSON.stringify(requestBody) }),
        credentials: 'include',
        headers,
        method: requestMethod,
      })
      let parsed: unknown = null
      try {
        parsed = await response.json()
      } catch {
        parsed = null
      }
      return { body: parsed, companyId: effectiveCompanyId, status: response.status }
    },
    { apiUrl: API_URL, body, companyId, method, path },
  )

  return {
    body: request.body,
    request: {
      ...(body === undefined ? {} : { body }),
      companyId: request.companyId,
      method,
      path,
    },
    status: request.status,
    timestamp: new Date().toISOString(),
  }
}

export function requireApiData(result: ApiResult, description: string): unknown {
  expect(result.status, `${description}: ${JSON.stringify(result.body)}`).toBeGreaterThanOrEqual(200)
  expect(result.status, `${description}: ${JSON.stringify(result.body)}`).toBeLessThan(300)
  if (!isRecord(result.body) || !('data' in result.body)) {
    throw new Error(`${description}: response has no data envelope: ${JSON.stringify(result.body)}`)
  }
  return result.body['data']
}

export async function renderFixture(templateName: string): Promise<string> {
  await mkdir(campaignOutputDir, { recursive: true })
  const templatePath = resolve(process.cwd(), 'e2e/campaign/fixtures', templateName)
  const outputPath = resolve(campaignOutputDir, templateName.replace('.template', ''))
  const template = await readFile(templatePath, 'utf8')
  await writeFile(outputPath, template.replaceAll('{{RUN}}', runId), 'utf8')
  journeyState.runDirectory = campaignOutputDir
  return outputPath
}

export async function runImportWizard(
  page: Page,
  importType: 'parties' | 'products',
  fixturePath: string,
  downloadWorkbook = false,
): Promise<{ duplicatePolicyApplied: boolean; workbookText: string | null }> {
  const selectors = campaignSelectors(page)
  await page.goto(routes.importDashboard)
  await (importType === 'parties' ? selectors.import.partiesTile : selectors.import.productsTile).click()
  await expect(page).toHaveURL(
    importType === 'parties' ? /\/settings\/import\/parties(?:[/?#]|$)/ : /\/settings\/import\/products(?:[/?#]|$)/,
  )
  await expect(selectors.import.step.upload).toBeVisible()
  await selectors.import.fileInput.setInputFiles(fixturePath)
  await expect(selectors.import.next).toBeEnabled({ timeout: 30_000 })
  await selectors.import.next.click()
  await expect(selectors.import.step.mapping).toBeVisible()
  await expect(selectors.import.validate).toBeEnabled({ timeout: 30_000 })
  await selectors.import.validate.click()

  await expect(selectors.import.step.options.or(selectors.import.step.preview)).toBeVisible({ timeout: 30_000 })
  if (await selectors.import.step.options.isVisible()) {
    await selectors.import.next.click()
  }
  await expect(selectors.import.step.preview).toBeVisible({ timeout: 30_000 })

  let duplicatePolicyApplied = false
  if (await selectors.import.duplicateSummary.isVisible()) {
    await selectors.import.duplicateSkip.click()
    duplicatePolicyApplied = true
  }
  await selectors.import.proceed.click()
  await expect(selectors.import.step.execute).toBeVisible()
  await selectors.import.execute.click()
  await expect(selectors.import.step.complete).toBeVisible({ timeout: 60_000 })
  await expect(selectors.import.viewResults.or(selectors.import.step.complete)).toBeVisible()
  let workbookText: string | null = null
  if (downloadWorkbook) {
    const downloadPromise = page.waitForEvent('download')
    await selectors.import.completeWorkbook.click()
    const download = await downloadPromise
    const workbookPath = resolve(campaignOutputDir, `${importType}-${runId}-result.xlsx`)
    await download.saveAs(workbookPath)
    const extracted = await execFileAsync('unzip', ['-p', workbookPath], { maxBuffer: 10 * 1024 * 1024 })
    workbookText = extracted.stdout
  }
  return { duplicatePolicyApplied, workbookText }
}

export async function pollUntil<T>(
  operation: () => Promise<T>,
  predicate: (value: T) => boolean,
  timeoutMs = 45_000,
): Promise<T> {
  const deadline = Date.now() + timeoutMs
  let latest = await operation()
  while (!predicate(latest) && Date.now() < deadline) {
    await new Promise<void>((resolvePromise) => {
      setTimeout(resolvePromise, 1_000)
    })
    latest = await operation()
  }
  if (!predicate(latest)) {
    throw new Error(`projection timeout — worker running? Latest value: ${JSON.stringify(latest)}`)
  }
  return latest
}

export async function whereDidItLand(
  leg: string,
  label: string,
  assertions: ReadonlyArray<() => Promise<string>>,
): Promise<void> {
  const evidence: string[] = []
  for (const assertion of assertions) {
    evidence.push(await assertion())
  }
  await addLedgerEvidence(leg, `${label}: ${evidence.join(' | ')}`)
}

export function asArray(value: unknown, description: string): unknown[] {
  if (!Array.isArray(value)) {
    throw new Error(`${description} must be an array: ${JSON.stringify(value)}`)
  }
  return value
}

export function asRecord(value: unknown, description: string): Record<string, unknown> {
  if (!isRecord(value)) {
    throw new Error(`${description} must be an object: ${JSON.stringify(value)}`)
  }
  return value
}

export function stringField(value: Record<string, unknown>, key: string): string {
  const field = value[key]
  if (typeof field !== 'string') {
    throw new Error(`${key} must be a string: ${JSON.stringify(value)}`)
  }
  return field
}

export function booleanField(value: Record<string, unknown>, key: string): boolean {
  const field = value[key]
  if (typeof field !== 'boolean') {
    throw new Error(`${key} must be a boolean: ${JSON.stringify(value)}`)
  }
  return field
}

export { apiRoutes }

function createRunId(): string {
  return `${new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14)}-${process.pid.toString(36)}`
}

async function readBrowserSession(page: Page): Promise<{ tenantId: string; userId: string }> {
  journeyState.persistedAuth = (await page.evaluate(() => localStorage.getItem('autoerp-auth'))) ?? undefined
  return page.evaluate(() => {
    const raw = localStorage.getItem('autoerp-auth')
    if (!raw) throw new Error('autoerp-auth was not persisted after registration')
    const parsed = JSON.parse(raw) as {
      state?: { user?: { id?: string; tenant_id?: string } }
    }
    const tenantId = parsed.state?.user?.tenant_id
    const userId = parsed.state?.user?.id
    if (!tenantId || !userId) throw new Error('registered session lacks tenant/user ids')
    return { tenantId, userId }
  })
}

function requireLedgerEntry(leg: string): LedgerEntry {
  const entry = ledger.legs.find((candidate) => candidate.leg === leg)
  if (!entry) throw new Error(`Unknown campaign leg: ${leg}`)
  return entry
}

async function persistLedger(): Promise<void> {
  await mkdir(campaignOutputDir, { recursive: true })
  await writeFile(ledgerPath, `${JSON.stringify(ledger, null, 2)}\n`, 'utf8')
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
