/**
 * MONEY TEST CAMPAIGN — wave W-6 (Finance / GL): `MTP-GL-01..23`, `MTP-GL-26..28`.
 *
 * Support helpers ONLY for W-6's own spec files. Talks to the LIVE local stack
 * (web :5173 -> api :8010, tenant `demo-pharmacy-tn`). No mocks anywhere.
 *
 * FIXTURE DISCIPLINE (brief §"CRITICAL", and the left-behind sections of
 * `docs/sessions/MONEY-CAMPAIGN-RESULTS.md` for W-4 / W-5a / W-5b / W-5c):
 * this tenant carries FIVE waves of deliberate, documented money. Accounts
 * `613`, `512`, `401`, `53`, `65`, `4456`, `6580`, `7580`, `706`, `411`, `37`,
 * `119` are ALL polluted, and the pollution GROWS on every sibling re-run. So:
 *
 *   - NEVER assert a global tenant total as a constant.
 *   - Assert either (a) a per-account / per-partner amount THIS wave created,
 *     or (b) a DELTA captured immediately around this wave's own mutation, or
 *     (c) a scale-free INVARIANT (Σdebits == Σcredits; Σbuckets == total).
 *
 * W-6 lands every journal entry it posts on its OWN dedicated accounts
 * (`W6GL1`/`W6GL2`/`W6PL1`/`W6PL2`/`W6COA`, created idempotently by
 * {@link ensureAccount}) so a successor wave can subtract this wave exactly.
 * Nothing `W4`-prefixed is read destructively and nothing `W4`/`W5b`/`W5C`
 * prefixed is mutated.
 */
import type { APIRequestContext, Page } from '@playwright/test'
import { expect } from '@playwright/test'
import {
  API_BASE,
  authHeaders,
  get,
  post,
  subMoney,
  type JsonResult,
  type Session,
} from './treasury-support'

export const PREFIX = 'W6'

/** Uniqueness across concurrent test runs / re-runs against the live stack. */
export function uniq(label: string): string {
  return `${PREFIX}-${label}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
}

export const TODAY = new Date().toISOString().slice(0, 10)

/** `YYYY-MM-DD`, `days` before today (UTC-safe, no float date math). */
export function daysAgo(days: number): string {
  const d = new Date(`${TODAY}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() - days)
  return d.toISOString().slice(0, 10)
}

/** `YYYY-MM-DD`, `days` after today. */
export function daysAhead(days: number): string {
  return daysAgo(-days)
}

/**
 * `'119.000'` -> `'119.0000'`. The GL reports (ledger / trial balance / aged
 * reports / P&L / balance sheet) render money at SCALE 4, one decimal more than
 * the treasury + document layer's scale 3. Keeping every literal written ONCE
 * at the canonical scale 3 and widening here is the campaign-wide convention
 * (originally `w5c-support.ts`).
 */
export function scale4(scale3: string): string {
  expect(scale3, `scale4() expects an exact scale-3 string, got ${scale3}`).toMatch(/^-?\d+\.\d{3}$/)
  return `${scale3}0`
}

// ---------------------------------------------------------------------------
// exact-string decimal arithmetic at report scale 4 (integer 1/10 000 units)
// ---------------------------------------------------------------------------

function toTenThousandths(value: string): bigint {
  expect(value, `expected a decimal string, got ${value}`).toMatch(/^-?\d+(\.\d{1,4})?$/)
  const [intPart, fracPart = ''] = value.split('.')
  const negative = intPart.trimStart().startsWith('-')
  const intAbs = intPart.replace('-', '')
  const frac = (fracPart + '0000').slice(0, 4)
  const magnitude = BigInt(intAbs) * 10_000n + BigInt(frac)
  return negative ? -magnitude : magnitude
}

function fromTenThousandths(value: bigint): string {
  const negative = value < 0n
  const abs = negative ? -value : value
  const intPart = abs / 10_000n
  const frac = (abs % 10_000n).toString().padStart(4, '0')
  return `${negative ? '-' : ''}${intPart.toString()}.${frac}`
}

