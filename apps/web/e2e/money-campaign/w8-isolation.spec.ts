/**
 * MONEY TEST CAMPAIGN — W-8 · `MTP-ISO-01..07` (cross-tenant / cross-company isolation).
 *
 * Plan: `docs/qa/2026-08-01-money-test-plan.md` §ISO · campaign plan §B.8 row 103-104, debt C-4.
 * Tenants: `demo-tenant-a` / `demo-tenant-b` (provisioned by this wave — see
 * `docs/sessions/MONEY-CAMPAIGN-RESULTS.md` § W-8 "C-4 provisioning record").
 *
 * `MTP-ISO-05` is the **P0 security gate of the wave**: an authenticated Tenant-A
 * sanctum token replayed against Tenant-B-scoped resource ids must fail closed —
 * never a 200 carrying B's data, and never a written row in either database.
 *
 * EVIDENCE DISCIPLINE
 * -------------------
 * Every isolation assertion runs against the RAW response text, not a parsed
 * field, because a leak that hides in an error message / validation hint / `meta`
 * block is still a leak. Every "no leak" assertion is paired with a POSITIVE
 * control on the same surface (the probing tenant sees its OWN fixture) so a
 * green verdict can never come from an empty universe.
 */
import { expect, test } from '@playwright/test'

import {
  AMOUNTS,
  type Session,
  type TenantFixture,
  buildTenantFixture,
  createPartner,
  get,
  harvestPharmacyPosReceipt,
  isCleanupSuccess,
  isFailClosed,
  loginPageAsTenant,
  loginTenant,
  post,
  renderedText,
  uniqueName,
  withCompany,
  withForeignCompanyHeader,
  expectNoLeak,
  TODAY,
} from './w8-support'

test.describe.configure({ mode: 'serial' })

let A: TenantFixture
let B: TenantFixture

test.beforeAll(async ({ request }) => {
  test.setTimeout(240_000)
  A = await buildTenantFixture(request, 'a')
  B = await buildTenantFixture(request, 'b')
})

// ---------------------------------------------------------------------------
// Money snapshot — the "no row written" half of MTP-ISO-05
// ---------------------------------------------------------------------------

interface MoneySnapshot {
  invoiceIds: string[]
  paymentIds: string[]
  invoiceBalanceDue: string
  invoiceStatus: string
  repositoryBalance: string
}

async function moneySnapshot(
  request: Parameters<typeof get>[0],
  fixture: TenantFixture,
): Promise<MoneySnapshot> {
  const invoices = await get(request, fixture.session, '/invoices?per_page=100')
  const payments = await get(request, fixture.session, '/payments?per_page=100')
  const invoice = await get(request, fixture.session, `/invoices/${fixture.invoiceId}`)
  const repository = await get(request, fixture.session, `/payment-repositories/${fixture.repositoryId}`)

  const invoiceRows = (invoices.data as unknown as Array<{ id: string }>) ?? []
  const paymentRows = (payments.data as unknown as Array<{ id: string }>) ?? []

  return {
    invoiceIds: (Array.isArray(invoiceRows) ? invoiceRows : []).map((r) => String(r.id)).sort(),
    paymentIds: (Array.isArray(paymentRows) ? paymentRows : []).map((r) => String(r.id)).sort(),
    invoiceBalanceDue: String(invoice.data.balance_due),
    invoiceStatus: String(invoice.data.status),
    repositoryBalance: String(repository.data.balance),
  }
}

// ===========================================================================
// MTP-ISO-01 — EDGE · P0 — Tenant B walks every sales-document list
// ===========================================================================

test('MTP-ISO-01: no Tenant A document, number or total appears in any Tenant B list (API + UI)', async ({
  request,
  page,
}) => {
  test.setTimeout(120_000)

  // --- API layer -----------------------------------------------------------
  for (const path of ['/invoices?per_page=100', '/quotes?per_page=100', '/orders?per_page=100', '/credit-notes']) {
    const res = await get(request, B.session, path)
    expect(res.ok, `B GET ${path} -> ${res.status}`).toBeTruthy()
    expectNoLeak(res.raw, A.forbidden, `B GET ${path}`)
  }

  // POSITIVE CONTROL: the same list DOES carry B's own fixture. Without this a
  // "no leak" verdict would also be satisfied by a broken endpoint returning [].
  const bInvoices = await get(request, B.session, '/invoices?per_page=100')
  expect(bInvoices.raw, 'B sees its OWN invoice — the no-leak assertion is not vacuous').toContain(
    B.invoiceNumber,
  )
  expect(bInvoices.raw).toContain(AMOUNTS.b.invoiceTotal)

  // --- UI layer ------------------------------------------------------------
  await loginPageAsTenant(page, 'b')
  const listText = await renderedText(page, '/sales/invoices')
  expectNoLeak(listText, [A.invoiceNumber, A.partnerName], 'B /sales/invoices rendered list')
  expect(listText, 'B UI shows B data').toContain(B.invoiceNumber)
})

