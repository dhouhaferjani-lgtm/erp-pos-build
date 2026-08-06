import { test, expect } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import {
  login,
  authHeaders,
  API_BASE,
  TODAY,
  addMoney,
  subMoney,
  listVatPeriods,
  loginResilient,
  uniqueName,
  type Session,
  type VatPeriodRow,
} from './wx-support'

/**
 * WAVE W-X — §B `TAX` VAT periods + VAT report (`/finance/vat-periods`,
 * `/finance/vat-report/:id`). Cases MTP-TAX-07/09/10/11/12.
 *
 * The VAT-declaration fix lane landed 2026-08-03 (per-rate tax_base buckets,
 * CN negation at aggregation, explicit-0% -> base_0); these cases assert the
 * FIXED behaviour.
 *
 * STATE RESTORATION: generating 2026 periods is idempotent and additive (exit
 * checklist item 2 explicitly permits periods to exist afterward, as long as
 * none blocks future posting). Every period this spec CLOSES is reopened in
 * afterAll. The ONE irreversible footprint is filing the EMPTY January 2026
 * period (MTP-TAX-11 — filing is terminal by design); an empty filed period
 * carries no fiscal data and does not block posting. All of this is documented
 * in the report.
 *
 * Run scoped only: --project=chromium --workers=1.
 */

const YEAR = 2026
const AUG_START = '2026-08-01'
const JAN_START = '2026-01-01'

// millimes helpers over the report's decimal strings
function toM(v: string): bigint {
  const [i, f = ''] = v.replace('-', '').split('.')
  const neg = v.trimStart().startsWith('-')
  const m = BigInt(i) * 1000n + BigInt((f + '000').slice(0, 3))
  return neg ? -m : m
}

interface Breakdown {
  direction: string
  tax_rate: string
  base_amount: string
  vat_amount: string
  document_count: number
  is_recoverable: boolean
  tax_configuration_id: string | null
}
interface PeriodSummary {
  output_vat: { total_base: string; total_vat: string; breakdowns: Breakdown[] }
  input_vat: { total_base: string; total_vat: string; breakdowns: Breakdown[] }
  net_vat: string
  credit_brought_forward: string
  amount_payable: string
}

async function ensurePeriods(request: APIRequestContext, session: Session): Promise<VatPeriodRow[]> {
  let periods = await listVatPeriods(request, session, YEAR)
  if (!periods.find((p) => p.period_start === AUG_START)) {
    const gen = await request.post(`${API_BASE}/vat/periods/generate`, {
      headers: authHeaders(session),
      data: { year: YEAR },
    })
    expect(gen.status(), `generate ${YEAR} periods -> ${gen.status()}`).toBe(201)
    periods = await listVatPeriods(request, session, YEAR)
  }
  return periods
}

async function periodSummary(request: APIRequestContext, session: Session, periodId: string): Promise<PeriodSummary> {
  const res = await request.get(`${API_BASE}/vat/reports/${periodId}/summary`, { headers: authHeaders(session) })
  expect(res.ok(), `period summary -> ${res.status()} ${await res.text()}`).toBeTruthy()
  return (await res.json()).data as PeriodSummary
}

async function periodStatus(request: APIRequestContext, session: Session, periodId: string): Promise<string> {
  const res = await request.get(`${API_BASE}/vat/periods/${periodId}`, { headers: authHeaders(session) })
  expect(res.ok()).toBeTruthy()
  return String(((await res.json()).data as { status: string }).status)
}

async function createCustomer(request: APIRequestContext, session: Session, name: string): Promise<string> {
  const res = await request.post(`${API_BASE}/partners`, { headers: authHeaders(session), data: { name, type: 'customer' } })
  expect(res.ok()).toBeTruthy()
  return String(((await res.json()).data as { id: string }).id)
}

/** Create + confirm + post an invoice dated `date` with one taxed line. Returns id. */
async function postInvoice(request: APIRequestContext, session: Session, partnerId: string, date: string): Promise<{ status: number; id: string | null; body: unknown }> {
  const createRes = await request.post(`${API_BASE}/invoices`, {
    headers: authHeaders(session),
    data: { partner_id: partnerId, document_date: date, lines: [{ description: 'WX vat-period line', quantity: '1', unit_price: '100.000', tax_rate: '19' }] },
  })
  if (!createRes.ok()) return { status: createRes.status(), id: null, body: await createRes.json().catch(() => null) }
  const id = String(((await createRes.json()).data as { id: string }).id)
  const confirmRes = await request.post(`${API_BASE}/invoices/${id}/confirm`, { headers: authHeaders(session) })
  if (!confirmRes.ok()) return { status: confirmRes.status(), id, body: await confirmRes.json().catch(() => null) }
  const postRes = await request.post(`${API_BASE}/invoices/${id}/post`, { headers: authHeaders(session) })
  return { status: postRes.status(), id, body: await postRes.json().catch(() => null) }
}

