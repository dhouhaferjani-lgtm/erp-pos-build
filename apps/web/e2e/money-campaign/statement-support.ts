/**
 * MONEY TEST CAMPAIGN — shared bank-statement / reconciliation fixture
 * (C-2, docs/qa/2026-08-02-full-e2e-campaign-plan.md §C: "Reuse
 * e2e/smoke/treasury-phase5b-reconciliation.smoke.ts — it already creates
 * the parser profile, uploads a real statement, and builds Tier 1/3/4
 * candidates. Extract its setup into money-campaign/statement-support.ts.").
 *
 * WHY this exists: 15 money-campaign cases (`MTP-PERM-08`, `MTP-TRE-36..49`)
 * were BLOCKED with "no bank_statements row exists in this tenant" — every
 * reconcile/void/complete/allocate route resolves its `{bankStatement}`
 * route-model binding BEFORE the permission middleware runs, so a
 * fabricated UUID 404s instead of exercising the gate (a false pass, not a
 * real test). `treasury-phase5b-reconciliation.smoke.ts` already proved a
 * full working recipe for a real statement with real Tier 1 (manual
 * adjustment), Tier 3 (outbound-instrument clearing), and Tier 4
 * (acquirer-fee card settlement) match candidates — this module lifts that
 * SETUP (its steps 1-2) into reusable, parameterized functions so any
 * money-campaign spec can build the same fixture without re-deriving it.
 *
 * This module authors ZERO test cases itself — see
 * `statement-support.smoke.spec.ts` for the single canary proving the
 * fixture builds against the live stack. PERM-08 and TRE-36..49 are NOT
 * authored here; that is follow-up work this fixture merely unblocks.
 *
 * Real login + real backend against the LIVE local stack (web :5173 -> api
 * :8010, tenant demo-pharmacy-tn). No mocks anywhere. Reuses
 * treasury-support.ts's `Session`/`login`/`authHeaders`/money-math helpers
 * rather than redefining them (house convention — see w2c-support.ts).
 */
import { createHash, randomUUID } from 'node:crypto'
import type { APIRequestContext } from '@playwright/test'
import { expect } from '@playwright/test'
import { API_BASE, TODAY, addMoney, authHeaders, type Session } from './treasury-support'

export const PREFIX = 'C2-STMT'