// ===========================================================================
// MTP-ISO-02 — EDGE · P0 — direct id addressing across the tenant boundary
// ===========================================================================

test('MTP-ISO-02: a known Tenant A document id fails closed for Tenant B by API and by URL', async ({
  request,
  page,
}) => {
  test.setTimeout(120_000)

  const probes = [
    `/invoices/${A.invoiceId}`,
    `/documents/${A.invoiceId}`,
    `/invoices/${A.invoiceId}/credit-summary`,
    `/invoices/${A.invoiceId}/can-cancel`,
  ]

  for (const path of probes) {
    const res = await get(request, B.session, path)
    expect(
      isFailClosed(res.status),
      `B GET ${path} must fail closed (404 preferred / 403), got ${res.status} :: ${res.raw.slice(0, 300)}`,
    ).toBeTruthy()
    expectNoLeak(res.raw, A.forbidden.filter((t) => t !== A.invoiceId), `B GET ${path}`)
  }

  // 404 is what this stack answers — a 403 would confirm the id exists somewhere.
  const direct = await get(request, B.session, `/invoices/${A.invoiceId}`)
  expect(direct.status, 'preferred refusal is 404 (does not confirm existence)').toBe(404)

  // --- UI: the deep link must not render A's money -------------------------
  await loginPageAsTenant(page, 'b')
  const detailText = await renderedText(page, `/sales/invoices/${A.invoiceId}`)
  expectNoLeak(
    detailText,
    [A.invoiceNumber, AMOUNTS.a.invoiceTotal, A.partnerName],
    'B deep-linked to A invoice detail',
  )
})

// ===========================================================================
// MTP-ISO-03 — EDGE · P0 — treasury + finance surfaces
// ===========================================================================

test('MTP-ISO-03: zero Tenant A figures on any Tenant B treasury or finance surface', async ({
  request,
}) => {
  test.setTimeout(120_000)

  const surfaces = [
    '/payments?per_page=100',
    '/payment-repositories',
    '/payment-methods',
    '/reports/aged-receivables',
    '/reports/aged-payables',
    '/reports/trial-balance',
    '/reports/balance-sheet',
    '/reports/cash-movements',
    '/ledger',
    '/journal-entries',
    '/partners?per_page=100',
    '/products?per_page=100',
  ]

  for (const path of surfaces) {
    const res = await get(request, B.session, path)
    expect(res.ok, `B GET ${path} -> ${res.status} ${res.raw.slice(0, 200)}`).toBeTruthy()
    expectNoLeak(res.raw, A.forbidden, `B GET ${path}`)
  }

  // The two tenants' aggregate GL figures must be DIFFERENT — identical totals
  // would be the signature of a shared read path that happens to be scoped on
  // the way out but not on the way in.
  const aTb = await get(request, A.session, '/reports/trial-balance')
  const bTb = await get(request, B.session, '/reports/trial-balance')
  const aTotal = String((aTb.data as { total_debit?: string }).total_debit)
  const bTotal = String((bTb.data as { total_debit?: string }).total_debit)
  expect(aTotal, 'A trial balance carries A money').not.toBe('0.00')
  expect(bTotal, 'B trial balance carries B money').not.toBe('0.00')
  expect(bTotal, 'A and B trial-balance totals are different figures').not.toBe(aTotal)

  // POSITIVE CONTROL on the treasury surface.
  const bPayments = await get(request, B.session, '/payments?per_page=100')
  expect(bPayments.raw, 'B sees its own payment').toContain(B.paymentId)
})

// ===========================================================================
// MTP-ISO-04 — EDGE · P0 — POS surfaces
// ===========================================================================

