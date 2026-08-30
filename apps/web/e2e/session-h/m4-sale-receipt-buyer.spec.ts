import { createHash, randomUUID } from 'node:crypto'
import { mkdir, stat } from 'node:fs/promises'
import { resolve } from 'node:path'
import { expect, test, type APIRequestContext, type APIResponse } from '@playwright/test'
import { canonicalEncode } from '../money-campaign/statement-support'

const FRONTEND_BASE_URL = process.env['E2E_BASE_URL'] ?? 'http://localhost:5174'
const API_BASE = process.env['SESSION_H_API_BASE'] ?? 'http://127.0.0.1:8011/api/v1'
const CREDENTIALS = { email: 'owner@pharmabio.tn', password: 'password' }
const EVIDENCE_DIR = resolve(process.cwd(), '../../.playwright-mcp/session-h/m4')
const EVIDENCE_PATH = resolve(EVIDENCE_DIR, 'signed-buyer-ingestion-summary.png')

test.skip(
  !!process.env['CI'] && !process.env['SESSION_H_API_BASE'],
  'requires the Session H worktree API and demo tenant',
)
test.use({ baseURL: FRONTEND_BASE_URL })
test.describe.configure({ mode: 'serial', retries: 0 })

interface Session {
  token: string
  userId: string
  tenantId: string
  companyId: string
  userName: string
}

interface Terminal {
  id: string
  genesis_seed: string
}

type Buyer = {
  address: null
  codice_fiscale: null
  contact_id: null
  customer_id: string | null
  name: string
  tax_number: string | null
} | null

async function responseJson(response: APIResponse): Promise<Record<string, unknown>> {
  const text = await response.text()
  return text === '' ? {} : JSON.parse(text) as Record<string, unknown>
}

async function expectStatus(response: APIResponse, status: number, label: string): Promise<void> {
  expect(response.status(), `${label}: ${await response.text()}`).toBe(status)
}

function headers(session: Session): Record<string, string> {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${session.token}`,
    'Content-Type': 'application/json',
    'X-Company-Id': session.companyId,
  }
}

async function login(request: APIRequestContext): Promise<Session> {
  const loginResponse = await request.post(`${API_BASE}/auth/login`, {
    headers: { Accept: 'application/json' },
    data: CREDENTIALS,
  })
  await expectStatus(loginResponse, 200, 'owner login')
  const loginBody = await responseJson(loginResponse) as {
    data: {
      token: string
      user: { id: string; name: string; tenantId: string }
    }
  }

  const companiesResponse = await request.get(`${API_BASE}/user/companies`, {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${loginBody.data.token}`,
    },
  })
  await expectStatus(companiesResponse, 200, 'company discovery')
  const companiesBody = await responseJson(companiesResponse) as {
    data: Array<{ id: string; is_primary: boolean }>
  }
  const company = companiesBody.data.find((candidate) => candidate.is_primary)
    ?? companiesBody.data[0]
  expect(company, 'owner must belong to a demo company').toBeTruthy()

  return {
    token: loginBody.data.token,
    userId: loginBody.data.user.id,
    tenantId: loginBody.data.user.tenantId,
    companyId: company!.id,
    userName: loginBody.data.user.name,
  }
}

async function createTerminal(request: APIRequestContext, session: Session): Promise<Terminal> {
  const locationsResponse = await request.get(`${API_BASE}/locations?per_page=100`, {
    headers: headers(session),
  })
  await expectStatus(locationsResponse, 200, 'location discovery')
  const locationsBody = await responseJson(locationsResponse) as {
    data: Array<{ id: string; type: string; pos_enabled?: boolean }>
  }
  const location = locationsBody.data.find(
    (candidate) => candidate.type === 'shop' && candidate.pos_enabled !== false,
  )
  expect(location, 'demo company must have a POS-enabled shop').toBeTruthy()

  const suffix = randomUUID().slice(0, 8)
  const terminalResponse = await request.post(`${API_BASE}/pos/terminals`, {
    headers: headers(session),
    data: {
      code: `H4-${suffix}`,
      description: 'Session H M4 signed buyer-ingestion gate',
      location_id: location!.id,
      name: `Session H M4 ${suffix}`,
    },
  })
  await expectStatus(terminalResponse, 201, 'dedicated terminal create')
  const terminalBody = await responseJson(terminalResponse) as { data: Terminal }

  return terminalBody.data
}

