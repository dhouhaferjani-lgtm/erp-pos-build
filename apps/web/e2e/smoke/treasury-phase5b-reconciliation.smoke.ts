import { createHash, randomUUID } from 'node:crypto'
import { mkdir } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { expect, test, type APIRequestContext, type APIResponse, type Page } from '@playwright/test'

/**
 * Treasury Phase 5b — live statement-import and reconciliation exit drive.
 *
 * This smoke talks only to the real local API and browser stack. It authors a
 * real fiscal card receipt, issues a real outbound expense cheque, imports a
 * CSV through the UI, confirms matching tiers 1/3/4, creates an agio expense,
 * documents an ignored line, completes the statement, and proves that the
 * resulting checkpoint rejects writes on the reconciled date.
 */

const FRONTEND_BASE_URL = process.env['TREASURY_PHASE5B_BASE_URL'] || 'http://127.0.0.1:5173'
const API_BASE = process.env['TREASURY_PHASE5B_API_BASE'] || 'http://127.0.0.1:8010/api/v1'
const CREDENTIALS = { email: 'owner@pharmabio.tn', password: 'password' }
const RUN_ID = `${Date.now()}-${randomUUID().slice(0, 8)}`
const TODAY = new Date().toISOString().slice(0, 10)
const DISPLAY_DATE = TODAY.split('-').reverse().join('/')
const CARD_GROSS = '100.000'
const CARD_NET = '98.500'
const CARD_FEE = '1.500'
const CHEQUE_AMOUNT = '37.125'
const ADJUSTMENT_AMOUNT = '15.000'
const AGIO_AMOUNT = '2.500'
const IGNORED_AMOUNT = '1.250'
const STATEMENT_DELTA = '72.625'

test.skip(
  !!process.env.CI && !process.env.TREASURY_PHASE5B_API_BASE,
  'requires a live db-per-tenant stack',
)
test.use({ baseURL: FRONTEND_BASE_URL })
test.describe.configure({ mode: 'serial', retries: 0 })

const SCREENSHOT_DIR = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
  '..',
  'docs',
  'sessions',
  'treasury-phase5b-e2e',
)

interface AuthUser {
  id: string
  tenant_id: string
  name: string
  email: string
  roles: string[]
}

interface BankRepository {
  id: string
  code: string
  name: string
  type: string
  currency: string
  balance: string
  is_active: boolean
  gl_account_id: string | null
}

interface PaymentMethod {
  id: string
  code: string
  has_maturity: boolean
  instrument_kind: string | null
  has_deducted_fees: boolean
  fee_percent: string
}

interface StatementLine {
  id: string
  label: string
  match_status: string
  executions?: Array<{
    action_type: string
    produced_repository_movement_ids: string[]
  }>
}

let token = ''
let user: AuthUser | null = null
let companyId = ''
let repository: BankRepository | null = null
let chequeMethodId = ''
let cardMethodId = ''
let cardMethodCode = ''
let feeAccountId = ''
let profileId = ''
let profileName = ''
let openingBalance = ''
let closingBalance = ''
let adjustmentLabel = ''
let chequeLabel = ''
let cardLabel = ''
let agioLabel = ''
let ignoredLabel = ''
let duplicateLabel = ''
let mainStatementId = ''
let primerStatementId = ''
let outboundInstrumentId = ''
let checkpointInstrumentId = ''
let fiscalEventId = ''

function authHeaders(): Record<string, string> {
  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
    ...(companyId ? { 'X-Company-ID': companyId } : {}),
  }
}

async function json(response: APIResponse): Promise<Record<string, unknown>> {
  return (await response.json()) as Record<string, unknown>
}

async function expectStatus(response: APIResponse, expected: number, label: string): Promise<void> {
  if (response.status() !== expected) {
    throw new Error(`${label}: expected ${expected}, got ${response.status()}\n${await response.text()}`)
  }
}

function toMillimes(value: string): bigint {
  const [whole = '0', fraction = ''] = value.split('.')
  const negative = whole.startsWith('-')
  const magnitude = BigInt(whole.replace('-', '')) * 1000n
    + BigInt((fraction + '000').slice(0, 3))
  return negative ? -magnitude : magnitude
}