test('MTP-ISO-04: no foreign Z-number, receipt, shift or cash figure on Tenant B POS surfaces', async ({
  request,
}) => {
  test.setTimeout(120_000)

  // Neither demo tenant has device-authored POS data (plan §0.4: no seeder
  // writes pos_shifts / pos_receipts), so asserting "B sees no A receipt" would
  // be proved against an empty universe on BOTH sides. A single READ-ONLY GET
  // against `demo-pharmacy-tn` (the other waves' tenant) supplies a REAL
  // money-bearing receipt id to aim the probe at. No write ever touches it.
  const pharmacy = await harvestPharmacyPosReceipt(request)

  // The analytics/report endpoints validate an explicit window (`from`/`to`);
  // a bare GET is a 422 and would prove nothing about isolation.
  const from = new Date(Date.now() - 30 * 86_400_000).toISOString().slice(0, 10)
  const window = `from=${from}&to=${TODAY}&start_date=${from}&end_date=${TODAY}`
  const surfaces = [
    '/pos/receipts?per_page=50',
    '/pos/shifts',
    `/pos/analytics/summary?${window}`,
    `/pos/analytics/sales-by-product?${window}`,
    `/reports/sales/summary?${window}`,
    `/reports/cash-register/reconciliation?${window}`,
  ]
  for (const path of surfaces) {
    const res = await get(request, B.session, path)
    expect(res.ok, `B GET ${path} -> ${res.status} ${res.raw.slice(0, 200)}`).toBeTruthy()
    expectNoLeak(res.raw, A.forbidden, `B GET ${path} (vs tenant A)`)
    if (pharmacy !== null) {
      expectNoLeak(res.raw, pharmacy.forbidden, `B GET ${path} (vs demo-pharmacy-tn POS money)`)
    }
  }

  if (pharmacy !== null) {
    // Direct-id addressing of a real foreign receipt.
    const byId = await get(request, B.session, `/pos/receipts/${pharmacy.receiptId}`)
    expect(
      isFailClosed(byId.status),
      `B GET /pos/receipts/${pharmacy.receiptId} must fail closed, got ${byId.status}`,
    ).toBeTruthy()
    // The probed id is EXCLUDED: with `APP_DEBUG=true` the local stack answers a
    // raw `ModelNotFoundException` that quotes the id the caller just sent. That
    // is an echo, not a disclosure — the money (`receiptTotal`) and the receipt
    // NUMBER are still scanned, and those are what a real leak would carry.
    expectNoLeak(byId.raw, pharmacy.forbidden, 'B GET foreign pos receipt by id', [pharmacy.receiptId])

    if (pharmacy.shiftId !== null) {
      const shift = await get(request, B.session, `/pos/shifts/${pharmacy.shiftId}`)
      expect(isFailClosed(shift.status), `B GET foreign shift -> ${shift.status}`).toBeTruthy()
    }
  }

  // BLOCKED half, recorded not hidden: `demo-tenant-a`/`-b` have no POS data of
  // their own, so the "Tenant A Z-report vs Tenant B" direction is proved only
  // through the read-only pharmacy probe above. Authoring device data in these
  // tenants is §Z-owned (campaign plan §D, O-4).
  const bReceipts = await get(request, B.session, '/pos/receipts?per_page=50')
  const bPage = bReceipts.data as unknown as { meta?: { total?: number } }
  expect(bPage.meta?.total, 'B POS receipt count is 0 — device half BLOCKED -> §Z').toBe(0)
})

// ===========================================================================
// MTP-ISO-05 — EDGE · P0 — CROSS-TENANT TOKEN REPLAY (SECURITY GATE)
// ===========================================================================

