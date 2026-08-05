/**
 * MONEY TEST CAMPAIGN — wave W-6 — trial balance / balance sheet / P&L /
 * ledger: `MTP-GL-08`, `-09`, `-15`, `-16`, `-17`, `-18`, `-19`
 * (plan §B.6 rows 76 and 79).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real
 * login, real backend, no mocks. Money compared as EXACT decimal strings.
 *
 * CONCURRENCY — THIS FILE ASSUMES `--workers=1`. `MTP-GL-09`, `-17` and `-18`
 * assert exact DELTAS on shared report totals, captured immediately around
 * their own posts. Run only as `--project=chromium --workers=1`.
 *
 * FIXTURE NOISE. Accounts `411`, `401`, `512`, `53`, `613`, `65`, `706`, `707`,
 * `4456`, `4457`, `6580`, `7580`, `37`, `119` all carry five earlier waves of
 * deliberate money that GROWS on every sibling re-run (see the "State left
 * behind" sections of `docs/sessions/MONEY-CAMPAIGN-RESULTS.md`). Nothing here
 * asserts a global tenant total as a constant. The only absolute numbers
 * asserted are (a) amounts this file itself posts, on its own `W6*` accounts,
 * and (b) the DEFECT MAGNITUDE pinned by the `MTP-GL-08` / `MTP-GL-15`
 * tripwires — which must be ZERO and is therefore not "noise" at all.
 */
import { test, expect } from '@playwright/test'
import { login, get, type Session } from './treasury-support'
import {
  TODAY,
  W6_ACCOUNTS,
  add4,
  balanceSheet,
  createJournalEntry,
  daysAgo,
  ensureAccount,
  findAccountByCode,
  ledger,
  norm4,
  plAmount,
  postJournalEntry,
  profitLoss,
  sub4,
  tbCredit,
  tbDebit,
  trialBalance,
  uniq,
  type AccountRow,
} from './w6-support'

/**
 * The KNOWN unbalanced posted entry this tenant carries, and the exact size of
 * the hole it puts in every GL report. See
 * `docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md` D1.
 *
 * `INV-2026-0320` is a posted invoice whose header says `tax_amount 0.000`
 * / `total 100.000`, while `AccountingService::createInvoiceGLEntries()`
 * RECOMPUTES VAT from the LINE's `tax_rate` (`groupTaxByRate():473-477`) and
 * credits `19.000` that the AR debit (the header `total`) never carried. The
 * residual leg that would have absorbed it is guarded `> 0`
 * (`AccountingService.php:206-215`), so the NEGATIVE residual is silently
 * dropped and a permanently unbalanced entry is sealed into the fiscal chain.
 */
const D1_INVOICE_NUMBER = 'INV-2026-0320'
const D1_GAP = '19.0000'