function fromMillimes(value: bigint): string {
  const negative = value < 0n
  const magnitude = negative ? -value : value
  return `${negative ? '-' : ''}${magnitude / 1000n}.${(magnitude % 1000n).toString().padStart(3, '0')}`
}

function addMoney(left: string, right: string): string {
  return fromMillimes(toMillimes(left) + toMillimes(right))
}

function canonicalize(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(canonicalize)
  if (value === null || typeof value !== 'object') return value
  return Object.fromEntries(
    Object.entries(value as Record<string, unknown>)
      .sort(([left], [right]) => left.localeCompare(right))
      .map(([key, item]) => [key, canonicalize(item)]),
  )
}

function canonicalEncode(value: Record<string, unknown>): string {
  return JSON.stringify(canonicalize(value))
}

async function uploadPreview(
  request: APIRequestContext,
  contents: string,
  filename: string,
): Promise<Record<string, unknown>> {
  const response = await request.post(`${API_BASE}/bank-statements/upload`, {
    headers: authHeaders(),
    multipart: {
      payment_repository_id: repository!.id,
      parser_profile_id: profileId,
      file: { name: filename, mimeType: 'text/csv', buffer: Buffer.from(contents) },
    },
  })
  await expectStatus(response, 200, `statement preview ${filename}`)
  return (await json(response)).data as Record<string, unknown>
}

async function confirmPreview(
  request: APIRequestContext,
  previewToken: string,
  opening: string,
  closing: string,
): Promise<string> {
  const response = await request.post(`${API_BASE}/bank-statements`, {
    headers: authHeaders(),
    data: {
      preview_token: previewToken,
      currency: repository!.currency,
      period_start: TODAY,
      period_end: TODAY,
      opening_balance: opening,
      closing_balance: closing,
    },
  })
  await expectStatus(response, 201, 'statement confirm')
  return String(((await json(response)).data as { id: string }).id)
}

async function issueExpenseCheque(
  request: APIRequestContext,
  amount: string,
  reference: string,
): Promise<string> {
  const categoriesResponse = await request.get(`${API_BASE}/expense-categories`, {
    headers: authHeaders(),
  })
  await expectStatus(categoriesResponse, 200, 'expense categories')
  const categories = ((await json(categoriesResponse)).data ?? []) as Array<{ id: string }>
  expect(categories.length).toBeGreaterThan(0)

  const createResponse = await request.post(`${API_BASE}/expenses`, {
    headers: authHeaders(),
    data: {
      vendor_name: `Phase 5b Vendor ${RUN_ID}`,
      expense_category_id: categories[0]!.id,
      receipt_number: `P5B-RCPT-${randomUUID()}`,
      total: amount,
      document_date: TODAY,
      is_paid: false,
      expense_kind: 'generic',
      notes: 'Phase 5b statement-driven outbound clearing',
    },
  })
  await expectStatus(createResponse, 201, 'expense create')
  const expenseId = String(((await json(createResponse)).data as { id: string }).id)

  const postResponse = await request.post(`${API_BASE}/expenses/${expenseId}/post`, {
    headers: authHeaders(),
  })
  await expectStatus(postResponse, 200, 'expense post')

  const payResponse = await request.post(`${API_BASE}/expenses/${expenseId}/pay`, {
    headers: authHeaders(),
    data: {
      mode: 'instrument',
      payment_repository_id: repository!.id,
      payment_method_id: chequeMethodId,
      payment_date: TODAY,
      instrument: {
        kind: 'cheque',
        reference,
        drawer_name: `Phase 5b Vendor ${RUN_ID}`,
      },
    },
  })
  await expectStatus(payResponse, 200, 'expense cheque issue')
  const paid = (await json(payResponse)).data as {
    metadata: { is_paid: boolean; payment_instrument_id: string }
  }
  expect(paid.metadata.is_paid).toBe(false)
  return paid.metadata.payment_instrument_id
}

async function waitForFiscalMovement(request: APIRequestContext): Promise<void> {
  await expect.poll(async () => {
    const response = await request.get(
      `${API_BASE}/payment-repositories/${repository!.id}/movements?search=${fiscalEventId}&per_page=100`,
      { headers: authHeaders() },
    )
    if (!response.ok()) return false
    const movements = ((await json(response)).data ?? []) as Array<{ source_id: string }>
    return movements.some((movement) => movement.source_id === fiscalEventId)
  }, { timeout: 60_000, intervals: [500, 1000, 2000] }).toBe(true)
}