test('MTP-ISO-05: a Tenant A token replayed against Tenant B money ids fails closed and writes nothing', async ({
  request,
}) => {
  test.setTimeout(180_000)

  const beforeA = await moneySnapshot(request, A)
  const beforeB = await moneySnapshot(request, B)

  interface Probe {
    label: string
    run: () => Promise<{ status: number; raw: string }>
    /** Routes that validate the foreign id as INPUT (ScopedExists) answer 422. */
    allow422?: boolean
    /**
     * Ids this probe SENT. They are excluded from the leak scan: the local stack
     * runs `APP_DEBUG=true` and Laravel's `ModelNotFoundException` body quotes
     * the requested id verbatim, which is an echo of the caller's own input, not
     * a disclosure. Everything the probe did NOT send — the foreign document
     * NUMBER, partner name, SKU, phone and the exact money strings — stays in
     * the scan, and those are what an actual leak would carry.
     */
    sends?: string[]
  }

  const aToken = A.session
  const bToken = B.session

  const probes: Probe[] = [
    // ---- READ replay: A's token, B's ids ---------------------------------
    { label: 'A->B GET /invoices/{B invoice}', sends: [B.invoiceId], run: () => get(request, aToken, `/invoices/${B.invoiceId}`) },
    { label: 'A->B GET /documents/{B invoice}', sends: [B.invoiceId], run: () => get(request, aToken, `/documents/${B.invoiceId}`) },
    { label: 'A->B GET /payments/{B payment}', sends: [B.paymentId], run: () => get(request, aToken, `/payments/${B.paymentId}`) },
    {
      label: 'A->B GET /payments/{B payment}/refund-history',
      sends: [B.paymentId],
      run: () => get(request, aToken, `/payments/${B.paymentId}/refund-history`),
    },
    {
      label: 'A->B GET /payments/{B payment}/can-refund',
      sends: [B.paymentId],
      run: () => get(request, aToken, `/payments/${B.paymentId}/can-refund`),
    },
    { label: 'A->B GET /products/{B product}', sends: [B.productId], run: () => get(request, aToken, `/products/${B.productId}`) },
    { label: 'A->B GET /partners/{B partner}', sends: [B.partnerId], run: () => get(request, aToken, `/partners/${B.partnerId}`) },
    {
      label: 'A->B GET /payment-repositories/{B repository}',
      sends: [B.repositoryId],
      run: () => get(request, aToken, `/payment-repositories/${B.repositoryId}`),
    },
    {
      label: 'A->B GET /payment-repositories/{B repository}/movements',
      sends: [B.repositoryId],
      run: () => get(request, aToken, `/payment-repositories/${B.repositoryId}/movements`),
    },
    {
      label: 'A->B GET /loyalty/members/{B member}',
      sends: [B.loyalty.memberId],
      run: () => get(request, aToken, `/loyalty/members/${B.loyalty.memberId}`),
    },
    {
      label: 'A->B GET /companies/{B company}',
      sends: [B.session.companyId],
      run: () => get(request, aToken, `/companies/${B.session.companyId}`),
    },

    // ---- MUTATION replay: A's token, B's ids ------------------------------
    { label: 'A->B POST /invoices/{B invoice}/cancel', sends: [B.invoiceId], run: () => post(request, aToken, `/invoices/${B.invoiceId}/cancel`, { reason: 'W8 ISO-05 replay probe' }) },
    { label: 'A->B POST /payments/{B payment}/reverse', sends: [B.paymentId], run: () => post(request, aToken, `/payments/${B.paymentId}/reverse`, { reason: 'W8 ISO-05 replay probe' }) },
    { label: 'A->B POST /payments/{B payment}/refund', sends: [B.paymentId], run: () => post(request, aToken, `/payments/${B.paymentId}/refund`, { reason: 'W8 ISO-05 replay probe' }) },
    {
      label: 'A->B POST /payments with B partner + B document',
      allow422: true,
      sends: [B.partnerId, B.invoiceId, B.repositoryId],
      run: () =>
        post(request, aToken, '/payments', {
          partner_id: B.partnerId,
          amount: '10.000',
          payment_date: TODAY,
          direction: 'inbound',
          payment_repository_id: B.repositoryId,
          allocations: [{ document_id: B.invoiceId, amount: '10.000' }],
        }),
    },
    {
      label: 'A->B POST /payment-repositories/{B repository}/adjustments',
      sends: [B.repositoryId],
      run: () =>
        // WELL-FORMED on purpose. A payload that fails validation produces a 422
        // that proves nothing about the boundary — `AdjustRepositoryRequest`
        // would have rejected it inside the caller's own tenant too. Every field
        // is valid so the ONLY reason this can fail is the foreign repository id.
        post(request, aToken, `/payment-repositories/${B.repositoryId}/adjustments`, {
          direction: 'in',
          amount: '99.000',
          reason_code: 'other',
          reason_text: 'W8 ISO-05 replay probe',
        }),
    },

    // ---- READ + MUTATION replay in the OTHER direction ---------------------
    { label: 'B->A GET /invoices/{A invoice}', sends: [A.invoiceId], run: () => get(request, bToken, `/invoices/${A.invoiceId}`) },
    { label: 'B->A GET /payments/{A payment}', sends: [A.paymentId], run: () => get(request, bToken, `/payments/${A.paymentId}`) },
    { label: 'B->A POST /invoices/{A invoice}/cancel', sends: [A.invoiceId], run: () => post(request, bToken, `/invoices/${A.invoiceId}/cancel`, { reason: 'W8 ISO-05 replay probe' }) },
    { label: 'B->A POST /payments/{A payment}/reverse', sends: [A.paymentId], run: () => post(request, bToken, `/payments/${A.paymentId}/reverse`, { reason: 'W8 ISO-05 replay probe' }) },

    // ---- HEADER vector: A's token carrying B's X-Company-Id ----------------
    {
      label: 'A token + X-Company-Id: B company -> GET /invoices',
      run: () => get(request, withForeignCompanyHeader(aToken, B.session.companyId), '/invoices?per_page=100'),
    },
    {
      label: 'A token + X-Company-Id: B company -> GET /reports/trial-balance',
      run: () => get(request, withForeignCompanyHeader(aToken, B.session.companyId), '/reports/trial-balance'),
    },
    {
      label: 'A token + X-Company-Id: B company -> GET /payments',
      run: () => get(request, withForeignCompanyHeader(aToken, B.session.companyId), '/payments?per_page=100'),
    },
  ]

  const failures: string[] = []
  const leaks: string[] = []

  for (const probe of probes) {
    const res = await probe.run()
    const forbidden = probe.label.startsWith('B->A') ? A.forbidden : B.forbidden
    const acceptable = isFailClosed(res.status) || (probe.allow422 === true && res.status === 422)
    if (!acceptable) {
      failures.push(`${probe.label} -> ${res.status} :: ${res.raw.slice(0, 220)}`)
    }
    const echoed = probe.sends ?? []
    const hits = forbidden.filter((t) => !echoed.includes(t) && res.raw.includes(t))
    if (hits.length > 0) {
      leaks.push(`${probe.label} -> ${res.status} LEAKED ${JSON.stringify(hits)} :: ${res.raw.slice(0, 300)}`)
    }
  }

  expect(leaks, `MTP-ISO-05 P0 SECURITY GATE — cross-tenant DATA LEAK:\n${leaks.join('\n')}`).toEqual([])
  expect(
    failures,
    `MTP-ISO-05 P0 SECURITY GATE — probe did not fail closed (2xx/5xx on a foreign id):\n${failures.join('\n')}`,
  ).toEqual([])

  // --- "no row written in either tenant DB" -------------------------------
  const afterA = await moneySnapshot(request, A)
  const afterB = await moneySnapshot(request, B)
  expect(afterA, 'Tenant A money state unchanged by the replay matrix').toEqual(beforeA)
  expect(afterB, 'Tenant B money state unchanged by the replay matrix').toEqual(beforeB)
})