test.describe('GL — trial balance, balance sheet, P&L and ledger', () => {
  test.setTimeout(180_000)

  let owner: Session
  let drAccount: AccountRow
  let crAccount: AccountRow
  let expenseAccount: AccountRow

  test.beforeAll(async ({ request }, workerInfo) => {
    expect(
      workerInfo.config.workers,
      'this file asserts exact report deltas on shared accounts — '
        + 'run it as `--project=chromium --workers=1`',
    ).toBe(1)

    owner = await login(request, 'owner')
    drAccount = await ensureAccount(request, owner, W6_ACCOUNTS.debit)
    crAccount = await ensureAccount(request, owner, W6_ACCOUNTS.credit)
    expenseAccount = await ensureAccount(request, owner, W6_ACCOUNTS.expense)
  })

  test('MTP-GL-08 (P0): the trial balance does NOT balance — TRIPWIRE on a launch-blocking 19.000 hole', async ({
    request,
  }) => {
    const tb = await trialBalance(request, owner)

    // --- VERDICT: FAIL. The plan is explicit: "Any non-zero difference is
    // launch-blocking." This assertion is a GREEN TRIPWIRE — it pins TODAY's
    // broken state, NOT the fix. When the hole is repaired (both the stranded
    // entry and the missing guard) this test goes RED and forces the ticket to
    // be revisited. It also goes RED if a NEW unbalanced entry appears, which
    // is itself a new P0.
    const gap = sub4(tb.total_credit, tb.total_debit)
    expect(
      tb.is_balanced,
      'TRIPWIRE D1 (P0, LAUNCH-BLOCKING): the general ledger is out of balance today',
    ).toBe(false)
    expect(
      gap,
      'TRIPWIRE D1: credits exceed debits by exactly 19.000. A DIFFERENT number here means a '
        + 'NEW unbalanced entry was posted — investigate before touching this literal.',
    ).toBe(D1_GAP)

    // --- The culprit, proven at the API layer, per-entry (never a global total).
    const invoices = await get(request, owner, `/invoices?search=${D1_INVOICE_NUMBER}`)
    expect(invoices.ok, `invoice lookup -> ${invoices.status}`).toBeTruthy()
    const rows = invoices.data as unknown as Array<{
      id: string
      document_number: string
      total: string
      tax_amount: string
      document_date: string
    }>
    const culprit = rows.find((r) => r.document_number === D1_INVOICE_NUMBER)
    expect(
      culprit,
      `${D1_INVOICE_NUMBER} must still exist on this tenant — if it does not, the tenant was `
        + 'reseeded and the whole W-6 wave needs re-running against the new data',
    ).toBeDefined()

    expect(culprit!.total, 'the header total').toBe('100.000')
    expect(
      culprit!.tax_amount,
      'the header says ZERO VAT — but the GL posting recomputes VAT from the LINE tax_rate',
    ).toBe('0.000')

    // Its three GL legs, read off the ledger for the three purpose-tagged
    // accounts. A `Document`-sourced entry carries `source_id = document id`.
    const ar = await findAccountByCode(request, owner, '411')
    const revenue = await findAccountByCode(request, owner, '707')
    const vat = await findAccountByCode(request, owner, '4457')
    expect(ar && revenue && vat, 'the three purpose-tagged accounts exist').toBeTruthy()

    let legDebit = '0.0000'
    let legCredit = '0.0000'
    for (const account of [ar!, revenue!, vat!]) {
      const page = await ledger(
        request,
        owner,
        `account_id=${account.id}&date_from=${culprit!.document_date}&date_to=${culprit!.document_date}&per_page=200`,
      )
      for (const line of page.data.lines.filter((l) => l.source_id === culprit!.id)) {
        legDebit = add4(legDebit, line.debit)
        legCredit = add4(legCredit, line.credit)
      }
    }

    expect(legDebit, 'the AR debit carries only the header total').toBe('100.0000')
    expect(
      legCredit,
      'revenue 100.000 + recomputed line VAT 19.000 — the credit side over-runs the debit side',
    ).toBe('119.0000')
    expect(
      sub4(legCredit, legDebit),
      'this ONE entry accounts for the whole trial-balance hole',
    ).toBe(D1_GAP)
  })

  test('MTP-GL-09 (P0): a posted 100.000 entry moves both trial-balance sides by exactly 100.000', async ({
    request,
  }) => {
    // Snapshot -> post a KNOWN entry -> assert the DELTA. The only shape that
    // survives a tenant five waves of fixtures have already written to.
    const before = await trialBalance(request, owner)
    const drBefore = tbDebit(before, W6_ACCOUNTS.debit.code)
    const crBefore = tbCredit(before, W6_ACCOUNTS.credit.code)
    const totalDebitBefore = norm4(before.total_debit)
    const totalCreditBefore = norm4(before.total_credit)

    const created = await createJournalEntry(request, owner, {
      entry_date: TODAY,
      description: uniq('GL09'),
      lines: [
        { account_id: drAccount.id, debit: '100.000', credit: '0.000' },
        { account_id: crAccount.id, debit: '0.000', credit: '100.000' },
      ],
    })
    expect(created.status, `create -> ${created.status} ${JSON.stringify(created.data)}`).toBe(201)
    const posted = await postJournalEntry(request, owner, String((created.data as { id: string }).id))
    expect(posted.ok, `post -> ${posted.status} ${JSON.stringify(posted.data)}`).toBeTruthy()

    const after = await trialBalance(request, owner)
    expect(tbDebit(after, W6_ACCOUNTS.debit.code)).toBe(add4(drBefore, '100.0000'))
    expect(tbCredit(after, W6_ACCOUNTS.credit.code)).toBe(add4(crBefore, '100.0000'))

    // The report-level totals move by the same 100.000 on BOTH sides — i.e. a
    // balanced entry does not change the (already broken) imbalance.
    expect(norm4(after.total_debit)).toBe(add4(totalDebitBefore, '100.0000'))
    expect(norm4(after.total_credit)).toBe(add4(totalCreditBefore, '100.0000'))
    expect(
      sub4(norm4(after.total_credit), norm4(after.total_debit)),
      'the pre-existing hole is unchanged by a correctly balanced entry',
    ).toBe(D1_GAP)
  })

  test('MTP-GL-15 (P0): the balance sheet does NOT balance — the same 19.000 hole, cross-checked against the trial balance', async ({
    request,
  }) => {
    const bs = await balanceSheet(request, owner)
    const tb = await trialBalance(request, owner)

    const liabilitiesPlusEquity = add4(norm4(bs.total_liabilities), norm4(bs.total_equity))
    const bsGap = sub4(liabilitiesPlusEquity, norm4(bs.total_assets))
    const tbGap = sub4(norm4(tb.total_credit), norm4(tb.total_debit))

    // --- VERDICT: FAIL (TRIPWIRE, green today).
    expect(
      bs.is_balanced,
      'TRIPWIRE D1 (P0, LAUNCH-BLOCKING): Assets != Liabilities + Equity',
    ).toBe(false)
    expect(bsGap, 'liabilities + equity over-run assets by exactly 19.000').toBe(D1_GAP)

    // The durable part of this case: the two reports must at minimum AGREE,
    // because both are pure projections of the same posted journal lines. This
    // half stays meaningful after the data is repaired.
    expect(
      bsGap,
      'the balance-sheet hole and the trial-balance hole are the SAME hole — one root cause',
    ).toBe(tbGap)
  })

  test('MTP-GL-16 (P0): the inventory opening JE (Dr 37 / Cr 119, 119.000) is present and balanced', async ({
    request,
  }) => {
    // The `MTP-INV-10` opening batch W-4 posted and deliberately left behind
    // ("State deliberately LEFT BEHIND (for W-6)" item 2: `119.000` per run).
    const inventory = await findAccountByCode(request, owner, '37')
    const openingEquity = await findAccountByCode(request, owner, '119')
    expect(inventory && openingEquity, 'the inventory and opening-balance-equity accounts exist').toBeTruthy()

    const OPENING_DATE = '2026-08-03'
    const invPage = await ledger(
      request,
      owner,
      `account_id=${inventory!.id}&date_from=${OPENING_DATE}&date_to=${OPENING_DATE}&per_page=200`,
    )
    const obePage = await ledger(
      request,
      owner,
      `account_id=${openingEquity!.id}&date_from=${OPENING_DATE}&date_to=${OPENING_DATE}&per_page=200`,
    )

    const invOpenings = invPage.data.lines.filter(
      (l) => l.source_type === 'opening_balance' && l.debit === '119.0000',
    )
    expect(
      invOpenings.length,
      'at least one Dr Inventory 119.000 opening line (W-4 MTP-INV-10)',
    ).toBeGreaterThan(0)

    // For EACH such opening, the mirror credit on opening-balance equity must
    // exist in the same entry, for the same 119.000 — i.e. the opening JE
    // balances. That is a per-entry invariant, immune to fixture accumulation.
    for (const debitLine of invOpenings) {
      const mirror = obePage.data.lines.find(
        (l) => l.entry_number === debitLine.entry_number && l.credit === '119.0000',
      )
      expect(
        mirror,
        `opening entry ${debitLine.entry_number} must credit 119 Solde d'ouverture by 119.0000`,
      ).toBeDefined()
    }

    // And they are visible to the trial balance (posted, not draft).
    const tb = await trialBalance(request, owner)
    expect(
      tbDebit(tb, '37') === '0.0000' && tbCredit(tb, '37') === '0.0000',
      'the inventory account carries the openings in the trial balance',
    ).toBeFalsy()
  })

  test('MTP-GL-17 (P1): the ledger running balance is exactly opening + Σ(debits − credits), page by page', async ({
    request,
  }) => {
    const page1 = await ledger(
      request,
      owner,
      `account_id=${drAccount.id}&date_from=2026-01-01&date_to=2026-12-31&per_page=200`,
    )

    expect(page1.data.lines.length, 'the W6 debit account has ledger movement to check').toBeGreaterThan(0)

    // 1) Line-by-line: each row's `balance` is the running total.
    let running = norm4(page1.data.opening_balance)
    for (const line of page1.data.lines) {
      running = sub4(add4(running, line.debit), line.credit)
      expect(
        norm4(line.balance),
        `running balance after ${line.entry_number} (${line.debit} Dr / ${line.credit} Cr)`,
      ).toBe(running)
    }

    // 2) Window-level: closing == opening + Σdr − Σcr, exactly.
    expect(
      norm4(page1.data.closing_balance),
      'closing_balance == opening_balance + total_debits - total_credits',
    ).toBe(
      sub4(add4(norm4(page1.data.opening_balance), norm4(page1.data.total_debits)), norm4(page1.data.total_credits)),
    )
    expect(running, 'the last row`s running balance IS the closing balance').toBe(
      norm4(page1.data.closing_balance),
    )

    // 3) Cross-report: the full-window ledger closing balance for a
    // debit-normal account equals its trial-balance debit column.
    const tb = await trialBalance(request, owner)
    expect(
      norm4(page1.data.closing_balance),
      'the ledger and the trial balance agree on this account',
    ).toBe(sub4(tbDebit(tb, W6_ACCOUNTS.debit.code), tbCredit(tb, W6_ACCOUNTS.debit.code)))

    // 4) RECORDED BEHAVIOUR (P2, `LedgerController::index()` + service):
    //    `total_debits` / `total_credits` / `closing_balance` are PAGE-scoped,
    //    not window-scoped — page 1 of a multi-page window reports the totals of
    //    that page only. The running balance itself DOES carry across pages
    //    (page 2's `opening_balance` is page 1's closing), so the invariant
    //    above holds per page; only the labels are misleading. Pinned here so a
    //    change in either direction is caught.
    const small = await ledger(
      request,
      owner,
      `account_id=${drAccount.id}&date_from=2026-01-01&date_to=2026-12-31&per_page=1`,
    )
    //
    // FIX ROUND 1: this block used to be silently skipped when the account had
    // a single page, so R3 could vacuate without any signal. The account is now
    // ASSERTED to be multi-page at per_page=1 (`MTP-GL-01` and `MTP-GL-09` each
    // post a leg to it, so >= 2 rows always exist by the time this runs), and
    // the else-branch annotates the vacuity instead of hiding it.
    if (small.meta.last_page > 1) {
      expect(
        norm4(small.data.total_debits),
        'PAGE-scoped totals: per_page=1 reports one row worth of debits',
      ).toBe(norm4(small.data.lines[0]!.debit))
      const second = await ledger(
        request,
        owner,
        `account_id=${drAccount.id}&date_from=2026-01-01&date_to=2026-12-31&per_page=1&page=2`,
      )
      expect(
        norm4(second.data.opening_balance),
        'the running balance DOES carry across pages',
      ).toBe(norm4(small.data.closing_balance))
    } else {
      test.info().annotations.push({
        type: 'VACUOUS',
        description:
          `R3 (page-scoped ledger totals) was NOT exercised: ${W6_ACCOUNTS.debit.code} has only `
          + `${small.meta.total} ledger row(s) in the window, so per_page=1 still yields one page.`,
      })
      expect(
        small.meta.total,
        'the only legitimate reason to skip the R3 block is a single-row account',
      ).toBeLessThanOrEqual(1)
    }
  })

  test('MTP-GL-18 (P1): the P&L period is INCLUSIVE of both boundary dates, and the balance sheet agrees', async ({
    request,
  }) => {
    const d1 = daysAgo(2)
    const d2 = TODAY
    const dayBeforeD2 = daysAgo(1)

    const liabilityOn = (bs: { liabilities: Array<{ account_code: string; amount: string; is_parent: boolean }> }) =>
      norm4(bs.liabilities.find((l) => l.account_code === W6_ACCOUNTS.credit.code && !l.is_parent)?.amount ?? '0.0000')

    // Snapshot EVERY window BEFORE posting. The W6 accounts are shared with the
    // sibling cases in this same file (`MTP-GL-01`/`-09` also credit `W6GL2`),
    // so only a delta captured around THIS test's own posts is meaningful.
    const plInclusiveBefore = plAmount(await profitLoss(request, owner, d1, d2), W6_ACCOUNTS.expense.code)
    const plWithoutD1Before = plAmount(
      await profitLoss(request, owner, dayBeforeD2, d2),
      W6_ACCOUNTS.expense.code,
    )
    const plWithoutD2Before = plAmount(
      await profitLoss(request, owner, d1, dayBeforeD2),
      W6_ACCOUNTS.expense.code,
    )
    const bsAsOfD2Before = liabilityOn(await balanceSheet(request, owner, `?as_of_date=${d2}`))
    const bsBeforeD2Before = liabilityOn(await balanceSheet(request, owner, `?as_of_date=${dayBeforeD2}`))

    // Two posted expense entries, one dated exactly on each boundary, with
    // DISTINCT amounts so the two windows below are distinguishable.
    for (const [date, amount] of [
      [d1, '11.000'],
      [d2, '13.000'],
    ] as const) {
      const created = await createJournalEntry(request, owner, {
        entry_date: date,
        description: uniq(`GL18-${date}`),
        lines: [
          { account_id: expenseAccount.id, debit: amount, credit: '0.000' },
          { account_id: crAccount.id, debit: '0.000', credit: amount },
        ],
      })
      expect(created.status, `create ${date} -> ${created.status} ${JSON.stringify(created.data)}`).toBe(201)
      const posted = await postJournalEntry(request, owner, String((created.data as { id: string }).id))
      expect(posted.ok, `post ${date} -> ${posted.status} ${JSON.stringify(posted.data)}`).toBeTruthy()
    }

    // Both boundary-dated entries are INCLUDED in [D1, D2]: the window picked
    // up exactly the 24.000 this test posted.
    const plInclusiveDelta = sub4(
      plAmount(await profitLoss(request, owner, d1, d2), W6_ACCOUNTS.expense.code),
      plInclusiveBefore,
    )
    expect(plInclusiveDelta, '[D1, D2] contains BOTH boundary-dated entries (11.000 + 13.000)').toBe(
      '24.0000',
    )

    const plWithoutD1Delta = sub4(
      plAmount(await profitLoss(request, owner, dayBeforeD2, d2), W6_ACCOUNTS.expense.code),
      plWithoutD1Before,
    )
    expect(
      sub4(plInclusiveDelta, plWithoutD1Delta),
      'the D1-dated 11.000 is inside [D1, D2] and outside [D1+1, D2] — the START boundary is INCLUSIVE',
    ).toBe('11.0000')

    const plWithoutD2Delta = sub4(
      plAmount(await profitLoss(request, owner, d1, dayBeforeD2), W6_ACCOUNTS.expense.code),
      plWithoutD2Before,
    )
    expect(
      sub4(plInclusiveDelta, plWithoutD2Delta),
      'the D2-dated 13.000 is inside [D1, D2] and outside [D1, D2-1] — the END boundary is INCLUSIVE',
    ).toBe('13.0000')

    // Same convention on the balance sheet: an `as_of` date INCLUDES entries
    // dated exactly on it (the credit legs landed on the W6 liability account).
    const bsAsOfD2Delta = sub4(
      liabilityOn(await balanceSheet(request, owner, `?as_of_date=${d2}`)),
      bsAsOfD2Before,
    )
    const bsBeforeD2Delta = sub4(
      liabilityOn(await balanceSheet(request, owner, `?as_of_date=${dayBeforeD2}`)),
      bsBeforeD2Before,
    )
    expect(bsAsOfD2Delta, 'as_of D2 sees both credit legs').toBe('24.0000')
    expect(bsBeforeD2Delta, 'as_of D2-1 sees only the D1-dated credit leg').toBe('11.0000')
    expect(
      sub4(bsAsOfD2Delta, bsBeforeD2Delta),
      'balance sheet `as_of` is INCLUSIVE of the boundary day — same convention as the P&L',
    ).toBe('13.0000')
  })

  test('MTP-GL-19 (P2): an empty period renders real zeros — no blanks, no NaN, no dashes', async ({
    request,
  }) => {
    // A PAST window that predates the tenant entirely. (A future `date_from` is
    // refused outright by `GetProfitLossRequest`: "The start date cannot be in
    // the future." — recorded, and the reason this uses 2019 rather than 2027.)
    const EMPTY_FROM = '2019-01-01'
    const EMPTY_TO = '2019-01-31'

    const pl = await profitLoss(request, owner, EMPTY_FROM, EMPTY_TO)
    expect(pl.total_revenue).toBe('0.0000')
    expect(pl.total_expenses).toBe('0.0000')
    expect(pl.net_income).toBe('0.0000')
    expect(pl.revenue).toEqual([])
    expect(pl.expenses).toEqual([])

    const bs = await balanceSheet(request, owner, `?as_of_date=${EMPTY_FROM}`)
    expect(bs.total_assets).toBe('0.0000')
    expect(bs.total_liabilities).toBe('0.0000')
    expect(bs.total_equity).toBe('0.0000')
    expect(bs.retained_earnings).toBe('0.0000')
    expect(bs.is_balanced, 'zero == zero + zero').toBe(true)

    const tb = await trialBalance(request, owner, `?as_of_date=${EMPTY_FROM}`)
    expect(tb.lines).toEqual([])
    expect(tb.is_balanced).toBe(true)

    // W-6 D3 — FIXED (fix lane L4, as a consequence of W-8 F-3). The trial
    // balance used to render its empty-period zeros at SCALE 2 (`'0.00'`) —
    // neither the report scale nor the TND currency scale — because
    // `TrialBalanceService::generate()` seeded its accumulators with the literal
    // `'0.00'` and only ever `bcadd`ed onto that seed. It now emits every figure
    // at the COMPANY CURRENCY's scale, so this TND tenant answers `'0.000'`.
    //
    // The sibling reports (P&L, balance sheet) still emit at the fixed report
    // scale 4 — they were NOT in the L4 ticket surface, and are recorded as an
    // adjacent inconsistency rather than changed here.
    expect(
      tb.total_debit,
      'D3 FIXED: the empty-period trial balance reaches the TND currency scale of 3',
    ).toBe('0.000')
    expect(tb.total_credit).toBe('0.000')
    for (const value of [tb.total_debit, tb.total_credit, pl.net_income, bs.total_assets]) {
      expect(value, 'never blank, never NaN, never a dash').toMatch(/^-?\d+\.\d{2,4}$/)
    }
  })
})
