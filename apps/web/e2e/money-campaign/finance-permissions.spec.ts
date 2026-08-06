/**
 * MONEY TEST CAMPAIGN — wave W-6 — finance route permission denials and the
 * finance money tiles: `MTP-GL-21`, `-22`, `-23` and the NEW `MTP-GL-27`,
 * `MTP-GL-28` (plan §B.6 rows 80 and 83).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real
 * login through the real form, real backend, no mocks.
 *
 * "Every PERM case must assert BOTH layers: the UI blocks it AND a direct API
 * call with the same credentials returns 403" (money-test-plan §I.4) — each
 * `MTP-GL-21`/`-22`/`-23` case below does both, via `apiRequest()`, which
 * issues the call FROM the browser context and therefore carries exactly the
 * credentials and `X-Company-Id` the SPA itself would send.
 *
 * This file MUTATES NOTHING except one DRAFT journal entry in `MTP-GL-23`
 * (drafts are invisible to every report, which filters `je.status='posted'`).
 * It asserts no cross-run totals, so it is safe at any worker count — but the
 * campaign runs it at `--workers=1` with its siblings anyway.
 */
import { test, expect } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import { login, type Session } from './treasury-support'
import {
  TODAY,
  W6_ACCOUNTS,
  ensureAccount,
  financeSummary,
  settleAfterNav,
  tileValue,
  uniq,
  type AccountRow,
} from './w6-support'

/** Every digit in a rendered money string, so a comparison is immune to the
 * locale's grouping separator (French uses U+202F) and symbol placement. */
function digitsOf(rendered: string): string {
  return rendered.replace(/\D/g, '')
}

/**
 * The digit strings of the two `dp`-decimal representations bracketing a
 * higher-precision money string — i.e. `floor` and `ceil` at `dp`. Exact BigInt
 * arithmetic, no float anywhere (CLAUDE.md rule 19).
 *
 * A renderer that rounds is guaranteed to land on one of the two, whatever its
 * tie-break policy, and a renderer showing a DIFFERENT number lands on neither.
 * `('228728.3860', 2)` -> `['22872838', '22872839']`.
 *
 * Parameterised on `dp` for the L4 fix: the widget now renders at the COMPANY
 * currency's scale (TND -> 3), not at a hardcoded EUR 2.
 */
function truncateNeighbours(value: string, dp: number): [string, string] {
  const [intPart, fracPart = ''] = value.trim().replace('-', '').split('.')
  const padded = (fracPart + '0'.repeat(dp + 4)).slice(0, dp + 4)
  const kept = BigInt(intPart || '0') * 10n ** BigInt(dp) + BigInt(padded.slice(0, dp) || '0')
  const remainder = BigInt(padded.slice(dp) || '0')
  const upper = remainder === 0n ? kept : kept + 1n
  const render = (v: bigint): string => v.toString().replace(/\D/g, '')
  return [render(kept), render(upper)]
}

