import { randomUUID } from 'node:crypto'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { expect, test, type APIRequestContext, type APIResponse } from '@playwright/test'

/**
 * Treasury Phase 5a — live outbound-instrument exit drive.
 *
 * This smoke runs only against a real db-per-tenant stack. It creates its
 * supplier invoice and expense through public HTTP endpoints, drives every
 * outbound lifecycle transition through the real services, and then observes
 * the resulting schedule, instrument history, repository movement, and paid
 * expense in the browser. The companion session report records the out-of-band
 * `treasury:reconcile` result.
 */

const FRONTEND_BASE_URL = process.env['TREASURY_PHASE5A_BASE_URL'] || 'http://127.0.0.1:5173'
const API_BASE = process.env['TREASURY_PHASE5A_API_BASE'] || 'http://127.0.0.1:8010/api/v1'
const CREDENTIALS = { email: 'owner@pharmabio.tn', password: 'password' }
const RUN_ID = `${Date.now()}-${randomUUID().slice(0, 8)}`
const TODAY = new Date().toISOString().slice(0, 10)

test.skip(
  !!process.env.CI && !process.env.TREASURY_PHASE5A_API_BASE,
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
  'treasury-phase5a-e2e',
)

interface AuthUser {
  id: string
  name: string
  email: string
  tenant_id: string
  roles: string[]
}

interface InstrumentData {
  id: string
  reference: string
  amount: string
  direction: string
  status: string
  repository_id: string
  lifecycle_cycle?: number
}

let token = ''
let user: AuthUser | null = null
let chequeMethodId = ''
let bankRepositoryId = ''
let bankRepositoryName = ''
let supplierInstrument: InstrumentData | null = null
let supplierInstrumentReference = ''
let supplierAmount = ''
let supplierBankBaseline = ''
let supplierBankAfterClear = ''
let expenseId = ''
let expenseNumber = ''
let expenseInstrument: InstrumentData | null = null

function authHeaders(): Record<string, string> {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json' }
}

async function responseJson(response: APIResponse): Promise<Record<string, unknown>> {
  return (await response.json()) as Record<string, unknown>
}

async function expectStatus(response: APIResponse, status: number, label: string): Promise<void> {
  if (response.status() !== status) {
    throw new Error(`${label}: expected ${status}, got ${response.status()}\n${await response.text()}`)
  }
}

async function repositoryBalance(request: APIRequestContext): Promise<string> {
  const response = await request.get(`${API_BASE}/payment-repositories/${bankRepositoryId}`, {
    headers: authHeaders(),
  })
  await expectStatus(response, 200, 'repository balance')
  const body = await responseJson(response)
  return String((body.data as { balance: string }).balance)
}

async function transition(
  request: APIRequestContext,
  instrumentId: string,
  action: string,
  data?: Record<string, string>,
): Promise<InstrumentData> {
  const response = await request.post(
    `${API_BASE}/payment-instruments/${instrumentId}/${action}`,
    { headers: authHeaders(), data },
  )
  await expectStatus(response, 200, `instrument ${action}`)
  const body = await responseJson(response)
  return body.data as unknown as InstrumentData
}