// ===========================================================================
// MTP-ISO-06 — EDGE · P0 — CROSS-COMPANY (a separate boundary from cross-tenant)
// ===========================================================================

test('MTP-ISO-06: company-B figures never appear under company A inside one tenant', async ({ request }) => {
  test.setTimeout(180_000)

  const base = await loginTenant(request, 'a')
  const tnd = base.companies.find((c) => c.currency === 'TND')
  const eur = base.companies.find((c) => c.currency === 'EUR')
  expect(tnd, 'demo-tenant-a has a TND company (C-4 provisioning)').toBeDefined()
  expect(
    eur,
    'demo-tenant-a has a SECOND company in a different currency — the cross-company fixture (C-4 provisioning record)',
  ).toBeDefined()

  const c1 = withCompany(base, tnd!.id)
  const c2 = withCompany(base, eur!.id)

  // Company 2's money is a posted manual journal entry, NOT an invoice.
  // WHY: a second company in the same tenant CANNOT author its first sales
  // document — `documents_tenant_id_type_document_number_unique` is scoped on
  // (tenant_id, type, document_number) while `companies.invoice_next_number` is
  // per-company, so company 2's `INV-2026-0001` collides with company 1's and
  // the POST 500s. Filed as a W-8 finding; see
  // docs/superpowers/tickets/2026-08-03-w8-isolation-findings.md (F-2).
  const debit = await ensureAccountFor(request, c2, 'W8GL1', 'W8 GL debit', 'asset')
  const credit = await ensureAccountFor(request, c2, 'W8GL2', 'W8 GL credit', 'liability')
  const jeAmount = '777.77'
  const je = await post(request, c2, '/journal-entries', {
    entry_date: TODAY,
    description: uniqueName('A2-cross-company-fixture'),
    lines: [
      { account_id: debit, debit: jeAmount, credit: '0' },
      { account_id: credit, debit: '0', credit: jeAmount },
    ],
  })
  expect(je.status, `create JE in company 2 -> ${je.status} ${je.raw}`).toBe(201)
  const jeId = String(je.data.id)
  const posted = await post(request, c2, `/journal-entries/${jeId}/post`)
  expect(posted.ok, `post JE in company 2 -> ${posted.status} ${posted.raw}`).toBeTruthy()

  const c2Forbidden = [jeId, 'W8GL1', 'W8GL2', '777.77']
  const c1Forbidden = [A.invoiceNumber, A.partnerName, AMOUNTS.a.invoiceTotal]

  // --- (a) surfaces that ARE company-scoped — these genuinely hold ---------
  for (const path of ['/reports/trial-balance', '/ledger', '/reports/balance-sheet']) {
    const inC1 = await get(request, c1, path)
    expect(inC1.ok, `company1 GET ${path} -> ${inC1.status}`).toBeTruthy()
    expectNoLeak(inC1.raw, c2Forbidden, `company1 GET ${path} must not carry company2 GL`)

    const inC2 = await get(request, c2, path)
    expect(inC2.ok, `company2 GET ${path} -> ${inC2.status}`).toBeTruthy()
    expectNoLeak(inC2.raw, c1Forbidden, `company2 GET ${path} must not carry company1 money`)
  }

  // Documents / treasury are company-scoped on both the list and the detail.
  const c2Docs = await get(request, c2, '/invoices?per_page=100')
  expectNoLeak(c2Docs.raw, c1Forbidden, 'company2 GET /invoices')
  const c2Payments = await get(request, c2, '/payments?per_page=100')
  expectNoLeak(c2Payments.raw, [A.paymentId, AMOUNTS.a.paymentAmount], 'company2 GET /payments')

  const foreignDoc = await get(request, c2, `/invoices/${A.invoiceId}`)
  expect(
    isFailClosed(foreignDoc.status),
    `company2 GET /invoices/{company1 invoice} must fail closed, got ${foreignDoc.status} :: ${foreignDoc.raw.slice(0, 250)}`,
  ).toBeTruthy()
  expectNoLeak(foreignDoc.raw, c1Forbidden, 'company2 addressing company1 invoice by id', [A.invoiceId])

  const foreignPayment = await get(request, c2, `/payments/${A.paymentId}`)
  expect(
    isFailClosed(foreignPayment.status),
    `company2 GET /payments/{company1 payment} -> ${foreignPayment.status}`,
  ).toBeTruthy()

  // POSITIVE CONTROLS on both sides.
  const c2Tb = await get(request, c2, '/reports/trial-balance')
  expect(c2Tb.raw, 'company 2 sees its own journal entry').toContain('W8GL1')
  const c1Docs = await get(request, c1, '/invoices?per_page=100')
  expect(c1Docs.raw, 'company 1 sees its own invoice').toContain(A.invoiceNumber)

  // --- (b) the CROSS-TENANT half of the same surfaces DOES hold ------------
  // Recorded here so the tripwires below can never be misread as "isolation is
  // broken everywhere": the per-tenant database boundary is intact.
  const bReadsA2Je = await get(request, B.session, `/journal-entries/${jeId}`)
  expect(
    bReadsA2Je.status,
    'cross-TENANT read of the same journal entry is correctly refused (db-per-tenant holds)',
  ).toBe(404)

  // =========================================================================
  // (c) FINDING F-1 — GREEN TRIPWIRES. P0 cross-COMPANY authorization break in
  // the Accounting module. `JournalEntryController` resolves
  // `$companyId = $this->companyContext->requireCompanyId()` and then NEVER
  // USES IT: index():32-43, show():125-138 and post():141-171 all query
  // `where('tenant_id', …)->findOrFail()` only. The CREATE path was already
  // hardened (`CreateJournalEntryRequest` uses
  // `ScopedExists::tenantAndCompany('accounts', …)`, comment "api.accounting.003")
  // — the read and the state transition in the SAME controller were missed.
  // `AccountController::index():28-34` has the same shape (`Account::forTenant`).
  //
  // These assertions PIN TODAY'S BEHAVIOUR and go RED when the leak is closed,
  // which is the intended forcing function. Ticket:
  // docs/superpowers/tickets/2026-08-03-w8-isolation-findings.md (F-1).
  // =========================================================================

  const c1Journal = await get(request, c1, '/journal-entries')
  expect(
    c1Journal.raw.includes(jeId),
    'TRIPWIRE F-1a: GET /journal-entries under company 1 LEAKS company 2 entries (flip to false when fixed)',
  ).toBe(true)
  expect(
    c1Journal.raw.includes('777.77'),
    "TRIPWIRE F-1a: …including company 2's exact GL money figure",
  ).toBe(true)

  const c1ReadsC2Je = await get(request, c1, `/journal-entries/${jeId}`)
  expect(
    c1ReadsC2Je.status,
    'TRIPWIRE F-1b: GET /journal-entries/{id} under company 1 returns company 2 entry in full (expect 404 once fixed)',
  ).toBe(200)
  expect(String((c1ReadsC2Je.data as { description?: string }).description)).toContain(
    'A2-cross-company-fixture',
  )

  const c1Accounts = await get(request, c1, '/accounts?search=W8GL1')
  const c1AccountRows = (c1Accounts.data as unknown as Array<{ code: string }>) ?? []
  expect(
    Array.isArray(c1AccountRows) && c1AccountRows.some((a) => a.code === 'W8GL1'),
    "TRIPWIRE F-1c: GET /accounts under company 1 LEAKS company 2's chart of accounts",
  ).toBe(true)

  // The MUTATION half — the reason this is graded P0 rather than a read leak.
  const draft = await post(request, c2, '/journal-entries', {
    entry_date: TODAY,
    description: uniqueName('A2-cross-company-post-probe'),
    lines: [
      { account_id: debit, debit: '55.550', credit: '0' },
      { account_id: credit, debit: '0', credit: '55.550' },
    ],
  })
  expect(draft.status, `draft JE in company 2 -> ${draft.status} ${draft.raw}`).toBe(201)
  const draftId = String(draft.data.id)

  const crossPost = await post(request, c1, `/journal-entries/${draftId}/post`)
  expect(
    crossPost.status,
    'TRIPWIRE F-1d: company 1 can POST company 2\'s draft journal entry (expect 404 once fixed)',
  ).toBe(200)
  expect(
    String((crossPost.data as { status?: string }).status),
    "TRIPWIRE F-1d: …and the entry really transitions to posted, settled with the CALLER'S currency (GeneralLedgerService::postEntry(entry, user, $company->currency) — company 1 is TND, the entry belongs to a EUR company)",
  ).toBe('posted')

  // --- cleanup: retire the probe JEs (2xx-gated, non-masking) --------------
  // `journal_entries` has no delete/reverse route (`route:list` shows only
  // index/store/show/post), so the two fixture entries are RECORDED in the
  // results ledger rather than removed. They live in this wave's own tenant and
  // are the standing evidence for F-1.
  const cleanupStatuses = [
    (await get(request, c2, `/journal-entries/${jeId}`)).status,
    (await get(request, c2, `/journal-entries/${draftId}`)).status,
  ]
  expect(cleanupStatuses.every((s) => isCleanupSuccess(s)), 'both F-1 evidence entries readable').toBe(true)
})