/** Uniqueness across concurrent test runs / re-runs against the live stack. */
export function uniq(base: string): string {
  return `${PREFIX}-${base}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
}

export interface BankRepository {
  id: string
  code: string
  name: string
  type: string
  currency: string
  balance: string
  is_active: boolean
  gl_account_id: string | null
}

export interface PaymentMethodRow {
  id: string
  code: string
  instrument_kind: string | null
  has_maturity: boolean
}

export interface ReconciliationFixture {
  repository: BankRepository
  openingBalance: string
  closingBalance: string
  profileId: string
  chequeMethodId: string
  cardMethodId: string
  cardMethodCode: string
  outboundInstrumentId: string
  fiscalEventId: string
  labels: {
    adjustment: string
    cheque: string
    card: string
  }
  amounts: {
    adjustment: string
    cheque: string
    cardGross: string
    cardNet: string
    cardFee: string
  }
  /** A ready-to-upload CSV matching the Tier 1/3/4 candidates above, so a
   * caller can go straight to `uploadStatementPreview()`/`confirmStatement()`
   * without re-deriving the row shape. */
  mainCsv: string
}

async function jsonData(res: { json(): Promise<unknown> }): Promise<Record<string, unknown>> {
  return ((await res.json()) as { data?: Record<string, unknown> }).data ?? {}
}

async function expectOk(
  res: { ok(): boolean; status(): number; text(): Promise<string> },
  label: string,
): Promise<void> {
  if (!res.ok()) {
    throw new Error(`${label}: expected ok, got ${res.status()}\n${await res.text()}`)
  }
}

/**
 * Finds an active, GL-linked, currently-unreconciled `bank_account`
 * repository with zero active non-terminal statements, or self-provisions a
 * fresh `C2-STMT-*` one when every existing repository is retired.
 * Mirrors `treasury-phase5b-reconciliation.smoke.ts` step 1's
 * discovery/provisioning/leftover-skip logic exactly, so this helper is
 * safe to call repeatedly across re-runs without colliding with a prior
 * run's fixtures or a sibling money-campaign agent's repository.
 */
export async function discoverOrProvisionRepository(
  request: APIRequestContext,
  session: Session,
): Promise<{ repository: BankRepository; openingBalance: string }> {
  const headers = authHeaders(session)
  const repositoriesRes = await request.get(`${API_BASE}/payment-repositories`, { headers })
  await expectOk(repositoriesRes, 'payment repositories')
  const repositories = ((await jsonData(repositoriesRes)) as unknown as BankRepository[]) ?? []
  const bankCandidates = (Array.isArray(repositories) ? repositories : []).filter(
    (candidate) => candidate.type === 'bank_account' && candidate.is_active && candidate.gl_account_id !== null,
  )

  for (const candidate of bankCandidates) {
    const balanceRes = await request.get(`${API_BASE}/payment-repositories/${candidate.id}/balance`, { headers })
    await expectOk(balanceRes, `repository checkpoint discovery (${candidate.code})`)
    const balance = (await jsonData(balanceRes)) as { balance: string; last_reconciled_at: string | null }
    if (balance.last_reconciled_at !== null) continue

    const statements = await fetchAllStatements(request, session, candidate.id)
    const hasOpenStatement = statements.some((s) => s.status !== 'reconciled' && s.status !== 'voided')
    if (hasOpenStatement) continue

    // A leftover fixture repository from a prior C2-STMT/SMOKE run whose
    // completed statements couldn't be voided (executions are irreversible)
    // is still a perfectly valid, clean fixture — skip only if it somehow
    // carries a non-zero balance we didn't expect.
    if ((candidate.code.startsWith('C2-STMT-') || candidate.code.startsWith('SMOKE-'))
      && toMillimes(balance.balance) !== 0n) {
      continue
    }

    return { repository: candidate, openingBalance: balance.balance }
  }

  // Every fixture repository is retired — self-provision a fresh one.
  const accountsRes = await request.get(`${API_BASE}/accounts?per_page=1000`, { headers })
  await expectOk(accountsRes, 'accounts for fixture repository provisioning')
  const accounts = ((await jsonData(accountsRes)) as unknown as Array<{ id: string; code: string; is_active?: boolean }>) ?? []
  const bankGlAccount = accounts.find((a) => a.code === '512')
    ?? accounts.find((a) => a.code.startsWith('512') && a.is_active !== false)
  expect(bankGlAccount, 'a bank GL account (512*) exists for fixture provisioning').toBeTruthy()

  const provisionRes = await request.post(`${API_BASE}/payment-repositories`, {
    headers,
    data: {
      code: `C2-STMT-${Date.now().toString().slice(-10)}`,
      name: `C-2 reconciliation fixture bank ${uniq('repo')}`,
      type: 'bank_account',
      gl_account_id: bankGlAccount!.id,
    },
  })
  await expectOk(provisionRes, 'fixture repository provisioning')
  const repository = (await jsonData(provisionRes)) as unknown as BankRepository

  const balanceRes = await request.get(`${API_BASE}/payment-repositories/${repository.id}/balance`, { headers })
  await expectOk(balanceRes, 'provisioned repository balance baseline')
  const balance = (await jsonData(balanceRes)) as { balance: string }

  return { repository, openingBalance: balance.balance }
}

/** Every active, non-terminal-or-terminal bank statement for a repository,
 * walking every page (the index paginates, per_page capped at 100). */
async function fetchAllStatements(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
): Promise<Array<{ status: string }>> {
  const headers = authHeaders(session)
  const collected: Array<{ status: string }> = []
  let page = 1
  let lastPage = 1
  do {
    const res = await request.get(
      `${API_BASE}/bank-statements?payment_repository_id=${repositoryId}&per_page=100&page=${page}`,
      { headers },
    )
    await expectOk(res, `statement listing (page ${page})`)
    const body = (await res.json()) as { data?: Array<{ status: string }>; meta?: { last_page?: number } }
    collected.push(...(body.data ?? []))
    lastPage = typeof body.meta?.last_page === 'number' ? body.meta.last_page : page
    page += 1
  } while (page <= lastPage)
  return collected
}

export async function getChequeMethod(request: APIRequestContext, session: Session): Promise<string> {
  const res = await request.get(`${API_BASE}/payment-methods`, { headers: authHeaders(session) })
  await expectOk(res, 'payment methods')
  const methods = ((await jsonData(res)) as unknown as PaymentMethodRow[]) ?? []
  const cheque = methods.find((m) => m.code === 'CHECK')
  expect(cheque?.instrument_kind, 'a CHECK payment method with instrument_kind=cheque is seeded').toBe('cheque')
  return cheque!.id
}

/** Creates a fresh percentage-fee card payment method routed to the given
 * repository — the Tier 4 (acquirer-fee) candidate needs a distinct method
 * per fixture build so its fee GL routing never collides with a sibling's. */
export async function ensureCardRoutingMethod(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
): Promise<{ id: string; code: string }> {
  const headers = authHeaders(session)
  const accountsRes = await request.get(`${API_BASE}/accounts?per_page=1000`, { headers })
  await expectOk(accountsRes, 'accounts for card fee routing')
  const accounts = ((await jsonData(accountsRes)) as unknown as Array<{ id: string; code: string; is_active?: boolean }>) ?? []
  const feeAccount = accounts.find((a) => a.code === '627')
    ?? accounts.find((a) => a.code.startsWith('627') && a.is_active !== false)
  expect(feeAccount, 'an active bank-fee expense account (627*) exists').toBeTruthy()

  const code = `C2CARD${randomUUID().replaceAll('-', '').slice(0, 10)}`
  const res = await request.post(`${API_BASE}/payment-methods`, {
    headers,
    data: {
      code,
      name: `C-2 reconciliation fixture card ${uniq('card')}`,
      default_repository_id: repositoryId,
      fee_account_id: feeAccount!.id,
      fee_type: 'percentage',
      fee_percent: '1.50',
      has_deducted_fees: true,
      has_maturity: false,
      is_physical: false,
    },
  })
  await expectOk(res, 'card routing configuration')
  // PaymentMethodController::store() uppercases `code` before persisting
  // (`strtoupper(trim($raw))`, PaymentMethodController.php:338) — the
  // resolver `PosCoreReceiptProjection` later uses to find this method by
  // `method_code` on a fiscal event is an exact-match lookup
  // (`EloquentPaymentMethodResolver::resolveByCode()`), so the caller MUST
  // use the code the API actually persisted, not the locally-generated
  // (mixed-case) one — otherwise `authorTier4CardFiscalSale()`'s fiscal
  // event silently fails projection with `payment_method_not_found`.
  const created = (await jsonData(res)) as { id: string; code: string }
  const id = String(created.id)
  expect(id).toMatch(/^[0-9a-f-]{36}$/)
  return { id, code: created.code }
}

/**
 * `overrides` (W-5b extension, brief: "extend it if a case needs another
 * statement variant"): `MTP-TRE-39` needs the SAME data under a
 * `debit_credit_columns` profile and `MTP-TRE-40` needs a European
 * `decimal_format: 'comma'` profile. Callers that pass nothing get the exact
 * profile the C-2 fixture has always created — the default object below is
 * byte-for-byte the pre-existing body, so `buildReconciliationFixture()` and
 * `statement-support.smoke.spec.ts` are unaffected.
 */
export async function createParserProfile(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
  overrides: Record<string, unknown> = {},
): Promise<string> {
  const res = await request.post(`${API_BASE}/statement-import-profiles`, {
    headers: authHeaders(session),
    data: {
      payment_repository_id: repositoryId,
      name: `C-2 CSV profile ${uniq('profile')}`,
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
      ...overrides,
    },
  })
  await expectOk(res, 'parser profile create')
  return String(((await jsonData(res)) as { id: string }).id)
}

/**
 * Raw preview call (W-5b extension): `uploadStatementPreview()` above returns
 * only the preview token + accepted count, but `MTP-TRE-36..40` assert the
 * WHOLE classification envelope (`duplicate_fingerprint_count`,
 * `dropped_zero_amount_rows`, `unparseable_rows`, and each parsed line's
 * signed direction/amount). This returns it verbatim, and — unlike
 * `uploadStatementPreview()` — does NOT assert 2xx, so a case can assert a
 * refusal status (`MTP-TRE-37`'s duplicate-file 422).
 */
export async function uploadStatementPreviewRaw(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
  profileId: string,
  csv: string,
  filename: string,
): Promise<{ status: number; body: Record<string, unknown> }> {
  const res = await request.post(`${API_BASE}/bank-statements/upload`, {
    headers: { Authorization: `Bearer ${session.token}`, Accept: 'application/json' },
    multipart: {
      payment_repository_id: repositoryId,
      parser_profile_id: profileId,
      file: { name: filename, mimeType: 'text/csv', buffer: Buffer.from(csv) },
    },
  })
  let parsed: Record<string, unknown> = {}
  try {
    parsed = (await res.json()) as Record<string, unknown>
  } catch {
    parsed = {}
  }
  return { status: res.status(), body: parsed }
}

/** Tier 1 candidate: a manual repository adjustment (count-variance
 * correction) with no matching instrument/payment. */
export async function createTier1Adjustment(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
  amount: string,
  reasonText: string,
): Promise<void> {
  const res = await request.post(`${API_BASE}/payment-repositories/${repositoryId}/adjustments`, {
    headers: authHeaders(session),
    data: { direction: 'in', amount, reason_code: 'count_variance', reason_text: reasonText },
  })
  await expectOk(res, 'Tier 1 adjustment')
}

/** Tier 3 candidate: issues (creates + posts + pays via outbound cheque
 * instrument) a generic expense, returning the `received` payment
 * instrument id a statement line can later confirm-clear against. */
export async function issueTier3OutboundCheque(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
  chequeMethodId: string,
  amount: string,
  reference: string,
): Promise<string> {
  const headers = authHeaders(session)
  const categoriesRes = await request.get(`${API_BASE}/expense-categories`, { headers })
  await expectOk(categoriesRes, 'expense categories')
  const categories = ((await jsonData(categoriesRes)) as unknown as Array<{ id: string }>) ?? []
  expect(categories.length).toBeGreaterThan(0)

  const createRes = await request.post(`${API_BASE}/expenses`, {
    headers,
    data: {
      vendor_name: `C-2 fixture vendor ${uniq('vendor')}`,
      expense_category_id: categories[0]!.id,
      receipt_number: `C2-RCPT-${randomUUID()}`,
      total: amount,
      document_date: TODAY,
      is_paid: false,
      expense_kind: 'generic',
      notes: 'C-2 reconciliation-fixture outbound clearing',
    },
  })
  await expectOk(createRes, 'expense create')
  const expenseId = String(((await jsonData(createRes)) as { id: string }).id)

  const postRes = await request.post(`${API_BASE}/expenses/${expenseId}/post`, { headers })
  await expectOk(postRes, 'expense post')

  const payRes = await request.post(`${API_BASE}/expenses/${expenseId}/pay`, {
    headers,
    data: {
      mode: 'instrument',
      payment_repository_id: repositoryId,
      payment_method_id: chequeMethodId,
      payment_date: TODAY,
      instrument: { kind: 'cheque', reference, drawer_name: `C-2 fixture vendor ${uniq('vendor')}` },
    },
  })
  await expectOk(payRes, 'expense cheque issue')
  const paid = (await jsonData(payRes)) as { metadata: { is_paid: boolean; payment_instrument_id: string } }
  expect(paid.metadata.is_paid).toBe(false)
  return paid.metadata.payment_instrument_id
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

export function canonicalEncode(value: Record<string, unknown>): string {
  return JSON.stringify(canonicalize(value))
}

/** Tier 4 candidate: ingests one real fiscal `SALE_RECEIPT` card payment on
 * a dedicated POS terminal, then polls until its repository movement lands
 * (the fiscal projection is asynchronous). Returns the fiscal event id a
 * statement line's card-net amount can later match against. */
export async function authorTier4CardFiscalSale(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
  cardMethodCode: string,
  grossAmount: string,
): Promise<string> {
  const headers = authHeaders(session)

  // A fiscal SALE_RECEIPT must never be rung at a non-POS location. Selecting
  // "the first active terminal" (no location predicate) is ORDER-LUCK: once a
  // warehouse-resident terminal sorts first in the list, this fixture clones
  // its location onto a new dedicated terminal and rings a real hash-chained
  // sale at the warehouse — a self-compounding contamination of the fiscal
  // chain. See docs/superpowers/tickets/2026-08-06-c2-fixture-terminal-location.md.
  // So select the template DETERMINISTICALLY by location type, never by list
  // order: the fixture wants a shop, and only a shop.
  const locationsRes = await request.get(`${API_BASE}/locations`, { headers })
  await expectOk(locationsRes, 'locations')
  const locations = ((await jsonData(locationsRes)) as unknown as Array<{ id: string; type: string; pos_enabled?: boolean }>) ?? []
  const shopLocationIds = new Set(locations.filter((l) => l.type === 'shop' && l.pos_enabled !== false).map((l) => l.id))

  const terminalsRes = await request.get(`${API_BASE}/pos/terminals`, { headers })
  await expectOk(terminalsRes, 'POS terminals')
  const terminals = ((await jsonData(terminalsRes)) as unknown as Array<{
    id: string
    location_id: string
    genesis_seed: string
    is_active: boolean
  }>) ?? []
  const template = terminals.find((t) => t.is_active && shopLocationIds.has(t.location_id))
  expect(
    template,
    'an active terminal at a SHOP location exists — a fiscal sale must never be rung at a warehouse',
  ).toBeTruthy()

  const terminalRes = await request.post(`${API_BASE}/pos/terminals`, {
    headers,
    data: {
      code: `C2-${randomUUID().slice(0, 8)}`,
      name: `C-2 fixture terminal ${uniq('terminal')}`,
      location_id: template!.location_id,
      description: 'C-2 reconciliation-fixture dedicated fiscal projection terminal',
    },
  })
  await expectOk(terminalRes, 'dedicated POS terminal create')
  const terminal = (await jsonData(terminalRes)) as { id: string; genesis_seed: string }

  const fiscalEventId = randomUUID()
  const payloadEventTime = new Date(Date.now() - 30_000).toISOString()
  const eventTime = payloadEventTime.replace(/\.\d{3}Z$/, 'Z')
  const salePayload = {
    business_date: TODAY,
    approval_references: [],
    buyer: null,
    cashier_id: session.userId,
    cashier_name: 'C-2 fixture cashier',
    consumption_mode: null,
    currency_code: 'TND',
    currency_scale: 3,
    event_time_device: payloadEventTime,
    invoice_type_code: 'SALE',
    line_items: [{
      gtin: null,
      line_discount_amount: '0.000',
      line_discount_reason: null,
      line_subtotal: grossAmount,
      line_vat: '0.000',
      name: 'C-2 fixture card batch item',
      non_collected_subtype: null,
      product_id: `c2-stmt-${fiscalEventId}`,
      quantity: '1.000',
      sku: `C2-${fiscalEventId.slice(0, 8)}`,
      tax_category_code: 'Z',
      unit_price: grossAmount,
      vat_rate: '0.00',
    }],
    lottery_code: null,
    notes: 'C-2 reconciliation-fixture live card projection',
    original_receipt_reference: null,
    payments: [{
      amount: grossAmount,
      foreign_currency_amount: null,
      foreign_currency_code: null,
      instrument_serial: null,
      instrument_type: null,
      method_code: cardMethodCode,
    }],
    receipt_uuid: fiscalEventId,
    seller: {
      address: { city: 'Tunis', country_code: 'TN', postal_code: '1000', street: 'C-2 fixture address' },
      name: 'Pharmabio',
      tax_jurisdiction_country_code: 'TN',
      tax_number: '1234567AM000',
    },
    shift_id: randomUUID(),
    subtotal: grossAmount,
    table_id: null,
    terminal_id: terminal.id,
    total: grossAmount,
    training_flag: false,
    transaction_discount_amount: '0.000',
    transaction_discount_reason: null,
    vat_breakdown: [{
      gross_amount: grossAmount,
      net_amount: grossAmount,
      rate: '0.00',
      tax_category_code: 'Z',
      vat_amount: '0.000',
    }],
    vat_total: '0.000',
    vouchers_redeemed: [],
  }
  const envelopeBase = {
    id: fiscalEventId,
    tenant_id: session.tenantId,
    company_id: session.companyId,
    terminal_id: terminal.id,
    operator_id: session.userId,
    event_type: 'SALE_RECEIPT',
    event_version: 1,
    signature_version: 'hash-chain-integrity-v1',
    sequence_number: 1,
    event_time_device: eventTime,
    business_date: TODAY,
    chain_context: 'operational',
    last_server_time_seen: null,
    reference_event_id: null,
    reference_document_id: null,
    source_event_class: null,
    source_event_id: null,
    previous_hash: terminal.genesis_seed,
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

  const fiscalRes = await request.post(`${API_BASE}/pos/sync/fiscal-events`, {
    headers,
    data: {
      envelopes: [{
        envelope_id: randomUUID(),
        type: 'FISCAL_EVENT',
        payload_version: 1,
        idempotency_key: `${terminal.id}:1`,
        payload: {
          ...envelopeBase,
          canonical_bytes: canonicalBytes,
          current_hash: createHash('sha256').update(canonicalBytes).digest('hex'),
        },
      }],
    },
  })
  await expectOk(fiscalRes, 'fiscal card receipt ingest')
  const result = ((await fiscalRes.json()).results as Array<{ stored: boolean; exception_class: string | null }>)[0]!
  expect(result).toMatchObject({ stored: true, exception_class: null })

  await expect.poll(async () => {
    const movementsRes = await request.get(
      `${API_BASE}/payment-repositories/${repositoryId}/movements?search=${fiscalEventId}&per_page=100`,
      { headers },
    )
    if (!movementsRes.ok()) return false
    const movements = ((await jsonData(movementsRes)) as unknown as Array<{ source_id: string }>) ?? []
    return movements.some((m) => m.source_id === fiscalEventId)
  }, { timeout: 60_000, intervals: [500, 1000, 2000] }).toBe(true)

  return fiscalEventId
}

export interface UploadedPreview {
  previewToken: string
  acceptedLineCount: number
}

export async function uploadStatementPreview(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
  profileId: string,
  csv: string,
  filename: string,
): Promise<UploadedPreview> {
  // NOT authHeaders(session) — it forces 'Content-Type: application/json',
  // which stomps Playwright's own multipart boundary Content-Type and makes
  // the server see an empty (unparseable) body ("field is required" on
  // every field). Multipart requests must omit Content-Type entirely and
  // let Playwright set it.
  const res = await request.post(`${API_BASE}/bank-statements/upload`, {
    headers: { Authorization: `Bearer ${session.token}`, Accept: 'application/json' },
    multipart: {
      payment_repository_id: repositoryId,
      parser_profile_id: profileId,
      file: { name: filename, mimeType: 'text/csv', buffer: Buffer.from(csv) },
    },
  })
  await expectOk(res, `statement preview ${filename}`)
  const data = (await jsonData(res)) as { preview_token: string; accepted_line_count: number }
  return { previewToken: data.preview_token, acceptedLineCount: data.accepted_line_count }
}

/**
 * `overrides` (W-5b extension): `MTP-TRE-43` dates its statement line 6 days
 * back to fall outside the profile's 5-day matching window, so it needs a
 * `period_start` that actually covers the line. Callers that pass nothing get
 * the pre-existing same-day period.
 */
export async function confirmStatement(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
  repositoryCurrency: string,
  previewToken: string,
  opening: string,
  closing: string,
  overrides: Record<string, unknown> = {},
): Promise<string> {
  const res = await request.post(`${API_BASE}/bank-statements`, {
    headers: authHeaders(session),
    data: {
      preview_token: previewToken,
      currency: repositoryCurrency,
      period_start: TODAY,
      period_end: TODAY,
      opening_balance: opening,
      closing_balance: closing,
      ...overrides,
    },
  })
  await expectOk(res, 'statement confirm')
  return String(((await jsonData(res)) as { id: string }).id)
}

/**
 * Sentinel returned when a cleanup call THREW (network/transport failure) rather
 * than answering with a status. It is deliberately >= 300 and outside the real
 * HTTP range: fix round 1 used `-1`, and every caller gated on `status < 300`,
 * so `-1 < 300` was TRUE and a throwing cleanup passed silently while the
 * fixture leaked with no signal — the exact inversion of the M-1 masking bug
 * (fix round 2, N-1). Callers must gate on 2xx, never on `< 300` alone.
 */
export const CLEANUP_THREW = 599

/** True only for a real 2xx. Never write `status < 300` for a cleanup gate. */
export function isCleanupSuccess(status: number): boolean {
  return status >= 200 && status < 300
}

/**
 * Fixture retirement (W-5b fix round 1, I-2a/I-2b). NEITHER HELPER THROWS —
 * each returns the observed HTTP status, or `CLEANUP_THREW`. A cleanup `expect` inside a `finally`
 * would REPLACE an in-flight exception from the test body (JS discards the
 * original when `finally` throws), so callers must assert these statuses only
 * on the path where the body already succeeded.
 */

/**
 * Retires a parser profile. `DELETE /statement-import-profiles/{id}` refuses a
 * profile that an imported statement references ("deactivate it instead",
 * StatementProfileController::destroy), so this falls back to
 * `PATCH is_active:false` — the retire path the API itself prescribes.
 */
export async function retireParserProfile(
  request: APIRequestContext,
  session: Session,
  profileId: string,
): Promise<number> {
  try {
    const deleted = await request.delete(`${API_BASE}/statement-import-profiles/${profileId}`, {
      headers: authHeaders(session),
    })
    if (isCleanupSuccess(deleted.status())) return deleted.status()
    const deactivated = await request.patch(`${API_BASE}/statement-import-profiles/${profileId}`, {
      headers: authHeaders(session),
      data: { is_active: false },
    })
    return deactivated.status()
  } catch {
    return CLEANUP_THREW
  }
}

/**
 * Deactivates a fixture repository. `payment_repositories` has NO delete route,
 * so deactivation is the only retire path; it also removes the repository from
 * every picker and from `discoverOrProvisionRepository`'s candidate scan.
 */
export async function deactivateRepository(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
): Promise<number> {
  try {
    const res = await request.patch(`${API_BASE}/payment-repositories/${repositoryId}`, {
      headers: authHeaders(session),
      data: { is_active: false },
    })
    return res.status()
  } catch {
    return CLEANUP_THREW
  }
}

function toMillimes(value: string): bigint {
  const [whole = '0', fraction = ''] = value.split('.')
  const negative = whole.startsWith('-')
  const magnitude = BigInt(whole.replace('-', '')) * 1000n + BigInt((fraction + '000').slice(0, 3))
  return negative ? -magnitude : magnitude
}

const DISPLAY_DATE = TODAY.split('-').reverse().join('/')

/**
 * Orchestrates the full C-2 reconciliation fixture (repository, parser
 * profile, cheque method, a fresh card-routed method, and Tier 1/3/4
 * candidates), returning everything a caller needs to upload + confirm the
 * matching statement via `uploadStatementPreview()`/`confirmStatement()`.
 * Does NOT itself upload/confirm the statement or drive any tier match —
 * that is left to the (not-yet-authored) PERM-08/TRE-36..49 specs, so this
 * fixture stays a pure builder.
 */
export async function buildReconciliationFixture(
  request: APIRequestContext,
  session: Session,
): Promise<ReconciliationFixture> {
  const { repository, openingBalance } = await discoverOrProvisionRepository(request, session)
  const chequeMethodId = await getChequeMethod(request, session)
  const { id: cardMethodId, code: cardMethodCode } = await ensureCardRoutingMethod(request, session, repository.id)
  const profileId = await createParserProfile(request, session, repository.id)

  const adjustmentAmount = '15.000'
  const chequeAmount = '37.125'
  const cardGross = '100.000'
  const cardNet = '98.500'
  const cardFee = '1.500'

  const adjustmentLabel = uniq('adjustment')
  const outboundReference = uniq('outbound')
  const chequeLabel = `${uniq('cheque')} ${outboundReference}`
  const cardLabel = uniq('card')

  await createTier1Adjustment(request, session, repository.id, adjustmentAmount, adjustmentLabel)
  const outboundInstrumentId = await issueTier3OutboundCheque(
    request,
    session,
    repository.id,
    chequeMethodId,
    chequeAmount,
    outboundReference,
  )
  const fiscalEventId = await authorTier4CardFiscalSale(request, session, repository.id, cardMethodCode, cardGross)

  // Signed statement delta under the profile's signed_amount convention:
  // +in for the adjustment and card net, -out for the cheque.
  const statementDelta = addMoney(addMoney(adjustmentAmount, cardNet), `-${chequeAmount}`)
  const closingBalance = addMoney(openingBalance, statementDelta)

  const mainCsv = [
    'Date,Amount,Reference,Transaction ID,Label',
    `${DISPLAY_DATE},${adjustmentAmount},ADJ-${adjustmentLabel},ADJ-TX-${adjustmentLabel},${adjustmentLabel}`,
    `${DISPLAY_DATE},-${chequeAmount},${outboundReference},CHEQUE-TX-${outboundReference},${chequeLabel}`,
    `${DISPLAY_DATE},${cardNet},CARD-${cardLabel},CARD-TX-${cardLabel},${cardLabel}`,
    '',
  ].join('\n')

  return {
    repository,
    openingBalance,
    closingBalance,
    profileId,
    chequeMethodId,
    cardMethodId,
    cardMethodCode,
    outboundInstrumentId,
    fiscalEventId,
    labels: { adjustment: adjustmentLabel, cheque: chequeLabel, card: cardLabel },
    amounts: { adjustment: adjustmentAmount, cheque: chequeAmount, cardGross, cardNet, cardFee },
    mainCsv,
  }
}