test.describe('Treasury Phase 5a — live outbound exit', () => {
  test('1. authenticate and discover the bank and cheque method', async ({ request }) => {
    const login = await request.post(`${API_BASE}/auth/login`, {
      headers: { Accept: 'application/json' },
      data: CREDENTIALS,
    })
    await expectStatus(login, 200, 'owner login')
    const body = (await responseJson(login)).data as {
      token: string
      user: {
        id: string
        name: string
        email: string
        tenantId: string
        roles: string[]
        permissions: string[]
      }
    }
    token = body.token
    user = {
      id: body.user.id,
      name: body.user.name,
      email: body.user.email,
      tenant_id: body.user.tenantId,
      roles: body.user.roles,
    }
    expect(body.user.permissions).toEqual(
      expect.arrayContaining([
        'instruments.clear-outbound',
        'instruments.cancel-outbound',
        'expenses.pay',
      ]),
    )

    const methodsResponse = await request.get(`${API_BASE}/payment-methods`, {
      headers: authHeaders(),
    })
    await expectStatus(methodsResponse, 200, 'payment methods')
    const methods = ((await responseJson(methodsResponse)).data ?? []) as Array<{
      id: string
      code: string
      instrument_kind: string | null
    }>
    const cheque = methods.find((method) => method.code === 'CHECK')
    expect(cheque?.instrument_kind).toBe('cheque')
    chequeMethodId = cheque!.id

    const repositoriesResponse = await request.get(`${API_BASE}/payment-repositories`, {
      headers: authHeaders(),
    })
    await expectStatus(repositoriesResponse, 200, 'payment repositories')
    const repositories = ((await responseJson(repositoriesResponse)).data ?? []) as Array<{
      id: string
      code: string
      name: string
      type: string
      balance: string
    }>
    const bank = repositories.find((repository) => repository.code === 'BANK-01')
      ?? repositories.find((repository) => repository.type === 'bank_account')
    expect(bank, 'an active bank repository exists').toBeTruthy()
    bankRepositoryId = bank!.id
    bankRepositoryName = bank!.name
  })

  test('2. issue a real deferred-supplier cheque without moving bank cash', async ({ request }) => {
    const purchaseOrdersResponse = await request.get(`${API_BASE}/purchase-orders?per_page=100`, {
      headers: authHeaders(),
    })
    await expectStatus(purchaseOrdersResponse, 200, 'purchase order index')
    const purchaseOrders = ((await responseJson(purchaseOrdersResponse)).data ?? []) as Array<{
      id: string
      status: string
      partner_id: string
      currency: string
    }>
    const receivedOrder = purchaseOrders.find((order) => order.status === 'received')
    expect(receivedOrder, 'a received purchase order exists').toBeTruthy()

    const purchaseOrderResponse = await request.get(
      `${API_BASE}/purchase-orders/${receivedOrder!.id}`,
      { headers: authHeaders() },
    )
    await expectStatus(purchaseOrderResponse, 200, 'purchase order detail')
    const purchaseOrder = (await responseJson(purchaseOrderResponse)).data as {
      id: string
      partner_id: string
      currency: string
      lines: Array<{ id: string; unit_price: string; tax_rate: string | null }>
    }
    expect(purchaseOrder.lines.length).toBeGreaterThan(0)
    const sourceLine = purchaseOrder.lines[0]!

    const invoiceResponse = await request.post(`${API_BASE}/supplier-invoices`, {
      headers: authHeaders(),
      data: {
        partner_id: purchaseOrder.partner_id,
        source_document_id: purchaseOrder.id,
        currency: purchaseOrder.currency,
        issue_date: TODAY,
        supplier_reference: `P5A-SI-${RUN_ID}`,
        lines: [{
          source_line_id: sourceLine.id,
          quantity: '1.0000',
          unit_price: sourceLine.unit_price,
          vat_rate: sourceLine.tax_rate ?? '0.00',
        }],
      },
    })
    await expectStatus(invoiceResponse, 201, 'supplier invoice create')
    const invoice = (await responseJson(invoiceResponse)).data as {
      id: string
      currency: string
      total: string
    }

    const postResponse = await request.post(`${API_BASE}/supplier-invoices/${invoice.id}/post`, {
      headers: authHeaders(),
    })
    await expectStatus(postResponse, 200, 'supplier invoice post')
    expect(((await responseJson(postResponse)).data as { status: string }).status).toBe('posted')

    supplierAmount = invoice.total
    supplierBankBaseline = await repositoryBalance(request)
    supplierInstrumentReference = `P5A-SUP-${RUN_ID}`
    const paymentResponse = await request.post(`${API_BASE}/payments`, {
      headers: {
        ...authHeaders(),
        'Idempotency-Key': `phase5a-supplier-${RUN_ID}`,
      },
      data: {
        partner_id: purchaseOrder.partner_id,
        payment_method_id: chequeMethodId,
        repository_id: bankRepositoryId,
        amount: invoice.total,
        currency: invoice.currency,
        payment_date: TODAY,
        allocations: [{ document_id: invoice.id, amount: invoice.total }],
        instrument: { reference: supplierInstrumentReference },
      },
    })
    await expectStatus(paymentResponse, 201, 'deferred supplier payment')
    const payment = (await responseJson(paymentResponse)).data as { instrument_id: string }
    expect(payment.instrument_id).toBeTruthy()

    const instrumentResponse = await request.get(
      `${API_BASE}/payment-instruments/${payment.instrument_id}`,
      { headers: authHeaders() },
    )
    await expectStatus(instrumentResponse, 200, 'issued supplier instrument')
    supplierInstrument = (await responseJson(instrumentResponse)).data as unknown as InstrumentData
    expect(supplierInstrument).toMatchObject({
      direction: 'outbound',
      status: 'received',
      reference: supplierInstrumentReference,
      repository_id: bankRepositoryId,
    })
    expect(await repositoryBalance(request), 'issue does not move bank cash').toBe(supplierBankBaseline)
  })

  test('3. browser shows the issued cheque in the outbound payable schedule', async ({ request, page }) => {
    const maturityResponse = await request.get(`${API_BASE}/treasury/maturing-instruments`, {
      headers: authHeaders(),
    })
    await expectStatus(maturityResponse, 200, 'maturity schedule')
    const maturity = (await responseJson(maturityResponse)) as unknown as {
      meta: { grand_total: { count: number; total_out: string } }
    }
    expect(maturity.meta.grand_total.count).toBeGreaterThan(0)
    expect(Number(maturity.meta.grand_total.total_out)).toBeGreaterThan(0)

    await seedAuth(page)
    await page.goto('/treasury/instruments')
    await expect(page.getByRole('link', { name: supplierInstrumentReference })).toBeVisible({
      timeout: 20_000,
    })

    const payableSchedule = page.getByRole('region', { name: /Payables schedule|Échéancier.*payer|الدفع/i })
    await expect(payableSchedule, 'the outbound payable schedule renders').toBeVisible()
    await expect(
      payableSchedule.getByTestId('maturity-outbound-total'),
      'the rendered payable total equals the API aggregate',
    ).toHaveAttribute('data-total', maturity.meta.grand_total.total_out)
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '01-issued-payable-schedule.png'),
      fullPage: true,
    })
  })

  test('4. clear the supplier cheque and observe the bank movement', async ({ request, page }) => {
    supplierInstrument = await transition(
      request,
      supplierInstrument!.id,
      'clear-outbound',
      { occurred_at: TODAY },
    )
    expect(supplierInstrument.status).toBe('cleared')
    supplierBankAfterClear = await repositoryBalance(request)
    expect(supplierBankAfterClear).not.toBe(supplierBankBaseline)

    const movementsResponse = await request.get(
      `${API_BASE}/payment-repositories/${bankRepositoryId}/movements?source_type=instrument&per_page=100`,
      { headers: authHeaders() },
    )
    await expectStatus(movementsResponse, 200, 'instrument movement index')
    const movementBody = await responseJson(movementsResponse)
    const movements = (movementBody.data ?? []) as Array<{
      source_id: string
      direction: string
      amount: string
    }>
    expect(movements).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          source_id: supplierInstrument.id,
          direction: 'out',
          amount: supplierAmount,
        }),
      ]),
    )

    await seedAuth(page)
    await page.goto(`/treasury/instruments/${supplierInstrument.id}`)
    await expect(page.getByText(supplierInstrumentReference, { exact: true })).toBeVisible({
      timeout: 20_000,
    })
    await expect(page.getByText(/Cleared|Encaiss|مسو/).first()).toBeVisible()
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '02-supplier-cheque-cleared.png'),
      fullPage: true,
    })
  })

  test('5. bounce and re-present the supplier cheque', async ({ request, page }) => {
    supplierInstrument = await transition(
      request,
      supplierInstrument!.id,
      'bounce-outbound',
      { reason: 'Phase 5a live dishonour drive' },
    )
    expect(supplierInstrument.status).toBe('bounced')
    expect(await repositoryBalance(request), 'bounce restores the bank balance').toBe(supplierBankBaseline)

    await seedAuth(page)
    await page.goto(`/treasury/instruments/${supplierInstrument.id}`)
    await expect(page.getByText(/Bounced|Rejet|مرتجع/).first()).toBeVisible({ timeout: 20_000 })
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '03-supplier-cheque-bounced.png'),
      fullPage: true,
    })

    supplierInstrument = await transition(request, supplierInstrument.id, 'represent')
    expect(supplierInstrument.status).toBe('cleared')
    expect(await repositoryBalance(request), 'representation clears through cycle two').toBe(
      supplierBankAfterClear,
    )
    const eventsResponse = await request.get(
      `${API_BASE}/payment-instruments/${supplierInstrument.id}/events`,
      { headers: authHeaders() },
    )
    await expectStatus(eventsResponse, 200, 'represented instrument events')
    const events = ((await responseJson(eventsResponse)).data ?? []) as Array<{
      event_type: string
      from_status: string | null
      to_status: string
    }>
    expect(events).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          event_type: 're_presented',
          from_status: 'bounced',
          to_status: 'cleared',
        }),
      ]),
    )

    await page.reload()
    await expect(page.getByText(/Cleared|Encaiss|مسو/).first()).toBeVisible({ timeout: 20_000 })
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '04-supplier-cheque-represented.png'),
      fullPage: true,
    })
  })

  test('6. issue an expense cheque, clear it, and observe the expense become paid', async ({
    request,
    page,
  }) => {
    const categoriesResponse = await request.get(`${API_BASE}/expense-categories`, {
      headers: authHeaders(),
    })
    await expectStatus(categoriesResponse, 200, 'expense categories')
    const categories = ((await responseJson(categoriesResponse)).data ?? []) as Array<{ id: string }>
    expect(categories.length).toBeGreaterThan(0)

    const createResponse = await request.post(`${API_BASE}/expenses`, {
      headers: authHeaders(),
      data: {
        vendor_name: `Phase 5a Vendor ${RUN_ID}`,
        expense_category_id: categories[0]!.id,
        receipt_number: `P5A-EXP-RCPT-${RUN_ID}`,
        total: '37.125',
        document_date: TODAY,
        is_paid: false,
        expense_kind: 'generic',
        notes: 'Phase 5a live outbound expense drive',
      },
    })
    await expectStatus(createResponse, 201, 'expense create')
    const createdExpense = (await responseJson(createResponse)).data as {
      id: string
      document_number: string | null
    }
    expenseId = createdExpense.id

    const postResponse = await request.post(`${API_BASE}/expenses/${expenseId}/post`, {
      headers: authHeaders(),
    })
    await expectStatus(postResponse, 200, 'expense post')
    expenseNumber = String(
      ((await responseJson(postResponse)).data as { document_number: string }).document_number,
    )

    const expenseReference = `P5A-EXP-${RUN_ID}`
    const payResponse = await request.post(`${API_BASE}/expenses/${expenseId}/pay`, {
      headers: authHeaders(),
      data: {
        mode: 'instrument',
        payment_repository_id: bankRepositoryId,
        payment_method_id: chequeMethodId,
        payment_date: TODAY,
        instrument: {
          kind: 'cheque',
          reference: expenseReference,
          drawer_name: `Phase 5a Vendor ${RUN_ID}`,
        },
      },
    })
    await expectStatus(payResponse, 200, 'expense instrument issue')
    const pendingExpense = (await responseJson(payResponse)).data as {
      metadata: { is_paid: boolean; payment_instrument_id: string }
    }
    expect(pendingExpense.metadata.is_paid).toBe(false)

    expenseInstrument = await transition(
      request,
      pendingExpense.metadata.payment_instrument_id,
      'clear-outbound',
      { occurred_at: TODAY },
    )
    expect(expenseInstrument.status).toBe('cleared')

    const expenseResponse = await request.get(`${API_BASE}/expenses/${expenseId}`, {
      headers: authHeaders(),
    })
    await expectStatus(expenseResponse, 200, 'paid expense detail')
    const paidExpense = (await responseJson(expenseResponse)).data as {
      metadata: { is_paid: boolean; payment_instrument_id: string }
    }
    expect(paidExpense.metadata).toMatchObject({
      is_paid: true,
      payment_instrument_id: expenseInstrument.id,
    })

    await seedAuth(page)
    await page.goto(`/expenses/${expenseId}/view`)
    await expect(page.getByText(expenseNumber, { exact: true })).toBeVisible({ timeout: 20_000 })
    const paidBadge = page.getByText(/^(Paid|Payé|مدفوع)$/).last()
    await expect(paidBadge).toBeVisible()
    await paidBadge.scrollIntoViewIfNeeded()
    await expect(page.getByText(bankRepositoryName, { exact: true })).toBeVisible()
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, '05-expense-paid-after-clear.png'),
      fullPage: true,
    })
  })

  test('7. cancel the second cheque from bounced and reopen the expense', async ({ request }) => {
    expenseInstrument = await transition(
      request,
      expenseInstrument!.id,
      'bounce-outbound',
      { reason: 'Phase 5a expense dishonour drive' },
    )
    expect(expenseInstrument.status).toBe('bounced')

    expenseInstrument = await transition(
      request,
      expenseInstrument.id,
      'cancel-outbound',
      { reason: 'Phase 5a abandon bounced expense cheque' },
    )
    expect(expenseInstrument.status).toBe('cancelled')

    const expenseResponse = await request.get(`${API_BASE}/expenses/${expenseId}`, {
      headers: authHeaders(),
    })
    await expectStatus(expenseResponse, 200, 'reopened expense detail')
    const expense = (await responseJson(expenseResponse)).data as {
      metadata: { is_paid: boolean; payment_instrument_id: string | null }
    }
    expect(expense.metadata).toMatchObject({ is_paid: false, payment_instrument_id: null })
  })
})

async function seedAuth(page: import('@playwright/test').Page): Promise<void> {
  const authState = {
    state: { user, token, isAuthenticated: true, isLoading: false },
    version: 0,
  }
  await page.addInitScript((value) => {
    window.localStorage.setItem('autoerp-auth', value)
  }, JSON.stringify(authState))
}