test.describe('GL — finance permission denials and money tiles', () => {
  test.setTimeout(120_000)

  let owner: Session
  let drAccount: AccountRow
  let crAccount: AccountRow

  test.beforeAll(async ({ request }) => {
    owner = await login(request, 'owner')
    drAccount = await ensureAccount(request, owner, W6_ACCOUNTS.debit)
    crAccount = await ensureAccount(request, owner, W6_ACCOUNTS.credit)
  })

  test('MTP-GL-21 (P0): the cashier is blocked from every journal/trial-balance route — UI and API', async ({
    page,
  }) => {
    await loginAsRole(page, 'cashier')

    // UI layer. `RequirePermission` redirects an unauthorised route to
    // /dashboard (`features/auth/components/RequirePermission.tsx:72-74`).
    for (const route of [
      '/finance/trial-balance',
      '/finance/journal-entries',
      '/finance/journal-entries/create',
    ]) {
      await page.goto(route)
      await settleAfterNav(page)
      expect(page.url(), `${route} must not render for a cashier`).not.toContain(route)
      expect(page.url()).toContain('/dashboard')
    }

    // API layer, same credentials.
    const trialBalance = await apiRequest(page, 'GET', '/reports/trial-balance')
    expect(trialBalance.status, 'GET /reports/trial-balance').toBe(403)
    const journalList = await apiRequest(page, 'GET', '/journal-entries')
    expect(journalList.status, 'GET /journal-entries').toBe(403)
    const journalCreate = await apiRequest(page, 'POST', '/journal-entries', {
      entry_date: TODAY,
      description: uniq('GL21-probe'),
      lines: [],
    })
    expect(journalCreate.status, 'POST /journal-entries').toBe(403)

    // "No money figures in the response body." A 403 envelope must not leak a
    // single decimal amount.
    for (const result of [trialBalance, journalList, journalCreate]) {
      expect(
        JSON.stringify(result.body),
        'a denial body carries no money figures',
      ).not.toMatch(/\d+\.\d{2,4}/)
    }
  })

  test('MTP-GL-22 (P1): the viewer can LIST journal entries but cannot reach the create route or API', async ({
    page,
  }) => {
    await loginAsRole(page, 'viewer')

    // The list route is allowed (`journal.view`) and really renders.
    await page.goto('/finance/journal-entries')
    await settleAfterNav(page)
    expect(page.url(), 'the viewer holds journal.view').toContain('/finance/journal-entries')
    expect(page.url()).not.toContain('/dashboard')

    // The create route is blocked (`journal.create`).
    await page.goto('/finance/journal-entries/create')
    await settleAfterNav(page)
    expect(page.url(), 'the viewer does not hold journal.create').not.toContain(
      '/finance/journal-entries/create',
    )

    // Both API layers, same credentials.
    expect((await apiRequest(page, 'GET', '/journal-entries')).status, 'the list API is allowed').toBe(200)
    const create = await apiRequest(page, 'POST', '/journal-entries', {
      entry_date: TODAY,
      description: uniq('GL22-probe'),
      lines: [],
    })
    expect(create.status, 'the create API is refused').toBe(403)
  })

  test('MTP-GL-23 (P1): the accountant CAN create a journal entry AND read every financial report', async ({
    page,
  }) => {
    await loginAsRole(page, 'accountant')

    // The role split is real: the create route renders for the accountant.
    await page.goto('/finance/journal-entries/create')
    await settleAfterNav(page)
    expect(page.url(), 'the accountant holds journal.create').toContain(
      '/finance/journal-entries/create',
    )

    // …and the API accepts a real, balanced entry from that principal.
    const created = await apiRequest(page, 'POST', '/journal-entries', {
      entry_date: TODAY,
      description: uniq('GL23-accountant-draft'),
      lines: [
        { account_id: drAccount.id, debit: '5.000', credit: '0.000' },
        { account_id: crAccount.id, debit: '0.000', credit: '5.000' },
      ],
    })
    expect(
      created.status,
      `the accountant can create a journal entry -> ${created.status} ${JSON.stringify(created.body)}`,
    ).toBe(201)
    // Deliberately NOT posted: the case asks whether creation is allowed, and a
    // draft adds no money to the tenant.

    // --- FINDING D5 (P1, TRIPWIRE — FIXED 2026-08-06/07, W-6 D5 "Option B
    // split" + gate round-2 fix I-1/I-2/I-3/I-5). ORIGINALLY pinned the
    // pre-fix defect: `reports.view`/`ledger.view` were admin-only while the
    // FE report routes were gated on `accounts.view` (which accountant held),
    // so the page rendered then 403'd on data fetch. Comment kept, not
    // deleted, per the fix-round instruction — the assertions below now pin
    // the FIXED behaviour instead of the defect.
    //
    // `RolesAndPermissionsSeeder::rolePermissionGrants()` now grants the
    // accountant `reports.financial` + `reports.operational` + `ledger.view`
    // (the D5 "financial + operational + ledger.view" role sub-rule), and
    // every route below was re-cut onto `reports.financial` / `.operational`
    // / `ledger.view` to match — so the accountant is 200 on all seven, and
    // the FE route it can already open now agrees with the API.
    for (const path of [
      '/reports/trial-balance',
      '/reports/balance-sheet',
      '/reports/profit-loss?date_from=2026-01-01&date_to=2026-12-31',
      '/reports/aged-receivables',
      '/reports/aged-payables',
      '/reports/finance-summary',
      '/ledger',
    ]) {
      const res = await apiRequest(page, 'GET', path)
      expect(
        res.status,
        `the accountant holds reports.financial/reports.operational/ledger.view, so ${path} is 200`,
      ).toBe(200)
    }

    // …and the FE route that renders that data is open to them, in agreement
    // with the API (no more render-then-403 incoherence).
    await page.goto('/finance/trial-balance')
    await settleAfterNav(page)
    expect(
      page.url(),
      'the accountant holds reports.financial, so the FE route and the API agree',
    ).toContain('/finance/trial-balance')
  })

  test('MTP-GL-27 (P1): every treasury-overview money tile matches the API in the COMPANY currency', async ({
    page,
    request,
  }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/finance/overview')
    await settleAfterNav(page)

    // Read the same endpoints the page reads, in the same run — the tiles are
    // compared against LIVE values, never against stored constants.
    const cashPosition = await apiRequest(page, 'GET', '/treasury/cash-position')
    expect(cashPosition.status).toBe(200)
    // `apiRequest` returns the RAW envelope (unlike the SPA's `apiGet`, which
    // unwraps `response.data.data`), so the payload is under `.data`.
    const cash = (cashPosition.body as {
      data: { currency: string; grand_total: string; groups: Array<{ type: string; total: string }> }
    }).data
    const summary = await financeSummary(request, owner)

    // 1) The four StatCards use `formatReportCurrency`, i.e. the COMPANY
    //    currency + locale — correct.
    const totalCash = await tileValue(page, 'Total Cash')
    expect(totalCash, 'the cash tiles render the company currency').toContain('TND')
    expect(digitsOf(totalCash), 'Total Cash == cash-position grand_total, digit for digit').toBe(
      digitsOf(cash.grand_total),
    )

    for (const [label, type] of [
      ['Cash Registers', 'cash_register'],
      ['Bank Accounts', 'bank_account'],
      ['Safes', 'safe'],
    ] as const) {
      const rendered = await tileValue(page, label)
      const group = cash.groups.find((g) => g.type === type)
      expect(rendered, `${label} renders the company currency`).toContain('TND')
      expect(digitsOf(rendered), `${label} == its cash-position group total`).toBe(
        digitsOf(group?.total ?? '0.000'),
      )
    }

    // 2) W-6 D6 — FIXED (fix lane L4). The "Finance Overview" widget on the
    //    SAME page used to call `formatCurrency(value)` with NO options
    //    (`features/finance/components/FinanceWidget.tsx`), and `lib/format.ts`
    //    defaulted `currency` to `'EUR'` — which also dragged the decimal count
    //    down from TND's 3 to EUR's 2, so six money tiles on a Tunisian company
    //    rendered as euros next to four that correctly rendered dinars.
    //
    //    The formatter now derives currency + scale + locale from the ACTIVE
    //    COMPANY when none is passed, and the widget formats through the
    //    company-bound `useCurrency().format` the way its StatCard sibling does.
    //    This assertion is the regression net: it goes RED if either half is
    //    reverted.
    const widgetTiles = [
      'Total Assets',
      'Total Liabilities',
      'Net Income (MTD)',
      'Net Income (YTD)',
      'Accounts Receivable',
      'Accounts Payable',
    ]

    for (const label of widgetTiles) {
      const rendered = await tileValue(page, label)
      expect(rendered, `D6 FIXED: ${label} renders the company currency`).toContain('TND')
      expect(rendered, `D6 FIXED: ${label} is no longer labelled EUR`).not.toContain('EUR')
      expect(
        rendered.split('TND')[0]!.trim(),
        `D6 FIXED: ${label} carries TND's 3 decimals, not EUR's 2`,
      ).toMatch(/,\d{3}$/)
    }

    // The underlying number was always right — D6 was purely a rendering defect,
    // so the digits must still match the finance-summary payload.
    //
    // FIX ROUND 1 (kept): compare the WHOLE rendered figure against the two
    // neighbours of the API value at the render scale, rather than the integer
    // part alone — exact under BigInt, immune to a carry, and immune to whichever
    // way the renderer breaks a tie.
    const totalAssets = await tileValue(page, 'Total Assets')
    expect(
      truncateNeighbours(summary.total_assets, 3),
      'the widget shows the finance-summary total_assets, rendered at the TND scale of 3',
    ).toContain(digitsOf(totalAssets))
  })

  test('MTP-GL-28 (P2): the finance hub is a permission-filtered navigator that surfaces NO money', async ({
    page,
  }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/finance')
    await settleAfterNav(page)

    // Every section, and every card the owner is entitled to. Located by
    // HREF, not by label: several card descriptions share words with other
    // card titles, so an accessible-name regex is ambiguous here.
    for (const heading of ['Banking & Payments', 'Accounting', 'Reports']) {
      await expect(
        page.getByRole('heading', { name: heading }),
        `the "${heading}" section renders`,
      ).toBeVisible({ timeout: 15_000 })
    }
    for (const href of [
      '/finance/overview',
      '/finance/chart-of-accounts',
      '/finance/ledger',
      '/finance/journal-entries',
      '/finance/trial-balance',
      '/finance/profit-loss',
      '/finance/balance-sheet',
      '/finance/aged-receivables',
      '/finance/aged-payables',
    ]) {
      await expect(
        page.locator(`a[href^="${href}"]`).first(),
        `the hub card linking to ${href} renders for the owner`,
      ).toBeVisible({ timeout: 15_000 })
    }

    // The hub carries NO figures at all — it is pure navigation, so there is
    // nothing here to reconcile against the ledger. Asserted as the absence of
    // any currency-shaped token in the whole page body.
    const body = (await page.locator('main').innerText()).trim()
    expect(body, 'the finance hub renders no money').not.toMatch(/\d[\d   ,.]*\s*(TND|EUR|€)/)

    // The permission filter is real — FIXED 2026-08-06/07 (was the same
    // `reports.view` admin-only-grant tripwire as D5 above; comment kept,
    // not deleted, per the fix-round instruction). The accountant now holds
    // `reports.operational` (D5 "financial + operational + ledger.view" role
    // sub-rule), and the Treasury card is gated on `reports.operational`
    // (`FinanceHubPage.tsx`), so it renders for the accountant instead of
    // being hidden.
    await loginAsRole(page, 'accountant')
    await page.goto('/finance')
    await settleAfterNav(page)
    await expect(
      page.locator('a[href^="/finance/trial-balance"]').first(),
      'the accountant still sees the reports.financial-gated report cards',
    ).toBeVisible({ timeout: 15_000 })
    await expect(
      page.locator('a[href^="/finance/overview"]'),
      'the accountant holds reports.operational, so the Treasury card renders',
    ).toBeVisible({ timeout: 15_000 })
  })
})
