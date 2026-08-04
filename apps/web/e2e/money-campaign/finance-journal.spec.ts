/**
 * MONEY TEST CAMPAIGN — wave W-6 — journal entry create / balance guard /
 * validation ceilings + chart of accounts: `MTP-GL-01..07` and `MTP-GL-20`
 * (plan §B.6 rows 75 and 79).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real
 * login, real backend, no mocks. Money compared as EXACT decimal strings.
 *
 * CONCURRENCY — THIS FILE ASSUMES `--workers=1`. `MTP-GL-01` asserts an exact
 * DELTA on the shared trial balance for the W-6 accounts, captured immediately
 * before and after its own post. Run only as
 * `--project=chromium --workers=1`.
 *
 * PERMANENCE. A posted journal entry has NO delete route, and neither does an
 * account. Everything this file posts therefore lands on the wave's own
 * dedicated accounts `W6GL1` (asset, debit side) and `W6GL2` (liability, credit
 * side) — the same containment pattern W-4 used with `W4GL1`/`W4GL2` — so a
 * successor wave can subtract W-6 exactly. `MTP-GL-02..07` are all REFUSALS and
 * create nothing; `MTP-GL-20` deliberately stops at a DRAFT (invisible to every
 * report, which filters `je.status = 'posted'`).
 */
import { test, expect } from '@playwright/test'
import { login, patch, type Session } from './treasury-support'
import { loginAsRole } from './helpers'
import {
  TODAY,
  W6_ACCOUNTS,
  createJournalEntry,
  ensureAccount,
  findAccountByCode,
  ledger,
  postJournalEntry,
  recentJournalEntries,
  settleAfterNav,
  tbCredit,
  tbDebit,
  trialBalance,
  uniq,
  add4,
  type AccountRow,
} from './w6-support'