async function seedAuth(page: Page): Promise<void> {
  const authState = {
    state: { user, token, isAuthenticated: true, isLoading: false },
    version: 0,
  }
  await page.addInitScript(({ auth, selectedCompany }) => {
    window.localStorage.setItem('autoerp-auth', auth)
    window.localStorage.setItem('autoerp-company-selection', selectedCompany)
  }, { auth: JSON.stringify(authState), selectedCompany: companyId })
}

async function chooseLine(page: Page, label: string): Promise<void> {
  const button = page.locator('button').filter({ hasText: label }).first()
  await expect(button).toBeVisible({ timeout: 20_000 })
  await button.click()
  await expect(page.locator('aside').getByText(label, { exact: true })).toBeVisible()
}

async function confirmTier(page: Page, tier: number, reason?: string): Promise<void> {
  const suggestion = page.locator('aside article')
    .filter({ hasText: `Tier ${tier}` })
    .filter(reason ? { hasText: reason } : {})
    .first()
  await expect(suggestion, `Tier ${tier} suggestion`).toBeVisible({ timeout: 20_000 })
  await suggestion.getByRole('button', { name: 'Confirm match' }).click()
  await expect(page.locator('aside').getByText('Matched', { exact: true })).toBeVisible({
    timeout: 20_000,
  })
}

