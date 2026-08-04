/**
 * MONEY TEST CAMPAIGN — wave W-5a — §E.7 `WHT` withholding READ surfaces
 * (MTP-WHT-01..05, plan §B.5 row 73).
 *
 * `MTP-TRE-15` / `MTP-TRE-79` (treasury-payments.spec.ts) already pin certificate
 * CREATION through the payment path. These cases pin the surfaces a human actually
 * reads afterwards:
 *   - `/treasury/withholding-certificates`        (list)
 *   - `/treasury/withholding-certificates/:id`    (detail)
 *   - `/treasury/sales-withholding-tracking`      (sales-side tracking)
 * plus the authorization boundary around create/edit.
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real login,
 * real backend, no mocks. Money is compared as EXACT decimal strings computed with
 * integer millime arithmetic (`treasury-support.ts`) — never a float.
 *
 * Withholding contract (landed 2026-08-02, MTP-TRE-15 fix + adversarial review C4/C5):
 * `withholding_rate` is a FRACTION in [0,1] normalised to a numeric-string at the HTTP
 * boundary; `rate_percentage` is the presentation-only x100 projection. A 1.5% rate on
 * gross `1000.000` is rate `0.0150`, withheld `15.000`, net `985.000`, `rate_percentage`
 * `"1.50"`.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import {
  login,
  createCustomer,
  createPostedInvoice,
  createPayment,
  getPaymentMethods,
  getRepositories,
  get,
  post,
  del,
  uniqueName,
  addMoney,
  subMoney,
  toMillimes,
  fromMillimes,
  TODAY,
  type Session,
} from './treasury-support'
import { loginAsRole } from './helpers'

let owner: Session
let cashRepoId: string
let cashMethodId: string

interface CertificateRow {
  id: string
  certificate_number: string
  direction: string
  status: string
  partner_id: string
  document_id: string | null
  payment_id: string | null
  currency: string
  gross_amount: string
  withholding_rate: string
  withholding_amount: string
  net_amount: string
  rate_percentage: string
}

interface TrackingRow {
  id: string
  documentId: string
  customerId: string
  invoiceAmount: string
  withholdingRate: string
  withholdingAmount: string
  expectedReceivable: string
  certificateReceived: boolean
}

/**
 * gross x rate at TND scale 3, computed with integer arithmetic only (BigInt division
 * truncates toward zero, exactly like `bcmul($gross, $rate, 3)` for the non-negative
 * domain the FormRequest enforces: `min:0`, `max:1`, 4dp regex). NEVER a float.
 */
function withhold(gross: string, rateFraction4dp: string): string {
  const [intPart, fracPart = ''] = rateFraction4dp.split('.')
  const rateTenThousandths = BigInt(intPart) * 10000n + BigInt((fracPart + '0000').slice(0, 4))
  return fromMillimes((toMillimes(gross) * rateTenThousandths) / 10000n)
}

/** `rate_percentage` is `bcmul(rate, 100, 2)` — an exact 2dp string, not a float. */
function ratePercentage(rateFraction4dp: string): string {
  const [intPart, fracPart = ''] = rateFraction4dp.split('.')
  const hundredths = BigInt(intPart) * 10000n + BigInt((fracPart + '0000').slice(0, 4))
  const whole = hundredths / 100n
  const frac = (hundredths % 100n).toString().padStart(2, '0')
  return `${whole.toString()}.${frac}`
}