test.describe('GL — journal entries and chart of accounts', () => {
  test.setTimeout(120_000)

  let owner: Session
  let drAccount: AccountRow
  let crAccount: AccountRow

  test.beforeAll(async ({ request }, workerInfo) => {
    // Runtime precondition, not a convention: `MTP-GL-01` asserts an exact
    // delta on the SHARED trial balance, and the Playwright config default
    // off-CI is parallel (`playwright.config.ts:6`).
    expect(
      workerInfo.config.workers,
      'this file asserts exact trial-balance deltas on shared accounts — '
        + 'run it as `--project=chromium --workers=1`',
    ).toBe(1)

    owner = await login(request, 'owner')
    drAccount = await ensureAccount(request, owner, W6_ACCOUNTS.debit)
    crAccount = await ensureAccount(request, owner, W6_ACCOUNTS.credit)
  })

  test('MTP-GL-01 (P0): a balanced entry posts, lands in the list, and moves BOTH accounts by exactly 100.000', async ({
    request,
  }) => {
    const description = uniq('GL01')

    // Snapshot IMMEDIATELY before the post — never a stored constant. Five
    // earlier waves left documented money on this tenant and sibling re-runs
    // move it, so only a self-captured delta is stable.
    const before = await trialBalance(request, owner)
    const drBefore = tbDebit(before, W6_ACCOUNTS.debit.code)
    const crBefore = tbCredit(before, W6_ACCOUNTS.credit.code)

    const created = await createJournalEntry(request, owner, {
      entry_date: TODAY,
      description,
      lines: [
        { account_id: drAccount.id, debit: '100.000', credit: '0.000', description: 'W6 GL-01 Dr' },
        { account_id: crAccount.id, debit: '0.000', credit: '100.000', description: 'W6 GL-01 Cr' },
      ],
    })
    expect(created.status, `create -> ${created.status} ${JSON.stringify(created.data)}`).toBe(201)
    const entryId = String((created.data as { id: string }).id)
    expect(
      (created.data as { status: string }).status,
      'a newly created entry is a DRAFT — posting is a separate, permissioned step',
    ).toBe('draft')

    const posted = await postJournalEntry(request, owner, entryId)
    expect(posted.ok, `post -> ${posted.status} ${JSON.stringify(posted.data)}`).toBeTruthy()
    expect((posted.data as { status: string }).status).toBe('posted')

    // Surface 1: `/finance/journal-entries` (the list the route renders).
    const list = await recentJournalEntries(request, owner)
    expect(
      list.some((e) => e.id === entryId && e.status === 'posted'),
      'the posted entry appears on the first page of GET /journal-entries',
    ).toBeTruthy()

    // Surface 2: `/finance/ledger`, for BOTH accounts. A manual entry carries
    // `source_type='manual'` and `source_id = its own id`
    // (JournalEntryController::store():100-102), which is the only API-layer
    // way to pin a specific entry's legs.
    const drLedger = await ledger(
      request,
      owner,
      `account_id=${drAccount.id}&date_from=${TODAY}&date_to=${TODAY}&per_page=200`,
    )
    const drLine = drLedger.data.lines.find((l) => l.source_id === entryId)
    expect(drLine, `the Dr leg is on the ${W6_ACCOUNTS.debit.code} ledger`).toBeDefined()
    expect(drLine?.debit).toBe('100.0000')
    expect(drLine?.credit).toBe('0.0000')

    const crLedger = await ledger(
      request,
      owner,
      `account_id=${crAccount.id}&date_from=${TODAY}&date_to=${TODAY}&per_page=200`,
    )
    const crLine = crLedger.data.lines.find((l) => l.source_id === entryId)
    expect(crLine, `the Cr leg is on the ${W6_ACCOUNTS.credit.code} ledger`).toBeDefined()
    expect(crLine?.debit).toBe('0.0000')
    expect(crLine?.credit).toBe('100.0000')

    // Surface 3: the trial balance moves by EXACTLY 100.000 on each side.
    const after = await trialBalance(request, owner)
    expect(tbDebit(after, W6_ACCOUNTS.debit.code), `${W6_ACCOUNTS.debit.code} debit +100.000`).toBe(
      add4(drBefore, '100.0000'),
    )
    expect(tbCredit(after, W6_ACCOUNTS.credit.code), `${W6_ACCOUNTS.credit.code} credit +100.000`).toBe(
      add4(crBefore, '100.0000'),
    )
  })

  test('MTP-GL-02 (P0): Dr 100.000 / Cr 99.000 is refused with 422 and persists nothing', async ({
    request,
  }) => {
    const description = uniq('GL02')
    const res = await createJournalEntry(request, owner, {
      entry_date: TODAY,
      description,
      lines: [
        { account_id: drAccount.id, debit: '100.000', credit: '0.000' },
        { account_id: crAccount.id, debit: '0.000', credit: '99.000' },
      ],
    })
    expect(res.status, `unbalanced create -> ${res.status} ${JSON.stringify(res.data)}`).toBe(422)
    expect((res.data as { code: string }).code).toBe('UNBALANCED_ENTRY')

    // "no entry created, no partial lines persisted": the guard runs BEFORE the
    // DB::transaction that writes the header and the lines
    // (JournalEntryController::store():72-90), so nothing at all can exist.
    const list = await recentJournalEntries(request, owner)
    expect(
      list.some((e) => e.description === description),
      'the refused entry is absent from the journal',
    ).toBeFalsy()
  })

  test('MTP-GL-03 (P1): the client balance indicator says BALANCED at a 0.005 gap the server refuses', async ({
    page,
    request,
  }) => {
    // The UI half. `JournalEntryForm.tsx:51-53` computes the indicator with
    // `parseFloat` and `Math.abs(totalDebits - totalCredits) < 0.01` — a FLOAT
    // tolerance an order of magnitude wider than the millime the TND ledger
    // stores. A 0.005 gap therefore renders as "Balanced" and the form submits.
    await loginAsRole(page, 'owner')
    await page.goto('/finance/journal-entries/create')
    await settleAfterNav(page)

    const row0 = page.getByTestId('journal-line-0')
    const row1 = page.getByTestId('journal-line-1')
    await row0.getByLabel('Account').selectOption({ label: `${drAccount.code} - ${drAccount.name}` })
    await row1.getByLabel('Account').selectOption({ label: `${crAccount.code} - ${crAccount.name}` })
    await row0.getByLabel('Debit').fill('100.000')
    await row1.getByLabel('Credit').fill('99.995')

    // OBSERVATION 1 — the indicator is misleading BY DESIGN today.
    await expect(
      page.getByText('Balanced', { exact: true }),
      'MISLEADING-BY-DESIGN: a 0.005 gap renders as Balanced (float tolerance 0.01)',
    ).toBeVisible({ timeout: 15_000 })
    await expect(page.getByText('Unbalanced', { exact: true })).toHaveCount(0)

    // OBSERVATION 2 — the client lets it through and the server refuses it.
    await page.getByRole('button', { name: 'Save as Draft' }).click()
    await expect(
      page.getByText('Failed to create journal entry'),
      'the client-side guard passed it; the server rejected it',
    ).toBeVisible({ timeout: 20_000 })
    expect(page.url(), 'the form stays put — no entry was created').toContain(
      '/finance/journal-entries/create',
    )

    // The exact server contract, asserted at the API layer with the same payload.
    const api = await createJournalEntry(request, owner, {
      entry_date: TODAY,
      description: uniq('GL03'),
      lines: [
        { account_id: drAccount.id, debit: '100.000', credit: '0.000' },
        { account_id: crAccount.id, debit: '0.000', credit: '99.995' },
      ],
    })
    expect(api.status, 'the server refuses the same 0.005 gap').toBe(422)
    expect((api.data as { code: string }).code).toBe('UNBALANCED_ENTRY')
  })

  test('MTP-GL-04 (P0): one line carrying BOTH a debit and a credit is refused', async ({
    request,
  }) => {
    const description = uniq('GL04')
    // Deliberately BALANCED overall (50+50 Dr == 50+50 Cr) so the refusal can
    // only come from the per-line XOR rule, not from the balance guard.
    const res = await createJournalEntry(request, owner, {
      entry_date: TODAY,
      description,
      lines: [
        { account_id: drAccount.id, debit: '50.000', credit: '50.000' },
        { account_id: crAccount.id, debit: '50.000', credit: '50.000' },
      ],
    })
    expect(res.status, `both-sides line -> ${res.status} ${JSON.stringify(res.data)}`).toBe(422)
    expect(
      (res.data as { code: string }).code,
      'DoubleEntryValidator::hasValidLines() requires XOR per line',
    ).toBe('INVALID_LINE')

    const list = await recentJournalEntries(request, owner)
    expect(list.some((e) => e.description === description)).toBeFalsy()
  })

  test('MTP-GL-05 (P1): a line with NEITHER a debit nor a credit is refused', async ({ request }) => {
    const description = uniq('GL05')
    // Two real legs that balance, plus a third all-zero line: the entry passes
    // the balance guard and can only fail on the empty line.
    const res = await createJournalEntry(request, owner, {
      entry_date: TODAY,
      description,
      lines: [
        { account_id: drAccount.id, debit: '100.000', credit: '0.000' },
        { account_id: crAccount.id, debit: '0.000', credit: '100.000' },
        { account_id: drAccount.id, debit: '0.000', credit: '0.000' },
      ],
    })
    expect(res.status, `empty line -> ${res.status} ${JSON.stringify(res.data)}`).toBe(422)
    expect((res.data as { code: string }).code).toBe('INVALID_LINE')

    const list = await recentJournalEntries(request, owner)
    expect(list.some((e) => e.description === description)).toBeFalsy()
  })

  test('MTP-GL-06 (P1): a NEGATIVE debit is refused by the field validator, which names the field', async ({
    request,
  }) => {
    const description = uniq('GL06')
    // The money regex on journal debit/credit permits a leading `-`
    // (`CreateJournalEntryRequest.php:41-42`), so the regex alone would let
    // `-50.000` through. RECORDED BEHAVIOUR: the sibling `min:0` rule on the
    // same field is what refuses it, and the message names the field — the
    // outcome the case demands ("if rejected, the message must name the field").
    const res = await createJournalEntry(request, owner, {
      entry_date: TODAY,
      description,
      lines: [
        { account_id: drAccount.id, debit: '-50.000', credit: '0.000' },
        { account_id: crAccount.id, debit: '0.000', credit: '-50.000' },
      ],
    })
    expect(res.status, `negative debit -> ${res.status} ${JSON.stringify(res.data)}`).toBe(422)

    const errors = (res.data as { errors?: Record<string, string[]> }).errors ?? {}
    const flattened = JSON.stringify(res.data)
    expect(
      Object.keys(errors).some((k) => k.includes('lines.0.debit')) || flattened.includes('lines.0.debit'),
      `the refusal names the offending field: ${flattened}`,
    ).toBeTruthy()

    const list = await recentJournalEntries(request, owner)
    expect(list.some((e) => e.description === description)).toBeFalsy()
  })

  test('MTP-GL-07 (P1): a 4-decimal debit is refused by the money regex ceiling with its own message', async ({
    request,
  }) => {
    const description = uniq('GL07')
    const res = await createJournalEntry(request, owner, {
      entry_date: TODAY,
      description,
      lines: [
        { account_id: drAccount.id, debit: '100.0001', credit: '0.000' },
        { account_id: crAccount.id, debit: '0.000', credit: '100.0001' },
      ],
    })
    expect(res.status, `4dp debit -> ${res.status} ${JSON.stringify(res.data)}`).toBe(422)
    expect(
      JSON.stringify(res.data),
      'the custom message from CreateJournalEntryRequest::messages():53',
    ).toContain('at most 3 decimal places')

    const list = await recentJournalEntries(request, owner)
    expect(list.some((e) => e.description === description)).toBeFalsy()
  })

  test('MTP-GL-20 (P1): an account can be created, edited, and is immediately usable in a new entry', async ({
    request,
  }) => {
    // `accounts` has NO delete route, so this creates the CoA subject once and
    // reuses it on every later run — the account itself is the fixture.
    const account = await ensureAccount(request, owner, W6_ACCOUNTS.coa)
    expect(account.code).toBe(W6_ACCOUNTS.coa.code)

    // Edit: rename to a run-unique name and read it back from the tree.
    const newName = uniq('GL20-renamed')
    const updated = await patch(request, owner, `/accounts/${account.id}`, { name: newName })
    expect(updated.ok, `PATCH /accounts -> ${updated.status} ${JSON.stringify(updated.data)}`).toBeTruthy()

    const reread = await findAccountByCode(request, owner, W6_ACCOUNTS.coa.code)
    expect(reread?.name, 'the edit is visible in the account tree').toBe(newName)

    // Usable in a new journal entry. Deliberately stops at DRAFT: the case asks
    // for usability, and every report filters `je.status='posted'`
    // (TrialBalanceService:187), so a draft adds no money to the tenant.
    const draft = await createJournalEntry(request, owner, {
      entry_date: TODAY,
      description: uniq('GL20-draft'),
      lines: [
        { account_id: reread!.id, debit: '10.000', credit: '0.000' },
        { account_id: crAccount.id, debit: '0.000', credit: '10.000' },
      ],
    })
    expect(draft.status, `draft on the new account -> ${draft.status} ${JSON.stringify(draft.data)}`).toBe(201)
    expect((draft.data as { status: string }).status).toBe('draft')

    // Proof the draft really is invisible to the money reports.
    const tb = await trialBalance(request, owner)
    expect(
      tbDebit(tb, W6_ACCOUNTS.coa.code),
      'a DRAFT entry contributes nothing to the trial balance',
    ).toBe('0.0000')
  })
})