test.describe('Treasury Phase 5b — live reconciliation exit', () => {
  test('1. authenticate, discover routing dependencies, and create the parser profile', async ({ request }) => {
    const login = await request.post(`${API_BASE}/auth/login`, {
      headers: { Accept: 'application/json' },
      data: CREDENTIALS,
    })
    await expectStatus(login, 200, 'owner login')
    const loginData = (await json(login)).data as {
      token: string
      user: {
        id: string
        tenantId: string
        name: string
        email: string
        roles: string[]
        permissions: string[]
      }
    }
    token = loginData.token
    user = {
      id: loginData.user.id,
      tenant_id: loginData.user.tenantId,
      name: loginData.user.name,
      email: loginData.user.email,
      roles: loginData.user.roles,
    }
    expect(loginData.user.permissions).toEqual(expect.arrayContaining([
      'bank-statements.import',
      'bank-statements.reconcile',
      'bank-statements.reopen',
      'instruments.clear-outbound',
    ]))

    const companiesResponse = await request.get(`${API_BASE}/user/companies`, {
      headers: authHeaders(),
    })
    await expectStatus(companiesResponse, 200, 'user companies')
    const companies = ((await json(companiesResponse)).data ?? []) as Array<{
      id: string
      is_primary: boolean
    }>
    companyId = (companies.find((company) => company.is_primary) ?? companies[0])!.id

    const repositoriesResponse = await request.get(`${API_BASE}/payment-repositories`, {
      headers: authHeaders(),
    })
    await expectStatus(repositoriesResponse, 200, 'payment repositories')
    const repositories = ((await json(repositoriesResponse)).data ?? []) as BankRepository[]
    const bankCandidates = repositories.filter((candidate) => candidate.type === 'bank_account'
      && candidate.is_active && candidate.gl_account_id !== null)
    let balance: { balance: string; last_reconciled_at: string | null } | null = null
    for (const candidate of bankCandidates) {
      const response = await request.get(
        `${API_BASE}/payment-repositories/${candidate.id}/balance`,
        { headers: authHeaders() },
      )
      await expectStatus(response, 200, `repository checkpoint discovery (${candidate.code})`)
      const candidateBalance = (await json(response)).data as {
        balance: string
        last_reconciled_at: string | null
      }
      if (candidateBalance.last_reconciled_at === null) {
        repository = candidate
        balance = candidateBalance
        break
      }
    }
    expect(repository, 'an active, GL-linked, unreconciled bank repository exists').toBeTruthy()
    expect(balance, 'the selected bank exposes its balance baseline').toBeTruthy()
    expect(balance!.last_reconciled_at).toBeNull()
    openingBalance = balance!.balance
    closingBalance = addMoney(openingBalance, STATEMENT_DELTA)

    const methodsResponse = await request.get(`${API_BASE}/payment-methods`, {
      headers: authHeaders(),
    })
    await expectStatus(methodsResponse, 200, 'payment methods')
    const methods = ((await json(methodsResponse)).data ?? []) as PaymentMethod[]
    const cheque = methods.find((method) => method.code === 'CHECK')
    expect(cheque?.instrument_kind).toBe('cheque')
    chequeMethodId = cheque!.id

    const accountsResponse = await request.get(`${API_BASE}/accounts?per_page=1000`, {
      headers: authHeaders(),
    })
    await expectStatus(accountsResponse, 200, 'accounts')
    const accounts = ((await json(accountsResponse)).data ?? []) as Array<{
      id: string
      code: string
      is_active?: boolean
    }>
    const feeAccount = accounts.find((account) => account.code === '627')
      ?? accounts.find((account) => account.code.startsWith('627') && account.is_active !== false)
    expect(feeAccount, 'an active bank-fee expense account exists').toBeTruthy()
    feeAccountId = feeAccount!.id

    cardMethodCode = `P5BCARD${randomUUID().replaceAll('-', '').slice(0, 10)}`
    const routingResponse = await request.post(`${API_BASE}/payment-methods`, {
      headers: authHeaders(),
      data: {
        code: cardMethodCode,
        name: `Phase 5b Card ${RUN_ID}`,
        default_repository_id: repository!.id,
        fee_account_id: feeAccountId,
        fee_type: 'percentage',
        fee_percent: '1.50',
        has_deducted_fees: true,
        has_maturity: false,
        is_physical: false,
      },
    })
    await expectStatus(routingResponse, 201, 'card routing configuration')
    cardMethodId = String(((await json(routingResponse)).data as { id: string }).id)
    expect(cardMethodId).toMatch(/^[0-9a-f-]{36}$/)

    profileName = `Phase 5b CSV ${RUN_ID}`
    const profileResponse = await request.post(`${API_BASE}/statement-import-profiles`, {
      headers: authHeaders(),
      data: {
        payment_repository_id: repository!.id,
        name: profileName,
        is_active: true,
        parser_key: 'csv',
        column_map: {
          value_date: 'Date',
          amount: 'Amount',
          reference: 'Reference',
          bank_transaction_id: 'Transaction ID',
          label: 'Label',
        },
        date_format: 'd/m/Y',
        decimal_format: 'dot',
        direction_convention: 'signed_amount',
        header_rows: 0,
        matching_window_days: 5,
      },
    })
    await expectStatus(profileResponse, 201, 'parser profile create')
    profileId = String(((await json(profileResponse)).data as { id: string }).id)
  })

  test('2. create real Tier 1, Tier 3, and Tier 4 candidates plus a duplicate primer', async ({ request }) => {
    adjustmentLabel = `P5B count variance ${RUN_ID}`
    chequeLabel = `P5B outbound cheque ${RUN_ID}`
    cardLabel = `P5B card settlement ${RUN_ID}`
    agioLabel = `P5B bank agio ${RUN_ID}`
    ignoredLabel = `P5B informational debit ${RUN_ID}`
    duplicateLabel = `P5B duplicate primer ${RUN_ID}`

    const adjustmentResponse = await request.post(
      `${API_BASE}/payment-repositories/${repository!.id}/adjustments`,
      {
        headers: authHeaders(),
        data: {
          direction: 'in',
          amount: ADJUSTMENT_AMOUNT,
          reason_code: 'count_variance',
          reason_text: adjustmentLabel,
        },
      },
    )
    await expectStatus(adjustmentResponse, 201, 'Tier 1 adjustment')

    const outboundReference = `P5B-OUT-${RUN_ID}`
    outboundInstrumentId = await issueExpenseCheque(request, CHEQUE_AMOUNT, outboundReference)
    checkpointInstrumentId = await issueExpenseCheque(request, '11.111', `P5B-CHECKPOINT-${RUN_ID}`)

    const terminalsResponse = await request.get(`${API_BASE}/pos/terminals`, {
      headers: authHeaders(),
    })
    await expectStatus(terminalsResponse, 200, 'POS terminals')
    const terminals = ((await json(terminalsResponse)).data ?? []) as Array<{
      id: string
      location_id: string
      genesis_seed: string
      last_hash: string | null
      hash_sequence: number
      is_active: boolean
    }>
    const terminalTemplate = terminals.find((candidate) => candidate.is_active)
    expect(terminalTemplate, 'an active terminal exists').toBeTruthy()
    const terminalResponse = await request.post(`${API_BASE}/pos/terminals`, {
      headers: authHeaders(),
      data: {
        code: `P5B-${randomUUID().slice(0, 8)}`,
        name: `Phase 5b E2E ${RUN_ID}`,
        location_id: terminalTemplate!.location_id,
        description: 'Dedicated Phase 5b fiscal projection terminal',
      },
    })
    await expectStatus(terminalResponse, 201, 'dedicated POS terminal create')
    const terminal = (await json(terminalResponse)).data as {
      id: string
      genesis_seed: string
    }

    fiscalEventId = randomUUID()
    const eventTime = new Date(Date.now() - 30_000).toISOString().replace(/\.\d{3}Z$/, 'Z')
    const payloadEventTime = new Date(Date.now() - 30_000).toISOString()
    const sequenceNumber = 1
    const previousHash = terminal.genesis_seed
    const salePayload = {
      business_date: TODAY,
      approval_references: [],
      buyer: null,
      cashier_id: user!.id,
      cashier_name: user!.name,
      consumption_mode: null,
      currency_code: 'TND',
      currency_scale: 3,
      event_time_device: payloadEventTime,
      invoice_type_code: 'SALE',
      line_items: [{
        gtin: null,
        line_discount_amount: '0.000',
        line_discount_reason: null,
        line_subtotal: CARD_GROSS,
        line_vat: '0.000',
        name: 'Phase 5b card batch item',
        non_collected_subtype: null,
        product_id: `phase5b-${RUN_ID}`,
        quantity: '1.000',
        sku: `P5B-${RUN_ID}`,
        tax_category_code: 'Z',
        unit_price: CARD_GROSS,
        vat_rate: '0.00',
      }],
      lottery_code: null,
      notes: 'Phase 5b live card projection',
      original_receipt_reference: null,
      payments: [{
        amount: CARD_GROSS,
        foreign_currency_amount: null,
        foreign_currency_code: null,
        instrument_serial: null,
        instrument_type: null,
        method_code: cardMethodCode,
      }],
      receipt_uuid: fiscalEventId,
      seller: {
        address: {
          city: 'Tunis',
          country_code: 'TN',
          postal_code: '1000',
          street: 'Phase 5b test address',
        },
        name: 'Pharmabio',
        tax_jurisdiction_country_code: 'TN',
        tax_number: '1234567AM000',
      },
      shift_id: randomUUID(),
      subtotal: CARD_GROSS,
      table_id: null,
      terminal_id: terminal.id,
      total: CARD_GROSS,
      training_flag: false,
      transaction_discount_amount: '0.000',
      transaction_discount_reason: null,
      vat_breakdown: [{
        gross_amount: CARD_GROSS,
        net_amount: CARD_GROSS,
        rate: '0.00',
        tax_category_code: 'Z',
        vat_amount: '0.000',
      }],
      vat_total: '0.000',
      vouchers_redeemed: [],
    }
    const envelopeBase = {
      id: fiscalEventId,
      tenant_id: user!.tenant_id,
      company_id: companyId,
      terminal_id: terminal.id,
      operator_id: user!.id,
      event_type: 'SALE_RECEIPT',
      event_version: 1,
      signature_version: 'hash-chain-integrity-v1',
      sequence_number: sequenceNumber,
      event_time_device: eventTime,
      business_date: TODAY,
      chain_context: 'operational',
      last_server_time_seen: null,
      reference_event_id: null,
      reference_document_id: null,
      source_event_class: null,
      source_event_id: null,
      previous_hash: previousHash,
    }
    const canonicalBytes = canonicalEncode({
      business_date: envelopeBase.business_date,
      chain_context: envelopeBase.chain_context,
      company_id: envelopeBase.company_id,
      event_time_device: envelopeBase.event_time_device,
      event_type: envelopeBase.event_type,
      event_version: envelopeBase.event_version,
      operator_id: envelopeBase.operator_id,
      payload: salePayload,
      previous_hash: envelopeBase.previous_hash,
      reference_document_id: envelopeBase.reference_document_id,
      reference_event_id: envelopeBase.reference_event_id,
      sequence_number: envelopeBase.sequence_number,
      signature_version: envelopeBase.signature_version,
      tenant_id: envelopeBase.tenant_id,
      terminal_id: envelopeBase.terminal_id,
    })
    const fiscalResponse = await request.post(`${API_BASE}/pos/sync/fiscal-events`, {
      headers: authHeaders(),
      data: {
        envelopes: [{
          envelope_id: randomUUID(),
          type: 'FISCAL_EVENT',
          payload_version: 1,
          idempotency_key: `${terminal.id}:${sequenceNumber}`,
          payload: {
            ...envelopeBase,
            canonical_bytes: canonicalBytes,
            current_hash: createHash('sha256').update(canonicalBytes).digest('hex'),
          },
        }],
      },
    })
    await expectStatus(fiscalResponse, 200, 'fiscal card receipt ingest')
    const fiscalResult = ((await json(fiscalResponse)).results as Array<{
      stored: boolean
      exception_class: string | null
    }>)[0]!
    expect(fiscalResult).toMatchObject({ stored: true, exception_class: null })
    await waitForFiscalMovement(request)

    const primerCsv = [
      'Date,Amount,Reference,Transaction ID,Label',
      `${DISPLAY_DATE},-1.250,DUP-${RUN_ID},DUP-TX-${RUN_ID},${duplicateLabel}`,
      '',
    ].join('\n')
    const primer = await uploadPreview(request, primerCsv, `phase5b-primer-${RUN_ID}.csv`)
    expect(primer.accepted_line_count).toBe(1)
    primerStatementId = await confirmPreview(
      request,
      String(primer.preview_token),
      openingBalance,
      addMoney(openingBalance, `-${IGNORED_AMOUNT}`),
    )

    chequeLabel = `${chequeLabel} ${outboundReference}`
  })

  test('3. upload in the browser and observe every preview exception report', async ({ page }) => {
    await mkdir(SCREENSHOT_DIR, { recursive: true })
    await seedAuth(page)
    await page.goto('/treasury/statements')
    await expect(page.getByRole('heading', { name: 'Bank statements' })).toBeVisible({ timeout: 20_000 })
    await page.getByRole('button', { name: 'Import statement' }).click()

    await page.getByLabel('Bank account', { exact: true }).selectOption(repository!.id)
    await page.getByRole('button', { name: 'Continue' }).click()

    const mainCsv = [
      'Date,Amount,Reference,Transaction ID,Label',
      `${DISPLAY_DATE},15.000,ADJ-${RUN_ID},ADJ-TX-${RUN_ID},${adjustmentLabel}`,
      `${DISPLAY_DATE},-37.125,${chequeLabel.split(' ').at(-1)},CHEQUE-TX-${RUN_ID},${chequeLabel}`,
      `${DISPLAY_DATE},98.500,CARD-${RUN_ID},CARD-TX-${RUN_ID},${cardLabel}`,
      `${DISPLAY_DATE},-2.500,AGIO-${RUN_ID},AGIO-TX-${RUN_ID},${agioLabel}`,
      `${DISPLAY_DATE},-1.250,INFO-${RUN_ID},INFO-TX-${RUN_ID},${ignoredLabel}`,
      `${DISPLAY_DATE},-1.250,DUP-${RUN_ID},DUP-TX-${RUN_ID},${duplicateLabel}`,
      `${DISPLAY_DATE},0.000,ZERO-${RUN_ID},ZERO-TX-${RUN_ID},P5B zero row ${RUN_ID}`,
      `not-a-date,9.999,BAD-${RUN_ID},BAD-TX-${RUN_ID},P5B unparseable row ${RUN_ID}`,
      '',
    ].join('\n')
    await page.getByLabel('Statement file').setInputFiles({
      name: `phase5b-main-${RUN_ID}.csv`,
      mimeType: 'text/csv',
      buffer: Buffer.from(mainCsv),
    })
    await page.getByRole('button', { name: 'Continue' }).click()
    await page.getByLabel('Parsing profile').selectOption(profileId)
    await page.getByRole('button', { name: 'Preview statement' }).click()

    const reports: Array<[string, string]> = [
      ['Accepted lines', '5'],
      ['Already imported', '1'],
      ['Zero rows dropped', '1'],
      ['Rows needing attention', '1'],
    ]
    for (const [label, value] of reports) {
      const report = page.getByText(label, { exact: true }).locator('..')
      await expect(report.getByText(value, { exact: true })).toBeVisible({ timeout: 20_000 })
    }
    await expect(page.getByText(/Row \d+:.*date/i)).toBeVisible()
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01-import-preview-reports.png'), fullPage: true })

    await page.getByLabel('Period start').fill(TODAY)
    await page.getByLabel('Period end').fill(TODAY)
    await page.getByLabel('Opening balance').fill(openingBalance)
    await page.getByLabel('Closing balance').fill(closingBalance)
    await page.getByLabel('Import a bank statement')
      .getByRole('button', { name: 'Import statement' })
      .click()
    await expect(page).toHaveURL(/\/treasury\/statements\/[0-9a-f-]+$/, { timeout: 20_000 })
    mainStatementId = page.url().split('/').at(-1) ?? ''
    expect(mainStatementId).toMatch(/^[0-9a-f-]{36}$/)
    await expect(page.getByRole('heading', { name: 'Statement reconciliation' })).toBeVisible()
  })

  test('4. confirm Tier 1 adjustment, Tier 3 outbound clear, and Tier 4 card fee in the UI', async ({ page, request }) => {
    await seedAuth(page)
    await page.goto(`/treasury/statements/${mainStatementId}`)

    await chooseLine(page, adjustmentLabel)
    await confirmTier(page, 1)

  await chooseLine(page, chequeLabel)
  await confirmTier(page, 3, 'Pending instrument')
  const clearedInstrument = await request.get(
    `${API_BASE}/payment-instruments/${outboundInstrumentId}`,
    { headers: authHeaders() },
  )
  await expectStatus(clearedInstrument, 200, 'tier 3 outbound instrument state')
  expect(((await json(clearedInstrument)).data as { status: string }).status).toBe('cleared')

    await chooseLine(page, cardLabel)
  await confirmTier(page, 4)
  await expect(page.locator('aside').getByText('Card batch and fee created')).toBeVisible()
  const matchedStatement = await request.get(`${API_BASE}/bank-statements/${mainStatementId}`, {
    headers: authHeaders(),
  })
  await expectStatus(matchedStatement, 200, 'tier 4 execution provenance')
  const matchedLines = ((await json(matchedStatement)).data as { lines: StatementLine[] }).lines
  const cardLine = matchedLines.find((line) => line.label === cardLabel)
  expect(cardLine?.executions?.filter((execution) => execution.action_type === 'acquirer_fee')).toHaveLength(1)
  const feeExecution = cardLine!.executions!.find((execution) => execution.action_type === 'acquirer_fee')!
  expect(feeExecution.produced_repository_movement_ids.length).toBeGreaterThanOrEqual(2)
  const feeMovements = await request.get(
    `${API_BASE}/payment-repositories/${repository!.id}/movements?search=${cardLine!.id}&per_page=100`,
    { headers: authHeaders() },
  )
  await expectStatus(feeMovements, 200, 'tier 4 fee movement')
  const feeRows = ((await json(feeMovements)).data ?? []) as Array<{ source_id: string; amount: string; direction: string }>
  expect(feeRows.filter((row) => row.source_id === cardLine!.id && row.direction === 'out')).toEqual([
    expect.objectContaining({ amount: CARD_FEE }),
  ])
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02-tier-matches-confirmed.png'), fullPage: true })
  })

  test('5. create the agio expense and document the ignored informational line', async ({ page }) => {
    await seedAuth(page)
    await page.goto(`/treasury/statements/${mainStatementId}`)

    await chooseLine(page, agioLabel)
    await page.getByRole('button', { name: 'Create expense from line' }).click()
    await page.getByLabel('Vendor name').fill('Phase 5b Test Bank')
    await page.getByLabel('Notes').fill(`Live agio reconciliation ${RUN_ID}`)
    await page.getByRole('button', { name: 'Create and match' }).click()
    await expect(page.locator('aside').getByText('Created and matched', { exact: true })).toBeVisible({
      timeout: 20_000,
    })
    await expect(page.locator('aside').getByText('Expense created')).toBeVisible()

    await chooseLine(page, ignoredLabel)
    await page.getByLabel('Reason').selectOption('informational')
    await page.getByLabel('Required explanation').fill(
      `Reviewed as an informational bank-only debit for live run ${RUN_ID}`,
    )
    await page.getByRole('button', { name: 'Ignore line' }).click()
    await expect(page.locator('aside').getByText('Ignored', { exact: true })).toBeVisible({
      timeout: 20_000,
    })
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '03-created-and-ignored.png'), fullPage: true })
  })

  test('6. complete, observe the checkpoint, and prove reconciled-date writes are rejected', async ({ request, page }) => {
    await seedAuth(page)
    await page.goto(`/treasury/statements/${mainStatementId}`)
    await expect(page.getByText('5/5', { exact: true })).toBeVisible({ timeout: 20_000 })
    await page.getByRole('button', { name: 'Complete statement' }).click()
    await expect(page.getByText('Complete reconciliation', { exact: true })).toBeVisible()
    await page.getByRole('checkbox').check()
    await page.getByRole('button', { name: 'Complete statement' }).last().click()
    await expect(page.getByText('Reconciled', { exact: true }).first()).toBeVisible({ timeout: 20_000 })
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '04-reconciled-checkpoint.png'), fullPage: true })

    const statementResponse = await request.get(`${API_BASE}/bank-statements/${mainStatementId}`, {
      headers: authHeaders(),
    })
    await expectStatus(statementResponse, 200, 'reconciled statement')
    const statement = (await json(statementResponse)).data as {
      status: string
      lines: StatementLine[]
    }
    expect(statement.status).toBe('reconciled')
    expect(statement.lines.map((line) => line.match_status).sort()).toEqual([
      'ignored',
      'matched',
      'matched',
      'matched',
      'resolved_by_creation',
    ])

    const balanceResponse = await request.get(
      `${API_BASE}/payment-repositories/${repository!.id}/balance`,
      { headers: authHeaders() },
    )
    await expectStatus(balanceResponse, 200, 'stamped repository checkpoint')
    const balance = (await json(balanceResponse)).data as {
      balance: string
      last_reconciled_at: string | null
      last_reconciled_balance: string | null
    }
    // The ignored informational outflow is metadata-only, so it remains in
    // the statement delta but never changes the live repository balance.
    expect(balance.balance).toBe(addMoney(closingBalance, IGNORED_AMOUNT))
    expect(balance.last_reconciled_at?.slice(0, 10)).toBe(TODAY)
    expect(balance.last_reconciled_balance).toBe(closingBalance)

    const adjustmentResponse = await request.post(
      `${API_BASE}/payment-repositories/${repository!.id}/adjustments`,
      {
        headers: authHeaders(),
        data: {
          direction: 'in',
          amount: '1.000',
          reason_code: 'correction',
          reason_text: `P5B checkpoint rejection ${RUN_ID}`,
        },
      },
    )
    await expectStatus(adjustmentResponse, 422, 'reconciled-date adjustment rejection')
    expect((await adjustmentResponse.text()).toLowerCase()).toContain('reconcil')

    const clearResponse = await request.post(
      `${API_BASE}/payment-instruments/${checkpointInstrumentId}/clear-outbound`,
      { headers: authHeaders(), data: { occurred_at: TODAY } },
    )
    await expectStatus(clearResponse, 422, 'reconciled-date outbound clear rejection')
    const checkpointEvents = await request.get(
      `${API_BASE}/payment-instruments/${checkpointInstrumentId}/events`,
      { headers: authHeaders() },
    )
    await expectStatus(checkpointEvents, 200, 'checkpoint instrument events')
    const events = ((await json(checkpointEvents)).data ?? []) as Array<{ event_type: string; journal_entry_id: string | null }>
    expect(events.some((event) => event.event_type === 'cleared')).toBe(false)
    expect(events.filter((event) => event.journal_entry_id !== null)).toHaveLength(1)
    const instrumentResponse = await request.get(
      `${API_BASE}/payment-instruments/${checkpointInstrumentId}`,
      { headers: authHeaders() },
    )
    await expectStatus(instrumentResponse, 200, 'checkpoint instrument remains pending')
    expect(((await json(instrumentResponse)).data as { status: string }).status).toBe('received')

    const voidPrimerResponse = await request.post(
      `${API_BASE}/bank-statements/${primerStatementId}/void`,
      { headers: authHeaders() },
    )
    await expectStatus(voidPrimerResponse, 200, 'duplicate primer cleanup')
  })
})