function salePayload(session: Session, terminal: Terminal, receiptId: string, buyer: Buyer): Record<string, unknown> {
  const payloadEventTime = new Date(Date.now() - 30_000).toISOString()

  return {
    approval_references: [],
    business_date: payloadEventTime.slice(0, 10),
    buyer,
    cash_rounding_adjustment: '0.000',
    cash_rounding_denomination: '0.000',
    cashier_id: session.userId,
    cashier_name: session.userName,
    consumption_mode: null,
    currency_code: 'TND',
    currency_scale: 3,
    event_time_device: payloadEventTime,
    invoice_type_code: 'SALE',
    line_items: [{
      gtin: null,
      line_discount_amount: '0.000',
      line_discount_reason: null,
      line_subtotal: '10.000',
      line_vat: '0.000',
      name: 'Session H buyer fixture',
      non_collected_subtype: null,
      product_id: `m4-${receiptId}`,
      quantity: '1.000',
      sku: `H4-${receiptId.slice(0, 8)}`,
      tax_category_code: 'Z',
      unit_price: '10.000',
      variant_id: null,
      variant_name: null,
      variant_sku: null,
      vat_rate: '0.00',
    }],
    lottery_code: null,
    notes: 'Session H M4 live signed ingestion',
    original_receipt_reference: null,
    payments: [{
      amount: '10.000',
      foreign_currency_amount: null,
      foreign_currency_code: null,
      instrument_serial: null,
      instrument_type: null,
      method_code: 'CASH',
    }],
    receipt_uuid: receiptId,
    seller: {
      address: {
        city: 'Tunis',
        country_code: 'TN',
        postal_code: '1000',
        street: 'Session H fixture address',
      },
      name: 'PharmaBio Tunisie',
      tax_jurisdiction_country_code: 'TN',
      tax_number: '1234567AM000',
    },
    shift_id: randomUUID(),
    subtotal: '10.000',
    table_id: null,
    terminal_id: terminal.id,
    total: '10.000',
    training_flag: false,
    transaction_discount_amount: '0.000',
    transaction_discount_reason: null,
    vat_breakdown: [{
      discount_allocated: '0.000',
      gross_amount: '10.000',
      net_amount: '10.000',
      rate: '0.00',
      tax_category_code: 'Z',
      vat_amount: '0.000',
    }],
    vat_total: '0.000',
    vouchers_redeemed: [],
  }
}

async function ingestReceipt(
  request: APIRequestContext,
  session: Session,
  terminal: Terminal,
  buyer: Buyer,
): Promise<{ exceptionClass: string | null; fiscalEventId: string; receiptId: string; stored: boolean }> {
  const fiscalEventId = randomUUID()
  const receiptId = randomUUID()
  const payload = salePayload(session, terminal, receiptId, buyer)
  const payloadEventTime = String(payload['event_time_device'])
  const eventTime = payloadEventTime.replace(/\.\d{3}Z$/, 'Z')
  const envelopeBase = {
    business_date: payloadEventTime.slice(0, 10),
    chain_context: 'operational',
    company_id: session.companyId,
    event_time_device: eventTime,
    event_type: 'SALE_RECEIPT',
    event_version: 5,
    id: fiscalEventId,
    last_server_time_seen: null,
    operator_id: session.userId,
    previous_hash: terminal.genesis_seed,
    reference_document_id: null,
    reference_event_id: null,
    sequence_number: 1,
    signature_version: 'hash-chain-integrity-v1',
    source_event_class: null,
    source_event_id: null,
    tenant_id: session.tenantId,
    terminal_id: terminal.id,
  }
  const canonicalBytes = canonicalEncode({
    business_date: envelopeBase.business_date,
    chain_context: envelopeBase.chain_context,
    company_id: envelopeBase.company_id,
    event_time_device: envelopeBase.event_time_device,
    event_type: envelopeBase.event_type,
    event_version: envelopeBase.event_version,
    operator_id: envelopeBase.operator_id,
    payload,
    previous_hash: envelopeBase.previous_hash,
    reference_document_id: envelopeBase.reference_document_id,
    reference_event_id: envelopeBase.reference_event_id,
    sequence_number: envelopeBase.sequence_number,
    signature_version: envelopeBase.signature_version,
    tenant_id: envelopeBase.tenant_id,
    terminal_id: envelopeBase.terminal_id,
  })

  const response = await request.post(`${API_BASE}/pos/sync/fiscal-events`, {
    headers: headers(session),
    data: {
      envelopes: [{
        envelope_id: randomUUID(),
        idempotency_key: `${terminal.id}:1`,
        payload: {
          ...envelopeBase,
          canonical_bytes: canonicalBytes,
          current_hash: createHash('sha256').update(canonicalBytes).digest('hex'),
        },
        payload_version: 1,
        type: 'FISCAL_EVENT',
      }],
    },
  })
  await expectStatus(response, 200, 'signed SALE_RECEIPT ingestion')
  const body = await responseJson(response) as {
    results: Array<{ exception_class: string | null; fiscal_event_id: string; stored: boolean }>
  }
  expect(body.results).toHaveLength(1)

  return {
    exceptionClass: body.results[0]!.exception_class,
    fiscalEventId,
    receiptId,
    stored: body.results[0]!.stored,
  }
}