async function certificatesForPartner(
  request: APIRequestContext,
  session: Session,
  partnerId: string
): Promise<CertificateRow[]> {
  const res = await get(request, session, `/withholding/certificates?partner_id=${partnerId}`)
  expect(res.ok, `certificates list -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as CertificateRow[]
}

/** Grouping separator tolerance: `formatNumber()` runs at the en-US default here
 * (the callers pass only `decimals`), but pin the SCALE, not the separator glyph. */
const GROSS_1000_RE = /1[, \s]?000\.000/

test.describe('MTP-WHT — withholding certificates & sales withholding tracking (W-5a §E.7)', () => {
  // The shared local backend is a single dev process serving every concurrent
  // campaign agent; 60s keeps a slow response from manufacturing a false FAIL.
  test.describe.configure({ timeout: 90_000 })

  test.beforeAll(async ({ request }) => {
    owner = await login(request, 'owner')
    const repos = await getRepositories(request, owner)
    cashRepoId = repos.find((r) => r.code === 'CASH-01')!.id
    const methods = await getPaymentMethods(request, owner)
    cashMethodId = methods.find((m) => m.code === 'CASH')!.id
  })

  test('MTP-WHT-01: a withheld payment surfaces on the certificates LIST with the fraction rate and exact money', async ({
    request,
    page,
  }) => {
    const customerId = await createCustomer(request, owner, uniqueName('WHT01'))
    const invoice = await createPostedInvoice(request, owner, customerId, '1000.000', 'W5a WHT-01 invoice')

    const RATE = '0.0150'
    const GROSS = '1000.000'
    const expectedWithheld = withhold(GROSS, RATE) // 15.000
    const expectedNet = subMoney(GROSS, expectedWithheld) // 985.000
    expect(expectedWithheld, 'fixture arithmetic: 1000.000 x 0.0150').toBe('15.000')
    expect(expectedNet).toBe('985.000')

    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: GROSS,
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: invoice.id, amount: GROSS }],
      withholding_enabled: true,
      withholding_rate: RATE,
    })
    expect(payment.status, `payment create -> ${payment.status} ${JSON.stringify(payment.data)}`).toBe(201)

    // ---- API read surface: GET /withholding/certificates ----
    const certificates = await certificatesForPartner(request, owner, customerId)
    expect(certificates, 'exactly one certificate for this partner').toHaveLength(1)
    const cert = certificates[0]!

    expect(cert.document_id, 'certificate linked to the paid invoice').toBe(invoice.id)
    expect(cert.payment_id, 'certificate linked to the payment').toBe(payment.data.id)
    expect(cert.gross_amount, 'gross = invoice total').toBe(GROSS)
    expect(cert.withholding_amount, 'withheld = gross x rate, exact at scale 3').toBe(expectedWithheld)
    expect(cert.net_amount, 'net = gross - withheld').toBe(expectedNet)
    expect(cert.currency).toBe('TND')

    // The headline of MTP-WHT-01: the rate is persisted as the FRACTION at
    // decimal(5,4), never as the percentage projection.
    expect(cert.withholding_rate, 'rate stored as the fraction 0.0150').toBe(RATE)
    expect(cert.withholding_rate, 'rate must NEVER be stored as the percentage 1.50').not.toBe('1.50')
    expect(cert.rate_percentage, 'rate_percentage is the x100 presentation projection').toBe(
      ratePercentage(RATE)
    )
    expect(typeof cert.rate_percentage, 'rate_percentage emitted as a numeric-string, not a float').toBe(
      'string'
    )

    // ---- UI read surface: /treasury/withholding-certificates ----
    await loginAsRole(page, 'owner')
    const listSettled = page.waitForResponse(
      (r) => r.url().includes('/withholding/certificates') && r.request().method() === 'GET'
    )
    await page.goto('/treasury/withholding-certificates')
    await expect(
      page.getByRole('heading', { name: 'Withholding Certificates', level: 1 })
    ).toBeVisible({ timeout: 20_000 })
    // Wait for the list query to settle so the empty state below cannot be the
    // loading skeleton's trivial absence-of-rows.
    await listSettled

    // TICKETED (docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md #4):
    // the page is PERMANENTLY EMPTY. `fetchWithholdingCertificates()`
    // (features/withholding/api/withholdingApi.ts:48) returns `apiGet(url)`, and
    // `apiGet` already unwraps `response.data.data` (lib/api.ts:225-228) — so it
    // resolves to the certificate ARRAY, not the `{data, meta, links}` envelope its
    // declared return type claims. `WithholdingCertificatesList` then reads
    // `data?.data ?? []` (WithholdingCertificatesList.tsx:80) -> `array.data` is
    // `undefined` -> always `[]`. Convention 14 (docs/conventions/01-API-RESPONSES.md)
    // double-unwrap.
    //
    // TRIPWIRE (#4) — GREEN today, pinning the DEFECT: the API returned exactly one
    // row for this partner (asserted above), yet the settled page renders its empty
    // state and no row for the certificate. When the unwrap fix lands this goes RED;
    // the deliberate update is to assert the row IS visible and renders gross
    // 1,000.000 / withheld 15.000 / rate 1.50% at scale 3.
    await expect(
      page.getByText(/no withholding certificates/i),
      'TRIPWIRE (#4): the list renders its empty state while the API holds >= 1 certificate'
    ).toBeVisible({ timeout: 15_000 })
    await expect(
      page.locator('tr', { hasText: cert.certificate_number }),
      'TRIPWIRE (#4): the certificate row must NOT render today (double-unwrap)'
    ).toHaveCount(0)
  })

  test('MTP-WHT-02: certificate DETAIL renders gross/rate/withheld/net at their scales and net == gross - withheld', async ({
    request,
    page,
  }) => {
    const customerId = await createCustomer(request, owner, uniqueName('WHT02'))
    const invoice = await createPostedInvoice(request, owner, customerId, '1000.000', 'W5a WHT-02 invoice')

    const RATE = '0.0150'
    const GROSS = '1000.000'
    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: GROSS,
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: invoice.id, amount: GROSS }],
      withholding_enabled: true,
      withholding_rate: RATE,
    })
    expect(payment.status, `payment create -> ${payment.status} ${JSON.stringify(payment.data)}`).toBe(201)

    const certificates = await certificatesForPartner(request, owner, customerId)
    expect(certificates).toHaveLength(1)
    const listCert = certificates[0]!

    // Detail endpoint must agree with the list endpoint field for field —
    // a detail/list divergence is exactly how a money screen lies.
    const detail = await get(request, owner, `/withholding/certificates/${listCert.id}`)
    expect(detail.ok, `certificate detail -> ${detail.status} ${JSON.stringify(detail.data)}`).toBeTruthy()
    const cert = detail.data as unknown as CertificateRow

    expect(cert.gross_amount, 'gross at currency scale 3').toBe(GROSS)
    expect(cert.gross_amount).toMatch(/^\d+\.\d{3}$/)
    expect(cert.withholding_amount).toMatch(/^\d+\.\d{3}$/)
    expect(cert.net_amount).toMatch(/^\d+\.\d{3}$/)
    expect(cert.withholding_rate, 'rate at decimal(5,4)').toMatch(/^\d\.\d{4}$/)
    expect(cert.rate_percentage, 'rate_percentage at 2dp').toMatch(/^\d+\.\d{2}$/)

    // The identity the case is actually about — computed independently, exact strings.
    expect(cert.net_amount, 'net == gross - withheld (exact, scale 3)').toBe(
      subMoney(cert.gross_amount, cert.withholding_amount)
    )
    expect(cert.withholding_amount, 'withheld == gross x rate (exact, scale 3)').toBe(
      withhold(cert.gross_amount, cert.withholding_rate)
    )
    expect(cert.rate_percentage).toBe(ratePercentage(cert.withholding_rate))

    // ---- UI read surface: /treasury/withholding-certificates/:id ----
    await loginAsRole(page, 'owner')
    await page.goto(`/treasury/withholding-certificates/${listCert.id}`)
    await expect(page.getByText(listCert.certificate_number).first()).toBeVisible({ timeout: 20_000 })

    // The "Amount Breakdown" card — anchored on its own heading so the assertion
    // cannot accidentally pass on a figure rendered elsewhere on the page.
    const breakdown = page.locator('xpath=//h2[contains(text(),"Amount Breakdown")]/..')
    await expect(breakdown, 'gross rendered at scale 3').toContainText(GROSS_1000_RE)
    await expect(breakdown, 'withheld rendered at scale 3').toContainText('15.000')
    await expect(breakdown, 'net rendered at scale 3').toContainText('985.000')
    // MINOR (ticket #5, no tripwire): the API emits `rate_percentage` as the 2dp
    // string "1.50", but `formatPercent()` -> `roundDecimalString()`
    // (lib/format.ts:249-278) TRIMS the trailing zero, so the screen reads
    // "Rate (1.5%)". Arithmetically identical and percent is not currency-scaled
    // (rule 19), so this assertion accepts either rendering rather than pinning a
    // cosmetic today-value that a padding fix would turn red.
    await expect(breakdown, 'rate rendered as the percentage projection').toContainText(/Rate \(1\.50?%\)/)

    // The rendered money must still agree with the API, digit for digit.
    await expect(breakdown, 'the rendered net is the rendered gross minus the rendered withheld').toContainText(
      subMoney(cert.gross_amount, cert.withholding_amount)
    )
  })

  test('MTP-WHT-03: sales-withholding TRACKING — sum of withheld equals the sum of the individual rows exactly', async ({
    request,
    page,
  }) => {
    const customerName = uniqueName('WHT03')
    const customerId = await createCustomer(request, owner, customerName)
    const RATE = '0.0150'
    const grossValues = ['1000.000', '2500.000', '750.000']

    const expectedPerRow = grossValues.map((g) => withhold(g, RATE))
    expect(expectedPerRow, 'fixture arithmetic at scale 3').toEqual(['15.000', '37.500', '11.250'])
    const expectedSum = expectedPerRow.reduce((acc, v) => addMoney(acc, v), '0.000')
    expect(expectedSum).toBe('63.750')

    const documentIds: string[] = []
    for (const gross of grossValues) {
      const invoice = await createPostedInvoice(request, owner, customerId, gross, `W5a WHT-03 ${gross}`)
      documentIds.push(invoice.id)
      const withheld = withhold(gross, RATE)
      const recorded = await post(request, owner, `/documents/${invoice.id}/record-withholding`, {
        customer_id: customerId,
        invoice_amount: gross,
        withholding_rate: RATE,
        withholding_amount: withheld,
        expected_receivable: subMoney(gross, withheld),
        notes: 'W5a MTP-WHT-03 tracking fixture',
      })
      expect(
        recorded.status,
        `record-withholding -> ${recorded.status} ${JSON.stringify(recorded.data)}`
      ).toBe(201)
    }

    // ---- API read surface: GET /sales-withholding ----
    const tracking = await get(request, owner, '/sales-withholding')
    expect(tracking.ok, `sales-withholding -> ${tracking.status}`).toBeTruthy()
    const allRows = tracking.data as unknown as TrackingRow[]
    const rows = allRows.filter((r) => documentIds.includes(r.documentId))
    expect(rows, 'all three tracking rows readable on the tracking surface').toHaveLength(3)

    for (const row of rows) {
      expect(row.customerId).toBe(customerId)
      expect(row.withholdingRate, 'rate persisted as the fraction, not the percentage').toBe(RATE)
      expect(row.withholdingAmount, 'per-row withheld == invoice x rate, exact').toBe(
        withhold(row.invoiceAmount, row.withholdingRate)
      )
      expect(row.expectedReceivable, 'per-row expected receivable == invoice - withheld, exact').toBe(
        subMoney(row.invoiceAmount, row.withholdingAmount)
      )
      expect(row.certificateReceived, 'freshly recorded rows are pending a certificate').toBe(false)
    }

    // The MTP-WHT-03 invariant: the aggregate is the exact sum of the parts —
    // recomputed with integer millimes, never a float reduction.
    const actualSum = rows.reduce((acc, r) => addMoney(acc, r.withholdingAmount), '0.000')
    expect(actualSum, 'sum(withheld) over the tracking surface is exact').toBe(expectedSum)
    const grossSum = rows.reduce((acc, r) => addMoney(acc, r.invoiceAmount), '0.000')
    const receivableSum = rows.reduce((acc, r) => addMoney(acc, r.expectedReceivable), '0.000')
    expect(receivableSum, 'sum(expected receivable) == sum(gross) - sum(withheld)').toBe(
      subMoney(grossSum, actualSum)
    )

    // TICKETED (docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md #2):
    // `POST /documents/{id}/record-withholding` writes ONLY the
    // `sales_withholding_tracking` table — it never creates a
    // `withholding_certificates` row, and nothing else does either for the sales
    // direction. The plan's WHT-03 wording ("Σ withheld matches the sum of the
    // individual CERTIFICATES") therefore cannot be satisfied literally today:
    // the two surfaces are disjoint. Tripwire — goes red when the two are joined.
    const certs = await certificatesForPartner(request, owner, customerId)
    expect(
      certs,
      'TRIPWIRE: recording sales withholding creates NO certificate today (surfaces disjoint)'
    ).toHaveLength(0)

    // ---- UI read surface: /treasury/sales-withholding-tracking ----
    // Tracking rows accumulate across runs and have no delete endpoint, so every
    // assertion anchors on THIS run's customer — a bare amount match (15.000 /
    // 37.500 / 11.250 recur every run) would pass on a previous run's stale rows.
    await loginAsRole(page, 'owner')
    await page.goto('/treasury/sales-withholding-tracking')
    const table = page.locator('table').last()
    await expect(table).toBeVisible({ timeout: 20_000 })
    const runRows = table.locator('tr', { hasText: customerName })
    await expect(runRows, "exactly this run's three rows render for the customer").toHaveCount(3)
    for (const withheldValue of expectedPerRow) {
      await expect(
        runRows.filter({ hasText: withheldValue }),
        `THIS run's row renders withheld ${withheldValue} at scale 3`
      ).toHaveCount(1)
    }
  })

  test('MTP-WHT-04: withholding_rate = 0 settles at full gross — TRIPWIRE(#1): a 0.000 certificate is manufactured today', async ({
    request,
  }) => {
    const customerId = await createCustomer(request, owner, uniqueName('WHT04'))
    const GROSS = '1000.000'
    const invoice = await createPostedInvoice(request, owner, customerId, GROSS, 'W5a WHT-04 invoice')

    const payment = await createPayment(request, owner, {
      partner_id: customerId,
      payment_method_id: cashMethodId,
      repository_id: cashRepoId,
      amount: GROSS,
      currency: 'TND',
      payment_date: TODAY,
      allocations: [{ document_id: invoice.id, amount: GROSS }],
      withholding_enabled: true,
      withholding_rate: '0',
    })
    expect(payment.status, `payment create -> ${payment.status} ${JSON.stringify(payment.data)}`).toBe(201)

    // Snapshot the certificate surface and retire any junk BEFORE any further
    // assertion runs. `finally`-based cleanup was rejected here: an `expect`
    // inside a `finally` masks the try-block's real failure, and a bare
    // `finally` leaves the cleanup unasserted on the failing path — which is
    // exactly the path this case takes today. No assertion sits between the
    // read and the delete, so the probe can never be stranded.
    const certificates = await certificatesForPartner(request, owner, customerId)
    const observed = certificates.map((c) => `${c.certificate_number}:${c.withholding_amount}`)
    // A 0.000-withheld draft is junk, not money — the tenant must not be left
    // holding a live fictitious tax document. EVERY observed certificate is
    // retired, not just the first.
    //
    // Retired by VOIDING, not deleting: `DELETE /withholding/certificates/{id}`
    // on a payment-linked certificate hits
    // `payments_withholding_certificate_id_foreign` and escapes
    // `WithholdingCertificateController::destroy()` (which catches only
    // \DomainException) as a raw 500 that (APP_DEBUG=true) leaks the SQL, host,
    // port and tenant database name — ticket #6. Void is the only working retire
    // path here.
    for (const junk of certificates) {
      const retired = await post(request, owner, `/withholding/certificates/${junk.id}/void`, {
        reason: 'W5a MTP-WHT-04 zero-rate junk certificate retired',
      })
      expect(
        retired.status,
        `junk zero-rate certificate ${junk.certificate_number} retired (voided)`
      ).toBeLessThan(300)
    }

    // "…payment settles at full gross" — true independently of the certificate
    // question: nothing is withheld from the cash actually moved.
    expect(payment.data.status).toBe('completed')
    expect(payment.data.amount, 'payment amount is the full gross').toBe(GROSS)
    expect(payment.data.unallocated_amount, 'the whole payment is allocated').toBe('0.000')
    const settled = await get(request, owner, `/documents/${invoice.id}`)
    expect(settled.data.balance_due, 'invoice settled at full gross, nothing withheld').toBe('0.000')

    // TICKETED (docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md #1):
    // today a zero rate still manufactures a draft certificate for 0.000 withheld,
    // because PaymentController::store():916-940 branches on `withholding_enabled`
    // alone and `WithholdingCalculation::calculate()` has no zero-rate guard.
    // TRIPWIRE (#1) — GREEN today, pinning the DEFECT: exactly one zero-amount
    // certificate is manufactured. When the guard lands this goes RED; the
    // deliberate update is the plan's expected behaviour, `toEqual([])`.
    expect(
      observed,
      'TRIPWIRE (#1): a zero withholding rate manufactures exactly one 0.000 certificate today'
    ).toEqual([expect.stringMatching(/:0\.000$/)])
  })

  test('MTP-WHT-05: TRIPWIRE(#3) — a user without withholding.* can read/create/edit certificates today (routes ungated)', async ({
    request,
  }) => {
    const cashier = await login(request, 'cashier')
    // Precondition proof — the case is only meaningful if the persona genuinely
    // lacks the permissions.
    expect(cashier.permissions, 'cashier must not hold withholding.create').not.toContain(
      'withholding.create'
    )
    expect(cashier.permissions, 'cashier must not hold withholding.update').not.toContain(
      'withholding.update'
    )
    expect(cashier.permissions, 'cashier must not hold withholding.view').not.toContain('withholding.view')

    const partnerId = await createCustomer(request, owner, uniqueName('WHT05'))

    // --- READ probe: the FE route is wrapped in RequirePermission
    // "withholding.view", but no route in Modules/Taxation/routes.php:48-59
    // carries a `can:` middleware for the certificate group.
    const readProbe = await get(request, cashier, '/withholding/certificates')

    // --- CREATE probe. `manual_rate_percentage` is the 0-100 PERCENTAGE domain
    // of the direct-create path (CreateWithholdingCertificateRequest:55), distinct
    // from the payment path's 0-1 fraction — 1.50 here == 0.0150 there.
    const created = await post(request, cashier, '/withholding/certificates', {
      direction: 'purchase',
      partner_id: partnerId,
      currency: 'TND',
      gross_amount: '1000.000',
      manual_rate_percentage: '1.50',
      override_reason: 'W5a MTP-WHT-05 authorization probe',
    })
    const junkCertificateId =
      created.status === 201 ? String((created.data as { id?: string }).id ?? '') : ''

    // --- EDIT probe. Deliberately submits an INVALID (too short) void reason:
    // a `can:` middleware would refuse with 403 BEFORE the FormRequest ran, so a
    // 422 here proves the request reached validation with no authorization gate
    // in front of it — and, unlike a valid void, it mutates NOTHING, so the probe
    // certificate stays a deletable draft.
    let editStatus: number | null = null
    if (junkCertificateId !== '') {
      const voided = await post(request, cashier, `/withholding/certificates/${junkCertificateId}/void`, {
        reason: 'short',
      })
      editStatus = voided.status
    }

    // Retire the junk probe BEFORE the verdict assertions (same rationale as
    // MTP-WHT-04): no `expect` runs between the create and this delete, and an
    // `expect` inside a `finally` would mask the real failure below.
    if (junkCertificateId !== '') {
      const removed = await del(request, owner, `/withholding/certificates/${junkCertificateId}`)
      expect(removed.status, 'junk authorization-probe certificate retired').toBeLessThan(300)
    }

    // TICKETED (docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md #3).
    // The plan's MTP-WHT-05 specifies the create/edit probes only; the read probe
    // is a W-5a extension recording the FE-only `withholding.view` gate.
    // TRIPWIRE (#3) — GREEN today, pinning the DEFECT: no `can:` middleware
    // anywhere on Modules/Taxation/routes.php:48-59, so read is served (200),
    // create succeeds (201), and the deliberately-invalid void reaches FormRequest
    // validation (422 — authorization never refused it; a `can:` gate would have
    // 403'd BEFORE validation ran). Asserted as ONE object so a partial fix
    // reports all three observed statuses at once. When `can:withholding.*`
    // middleware lands this goes RED; the deliberate update is
    // `{ read: 403, create: 403, edit: 403 }`.
    expect(
      { read: readProbe.status, create: created.status, edit: editStatus },
      'TRIPWIRE (#3): certificate routes are unprotected today — read 200 / create 201 / edit(void) 422'
    ).toEqual({ read: 200, create: 201, edit: 422 })
  })
})
