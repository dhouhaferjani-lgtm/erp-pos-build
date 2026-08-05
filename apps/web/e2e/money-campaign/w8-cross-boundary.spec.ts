/**
 * MONEY TEST CAMPAIGN — W-8 · `MTP-GL-24`, `MTP-GL-25`, `MTP-PUR-13`, `MTP-LOY-15`.
 *
 * The three boundaries this file separates, because the plan treats them as one
 * and they are not:
 *
 *   cross-TENANT   — two databases (`demo-tenant-a` / `demo-tenant-b`)
 *   cross-COMPANY  — one database, two `companies` rows (demo-tenant-a's TND + EUR)
 *   cross-SURFACE  — the same boundary observed through a different module (loyalty)
 *
 * Tenants/companies provisioned by this wave's C-4 record — see
 * `docs/sessions/MONEY-CAMPAIGN-RESULTS.md` § W-8.
 */
import { expect, test } from '@playwright/test'

import {
  AMOUNTS,
  addMoney,
  type Session,
  type TenantFixture,
  buildTenantFixture,
  createPartner,
  createProductWithCost,
  expectNoLeak,
  subMoney,
  toMillimes,
  get,
  isFailClosed,
  loginPageAsTenant,
  loginTenant,
  post,
  renderedText,
  uniqueName,
  withCompany,
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

// ===========================================================================
// MTP-GL-24 — EDGE · P0 — cross-TENANT finance isolation, every /finance/* page
// ===========================================================================

test('MTP-GL-24: zero Tenant A figures on any Tenant B /finance/* page, and the bucket totals differ', async ({
  request,
  page,
}) => {
  test.setTimeout(180_000)

  // --- API layer: every finance read the /finance/* routes consume ---------
  const financeApi = [
    '/reports/trial-balance',
    '/reports/aged-receivables',
    '/reports/aged-payables',
    '/reports/balance-sheet',
    '/reports/cash-movements',
    '/reports/finance-summary',
    '/reports/overdue-summary',
    '/reports/upcoming-payments',
    '/ledger',
    '/journal-entries',
    '/accounts?per_page=100',
  ]

  for (const path of financeApi) {
    const res = await get(request, B.session, path)
    expect(res.ok, `B GET ${path} -> ${res.status} ${res.raw.slice(0, 200)}`).toBeTruthy()
    expectNoLeak(res.raw, A.forbidden, `B GET ${path}`)
  }

  // --- the bucket totals must differ, and each side must show ITS OWN ------
  interface AgedLine {
    customer_name: string
    current: string
    total: string
  }
  interface AgedReport {
    lines: AgedLine[]
    total_current: string
    grand_total: string
  }

  const aAged = await get(request, A.session, '/reports/aged-receivables')
  const bAged = await get(request, B.session, '/reports/aged-receivables')
  const aReport = aAged.data as unknown as AgedReport
  const bReport = bAged.data as unknown as AgedReport

  expect(bReport.grand_total, "B's aged-AR grand total is not A's").not.toBe(aReport.grand_total)

  // Each tenant's OWN fixture line, by exact arithmetic:
  // outstanding = invoiceTotal - paymentAmount.
  // NOTE the report emits scale 4 on a scale-3 TND company (`376.0000`). That
  // drift belongs to W-6's surface (`finance-aged.spec.ts`, ticket
  // 2026-08-05-w6-finance-gl-defects.md) and is CROSS-REFERENCED here rather
  // than re-filed — but it is PINNED so this case cannot silently absorb a
  // change to the emitted scale.
  const aOutstanding = subMoney(AMOUNTS.a.invoiceTotal, AMOUNTS.a.paymentAmount) // 376.000
  const bOutstanding = subMoney(AMOUNTS.b.invoiceTotal, AMOUNTS.b.paymentAmount) // 886.000

  const aLine = aReport.lines.find((l) => l.customer_name === A.partnerName)
  const bLine = bReport.lines.find((l) => l.customer_name === B.partnerName)
  expect(aLine, 'A aged AR carries A own fixture line').toBeDefined()
  expect(bLine, 'B aged AR carries B own fixture line').toBeDefined()
  expect(aLine!.total, `A outstanding is ${aOutstanding}, emitted at scale 4`).toBe(`${aOutstanding}0`)
  expect(bLine!.total, `B outstanding is ${bOutstanding}, emitted at scale 4`).toBe(`${bOutstanding}0`)

  // Neither side's line list mentions the other's customer.
  expect(bReport.lines.some((l) => l.customer_name === A.partnerName), 'B aged AR has no A customer').toBe(
    false,
  )
  expect(aReport.lines.some((l) => l.customer_name === B.partnerName), 'A aged AR has no B customer').toBe(
    false,
  )

  // --- UI layer: the rendered /finance/* pages -----------------------------
  await loginPageAsTenant(page, 'b')
  for (const route of [
    '/finance/trial-balance',
    '/finance/aged-receivables',
    '/finance/aged-payables',
    '/finance/balance-sheet',
    '/finance/journal-entries',
    '/finance/chart-of-accounts',
  ]) {
    const text = await renderedText(page, route)
    expectNoLeak(
      text,
      [A.invoiceNumber, A.partnerName, AMOUNTS.a.invoiceTotal],
      `B rendered ${route}`,
    )
  }
})

// ===========================================================================
// MTP-GL-25 — EDGE · P1 — cross-COMPANY currency scaling on the trial balance
// ===========================================================================

test('MTP-GL-25: switching company re-scopes the trial balance, and the currency scale is pinned', async ({
  request,
}) => {
  test.setTimeout(180_000)

  const base = await loginTenant(request, 'a')
  const tnd = base.companies.find((c) => c.currency === 'TND')
  const eur = base.companies.find((c) => c.currency === 'EUR')
  expect(tnd, 'demo-tenant-a TND company').toBeDefined()
  expect(eur, 'demo-tenant-a EUR company (C-4 cross-company fixture)').toBeDefined()

  const c1 = withCompany(base, tnd!.id)
  const c2 = withCompany(base, eur!.id)

  interface TbLine {
    account_code: string
    debit: string
    credit: string
  }
  const tbLines = (r: { data: Record<string, unknown> }): TbLine[] =>
    (r.data as unknown as { lines: TbLine[] }).lines

  // BASELINE FIRST. The trial balance ACCUMULATES: this wave's own re-runs each
  // post another EUR journal entry, so an absolute assertion on the account
  // total is single-shot by construction. The money assertion is therefore a
  // DELTA — exactly `777.770` more debit after the post — which is re-runnable
  // and still exact.
  const before = await get(request, c2, '/reports/trial-balance')
  const beforeDebit = tbLines(before).find((l) => l.account_code === 'W8GL1')?.debit ?? '0.000'

  // Money in the EUR company: a posted manual JE (the EUR company cannot author
  // a sales document — see the W-8 finding F-2 on the tenant-scoped
  // `documents_tenant_id_type_document_number_unique`).
  const debit = await ensureAccount(request, c2, 'W8GL1', 'W8 GL debit', 'asset')
  const credit = await ensureAccount(request, c2, 'W8GL2', 'W8 GL credit', 'liability')
  const je = await post(request, c2, '/journal-entries', {
    entry_date: TODAY,
    description: uniqueName('GL25-EUR'),
    lines: [
      { account_id: debit, debit: '777.77', credit: '0' },
      { account_id: credit, debit: '0', credit: '777.77' },
    ],
  })
  expect(je.status, `EUR JE create -> ${je.status} ${je.raw}`).toBe(201)
  const posted = await post(request, c2, `/journal-entries/${String(je.data.id)}/post`)
  expect(posted.ok, `EUR JE post -> ${posted.status} ${posted.raw}`).toBeTruthy()

  const tb1 = await get(request, c1, '/reports/trial-balance')
  const tb2 = await get(request, c2, '/reports/trial-balance')
  expect(tb1.ok && tb2.ok, 'both trial balances readable').toBeTruthy()

  const lines1 = tbLines(tb1)
  const lines2 = tbLines(tb2)

  // --- ISOLATION half: no value crosses the company boundary ---------------
  expect(lines1.some((l) => l.account_code === 'W8GL1'), 'TND company does not show the EUR company GL').toBe(
    false,
  )
  expect(lines2.some((l) => l.account_code === '411'), 'EUR company does not show the TND receivable').toBe(
    false,
  )
  expectNoLeak(tb2.raw, [A.invoiceNumber, A.partnerName], 'EUR company trial balance')

  const eurDebit = lines2.find((l) => l.account_code === 'W8GL1')
  expect(eurDebit, 'the EUR company sees its own posted JE').toBeDefined()

  // --- MONEY half: the exact delta ----------------------------------------
  // Compared in MILLIMES, not as strings: after the L4 fix the EUR company emits
  // at its own currency scale (2), so a string comparison against a scale-3
  // helper output would fail on the RENDER scale rather than on the money.
  expect(
    toMillimes(eurDebit!.debit),
    `EUR debit moved by exactly 777.770 (was ${beforeDebit})`,
  ).toBe(toMillimes(addMoney(beforeDebit, '777.770')))

  // --- SCALE half -----------------------------------------------------------
  // W-8 F-3 — FIXED (fix lane L4). The plan expects "figures re-scale: EUR
  // displays 2 dp, TND displays 3 dp". They did NOT:
  // `GET /reports/trial-balance` emitted the SAME output for a 2-dp EUR company
  // as for a 3-dp TND one, and emitted THREE different scales inside ONE
  // payload — `debit` at 3 dp (the raw DB string), `credit` at 4 dp (a `bcmul`
  // result) and a literal `"0.00"` for the zero side. None of them derived from
  // `companies.currency`. See
  // docs/superpowers/tickets/2026-08-03-w8-isolation-findings.md (F-3).
  //
  // `TrialBalanceService` now keeps its scale-4 INTERNAL working precision but
  // renders every emitted figure — both sides of every line and both totals,
  // populated or zero — at the company currency's scale. That also closes W-6's
  // D3 (an empty period answered `'0.00'`).
  expect(
    eurDebit!.debit,
    'F-3 FIXED: the EUR company emits its debit at the currency scale 2',
  ).toMatch(/^-?\d+\.\d{2}$/)
  const eurCredit = lines2.find((l) => l.account_code === 'W8GL2')
  expect(
    eurCredit!.credit,
    'F-3 FIXED: …and its credit at the SAME scale, not the bcmul scale 4',
  ).toMatch(/^-?\d+\.\d{2}$/)
  // NOTE — this one assertion is NOT a discriminator: EUR's scale is 2, and
  // `'0.00'` is also the pre-fix hardcoded literal, so the value is
  // byte-identical before and after the fix. It is kept because the zero side
  // must still be exactly this; the assertion that actually proves the zero
  // side became currency-derived is the TND one at the end of this test
  // (`/^-?\d+\.\d{3}$/`), which the old literal could never satisfy.
  expect(
    eurDebit!.credit,
    'F-3: the EUR zero side is 0.00 — same as the old literal, see the TND check below',
  ).toBe('0.00')
  const eurTotals = tb2.data as unknown as { total_debit: string; total_credit: string }
  expect(
    [eurTotals.total_debit, eurTotals.total_credit],
    'F-3 FIXED: the report-level totals carry the currency scale as well',
  ).toEqual([
    expect.stringMatching(/^-?\d+\.\d{2}$/),
    expect.stringMatching(/^-?\d+\.\d{2}$/),
  ])

  // The TND company's own figures, for the side-by-side the plan asks for: the
  // two currencies must now DIFFER in scale — byte-identical emission was
  // exactly the defect.
  const tndReceivable = lines1.find((l) => l.account_code === '411')
  expect(tndReceivable, 'TND company has its receivable line').toBeDefined()
  expect(
    tndReceivable!.debit,
    'F-3 FIXED: the TND company emits scale 3 where the EUR company emits 2',
  ).toMatch(/^-?\d+\.\d{3}$/)
  expect(
    tndReceivable!.credit,
    'F-3 FIXED: including its zero side',
  ).toMatch(/^-?\d+\.\d{3}$/)
})

async function ensureAccount(
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
// MTP-PUR-13 — EDGE · P0 — supplier invoice referencing a FOREIGN receipt
// ===========================================================================

test('MTP-PUR-13: a supplier invoice referencing another company/tenant receipt is refused, with no foreign data rendered', async ({
  request,
}) => {
  test.setTimeout(240_000)

  const base = await loginTenant(request, 'a')
  const tnd = base.companies.find((c) => c.currency === 'TND')!
  const eur = base.companies.find((c) => c.currency === 'EUR')!
  const c1 = withCompany(base, tnd.id)
  const c2 = withCompany(base, eur.id)

  // --- build a REAL receipt in company 1 ----------------------------------
  const supplierName = uniqueName('PUR13-Supplier')
  const supplierId = await createPartner(request, c1, supplierName, 'supplier')
  const product = await createProductWithCost(request, c1, uniqueName('PUR13-Product'))

  const locations = await get(request, c1, '/locations')
  const locationRows = (locations.data as unknown as Array<{ id: string }>) ?? []
  expect(locationRows.length, 'company 1 has a location').toBeGreaterThan(0)

  const poRes = await post(request, c1, '/purchase-orders', {
    partner_id: supplierId,
    document_date: TODAY,
    location_id: locationRows[0]!.id,
    lines: [
      {
        product_id: product.id,
        description: 'W8 PUR-13 receipt line',
        quantity: '10',
        unit_price: '12.500',
        tax_rate: '19.00',
      },
    ],
  })
  expect(poRes.status, `PO create -> ${poRes.status} ${poRes.raw}`).toBe(201)
  const poId = String(poRes.data.id)
  const poNumber = String(poRes.data.document_number ?? poRes.data.number ?? '')

  const confirmRes = await post(request, c1, `/purchase-orders/${poId}/confirm`)
  expect(confirmRes.ok, `PO confirm -> ${confirmRes.status} ${confirmRes.raw}`).toBeTruthy()

  const poDetail = await get(request, c1, `/purchase-orders/${poId}`)
  const lineId = String((poDetail.data as { lines: Array<{ id: string }> }).lines[0]!.id)

  const receiveRes = await post(request, c1, `/purchase-orders/${poId}/receive`, {
    quantities: { [lineId]: '10' },
  })
  expect(receiveRes.ok, `PO receive -> ${receiveRes.status} ${receiveRes.raw}`).toBeTruthy()

  const foreignTokens = [poId, supplierName, product.sku, poNumber].filter((t) => t !== '')

  const payload = {
    partner_id: supplierId,
    source_document_ids: [poId],
    currency: 'TND',
    issue_date: TODAY,
    lines: [
      {
        source_line_id: lineId,
        product_id: product.id,
        quantity: '10',
        unit_price: '12.500',
        vat_rate: '19.00',
      },
    ],
  }

  // --- CROSS-COMPANY refusal ----------------------------------------------
  const crossCompany = await post(request, c2, '/supplier-invoices', {
    ...payload,
    external_reference: uniqueName('PUR13-XCOMPANY'),
  })
  expect(
    crossCompany.status,
    `cross-COMPANY supplier invoice must be refused, got ${crossCompany.status} :: ${crossCompany.raw.slice(0, 300)}`,
  ).toBe(422)
  const xcErrors = (crossCompany.data as { errors?: Record<string, string[]> }).errors ?? {}
  expect(Object.keys(xcErrors), 'the FOREIGN receipt reference itself is what is rejected').toContain(
    'source_document_ids.0',
  )
  expect(Object.keys(xcErrors), 'the foreign partner is rejected too').toContain('partner_id')
  expectNoLeak(
    crossCompany.raw,
    [supplierName, poNumber].filter((t) => t !== ''),
    'cross-company supplier-invoice refusal must not echo the foreign document',
  )

  // --- CROSS-TENANT refusal -------------------------------------------------
  const crossTenant = await post(request, B.session, '/supplier-invoices', {
    ...payload,
    external_reference: uniqueName('PUR13-XTENANT'),
  })
  expect(
    crossTenant.status,
    `cross-TENANT supplier invoice must be refused, got ${crossTenant.status} :: ${crossTenant.raw.slice(0, 300)}`,
  ).toBe(422)
  expectNoLeak(crossTenant.raw, foreignTokens.filter((t) => t !== poId), 'cross-tenant refusal body')

  // Nothing was created on either refused path.
  for (const [label, session] of [
    ['company 2', c2],
    ['tenant B', B.session],
  ] as const) {
    const list = await get(request, session, '/supplier-invoices?per_page=100')
    expectNoLeak(list.raw, foreignTokens, `${label} supplier-invoice list after the refusal`)
  }

  // --- POSITIVE CONTROL: the SAME payload succeeds inside company 1 --------
  // Without this the 422s prove nothing about the boundary — they could be a
  // malformed payload.
  const control = await post(request, c1, '/supplier-invoices', {
    ...payload,
    external_reference: uniqueName('PUR13-CONTROL'),
  })
  expect(
    control.status,
    `same-company control must succeed, got ${control.status} :: ${control.raw.slice(0, 300)}`,
  ).toBe(201)
})

// ===========================================================================
// MTP-LOY-15 — EDGE · P1 — loyalty cross-tenant isolation (admin/read surface)
// ===========================================================================

test('MTP-LOY-15: Tenant A cannot read a Tenant B loyalty member, balance or transaction history', async ({
  request,
}) => {
  test.setTimeout(180_000)

  // Positive control on B's own side FIRST — the fixture really has a balance.
  const ownMember = await get(request, B.session, `/loyalty/members/${B.loyalty.memberId}`)
  expect(ownMember.ok, `B reads its own member -> ${ownMember.status} ${ownMember.raw}`).toBeTruthy()
  const ownTx = await get(
    request,
    B.session,
    `/loyalty/members/${B.loyalty.memberId}/enrollments/${B.loyalty.enrollmentId}/transactions`,
  )
  expect(ownTx.ok, `B reads its own transactions -> ${ownTx.status}`).toBeTruthy()
  expect(ownTx.raw, "B's ledger carries the adjusted points").toContain(AMOUNTS.b.loyaltyPoints)

  const bTokens = [
    B.loyalty.memberId,
    B.loyalty.programId,
    B.loyalty.enrollmentId,
    B.loyalty.phone,
    AMOUNTS.b.loyaltyPoints,
  ]

  // --- read probes with Tenant B ids, authenticated as Tenant A ------------
  interface LoyaltyProbe {
    label: string
    run: () => Promise<{ status: number; raw: string }>
    /** Lookup routes that answer 200-with-empty or 422 rather than 404. */
    allow422?: boolean
    /**
     * TRIPWIRE F-4 — this route answers 500, not 404, on ANY unknown id.
     * `ProgramManagementService.php:130` throws a bare `InvalidArgumentException`
     * instead of aborting 404, so a legitimate cross-tenant refusal surfaces as
     * an unhandled server error. It is NOT a data leak (nothing of B's is
     * returned) and it is NOT an existence oracle (an id that exists nowhere
     * throws identically) — but a 500 is never an acceptable refusal shape.
     * Pinned green; goes red when the route learns to 404.
     * docs/superpowers/tickets/2026-08-03-w8-isolation-findings.md (F-4).
     */
    tripwire500?: boolean
    /**
     * Ids this probe SENT. Excluded from the leak scan — the local stack runs
     * `APP_DEBUG=true`, so `ModelNotFoundException` quotes the caller's own
     * input verbatim. The POINTS, the PHONE and the enrollment balances stay in
     * the scan, and those are what a real leak would carry.
     */
    sends?: string[]
  }

  const probes: LoyaltyProbe[] = [
    {
      label: 'GET /loyalty/members/{B member}',
      sends: [B.loyalty.memberId],
      run: () => get(request, A.session, `/loyalty/members/${B.loyalty.memberId}`),
    },
    {
      label: 'GET /loyalty/members/{B member}/enrollments',
      sends: [B.loyalty.memberId],
      run: () => get(request, A.session, `/loyalty/members/${B.loyalty.memberId}/enrollments`),
    },
    {
      label: 'GET /loyalty/members/{B member}/enrollments/{B enrollment}/transactions',
      sends: [B.loyalty.memberId, B.loyalty.enrollmentId],
      run: () =>
        get(
          request,
          A.session,
          `/loyalty/members/${B.loyalty.memberId}/enrollments/${B.loyalty.enrollmentId}/transactions`,
        ),
    },
    {
      label: 'GET /loyalty/programs/{B program}',
      sends: [B.loyalty.programId],
      tripwire500: true,
      run: () => get(request, A.session, `/loyalty/programs/${B.loyalty.programId}`),
    },
    {
      label: 'GET /loyalty/programs/{B program}/rewards',
      sends: [B.loyalty.programId],
      run: () => get(request, A.session, `/loyalty/programs/${B.loyalty.programId}/rewards`),
    },
    {
      label: 'POST /loyalty/members/lookup with B phone',
      allow422: true,
      sends: [B.loyalty.phone],
      run: () => post(request, A.session, '/loyalty/members/lookup', { phone: B.loyalty.phone }),
    },
    {
      label: 'POST /loyalty/pos/member-lookup with B phone',
      allow422: true,
      sends: [B.loyalty.phone],
      run: () => post(request, A.session, '/loyalty/pos/member-lookup', { phone: B.loyalty.phone }),
    },
    {
      label: 'POST /loyalty/pos/balance with B enrollment',
      allow422: true,
      sends: [B.loyalty.enrollmentId],
      run: () => post(request, A.session, '/loyalty/pos/balance', { enrollment_id: B.loyalty.enrollmentId }),
    },
    {
      label: 'MUTATION POST .../adjust on B enrollment',
      allow422: true,
      sends: [B.loyalty.memberId, B.loyalty.enrollmentId],
      run: () =>
        post(
          request,
          A.session,
          `/loyalty/members/${B.loyalty.memberId}/enrollments/${B.loyalty.enrollmentId}/adjust`,
          { points: '999', reason: 'W8 LOY-15 cross-tenant probe' },
        ),
    },
  ]

  const failures: string[] = []
  const leaks: string[] = []
  const tripwired: string[] = []
  for (const probe of probes) {
    const res = await probe.run()
    // A lookup-by-phone that legitimately answers 200 with an EMPTY result is a
    // pass: nothing leaked. Only a 200 that CARRIES B's data is a failure, and
    // the leak scan below is what decides that.
    const emptyOk = probe.allow422 === true && res.status === 200
    if (probe.tripwire500 === true) {
      tripwired.push(`${probe.label} -> ${res.status}`)
    } else if (!isFailClosed(res.status) && !(probe.allow422 === true && res.status === 422) && !emptyOk) {
      failures.push(`${probe.label} -> ${res.status} :: ${res.raw.slice(0, 220)}`)
    }
    const echoed = probe.sends ?? []
    const hits = bTokens.filter((t) => !echoed.includes(t) && res.raw.includes(t))
    if (hits.length > 0) {
      leaks.push(`${probe.label} -> ${res.status} LEAKED ${JSON.stringify(hits)} :: ${res.raw.slice(0, 300)}`)
    }
  }

  // TRIPWIRE F-4 — pins today's 500-instead-of-404 refusal shape.
  expect(tripwired, 'TRIPWIRE F-4: GET /loyalty/programs/{id} answers 500 on an unknown id').toEqual([
    'GET /loyalty/programs/{B program} -> 500',
  ])

  expect(leaks, `MTP-LOY-15 cross-tenant loyalty LEAK:\n${leaks.join('\n')}`).toEqual([])
  expect(failures, `MTP-LOY-15 probe did not fail closed:\n${failures.join('\n')}`).toEqual([])

  // The mutation probe wrote nothing: B's balance is untouched.
  const afterTx = await get(
    request,
    B.session,
    `/loyalty/members/${B.loyalty.memberId}/enrollments/${B.loyalty.enrollmentId}/transactions`,
  )
  expect(afterTx.raw, "the cross-tenant adjust did not write into B's ledger").not.toContain(
    'W8 LOY-15 cross-tenant probe',
  )

  // A's own loyalty surface never lists B's member.
  const aMembers = await get(request, A.session, '/loyalty/members?per_page=100')
  expectNoLeak(aMembers.raw, bTokens, 'A GET /loyalty/members')
  expect(aMembers.raw, "A sees its OWN member — the probe is not vacuous").toContain(A.loyalty.memberId)
})