async function projectedPartnerId(
  request: APIRequestContext,
  session: Session,
  terminalId: string,
): Promise<string> {
  const response = await request.get(
    `${API_BASE}/pos/receipts/qr-index?terminal_id=${encodeURIComponent(terminalId)}`,
    { headers: headers(session) },
  )
  if (!response.ok()) return '__request_failed__'
  const body = await responseJson(response) as {
    data: { entries: Array<{ partner_id: string | null; receipt_uuid: string }> }
  }
  // Every case provisions a dedicated terminal and ingests exactly one event,
  // so its QR index has exactly one candidate. The projected receipt row uses
  // its own UUID; it is intentionally not the payload's receipt_uuid.
  const row = body.data.entries[0]
  if (!row) return '__missing__'

  return row.partner_id ?? '__null__'
}

async function projectedCustomerSnapshot(
  request: APIRequestContext,
  session: Session,
  partnerId: string,
): Promise<{ customer_name: string; partner_id: string } | null> {
  const today = new Date()
  const yesterday = new Date(today.getTime() - 86_400_000)
  const params = new URLSearchParams({
    from: yesterday.toISOString().slice(0, 10),
    to: today.toISOString().slice(0, 10),
  })
  const response = await request.get(`${API_BASE}/pos/analytics/customers?${params}`, {
    headers: headers(session),
  })
  if (!response.ok()) return null

  const body = await responseJson(response) as {
    data: { top_customers: Array<{ customer_name: string; partner_id: string }> }
  }
  const row = body.data.top_customers.find((candidate) => candidate.partner_id === partnerId)

  return row == null
    ? null
    : { customer_name: row.customer_name, partner_id: row.partner_id }
}

async function quarantineReason(
  request: APIRequestContext,
  session: Session,
  fiscalEventId: string,
): Promise<string> {
  const response = await request.get(
    `${API_BASE}/fiscal/dead-lettered-projections/${encodeURIComponent(fiscalEventId)}`,
    { headers: headers(session) },
  )
  if (!response.ok()) return `__request_failed_${response.status()}__`

  const body = await responseJson(response) as {
    data: { integrity_exception_reason: string | null; source: string }
  }
  if (body.data.source !== 'ingress_quarantine') return `__wrong_source_${body.data.source}__`

  return body.data.integrity_exception_reason ?? '__missing_reason__'
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;')
}

