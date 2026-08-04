/**
 * MONEY TEST CAMPAIGN — wave W-5c — expenses, income and partner deposits
 * (`MTP-TRE-60..77`, `MTP-DEP-01..03`; plan §B.5 rows 71–74).
 *
 * Support helpers ONLY for W-5c's own spec files. Live local stack
 * (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real login, real backend,
 * no mocks anywhere. Money is compared as EXACT decimal strings using the integer
 * millime arithmetic already proven by `treasury-support.ts` (`addMoney`/`subMoney`) —
 * never a float, never `toBeCloseTo`.
 *
 * Everything W-5c authors carries the `W5C-` prefix (vendor names, income source
 * names, partner names, product SKUs, recurrence template names) so a single
 * predicate excludes the whole wave's footprint downstream — see the wave report's
 * "State left behind (for W-6)".
 *
 * WHY THE API LAYER: the expense/income/deposit money legs are multi-step server
 * flows (create -> post -> pay -> reverse; deposit -> FIFO allocation -> balances)
 * whose money effects live in `repository_movements`, `journal_lines` and partner
 * balances — none of which the UI renders at scale-exact precision. Driving them
 * through `request` is the same choice `treasury-support.ts` documented, and the
 * one UI-shaped requirement in this wave (`/expenses/*` reachability under a
 * cashier) is asserted through `page` in the spec that needs it.
 */
import { execSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import type { APIRequestContext } from '@playwright/test'
import { expect } from '@playwright/test'
import { API_BASE, authHeaders, get, post, type JsonResult, type Session } from './treasury-support'

export const PREFIX = 'W5C'

/** Seeded fixtures pinned BY CODE, never by array position (W-5b M-6): a bare
 * `find()!` silently retargets the moment the fixture set changes. */
export const CASH_REPOSITORY_CODE = 'CASH-01'
export const BANK_REPOSITORY_CODE = 'BANK-01'
export const CHEQUE_METHOD_CODE = 'CHECK'
export const CASH_METHOD_CODE = 'CASH'

/** demo-pharmacy-tn warehouse + unit fixtures (resolved 2026-08-01 by W2b,
 * re-verified live 2026-08-04 for `MTP-TRE-71`'s purchase chain). */
export const WAREHOUSE_LOCATION_ID = 'a38d315f-b457-4bd1-b8de-b83035f90ddb'
export const PIECE_UNIT_ID = '019fbe86-a6c8-72e5-bf30-1778f7253fd9'

export const TODAY = new Date().toISOString().slice(0, 10)

export function uniq(label: string): string {
  return `${PREFIX}-${label}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
}

// ---------------------------------------------------------------------------
// GL / treasury readers
// ---------------------------------------------------------------------------

export interface LedgerLine {
  id: string
  date: string
  entry_number: string
  description: string
  account_code: string
  account_name: string
  partner_name: string | null
  /** NOTE: the ledger renders money at SCALE 4 (`'119.0000'`), not the scale-3
   * treasury/document rendering. Assert against `scale4()` of an exact
   * scale-3 string, never against the scale-3 literal. */
  debit: string
  credit: string
  balance: string
  source_type: string
  source_id: string
}

export interface MovementRow {
  id: string
  direction: string
  amount: string
  currency: string
  balance_after: string
  ordinal: number
  source_type: string
  source_id: string
  journal_entry_id: string | null
}

/** `'119.000'` -> `'119.0000'`. The GL ledger endpoint renders one extra
 * decimal than the treasury layer; this keeps every literal in a spec written
 * ONCE, at the canonical scale 3. */
export function scale4(scale3: string): string {
  expect(scale3, `scale4() expects an exact scale-3 string, got ${scale3}`).toMatch(/^-?\d+\.\d{3}$/)
  return `${scale3}0`
}

/** All system account purposes for the session's company, keyed by purpose. */
export async function accountsByPurpose(
  request: APIRequestContext,
  session: Session,
): Promise<Record<string, { id: string; code: string; name: string }>> {
  const res = await get(request, session, `/companies/${session.companyId}/accounts/purposes`)
  expect(res.ok, `account purposes -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  const accounts = (res.data as unknown as {
    accounts: Array<{ id: string; code: string; name: string; system_purpose: string | null }>
  }).accounts
  const map: Record<string, { id: string; code: string; name: string }> = {}
  for (const account of accounts) {
    if (account.system_purpose !== null) {
      map[account.system_purpose] = { id: account.id, code: account.code, name: account.name }
    }
  }
  return map
}

/**
 * The GL lines one document produced on ONE account, on one day.
 *
 * There is no `source_id` filter on `GET /journal-entries` (it paginates the 20
 * newest entries, unfiltered), so the ledger report — which DOES carry
 * `source_type`/`source_id` per line — is the only API-layer way to reach a
 * specific document's GL legs. Narrowing by `account_id` keeps the page well
 * inside `per_page` on a shared, heavily-exercised tenant.
 */
export async function ledgerLinesFor(
  request: APIRequestContext,
  session: Session,
  accountId: string,
  sourceId: string,
  date: string = TODAY,
): Promise<LedgerLine[]> {
  const res = await get(
    request,
    session,
    `/ledger?account_id=${accountId}&date_from=${date}&date_to=${date}&per_page=200`,
  )
  expect(res.ok, `ledger -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  const lines = (res.data as unknown as { lines: LedgerLine[] }).lines
  return lines.filter((line) => line.source_id === sourceId)
}

/** The append-only repository movements a given document produced (`search=`
 * matches `source_id`, `RepositoryMovementController::index`). */
export async function movementsFor(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
  sourceId: string,
): Promise<MovementRow[]> {
  const res = await get(
    request,
    session,
    `/payment-repositories/${repositoryId}/movements?search=${sourceId}`,
  )
  expect(res.ok, `movements -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as MovementRow[]
}

export async function repositoryBalance(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
): Promise<string> {
  const res = await get(request, session, `/payment-repositories/${repositoryId}`)
  expect(res.ok, `repository -> ${res.status}`).toBeTruthy()
  const balance = String((res.data as { balance: string }).balance)
  expect(balance, 'repository balances render at scale 3').toMatch(/^-?\d+\.\d{3}$/)
  return balance
}

export async function repositoryIdByCode(
  request: APIRequestContext,
  session: Session,
  code: string,
): Promise<string> {
  const res = await get(request, session, '/payment-repositories')
  expect(res.ok).toBeTruthy()
  const repositories = res.data as unknown as Array<{ id: string; code: string; is_active: boolean }>
  const found = repositories.find((r) => r.code === code)
  expect(
    found,
    `W-5c requires the seeded repository '${code}'. Present: ${repositories
      .filter((r) => r.is_active)
      .map((r) => r.code)
      .join(', ')}`,
  ).toBeTruthy()
  return found!.id
}

/**
 * EVERY payment id for the session's company, SORTED.
 *
 * `PaymentController::index` branches on the `page` parameter: **without** `page`
 * it takes the `->get()` branch and returns the WHOLE company payment set (no
 * pagination, no `meta`); **with** `?page=` it paginates and does carry
 * `meta.total`. This helper deliberately omits `page`, so the comparison it feeds
 * is a FULL-SET comparison — not a "page 1 didn't change" heuristic. That is what
 * makes the plan's "**No** `Payment` entity created" caveat (§B.5 row 71)
 * assertable exactly: any new payment row anywhere in the set changes the result.
 *
 * SORTED because the index orders by `payment_date` DESC with no tiebreaker, and
 * that column is date-only — two payments on the same date may come back in either
 * order between two reads, which would flake an ordered comparison.
 */
export async function allPaymentIds(
  request: APIRequestContext,
  session: Session,
): Promise<string[]> {
  const res = await get(request, session, '/payments')
  expect(res.ok, `payments index -> ${res.status}`).toBeTruthy()
  return (res.data as unknown as Array<{ id: string }>).map((p) => p.id).sort()
}

export async function paymentMethodIdByCode(
  request: APIRequestContext,
  session: Session,
  code: string,
): Promise<string> {
  const res = await get(request, session, '/payment-methods')
  expect(res.ok).toBeTruthy()
  const methods = res.data as unknown as Array<{ id: string; code: string }>
  const found = methods.find((m) => m.code === code)
  expect(found, `W-5c requires the seeded payment method '${code}'`).toBeTruthy()
  return found!.id
}

// ---------------------------------------------------------------------------
// Expenses
// ---------------------------------------------------------------------------

export interface ExpenseBody {
  id: string
  status: string
  document_number: string | null
  document_date: string
  subtotal: string
  tax_amount: string | null
  total: string
  currency: string
  metadata: {
    vendor_name: string | null
    is_paid: boolean
    paid_at: string | null
    payment_repository_id: string | null
    payment_instrument_id: string | null
    recurrence_template_id: string | null
    expense_kind: string
    vat_rate: string | null
    vat_deductible_percent: string | null
  }
}

/**
 * `ExpenseService::create()` defaults `is_paid` to TRUE when the field is
 * omitted (`ExpenseService.php:146`, `$data['is_paid'] ?? true`). Every
 * unpaid-AP fixture in this wave therefore passes `is_paid: false`
 * EXPLICITLY — omitting it silently produces a paid expense whose `post()`
 * credits Cash instead of the supplier payable.
 */
export async function createExpense(
  request: APIRequestContext,
  session: Session,
  payload: Record<string, unknown>,
): Promise<JsonResult> {
  return post(request, session, '/expenses', { document_date: TODAY, ...payload })
}

export async function postExpense(
  request: APIRequestContext,
  session: Session,
  id: string,
): Promise<JsonResult> {
  return post(request, session, `/expenses/${id}/post`)
}

export async function payExpense(
  request: APIRequestContext,
  session: Session,
  id: string,
  payload: Record<string, unknown>,
): Promise<JsonResult> {
  return post(request, session, `/expenses/${id}/pay`, { payment_date: TODAY, ...payload })
}

export async function getExpense(
  request: APIRequestContext,
  session: Session,
  id: string,
): Promise<ExpenseBody> {
  const res = await get(request, session, `/expenses/${id}`)
  expect(res.ok, `read expense -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as ExpenseBody
}

// ---------------------------------------------------------------------------
// Income
// ---------------------------------------------------------------------------

export async function createIncome(
  request: APIRequestContext,
  session: Session,
  payload: Record<string, unknown>,
): Promise<JsonResult> {
  return post(request, session, '/income', { document_date: TODAY, ...payload })
}

export async function postIncome(
  request: APIRequestContext,
  session: Session,
  id: string,
): Promise<JsonResult> {
  return post(request, session, `/income/${id}/post`)
}

// ---------------------------------------------------------------------------
// Fixture retirement — NEITHER helper throws (W-5b I-2a/I-2b, N-1)
// ---------------------------------------------------------------------------

/**
 * `DELETE /expenses/{id}` (draft only). Returns the observed status, or
 * `CLEANUP_THREW` — it NEVER throws, because a throw inside a `finally`
 * REPLACES the in-flight exception from the test body and would convert a real
 * money-assertion failure into a cleanup failure, hiding the defect entirely.
 * Callers assert the returned status ONLY on the path where the body already
 * succeeded, via `isCleanupSuccess` (2xx only — a `-1`/`599` sentinel MUST fail
 * the gate).
 */
export async function retireDraftExpense(
  request: APIRequestContext,
  session: Session,
  id: string,
): Promise<number> {
  try {
    const res = await request.delete(`${API_BASE}/expenses/${id}`, { headers: authHeaders(session) })
    return res.status()
  } catch {
    return 599
  }
}

export async function retireRecurrenceTemplate(
  request: APIRequestContext,
  session: Session,
  id: string,
): Promise<number> {
  try {
    const res = await request.delete(`${API_BASE}/expense-recurrences/${id}`, {
      headers: authHeaders(session),
    })
    return res.status()
  } catch {
    return 599
  }
}

// ---------------------------------------------------------------------------
// Recurring-expense generation (MTP-TRE-75)
// ---------------------------------------------------------------------------

const API_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../api')

/**
 * Env gate for the two cases in this wave that mutate state OUTSIDE the tenant
 * they can clean up, or that mint permanently-undeletable fiscal artefacts:
 *
 *   - `MTP-TRE-75` drives `expenses:generate-recurring`, which iterates EVERY
 *     tenant (see {@link generateRecurringExpenses});
 *   - `MTP-DEP-03`'s D1 tripwire seals a `DEPOSIT_RECEIPT` fiscal event that has
 *     no delete route, by design.
 *
 * Neither may run unattended against a tenant whose hash chain is E-7 evidence
 * (the staging rehearsal). Set `MONEY_CAMPAIGN_ALLOW_CROSS_TENANT=1` to opt in,
 * knowing what it costs.
 */
export const ALLOW_CROSS_TENANT_SIDE_EFFECTS =
  process.env.MONEY_CAMPAIGN_ALLOW_CROSS_TENANT === '1'

export const CROSS_TENANT_SKIP_REASON =
  'Skipped: this case mutates state outside the tenant it can clean up, or mints a '
  + 'permanently-undeletable sealed fiscal event. Set MONEY_CAMPAIGN_ALLOW_CROSS_TENANT=1 to run it. '
  + 'NEVER set it against a tenant whose hash chain is E-7 evidence.'

/**
 * `expenses:generate-recurring` is a SCHEDULED CONSOLE COMMAND — there is no API
 * route that materializes a due recurrence template (`Expense/routes.php` exposes
 * CRUD + pause/resume only). `MTP-TRE-75` asserts the generated instance's amount,
 * so the command has to be driven directly; shelling out from a spec is the same
 * mechanism `w2b-support.ts` established for `psql` reads.
 *
 * ⚠️ CROSS-TENANT BLAST RADIUS (W-5c fix round 1, I-1). The command extends
 * `TenantScopedCommand` and takes **no `--tenant` option**: `executeCommand()`
 * calls `forEachTenant(...)` and materializes due templates for EVERY tenant on
 * the box (izipos, coffee-shop, …), resolving a fallback admin per tenant when
 * the template's creator is unavailable. A caller running this against
 * `demo-pharmacy-tn` therefore also generates draft expenses in OTHER tenants —
 * documents this suite is tenant-scoped and can never retire.
 *
 * Two consequences the caller MUST respect:
 *   1. gate the call on {@link ALLOW_CROSS_TENANT_SIDE_EFFECTS};
 *   2. never assert on the command's aggregate output (`'N error(s)'` counts every
 *      tenant), only on THIS tenant's own read model through the API.
 */
export function generateRecurringExpenses(): string {
  try {
    return execSync('php artisan expenses:generate-recurring', {
      cwd: API_DIR,
      encoding: 'utf-8',
    }).trim()
  } catch (error) {
    // The command exits FAILURE when ANY tenant's templates error — it iterates
    // every tenant (blast-radius note above), so another tenant's broken
    // template would otherwise red THIS tenant's case through the exit code
    // (fix round 2, N-2). A run that executed and produced output is returned
    // for the caller's tenant-scoped API assertions; only genuine spawn
    // failures (php missing, wrong cwd) still throw.
    const e = error as { status?: number | null; stdout?: string | Buffer }
    if (typeof e.status === 'number' && e.stdout !== undefined) {
      return String(e.stdout).trim()
    }
    throw error
  }
}