test.describe.serial('W-X §B VAT periods + report (country-scoped fiscal objects; close/reopen restored)', () => {
  let augId = ''
  let janId = ''
  // Cache sessions once per file: /auth/login is throttled, and this serial
  // suite would otherwise re-login on every test + afterAll and trip a 429.
  let ownerSession: Session
  let cashierSession: Session

  test.beforeAll(async ({ request }) => {
    ownerSession = await loginResilient(request, 'owner')
    cashierSession = await loginResilient(request, 'cashier')
  })

  test.afterAll(async ({ request }) => {
    // RESTORATION: ensure the August period is OPEN (reopen if we left it Closed).
    const session = ownerSession
    if (augId) {
      const st = await periodStatus(request, session, augId)
      if (st === 'CLOSED' || st === 'closed') {
        const re = await request.post(`${API_BASE}/vat/periods/${augId}/reopen`, { headers: authHeaders(session) })
        // 200 expected; if a successor is closed/filed it 422s — but W-X closed none after Aug.
        expect([200, 422]).toContain(re.status())
      }
      const finalStatus = await periodStatus(request, session, augId)
      expect(['OPEN', 'open'], `August period restored to OPEN (was ${finalStatus})`).toContain(finalStatus)
    }
  })

  test('MTP-TAX-07 [P1] period report — per-rate buckets sum to output total; net_vat/amount_payable present', async ({ request }) => {
    const session = ownerSession
    const periods = await ensurePeriods(request, session)
    augId = periods.find((p) => p.period_start === AUG_START)!.id
    janId = periods.find((p) => p.period_start === JAN_START)!.id

    const s = await periodSummary(request, session, augId)
    // Σ per-rate output vat_amount == output total_vat (exact, in millimes).
    const sumOut = s.output_vat.breakdowns.reduce((acc, b) => acc + toM(b.vat_amount), 0n)
    expect(sumOut, 'Σ per-rate output vat == output total_vat').toBe(toM(s.output_vat.total_vat))
    const sumIn = s.input_vat.breakdowns.reduce((acc, b) => acc + toM(b.vat_amount), 0n)
    expect(sumIn, 'Σ per-rate input vat == input total_vat').toBe(toM(s.input_vat.total_vat))
    // net_vat == output.total_vat - input.total_vat
    expect(s.net_vat, 'net_vat == output - input').toBe(subMoney(s.output_vat.total_vat, s.input_vat.total_vat))
    // Every breakdown carries the declaration fields.
    for (const b of s.output_vat.breakdowns) {
      expect(b.base_amount, 'base_amount present').toBeTruthy()
      expect(typeof b.document_count, 'document_count is a number').toBe('number')
      expect(typeof b.is_recoverable, 'is_recoverable present').toBe('boolean')
    }
    expect(s.amount_payable, 'amount_payable present').toBeTruthy()
    expect(s.credit_brought_forward, 'credit_brought_forward present').toBeTruthy()
  })

  test('MTP-TAX-09 [P0] close period -> persisted snapshot frozen; posting into it does not move the snapshot', async ({ request }) => {
    const session = ownerSession
    expect(augId, 'August period id resolved by TAX-07').toBeTruthy()

    const before = await periodSummary(request, session, augId)
    const snapVatBefore = before.output_vat.total_vat

    // CLOSE (reports.manage).
    const close = await request.post(`${API_BASE}/vat/periods/${augId}/close`, { headers: authHeaders(session) })
    expect(close.status(), `close August -> ${close.status()} ${await close.text()}`).toBe(200)
    expect(await periodStatus(request, session, augId)).toMatch(/closed/i)

    // Persisted snapshot right after close.
    const snapAfterClose = (await periodSummary(request, session, augId)).output_vat.total_vat

    // Post a NEW invoice INTO the closed August period.
    const partnerId = await createCustomer(request, session, uniqueName('TAX09'))
    const posted = await postInvoice(request, session, partnerId, TODAY)
    expect([200, 201], `posting into a closed period is allowed -> got ${posted.status} ${JSON.stringify(posted.body)}`).toContain(posted.status)

    // Snapshot must NOT move (closed period returns the persisted snapshot).
    const snapAfterPost = (await periodSummary(request, session, augId)).output_vat.total_vat
    expect(snapAfterPost, 'closed-period snapshot frozen after posting').toBe(snapAfterClose)
    expect(toM(snapAfterClose), 'snapshot captured a nonzero output VAT').toBeGreaterThan(0n)
    // record the pre-close vs snapshot relationship
    expect(snapVatBefore, 'live pre-close == snapshot at close (nothing posted in between)').toBe(snapAfterClose)
  })

  test('MTP-TAX-10 [P1] reopen -> live query resumes and now includes the newly posted invoice', async ({ request }) => {
    const session = ownerSession
    const frozen = (await periodSummary(request, session, augId)).output_vat.total_vat

    const reopen = await request.post(`${API_BASE}/vat/periods/${augId}/reopen`, { headers: authHeaders(session) })
    expect(reopen.status(), `reopen August -> ${reopen.status()} ${await reopen.text()}`).toBe(200)
    expect(await periodStatus(request, session, augId)).toMatch(/open/i)

    // Live query now reflects the invoice posted while closed: strictly greater,
    // and greater by at least the 19.000 line VAT of that one invoice.
    const live = (await periodSummary(request, session, augId)).output_vat.total_vat
    expect(toM(live), 'reopened live output VAT > frozen snapshot').toBeGreaterThan(toM(frozen))
    expect(toM(live) - toM(frozen), 'live includes the +19.000 line VAT of the posted invoice').toBeGreaterThanOrEqual(toM('19.000'))
  })

  test('MTP-TAX-11 [P1] file an EMPTY period (irreversible); a filed period cannot be reopened', async ({ request }) => {
    const session = ownerSession
    expect(janId, 'January period id resolved').toBeTruthy()
    // January 2026 is empty (no documents) — filing it freezes nothing fiscal.
    const janSummary = await periodSummary(request, session, janId)
    expect(janSummary.output_vat.total_vat, 'January is empty (no output VAT)').toBe('0.000')

    // Idempotent across re-runs: filing is terminal, so on a second run January
    // is already FILED — assert the end state either way.
    const st0 = await periodStatus(request, session, janId)
    if (/open/i.test(st0)) {
      const close = await request.post(`${API_BASE}/vat/periods/${janId}/close`, { headers: authHeaders(session) })
      expect(close.status(), `close January -> ${close.status()} ${await close.text()}`).toBe(200)
    }
    if (!/filed/i.test(await periodStatus(request, session, janId))) {
      const file = await request.post(`${API_BASE}/vat/periods/${janId}/file`, {
        headers: authHeaders(session),
        data: { filing_reference: uniqueName('TAX11-ref') },
      })
      expect(file.status(), `file January -> ${file.status()} ${await file.text()}`).toBe(200)
    }
    expect(await periodStatus(request, session, janId)).toMatch(/filed/i)

    // A FILED period cannot be reopened (only CLOSED can) — record the answer.
    const reopen = await request.post(`${API_BASE}/vat/periods/${janId}/reopen`, { headers: authHeaders(session) })
    expect(reopen.status(), 'reopen of a FILED period is refused (422)').toBe(422)
    expect(String((await reopen.json()).message)).toMatch(/only closed periods can be reopened/i)
  })

  test('MTP-TAX-12 [P1] mutation gate — non-manage role gets 403 on close/reopen/file; reads open to a reader', async ({ request }) => {
    const owner = ownerSession
    const cashier = cashierSession
    expect(augId).toBeTruthy()

    // Post W-6 D5 split: reads require reports.financial (admin/accountant),
    // mutation requires reports.manage (admin/accountant only — manager lost it
    // in the I-1 fix; cashier/technician never had either). The gate is proven
    // as a SPLIT: mutation requires reports.manage (cashier, lacking it, is
    // refused), reads require reports.financial (owner/admin, holding it, is
    // allowed).
    const close = await request.post(`${API_BASE}/vat/periods/${augId}/close`, { headers: authHeaders(cashier) })
    const reopen = await request.post(`${API_BASE}/vat/periods/${augId}/reopen`, { headers: authHeaders(cashier) })
    const file = await request.post(`${API_BASE}/vat/periods/${augId}/file`, { headers: authHeaders(cashier), data: { filing_reference: 'x' } })
    expect(close.status(), 'cashier close -> 403 (no reports.manage)').toBe(403)
    expect(reopen.status(), 'cashier reopen -> 403').toBe(403)
    expect(file.status(), 'cashier file -> 403').toBe(403)

    // Reader (owner holds reports.view) can read the period + report.
    const readPeriods = await request.get(`${API_BASE}/vat/periods`, { headers: authHeaders(owner) })
    expect(readPeriods.status(), 'owner reads periods -> 200').toBe(200)
    const readReport = await request.get(`${API_BASE}/vat/reports/${augId}/summary`, { headers: authHeaders(owner) })
    expect(readReport.status(), 'owner reads report -> 200').toBe(200)

    // No mutation happened (August still OPEN after cashier's refused attempts).
    expect(await periodStatus(request, owner, augId)).toMatch(/open/i)
    // money-string sanity guard
    expect(addMoney('0.000', '19.000')).toBe('19.000')
  })
})