test('signed v5 receipts accept null/scoped buyers and quarantine pending IDs', async ({ page, request }, testInfo) => {
  test.setTimeout(120_000)
  const session = await login(request)

  const partnerName = `Session H Buyer ${randomUUID().slice(0, 8)}`
  const partnerResponse = await request.post(`${API_BASE}/partners`, {
    headers: headers(session),
    data: { name: partnerName, type: 'customer' },
  })
  await expectStatus(partnerResponse, 201, 'demo customer create')
  const partnerBody = await responseJson(partnerResponse) as { data: { id: string; name: string } }
  expect(partnerBody.data.name).toBe(partnerName)
  const sealedBuyerName = `Sealed snapshot for ${partnerName}`

  const nullTerminal = await createTerminal(request, session)
  const anonymous = await ingestReceipt(request, session, nullTerminal, null)
  expect(anonymous).toMatchObject({ stored: true, exceptionClass: null })
  await expect.poll(
    () => projectedPartnerId(request, session, nullTerminal.id),
    { timeout: 60_000, intervals: [500, 1_000, 2_000] },
  ).toBe('__null__')

  const customerTerminal = await createTerminal(request, session)
  const customer = await ingestReceipt(request, session, customerTerminal, {
    address: null,
    codice_fiscale: null,
    contact_id: null,
    customer_id: partnerBody.data.id,
    name: sealedBuyerName,
    tax_number: null,
  })
  expect(customer).toMatchObject({ stored: true, exceptionClass: null })
  await expect.poll(
    () => projectedPartnerId(request, session, customerTerminal.id),
    { timeout: 60_000, intervals: [500, 1_000, 2_000] },
  ).toBe(partnerBody.data.id)
  await expect.poll(
    () => projectedCustomerSnapshot(request, session, partnerBody.data.id),
    { timeout: 60_000, intervals: [500, 1_000, 2_000] },
  ).toEqual({ customer_name: sealedBuyerName, partner_id: partnerBody.data.id })

  const pendingTerminal = await createTerminal(request, session)
  const pending = await ingestReceipt(request, session, pendingTerminal, {
    address: null,
    codice_fiscale: null,
    contact_id: null,
    customer_id: 'pending-customer-7',
    name: 'Pending Session H Buyer',
    tax_number: null,
  })
  expect(pending).toMatchObject({ stored: true, exceptionClass: 'canonical_parse_failure' })
  await expect.poll(
    () => quarantineReason(request, session, pending.fiscalEventId),
    { timeout: 30_000, intervals: [250, 500, 1_000] },
  ).toContain('payload_buyer_invalid')

  const anonymousPartnerId = await projectedPartnerId(request, session, nullTerminal.id)
  const projectedCustomer = await projectedCustomerSnapshot(request, session, partnerBody.data.id)
  const pendingReason = await quarantineReason(request, session, pending.fiscalEventId)
  const evidenceRows = [
    ['Anonymous v5 receipt', `stored=${anonymous.stored}; projected partner_id=${anonymousPartnerId}`],
    ['Scoped v5 receipt', `partner_id=${projectedCustomer?.partner_id ?? '__missing__'}`],
    ['Sealed snapshot', `customer_name=${projectedCustomer?.customer_name ?? '__missing__'}`],
    ['Pending-ID quarantine', `class=${pending.exceptionClass}; reason=${pendingReason}`],
  ]

  await mkdir(EVIDENCE_DIR, { recursive: true })
  await page.setContent(`<!doctype html>
    <html lang="en"><head><meta charset="utf-8"><title>Session H M4 evidence</title>
    <style>
      body { background: #f5f7fb; color: #172033; font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; padding: 48px; }
      main { background: white; border: 1px solid #d9dfeb; border-radius: 16px; box-shadow: 0 12px 32px #23304d1f; margin: auto; max-width: 1040px; padding: 36px; }
      h1 { font-size: 28px; margin: 0 0 8px; } p { color: #5a6579; margin: 0 0 28px; }
      table { border-collapse: collapse; width: 100%; } th, td { border-top: 1px solid #e4e8f0; padding: 16px 12px; text-align: left; vertical-align: top; }
      th { color: #26324a; width: 220px; } td { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; overflow-wrap: anywhere; }
      .pass { background: #e8f7ee; border-radius: 999px; color: #166534; display: inline-block; font-size: 13px; font-weight: 700; padding: 5px 10px; }
    </style></head><body><main>
      <span class="pass">LIVE SIGNED HTTP GATE PASSED</span>
      <h1>Session H · M4 buyer ingestion evidence</h1>
      <p>Observed through the worktree API, POS receipt projection, analytics snapshot, and quarantine read surface.</p>
      <table><tbody>${evidenceRows.map(([label, value]) => `<tr><th>${escapeHtml(label!)}</th><td>${escapeHtml(value!)}</td></tr>`).join('')}</tbody></table>
    </main></body></html>`)
  await page.screenshot({ path: EVIDENCE_PATH, fullPage: true })
  expect((await stat(EVIDENCE_PATH)).size).toBeGreaterThan(0)
  await testInfo.attach('m4-signed-buyer-ingestion-summary', {
    path: EVIDENCE_PATH,
    contentType: 'image/png',
  })
})