async function ensureAccountFor(
  request: Parameters<typeof get>[0],
  session: Session,
  code: string,
  name: string,
  type: string,
): Promise<string> {
  const existing = await get(request, session, `/accounts?search=${encodeURIComponent(code)}`)
  const rows = (existing.data as unknown as Array<{ id: string; code: string }>) ?? []
  const hit = Array.isArray(rows) ? rows.find((a) => a.code === code) : undefined
  if (hit !== undefined) return hit.id
  const created = await post(request, session, '/accounts', { code, name, type, is_active: true })
  expect(created.status, `create account ${code} -> ${created.status} ${created.raw}`).toBe(201)
  return String(created.data.id)
}

// ===========================================================================
// MTP-ISO-07 — EDGE · P0 — permission bleed across the tenant-blind Spatie cache
// ===========================================================================

test('MTP-ISO-07: no permission bleed when a Tenant B session immediately follows a Tenant A session', async ({
  request,
}) => {
  test.setTimeout(180_000)

  // The Spatie permission cache is TENANT-BLIND (memory:
  // project_spatie_permission_cache_tenant_blind). Under db-per-tenant the
  // `permissions` / `roles` PRIMARY KEYS differ per tenant database, so a cache
  // populated while serving tenant A and then reused while serving tenant B
  // would resolve B's `role_has_permissions` rows against A's permission ids —
  // producing either a WIDER grant (a leak) or a spurious 403. Both are
  // observable through a permission-gated money mutation.

  // 1. Warm the cache from Tenant A with a permission-gated read AND write.
  const aSession = await loginTenant(request, 'a')
  const aRead = await get(request, aSession, '/reports/trial-balance')
  expect(aRead.ok, `A warm-up read -> ${aRead.status}`).toBeTruthy()
  const aPartner = await createPartner(request, aSession, uniqueName('ISO07-A'))
  expect(aPartner, 'A warm-up write succeeded').toBeTruthy()

  // 2. Immediately authenticate Tenant B and exercise the same gated surfaces.
  const bSession = await loginTenant(request, 'b')

  expect(bSession.tenantId, 'B session resolves to B tenant').not.toBe(aSession.tenantId)
  expect(bSession.companyId, 'B session resolves to B company').not.toBe(aSession.companyId)

  // The permission SET must be B's own and complete. Both principals hold the
  // seeded `admin` role, so a bleed shows up as a MISSING permission (ids from
  // the wrong database resolve to nothing), not as an extra one.
  expect(bSession.permissions.length, 'B admin permission count survives A-warmed cache').toBe(
    aSession.permissions.length,
  )
  for (const required of ['payments.create', 'invoices.create', 'journal.create', 'reports.view']) {
    expect(bSession.permissions, `B holds ${required} after an A session`).toContain(required)
  }

  const bRead = await get(request, bSession, '/reports/trial-balance')
  expect(bRead.status, 'B gated read is not spuriously denied by an A-warmed cache').toBe(200)
  expectNoLeak(bRead.raw, A.forbidden, 'B trial balance immediately after an A session')

  const bWrite = await createPartner(request, bSession, uniqueName('ISO07-B'))
  expect(bWrite, 'B gated write is not spuriously denied by an A-warmed cache').toBeTruthy()

  // 3. Reverse the order — a cache warmed by B must not widen or narrow A.
  const aAgain = await loginTenant(request, 'a')
  expect(aAgain.permissions.length, 'A permission count survives a B-warmed cache').toBe(
    aSession.permissions.length,
  )
  const aReadAgain = await get(request, aAgain, '/reports/trial-balance')
  expect(aReadAgain.status, 'A gated read after a B session').toBe(200)
  expectNoLeak(aReadAgain.raw, B.forbidden, 'A trial balance immediately after a B session')

  // 4. The written probes land in the right database and only there.
  const aPartners = await get(request, aAgain, '/partners?per_page=100')
  const bPartners = await get(request, bSession, '/partners?per_page=100')
  expect(aPartners.raw, 'A partner probe is visible to A').toContain('ISO07-A')
  expect(aPartners.raw, 'A cannot see B partner probe').not.toContain('ISO07-B')
  expect(bPartners.raw, 'B partner probe is visible to B').toContain('ISO07-B')
  expect(bPartners.raw, 'B cannot see A partner probe').not.toContain('ISO07-A')
})