/** Exact scale-4 addition. Never a float on money (CLAUDE.md rule 19). */
export function add4(a: string, b: string): string {
  return fromTenThousandths(toTenThousandths(a) + toTenThousandths(b))
}

/** Exact scale-4 subtraction. */
export function sub4(a: string, b: string): string {
  return fromTenThousandths(toTenThousandths(a) - toTenThousandths(b))
}

/** Normalises any scale-<=4 decimal string to exactly 4 decimals for comparison. */
export function norm4(value: string): string {
  return fromTenThousandths(toTenThousandths(value))
}

// ---------------------------------------------------------------------------
// Chart of accounts
// ---------------------------------------------------------------------------

export interface AccountRow {
  id: string
  code: string
  name: string
  type: string
  is_active: boolean
}

/**
 * W-6's dedicated GL landing zone. Same pattern (and same rationale) as W-4's
 * `W4GL1`/`W4GL2`: every journal entry this wave POSTS is permanent — there is
 * no delete route for a journal entry — so it must land somewhere a successor
 * can isolate. `accounts` has no delete route either, so these five rows are
 * created ONCE and reused by every later run.
 */
export const W6_ACCOUNTS = {
  debit: { code: 'W6GL1', name: 'W6 campaign GL debit', type: 'asset' },
  credit: { code: 'W6GL2', name: 'W6 campaign GL credit', type: 'liability' },
  expense: { code: 'W6PL2', name: 'W6 campaign P&L expense', type: 'expense' },
  /** The `MTP-GL-20` chart-of-accounts CRUD subject. Never posted to. */
  coa: { code: 'W6COA', name: 'W6 campaign CoA subject', type: 'asset' },
} as const

