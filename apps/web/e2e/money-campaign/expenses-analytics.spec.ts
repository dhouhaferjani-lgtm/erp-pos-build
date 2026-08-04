/**
 * MONEY TEST CAMPAIGN — wave W-5c — `/expenses/analytics` and `/expenses/recurring`:
 * `MTP-TRE-73/74/75` (plan §B.5 row 71).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real login,
 * real backend, no mocks.
 *
 * CONCURRENCY — THIS FILE ASSUMES `--workers=1`. `MTP-TRE-73` reads a SHARED,
 * TENANT-WIDE aggregate (every posted expense in the window, including the ones
 * every other case in this campaign posts) and `MTP-TRE-75` drives a tenant-wide
 * console command. A concurrent expense post would land between the tile read and
 * the breakdown read and break the reconciliation. Run only as
 * `--project=chromium --workers=1`.
 *
 * PLAN-vs-TENANT deviation on `MTP-TRE-73`: the plan's assertions are stated as
 * INVARIANTS ("Σ `total` == the `total` tile", "Σ `share_percent` == 100", "the
 * matrix reconciles to the same figure"), not as absolute amounts — which is
 * exactly right here, because the aggregate is shared and drifts on every wave.
 * The invariants are asserted with exact decimal-string arithmetic; no absolute
 * total is pinned.
 *
 * The Σ share_percent rule is NOT "== 100". `ExpenseAnalyticsService::byCategory()`
 * rounds each category's share INDEPENDENTLY to 2 dp (`CurrencyScale::bcround(...,
 * 2)`) with no largest-remainder redistribution, so the sum may miss 100 by up to
 * half a centipoint per category. That documented band — |Σ − 100| <= 0.005 × n —
 * is what this file asserts. Live at authoring time: 7 categories, Σ = 99.99.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import { login, get, post, addMoney, subMoney, toMillimes, type Session } from './treasury-support'
import { isCleanupSuccess } from './statement-support'
import {
  generateRecurringExpenses,
  retireDraftExpense,
  retireRecurrenceTemplate,
  TODAY,
  uniq,
} from './w5c-support'

let owner: Session

interface AnalyticsBody {
  tiles: { total: string; count: number; unpaid_total: string; mom_delta_percent: string | null }
  by_category: Array<{ category_id: string | null; name: string; total: string; share_percent: string }>
  matrix: Array<{ category_id: string | null; name: string; months: Record<string, string> }>
  top_vendors: Array<{ partner_id: string | null; vendor_name: string | null; total: string }>
}

async function analytics(
  request: APIRequestContext,
  from: string,
  to: string,
): Promise<AnalyticsBody> {
  const res = await get(request, owner, `/expenses/analytics?date_from=${from}&date_to=${to}`)
  expect(res.ok, `analytics -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as AnalyticsBody
}

/** Σ of a list of exact decimal strings, in integer millimes. */
function sumMoney(values: string[]): string {
  return values.reduce((total, value) => addMoney(total, value), '0.000')
}

/** Σ of 2-dp percent strings, as an exact 2-dp string (centipoint arithmetic). */
function sumPercent(values: string[]): string {
  const centipoints = values.reduce((total, value) => {
    expect(value, `share_percent renders at exactly 2 decimals, got '${value}'`).toMatch(/^-?\d+\.\d{2}$/)
    const [whole, fraction] = value.split('.')
    const negative = whole!.startsWith('-')
    const magnitude = BigInt(whole!.replace('-', '')) * 100n + BigInt(fraction!)
    return total + (negative ? -magnitude : magnitude)
  }, 0n)
  const negative = centipoints < 0n
  const abs = negative ? -centipoints : centipoints
  return `${negative ? '-' : ''}${abs / 100n}.${(abs % 100n).toString().padStart(2, '0')}`
}