// ---------------------------------------------------------------------------
// Fixture retirement — 2xx-gated and NON-MASKING (statement-support §I-2a/I-2b:
// an `expect` inside a `finally` REPLACES an in-flight exception, so cleanup
// statuses are recorded, never asserted on the failure path).
// ---------------------------------------------------------------------------

test.afterAll(async ({ request }) => {
  const statuses: Array<{ what: string; status: number }> = []
  for (const fixture of [A, B]) {
    if (fixture === undefined) continue
    try {
      const res = await get(request, fixture.session, `/invoices/${fixture.invoiceId}`)
      statuses.push({ what: `${fixture.session.tenant} invoice readback`, status: res.status })
    } catch {
      statuses.push({ what: `${fixture.session.tenant} invoice readback`, status: 599 })
    }
  }
  // Posted, hash-chained fiscal documents are NOT deletable by design, and both
  // tenants exist solely for this wave — the fixtures are the wave's evidence,
  // not junk. What IS retired is every probe that could have written: the
  // MTP-ISO-05 matrix asserts a byte-identical money snapshot, so by definition
  // it left nothing behind. Statuses are recorded for the results ledger.
  // eslint-disable-next-line no-console
  console.log('[W-8] fixture readback:', JSON.stringify(statuses), 'all2xx=', statuses.every((s) => isCleanupSuccess(s.status)))
})
