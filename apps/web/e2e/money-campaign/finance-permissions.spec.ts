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
 * The digit strings of the two 2-decimal representations bracketing a scale-4
 * money string — i.e. `floor` and `ceil` at 2dp. Exact BigInt arithmetic, no
 * float anywhere (CLAUDE.md rule 19).
 *
 * A renderer that rounds is guaranteed to land on one of the two, whatever its
 * tie-break policy, and a renderer showing a DIFFERENT number lands on neither.
 * `'228728.3860'` -> `['22872838', '22872839']`.
 */
function truncate2dpNeighbours(scale4: string): [string, string] {
  const negative = scale4.trimStart().startsWith('-')
  const [intPart, fracPart = ''] = scale4.replace('-', '').split('.')
  const hundredths = BigInt(intPart) * 100n + BigInt((fracPart + '0000').slice(0, 2))
  const remainder = BigInt((fracPart + '0000').slice(2, 4))
  const lower = hundredths
  const upper = remainder === 0n ? hundredths : hundredths + 1n
  const render = (v: bigint): string => `${negative ? '-' : ''}${v.toString()}`.replace(/\D/g, '')
  return [render(lower), render(upper)]
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

  test('MTP-GL-23 (P1): the accountant CAN create a journal entry — but is 403 on every financial report', async ({
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

    // --- FINDING D5 (P1, TRIPWIRE, GREEN: pins TODAY's behaviour).
    //
    // `reports.view` (every `/reports/*` financial report) and `ledger.view`
    // (`GET /ledger`) are granted to NO seeded role except `admin`
    // (`RolesAndPermissionsSeeder::rolePermissionGrants()` — the accountant
    // block at :717-745 lists `reports.financial` and `reports.manage`, never
    // `reports.view`; the manager block at :519 is the same, and viewer at :643
    // holds only `reports.operational`). The FRONT-END routes for those very
    // pages are gated on `accounts.view` instead
    // (`apps/web/src/routes/index.tsx` trial-balance / profit-loss /
    // balance-sheet / aged-receivables / aged-payables), which the accountant,
    // the manager and the viewer all HOLD. Net effect: the page renders for the
    // finance persona and then fails its data fetch with a 403. Verified live
    // for accountant, viewer and manager alike.
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
        `TRIPWIRE D5: the accountant is refused ${path} (reports.view / ledger.view are admin-only)`,
      ).toBe(403)
    }

    // …while the FE route that renders that data is open to them.
    await page.goto('/finance/trial-balance')
    await settleAfterNav(page)
    expect(
      page.url(),
      'TRIPWIRE D5: the FE gate (accounts.view) lets the accountant onto a page the API will 403',
    ).toContain('/finance/trial-balance')
  })

  test('MTP-GL-27 (P1): the treasury overview money tiles match the API — except the widget, which renders TND as EUR', async ({
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

    // 2) FINDING D6 (P1, TRIPWIRE, GREEN): the "Finance Overview" widget on the
    //    SAME page calls `formatCurrency(value)` with NO options
    //    (`features/finance/components/FinanceWidget.tsx`), and
    //    `lib/format.ts:77` defaults `currency` to `'EUR'` — which also drags
    //    the decimal count down from TND's 3 to EUR's 2. Six money tiles on a
    //    Tunisian company therefore render as euros, next to four tiles that
    //    correctly render dinars.
    const totalAssets = await tileValue(page, 'Total Assets')
    expect(
      totalAssets,
      'TRIPWIRE D6: the FinanceWidget labels TND money as EUR (formatCurrency default)',
    ).toContain('EUR')
    expect(totalAssets, 'TRIPWIRE D6: and it is NOT the company currency').not.toContain('TND')
    expect(
      totalAssets.split('EUR')[0]!.trim(),
      'TRIPWIRE D6: EUR`s 2 decimals, not the TND scale of 3',
    ).toMatch(/,\d{2}$/)

    // The underlying number is still the right one — this is a rendering
    // defect, not a data defect.
    //
    // FIX ROUND 1: the earlier version compared only the INTEGER part, which
    // silently breaks on a carry (`…728.996` renders `228 729,00`, so the
    // integer parts differ by one and the assertion fails for the wrong
    // reason). Compare the WHOLE rendered figure against the two 2dp
    // neighbours of the API value instead — exact under BigInt, immune to the
    // carry, and immune to whichever way `Number.toFixed()` breaks a tie
    // (`lib/format.ts:43` rounds through `toFixed`, whose half-way behaviour
    // is binary-float dependent).
    expect(
      truncate2dpNeighbours(summary.total_assets),
      'the widget shows the finance-summary total_assets, rendered at EUR`s 2 decimals',
    ).toContain(digitsOf(totalAssets))

    for (const label of [
      'Total Liabilities',
      'Net Income (MTD)',
      'Net Income (YTD)',
      'Accounts Receivable',
      'Accounts Payable',
    ]) {
      expect(await tileValue(page, label), `TRIPWIRE D6: ${label} is mislabelled EUR too`).toContain(
        'EUR',
      )
    }
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

    // The permission filter is real — and it is the same `reports.view`
    // admin-only grant as D5: the finance persona loses the hub's entry point
    // to the treasury overview.
    await loginAsRole(page, 'accountant')
    await page.goto('/finance')
    await settleAfterNav(page)
    await expect(
      page.locator('a[href^="/finance/trial-balance"]').first(),
      'the accountant still sees the accounts-gated report cards',
    ).toBeVisible({ timeout: 15_000 })
    await expect(
      page.locator('a[href^="/finance/overview"]'),
      'TRIPWIRE D5: the Treasury card needs reports.view, which no non-admin role holds',
    ).toHaveCount(0)
  })
})