test.describe('MTP-TRE — expense analytics and recurrence (W-5c §B.5 row 71)', () => {
  test.describe.configure({ timeout: 180_000 })

  test.beforeAll(async ({ request }) => {
    owner = await login(request, 'owner')
  })

  test('MTP-TRE-73 (P1): the analytics tiles, category breakdown and month×category matrix all reconcile', async ({
    request,
  }) => {
    // A window that certainly spans more than one month of posted expenses in this
    // tenant, so the matrix has at least two month columns to reconcile across.
    const from = `${TODAY.slice(0, 4)}-01-01`
    const body = await analytics(request, from, TODAY)

    expect(body.tiles.total, 'the total tile is an exact scale-3 decimal string').toMatch(/^-?\d+\.\d{3}$/)
    expect(body.tiles.unpaid_total).toMatch(/^-?\d+\.\d{3}$/)
    expect(body.tiles.count, 'the window is non-empty — otherwise this case proves nothing').toBeGreaterThan(0)

    // (1) Σ by_category.total == the total tile, EXACTLY.
    expect(
      sumMoney(body.by_category.map((row) => row.total)),
      'Σ category totals == the total tile, to the millime',
    ).toBe(body.tiles.total)

    // (2) Σ share_percent within the documented independent-rounding band.
    const shareSum = sumPercent(body.by_category.map((row) => row.share_percent))
    const drift = subMoney(`${shareSum}0`, '100.000')
    const toleranceMillimes = BigInt(5 * body.by_category.length)
    const driftMillimes = toMillimes(drift) < 0n ? -toMillimes(drift) : toMillimes(drift)
    expect(
      driftMillimes <= toleranceMillimes,
      `Σ share_percent = ${shareSum} over ${body.by_category.length} categories; `
        + `drift ${drift} exceeds the documented independent-rounding band of `
        + `0.005 × ${body.by_category.length} (ExpenseAnalyticsService::byCategory rounds each share separately)`,
    ).toBe(true)

    // (3) The month×category matrix reconciles to the SAME figure, twice over:
    //     per category (matrix row == by_category row) and in total.
    const matrixByCategory = new Map(
      body.matrix.map((row) => [row.category_id, sumMoney(Object.values(row.months))]),
    )
    for (const category of body.by_category) {
      expect(
        matrixByCategory.get(category.category_id),
        `matrix row '${category.name}' sums to its by_category total`,
      ).toBe(category.total)
    }
    expect(
      sumMoney(body.matrix.flatMap((row) => Object.values(row.months))),
      'and the whole matrix sums to the total tile',
    ).toBe(body.tiles.total)
    expect(
      new Set(body.matrix.flatMap((row) => Object.keys(row.months))).size,
      'the window spans more than one month, so the matrix genuinely has columns to reconcile',
    ).toBeGreaterThan(1)

    // (4) top_vendors is a subset of the same money: no vendor may exceed the total.
    for (const vendor of body.top_vendors) {
      expect(vendor.total).toMatch(/^-?\d+\.\d{3}$/)
      expect(
        subMoney(body.tiles.total, vendor.total).startsWith('-'),
        `vendor '${vendor.vendor_name}' total ${vendor.total} cannot exceed the tile total ${body.tiles.total}`,
      ).toBe(false)
    }
  })

  test('MTP-TRE-74 (P1): a zero-expense comparison period yields a DEFINED mom_delta_percent, never Infinity/NaN', async ({
    request,
  }) => {
    // `priorPeriod()` shifts the window back by its own length, so a window that
    // predates the tenant's first expense has a zero-total comparison period.
    const empty = await analytics(request, '2020-03-01', '2020-03-31')
    expect(
      { total: empty.tiles.total, count: empty.tiles.count },
      'a pre-tenant window is genuinely empty',
    ).toEqual({ total: '0.000', count: 0 })
    expect(
      empty.tiles.mom_delta_percent,
      'divide-by-zero is guarded: an explicit null ("n/a"), never Infinity or NaN',
    ).toBeNull()

    // The stronger shape of the same guard: a NON-empty current period whose
    // comparison period is empty. Same null, so the tile can never render a
    // percentage against a zero base.
    const currentMonthStart = `${TODAY.slice(0, 7)}-01`
    const wholeYear = await analytics(request, `${TODAY.slice(0, 4)}-01-01`, TODAY)
    expect(wholeYear.tiles.count, 'the year-to-date window has expenses').toBeGreaterThan(0)
    expect(
      wholeYear.tiles.mom_delta_percent,
      'its comparison period (the previous year-length window) has none -> null, not Infinity',
    ).toBeNull()

    // And when the comparison period IS non-empty, the tile is a finite, exact
    // 2-dp decimal string — asserted by SHAPE only, because the value is a
    // function of the shared tenant aggregate and drifts every wave.
    const shortWindow = await analytics(request, currentMonthStart, TODAY)
    const delta = shortWindow.tiles.mom_delta_percent
    if (delta !== null) {
      expect(delta, 'a real mom delta is an exact 2-dp decimal string').toMatch(/^-?\d+\.\d{2}$/)
      expect(Number.isFinite(Number(delta)), `'${delta}' is finite`).toBe(true)
      expect(delta).not.toMatch(/Infinity|NaN/)
    }
  })

  test('MTP-TRE-75 (P1): generated recurring instances carry the configured amount exactly, with no drift', async ({
    request,
  }) => {
    let caseSucceeded = false
    const vendor = uniq('TRE75-vendor')
    // 333.333 is deliberately a full-precision scale-3 amount: any float round-trip
    // in the template -> generation -> document chain would surface as 333.33 or
    // 333.3330000001.
    const configuredAmount = '333.333'

    const template = await post(request, owner, '/expense-recurrences', {
      name: uniq('TRE75'),
      vendor_name: vendor,
      amount: configuredAmount,
      frequency: 'monthly',
      start_date: TODAY,
      // MAX_LEAD_DAYS (ExpenseRecurrenceTemplate::MAX_LEAD_DAYS = 60): with a
      // 60-day lead, TODAY's occurrence and next month's are both due, so ONE
      // command run per occurrence gives two generations to compare — which is
      // what "no drift ACROSS generations" needs. The third run is a no-op.
      lead_days: 60,
    })
    expect(template.status, `template -> ${template.status} ${JSON.stringify(template.data)}`).toBe(201)
    const templateId = String(template.data.id)
    const generatedIds: string[] = []

    try {
      expect(
        { amount: template.data.amount, next_due_date: template.data.next_due_date, status: template.data.status },
        'the template stores the amount at exactly scale 3 and is due today',
      ).toEqual({ amount: configuredAmount, next_due_date: TODAY, status: 'active' })

      // `expenses:generate-recurring` is a scheduled console command; there is no
      // API route that materializes a due template (see w5c-support.ts).
      generateRecurringExpenses()
      generateRecurringExpenses()
      const thirdRun = generateRecurringExpenses()
      expect(thirdRun, 'the command reports a clean sweep').toContain('0 error(s)')

      const listed = await get(request, owner, `/expenses?search=${encodeURIComponent(vendor)}&per_page=50`)
      expect(listed.ok, `expense search -> ${listed.status}`).toBeTruthy()
      const generated = listed.data as unknown as Array<{
        id: string
        status: string
        total: string
        document_date: string
        metadata: { recurrence_template_id: string | null; is_paid: boolean }
      }>
      generatedIds.push(...generated.map((e) => e.id))

      expect(
        generated.length,
        'exactly TWO generations were due (today + next month at a 60-day lead); the third run added none',
      ).toBe(2)
      expect(
        generated.map((e) => e.total),
        'BOTH generations carry the configured amount to the millime — no drift across generations',
      ).toEqual([configuredAmount, configuredAmount])
      expect(
        sumMoney(generated.map((e) => e.total)),
        'and their sum is exactly 2 × the configured amount',
      ).toBe(addMoney(configuredAmount, configuredAmount))
      expect(
        generated.every((e) => e.metadata.recurrence_template_id === templateId),
        'both are stamped with the template that produced them',
      ).toBe(true)
      expect(
        generated.every((e) => e.status === 'draft' && e.metadata.is_paid === false),
        'generated instances land as UNPAID DRAFTS — generation moves no money',
      ).toBe(true)
      const dates = [...new Set(generated.map((e) => e.document_date))].sort()
      expect(dates, 'one instance per period — two distinct document dates').toHaveLength(2)
      expect(dates[0], 'the first is TODAY, the template start date').toBe(TODAY)

      const afterRuns = await get(request, owner, `/expense-recurrences/${templateId}`)
      expect(afterRuns.ok).toBeTruthy()
      const nextDue = String(afterRuns.data.next_due_date)
      expect(nextDue).toMatch(/^\d{4}-\d{2}-\d{2}$/)
      expect(
        nextDue > dates[1]!,
        `the cursor advanced past the last generated period (next_due=${nextDue}, last generated=${dates[1]})`,
      ).toBe(true)
      expect(
        nextDue.slice(8, 10),
        'and stayed on the same day-of-month as the start date (RecurrenceCursor advances whole periods)',
      ).toBe(TODAY.slice(8, 10))

      caseSucceeded = true
    } finally {
      // Drafts first (the template delete would otherwise leave them orphaned).
      const statuses: number[] = []
      for (const id of generatedIds) {
        statuses.push(await retireDraftExpense(request, owner, id))
      }
      statuses.push(await retireRecurrenceTemplate(request, owner, templateId))
      if (caseSucceeded) {
        expect(
          statuses.every((status) => isCleanupSuccess(status)),
          `retire generated drafts + template -> ${JSON.stringify(statuses)}`,
        ).toBe(true)
      }
    }
  })
})