export async function listAccounts(
  request: APIRequestContext,
  session: Session,
  search?: string,
): Promise<AccountRow[]> {
  const res = await get(request, session, `/accounts${search !== undefined ? `?search=${encodeURIComponent(search)}` : ''}`)
  expect(res.ok, `GET /accounts -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return (res.data as unknown as AccountRow[]) ?? []
}

export async function findAccountByCode(
  request: APIRequestContext,
  session: Session,
  code: string,
): Promise<AccountRow | undefined> {
  const rows = await listAccounts(request, session, code)
  return rows.find((a) => a.code === code)
}

/** Idempotent: returns the existing account with this code, or creates it. */
export async function ensureAccount(
  request: APIRequestContext,
  session: Session,
  spec: { code: string; name: string; type: string },
): Promise<AccountRow> {
  const existing = await findAccountByCode(request, session, spec.code)
  if (existing !== undefined) return existing

  const res = await post(request, session, '/accounts', {
    code: spec.code,
    name: spec.name,
    type: spec.type,
    is_active: true,
  })
  expect(res.status, `create account ${spec.code} -> ${res.status} ${JSON.stringify(res.data)}`).toBe(201)
  return res.data as unknown as AccountRow
}

// ---------------------------------------------------------------------------
// Journal entries
// ---------------------------------------------------------------------------

export interface JournalLinePayload {
  account_id: string
  debit: string
  credit: string
  description?: string
}

export async function createJournalEntry(
  request: APIRequestContext,
  session: Session,
  payload: {
    entry_date: string
    description?: string
    lines: JournalLinePayload[]
  },
): Promise<JsonResult> {
  return post(request, session, '/journal-entries', payload as unknown as Record<string, unknown>)
}

export async function postJournalEntry(
  request: APIRequestContext,
  session: Session,
  entryId: string,
): Promise<JsonResult> {
  return post(request, session, `/journal-entries/${entryId}/post`)
}

/** The first page of `GET /journal-entries` (20 newest by entry_date desc). */
export async function recentJournalEntries(
  request: APIRequestContext,
  session: Session,
): Promise<Array<{ id: string; entry_number: string; description: string | null; status: string }>> {
  const res = await request.get(`${API_BASE}/journal-entries`, { headers: authHeaders(session) })
  expect(res.ok(), `GET /journal-entries -> ${res.status()}`).toBeTruthy()
  return (await res.json()).data
}

// ---------------------------------------------------------------------------
// Reports
// ---------------------------------------------------------------------------

export interface TrialBalanceLine {
  account_code: string
  account_name: string
  account_type: string
  debit: string
  credit: string
  level: number
  is_parent: boolean
}

export interface TrialBalance {
  lines: TrialBalanceLine[]
  total_debit: string
  total_credit: string
  is_balanced: boolean
  as_of_date: string
}

export async function trialBalance(
  request: APIRequestContext,
  session: Session,
  query = '',
): Promise<TrialBalance> {
  const res = await get(request, session, `/reports/trial-balance${query}`)
  expect(res.ok, `trial-balance -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as TrialBalance
}

/** The trial-balance row for one account code, or `undefined` when the account
 * has a zero balance (zero-balance rows are filtered out by default). */
export function tbLine(tb: TrialBalance, code: string): TrialBalanceLine | undefined {
  return tb.lines.find((l) => l.account_code === code)
}

/** A trial-balance account's net debit side, treating an absent (zero) row as `'0.0000'`. */
export function tbDebit(tb: TrialBalance, code: string): string {
  return norm4(tbLine(tb, code)?.debit ?? '0.0000')
}

export function tbCredit(tb: TrialBalance, code: string): string {
  return norm4(tbLine(tb, code)?.credit ?? '0.0000')
}

export interface ReportAmountLine {
  account_code: string
  account_name: string
  account_type: string
  amount: string
  level: number
  is_parent: boolean
}

export interface BalanceSheet {
  assets: ReportAmountLine[]
  liabilities: ReportAmountLine[]
  equity: ReportAmountLine[]
  total_assets: string
  total_liabilities: string
  total_equity: string
  retained_earnings: string
  is_balanced: boolean
  as_of_date: string
}

export async function balanceSheet(
  request: APIRequestContext,
  session: Session,
  query = '',
): Promise<BalanceSheet> {
  const res = await get(request, session, `/reports/balance-sheet${query}`)
  expect(res.ok, `balance-sheet -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as BalanceSheet
}

export interface ProfitLoss {
  revenue: ReportAmountLine[]
  expenses: ReportAmountLine[]
  total_revenue: string
  total_expenses: string
  net_income: string
  date_from: string
  date_to: string
}

export async function profitLoss(
  request: APIRequestContext,
  session: Session,
  from: string,
  to: string,
): Promise<ProfitLoss> {
  const res = await get(request, session, `/reports/profit-loss?date_from=${from}&date_to=${to}`)
  expect(res.ok, `profit-loss -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as ProfitLoss
}

/** A P&L account's own amount, treating an absent (zero-balance) row as `'0.0000'`. */
export function plAmount(pl: ProfitLoss, code: string): string {
  const line = [...pl.revenue, ...pl.expenses].find((l) => l.account_code === code && !l.is_parent)
  return norm4(line?.amount ?? '0.0000')
}

export interface AgedLine {
  customer_id?: string
  customer_name?: string
  vendor_id?: string
  vendor_name?: string
  current: string
  days_30: string
  days_60: string
  days_90: string
  over_90: string
  total: string
}

export interface AgedReport {
  as_of_date: string
  lines: AgedLine[]
  total_current: string
  total_days_30: string
  total_days_60: string
  total_days_90: string
  total_over_90: string
  grand_total: string
}

export async function agedReceivables(
  request: APIRequestContext,
  session: Session,
  query = '',
): Promise<AgedReport> {
  const res = await get(request, session, `/reports/aged-receivables${query}`)
  expect(res.ok, `aged-receivables -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as AgedReport
}

export async function agedPayables(
  request: APIRequestContext,
  session: Session,
  query = '',
): Promise<AgedReport> {
  const res = await get(request, session, `/reports/aged-payables${query}`)
  expect(res.ok, `aged-payables -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as AgedReport
}

/** The AR/AP line for one partner name, or `undefined` when absent. */
export function agedLineFor(report: AgedReport, partnerName: string): AgedLine | undefined {
  return report.lines.find((l) => (l.customer_name ?? l.vendor_name) === partnerName)
}

/** Which single bucket an aged line put its money in. Fails if the money is
 * spread over more than one bucket (`MTP-GL-13`: exactly one bucket per line). */
export const AGED_BUCKETS = ['current', 'days_30', 'days_60', 'days_90', 'over_90'] as const
export type AgedBucket = (typeof AGED_BUCKETS)[number]

export function occupiedBuckets(line: AgedLine): AgedBucket[] {
  return AGED_BUCKETS.filter((b) => toTenThousandths(line[b]) !== 0n)
}

/** Σ of the five buckets on one line, as an exact scale-4 string. */
export function bucketSum(line: AgedLine): string {
  return AGED_BUCKETS.reduce((acc, b) => add4(acc, line[b]), '0.0000')
}

export interface FinanceSummary {
  total_assets: string
  total_liabilities: string
  total_equity: string
  net_income_mtd: string
  net_income_ytd: string
  accounts_receivable: string
  accounts_payable: string
}

export async function financeSummary(
  request: APIRequestContext,
  session: Session,
): Promise<FinanceSummary> {
  const res = await get(request, session, '/reports/finance-summary')
  expect(res.ok, `finance-summary -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as FinanceSummary
}

export interface LedgerLine {
  id: string
  date: string
  entry_number: string
  description: string
  account_code: string
  account_name: string
  partner_name: string | null
  debit: string
  credit: string
  balance: string
  source_type: string
  source_id: string
}

export interface LedgerPage {
  data: {
    opening_balance: string
    closing_balance: string
    total_debits: string
    total_credits: string
    lines: LedgerLine[]
    date_from: string | null
    date_to: string
    account_filter: string | null
    partner_filter: string | null
  }
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

/**
 * `GET /ledger` returns `{data, meta}`; `treasury-support.get()` collapses to
 * `data` and would drop `meta`, so this reads the raw envelope.
 */
export async function ledger(
  request: APIRequestContext,
  session: Session,
  query: string,
): Promise<LedgerPage> {
  const res = await request.get(`${API_BASE}/ledger?${query}`, { headers: authHeaders(session) })
  expect(res.ok(), `ledger -> ${res.status()} ${await res.text()}`).toBeTruthy()
  return (await res.json()) as LedgerPage
}

// ---------------------------------------------------------------------------
// Documents (aged-report fixtures)
// ---------------------------------------------------------------------------

/**
 * A Posted customer invoice whose final `total` / `balance_due` is exactly
 * `targetTotal`, dated `documentDate` and due on `dueDate`.
 *
 * Same stamp-duty mechanism as `treasury-support.createPostedInvoice()`: the
 * single line carries `tax_rate '0.00'` (no line VAT) and is priced at
 * `targetTotal - 1.000` to absorb the Tunisia document-level stamp duty that
 * `confirm()` applies. `targetTotal` must therefore be > `1.000`.
 *
 * DIFFERENCE from the treasury helper: it takes explicit dates. `MTP-GL-14`
 * needs invoices whose age is exactly 30 / 31 / 60 / 61 / 90 / 91 days, and
 * `due_date` is validated `after_or_equal:document_date`
 * (`CreateDocumentRequest.php:79`) — so an overdue fixture must backdate BOTH.
 */
export async function createPostedInvoiceDated(
  request: APIRequestContext,
  session: Session,
  partnerId: string,
  targetTotal: string,
  documentDate: string,
  dueDate: string,
  description: string,
): Promise<{ id: string; total: string; documentNumber: string }> {
  const unitPrice = subMoney(targetTotal, '1.000')
  const createRes = await post(request, session, '/invoices', {
    partner_id: partnerId,
    document_date: documentDate,
    due_date: dueDate,
    lines: [{ description, quantity: '1', unit_price: unitPrice, tax_rate: '0.00' }],
  })
  expect(createRes.ok, `create invoice -> ${createRes.status} ${JSON.stringify(createRes.data)}`).toBeTruthy()
  const invoiceId = String((createRes.data as { id: string }).id)

  const confirmRes = await post(request, session, `/invoices/${invoiceId}/confirm`)
  expect(confirmRes.ok, `confirm invoice -> ${confirmRes.status} ${JSON.stringify(confirmRes.data)}`).toBeTruthy()

  const postRes = await post(request, session, `/invoices/${invoiceId}/post`)
  expect(postRes.ok, `post invoice -> ${postRes.status} ${JSON.stringify(postRes.data)}`).toBeTruthy()
  const posted = postRes.data as { total: string; balance_due: string; document_number: string }
  expect(posted.total, `posted invoice total matches target ${targetTotal}`).toBe(targetTotal)
  expect(posted.balance_due).toBe(targetTotal)

  return { id: invoiceId, total: posted.total, documentNumber: posted.document_number }
}

// ---------------------------------------------------------------------------
// Cleanup (2xx-gated — NEVER `status < 300`, see statement-support.ts)
// ---------------------------------------------------------------------------

/**
 * Sentinel for a cleanup that THREW rather than answering with a status.
 * Deliberately >= 300 and outside the real HTTP range so a `< 300` gate can
 * never pass it silently (`statement-support.ts` fix round 2 / N-1).
 */
export const CLEANUP_THREW = 599

/** True only for a real 2xx. Never write `status < 300` for a cleanup gate. */
export function isCleanupSuccess(status: number): boolean {
  return status >= 200 && status < 300
}

/**
 * Retires a DRAFT invoice. Does NOT throw — returns the observed status, or
 * {@link CLEANUP_THREW}. A cleanup `expect` inside a `finally` would REPLACE an
 * in-flight exception from the test body, so callers assert these statuses only
 * on the path where the body already succeeded.
 *
 * `DELETE /documents/{id}` does not exist (405); `DELETE /invoices/{id}` is the
 * real route, and it refuses anything past draft.
 */
export async function retireDraftInvoice(
  request: APIRequestContext,
  session: Session,
  invoiceId: string,
): Promise<number> {
  try {
    const res = await request.delete(`${API_BASE}/invoices/${invoiceId}`, { headers: authHeaders(session) })
    return res.status()
  } catch {
    return CLEANUP_THREW
  }
}

/** Retires a partner. Does NOT throw. A partner carrying posted documents is
 * not deletable — that refusal is reported, never swallowed into "success". */
export async function retirePartner(
  request: APIRequestContext,
  session: Session,
  partnerId: string,
): Promise<number> {
  try {
    const res = await request.delete(`${API_BASE}/partners/${partnerId}`, { headers: authHeaders(session) })
    return res.status()
  } catch {
    return CLEANUP_THREW
  }
}

// ---------------------------------------------------------------------------
// UI helpers (the handful of W-6 cases that assert the rendered surface)
// ---------------------------------------------------------------------------

/**
 * Wait past BOTH loading layers this app shows after a navigation: the
 * top-level shell spinner (lazy route chunk / provider bootstrap) and
 * `RequirePermission`'s synchronous redirect once mounted.
 *
 * Regex, not a literal: the app shows several distinct loading strings
 * ("Loading...", "Loading companies…", "Loading cash position...") and matching
 * only one of them was the exact cause of an earlier flake on `MTP-PERM-04`
 * (`permissions.spec.ts:12-18`, whose helper this mirrors).
 */
export async function settleAfterNav(page: Page): Promise<void> {
  await expect(page.locator('body')).not.toContainText(/loading/i, { timeout: 30_000 })
}

/**
 * The value rendered next to a label, for the two money-tile shapes on
 * `/finance/overview`: `StatCard` (`<p>label</p><p>value</p>`) and
 * `FinanceWidget` (`<div>label</div><div>value</div>`). Both put the value in
 * the IMMEDIATELY following sibling of the label element.
 */
export async function tileValue(page: Page, label: string): Promise<string> {
  const value = page
    .getByText(label, { exact: true })
    .first()
    .locator('xpath=following-sibling::*[1]')
  await expect(value, `tile "${label}" renders a value`).toBeVisible({ timeout: 20_000 })
  return (await value.innerText()).trim()
}
