/**
 * MONEY TEST CAMPAIGN — wave W-5b — §E.5 `TRE` repositories: transfers,
 * adjustments, movements ledger (MTP-TRE-50..59, plan §B.5 row 69).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real login,
 * real backend, no mocks. Money is compared as EXACT decimal strings (integer
 * millime arithmetic from `treasury-support.ts`) — never a float.
 *
 * PLAN-vs-TENANT deviation on the stated preconditions: the plan's fixtures
 * ("CASH-01 balance `1000.000`, BANK-01 balance `5000.000`") do not describe this
 * tenant — `DemoPharmacySeeder` seeds different balances and every other money case
 * in this campaign moves them. Assertions are therefore on EXACT DELTAS and on
 * exact absolute balances of repositories this file provisions itself, which is
 * strictly stronger than an absolute assertion against a shared, drifting balance.
 *
 * Junk hygiene: every repository this file provisions carries a `W5B-` code and is
 * DEACTIVATED (`PATCH is_active:false`, asserted `< 300`) at the end of its case.
 * `payment_repositories` has no DELETE route, so deactivation is the only retire
 * path. The single transfer between the two SEEDED repositories (`MTP-TRE-50`) is
 * reversed in the same case, and both balances are asserted back to their exact
 * baselines — no drift is left behind.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import {
  login,
  get,
  post,
  patch,
  addMoney,
  subMoney,
  getRepositories,
  uniqueName,
  type Session,
} from './treasury-support'
import { loginAsRole } from './helpers'

let owner: Session
let cashRepoId: string
let bankRepoId: string
let virtualRepoId: string
let noGlRepoId: string
let bankGlAccountId: string

interface MovementRow {
  id: string
  direction: string
  amount: string
  balance_after: string
  ordinal: number
  source_type: string
  source_id: string
  journal_entry_id: string | null
  reason_code: string | null
}

interface JournalLine {
  account_code: string
  account_name: string
  debit: string
  credit: string
}

async function balanceOf(request: APIRequestContext, repositoryId: string): Promise<string> {
  const res = await get(request, owner, `/payment-repositories/${repositoryId}`)
  expect(res.ok, `read repository -> ${res.status}`).toBeTruthy()
  const balance = String(res.data.balance)
  expect(balance, 'balances are always rendered at scale 3').toMatch(/^-?\d+\.\d{3}$/)
  return balance
}

async function movements(
  request: APIRequestContext,
  repositoryId: string,
  query = '',
): Promise<MovementRow[]> {
  const res = await get(
    request,
    owner,
    `/payment-repositories/${repositoryId}/movements${query === '' ? '' : `?${query}`}`,
  )
  expect(res.ok, `movements -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as MovementRow[]
}

async function journalLines(request: APIRequestContext, journalEntryId: string): Promise<JournalLine[]> {
  const res = await get(request, owner, `/journal-entries/${journalEntryId}`)
  expect(res.ok, `journal entry -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return (res.data as unknown as { lines: JournalLine[] }).lines
}

/** Σdebit and Σcredit as exact decimal strings — a balanced entry has them equal. */
function ledgerTotals(lines: JournalLine[]): { debit: string; credit: string } {
  return lines.reduce(
    (totals, line) => ({
      debit: addMoney(totals.debit, line.debit),
      credit: addMoney(totals.credit, line.credit),
    }),
    { debit: '0.000', credit: '0.000' },
  )
}

/** Provisions a dedicated, GL-linked repository so absolute balances are exact. */
async function provisionRepository(
  request: APIRequestContext,
  type: 'cash_register' | 'bank_account',
  label: string,
): Promise<string> {
  const created = await post(request, owner, '/payment-repositories', {
    code: `W5B-${label}-${Date.now().toString(36).toUpperCase().slice(-6)}`,
    name: `W5b ${label} fixture ${uniqueName(label)}`,
    type,
    gl_account_id: bankGlAccountId,
  })
  expect(created.ok, `provision ${label} -> ${created.status} ${JSON.stringify(created.data)}`).toBeTruthy()
  expect(created.data.balance, 'a fresh repository opens at zero').toBe('0.000')
  return String(created.data.id)
}

/** `payment_repositories` has no DELETE route — deactivation is the retire path. */
async function retireRepository(request: APIRequestContext, repositoryId: string): Promise<void> {
  const retired = await patch(request, owner, `/payment-repositories/${repositoryId}`, {
    is_active: false,
  })
  expect(retired.status, `retire fixture repository -> ${JSON.stringify(retired.data)}`).toBeLessThan(300)
}

async function adjust(
  request: APIRequestContext,
  session: Session,
  repositoryId: string,
  payload: Record<string, unknown>,
): Promise<{ status: number; data: Record<string, unknown> }> {
  const res = await post(request, session, `/payment-repositories/${repositoryId}/adjustments`, payload)
  return { status: res.status, data: res.data }
}

async function transfer(
  request: APIRequestContext,
  session: Session,
  fromId: string,
  toId: string,
  amount: string,
  notes: string,
): Promise<{ status: number; data: Record<string, unknown> }> {
  const res = await post(request, session, '/payment-repositories/transfers', {
    from_repository_id: fromId,
    to_repository_id: toId,
    amount,
    notes,
  })
  return { status: res.status, data: res.data }
}

test.describe('MTP-TRE — repositories, transfers and adjustments (W-5b §E.5)', () => {
  test.describe.configure({ timeout: 180_000 })

  test.beforeAll(async ({ request }) => {
    owner = await login(request, 'owner')
    const repos = await getRepositories(request, owner)
    cashRepoId = repos.find((r) => r.code === 'CASH-01')!.id
    bankRepoId = repos.find((r) => r.code === 'BANK-01')!.id
    virtualRepoId = repos.find((r) => r.type === 'virtual')!.id
    noGlRepoId = repos.find((r) => r.gl_account_id === null && r.is_active)!.id
    bankGlAccountId = repos.find((r) => r.code === 'BANK-01')!.gl_account_id!
  })

  test('MTP-TRE-50 (P0): a transfer moves exactly the amount, as two legs on one balanced GL entry', async ({
    request,
  }) => {
    const cashBefore = await balanceOf(request, cashRepoId)
    const bankBefore = await balanceOf(request, bankRepoId)
    const notes = uniqueName('T50')

    const moved = await transfer(request, owner, cashRepoId, bankRepoId, '500.000', notes)
    expect(moved.status, `transfer -> ${JSON.stringify(moved.data)}`).toBeLessThan(300)
    const groupId = String(moved.data.transfer_group_id)
    const journalEntryId = String(moved.data.journal_entry_id)
    const out = moved.data.out as { movement_id: string; balance_after: string }
    const inLeg = moved.data.in as { movement_id: string; balance_after: string }
    expect(groupId).toMatch(/^[0-9a-f-]{36}$/)
    expect(journalEntryId, 'a cross-GL transfer carries a journal entry').toMatch(/^[0-9a-f-]{36}$/)

    // Exact balances, both from the transfer response and re-read from the API.
    expect(out.balance_after, 'source debited by exactly 500.000').toBe(subMoney(cashBefore, '500.000'))
    expect(inLeg.balance_after, 'destination credited by exactly 500.000').toBe(
      addMoney(bankBefore, '500.000'),
    )
    expect(await balanceOf(request, cashRepoId)).toBe(subMoney(cashBefore, '500.000'))
    expect(await balanceOf(request, bankRepoId)).toBe(addMoney(bankBefore, '500.000'))

    // Exactly two movements share the transfer group id (one per repository) —
    // both legs carry `source_id = transfer_group_id`.
    const outMovements = await movements(request, cashRepoId, `search=${groupId}`)
    const inMovements = await movements(request, bankRepoId, `search=${groupId}`)
    expect(outMovements, 'exactly one out leg').toHaveLength(1)
    expect(inMovements, 'exactly one in leg').toHaveLength(1)
    expect([outMovements[0]!.id, inMovements[0]!.id].sort()).toEqual(
      [out.movement_id, inLeg.movement_id].sort(),
    )
    expect(
      {
        outDirection: outMovements[0]!.direction,
        inDirection: inMovements[0]!.direction,
        outSource: outMovements[0]!.source_type,
        inSource: inMovements[0]!.source_type,
        outAmount: outMovements[0]!.amount,
        inAmount: inMovements[0]!.amount,
      },
      'paired out/in transfer legs of the same exact amount',
    ).toEqual({
      outDirection: 'out',
      inDirection: 'in',
      outSource: 'transfer',
      inSource: 'transfer',
      outAmount: '500.000',
      inAmount: '500.000',
    })
    expect(outMovements[0]!.source_id).toBe(groupId)
    expect(inMovements[0]!.source_id).toBe(groupId)
    expect(
      [outMovements[0]!.journal_entry_id, inMovements[0]!.journal_entry_id],
      'ONE journal entry, shared by both legs',
    ).toEqual([journalEntryId, journalEntryId])

    const lines = await journalLines(request, journalEntryId)
    const totals = ledgerTotals(lines)
    expect(totals.debit, 'the GL entry debits exactly 500.000').toBe('500.000')
    expect(totals.credit, 'and credits exactly 500.000 — balanced').toBe('500.000')

    // Restore both seeded balances exactly, so this case leaves no drift.
    const restored = await transfer(request, owner, bankRepoId, cashRepoId, '500.000', `${notes}-undo`)
    expect(restored.status, `restore -> ${JSON.stringify(restored.data)}`).toBeLessThan(300)
    expect(await balanceOf(request, cashRepoId), 'CASH-01 back to its exact baseline').toBe(cashBefore)
    expect(await balanceOf(request, bankRepoId), 'BANK-01 back to its exact baseline').toBe(bankBefore)
  })

  test('MTP-TRE-51 (P0): BLOCKED — no non-TND repository can exist in this tenant', async ({
    request,
  }) => {
    test.info().annotations.push({
      type: 'BLOCKED',
      description:
        'A cross-currency transfer cannot be constructed against demo-pharmacy-tn: every ' +
        'payment repository is TND, and repository currency is NOT settable over HTTP — ' +
        'neither PaymentRepositoryController::store() nor ::update() validates or accepts a ' +
        '`currency` field, so it is always inherited from the company. Fabricating one would ' +
        'require a direct DB write, which is out of scope for a web campaign. The guard ' +
        'itself is real and unit-reachable: TreasuryMovementService::transfer() throws ' +
        'CurrencyMismatchException (extends DomainException -> HTTP 422) when either locked ' +
        'repository currency differs from the intent currency, and TransferCashModal.tsx:99-101 ' +
        'filters the destination picker to the source currency. Needs a multi-currency tenant ' +
        '(campaign fixture debt C-9, demo-garage FR/EUR + TN/TND).',
    })
    // Evidence for the BLOCKED verdict: the tenant is single-currency.
    const repos = await getRepositories(request, owner)
    expect(repos.length).toBeGreaterThan(0)
    expect(
      [...new Set(repos.map((r) => r.currency))],
      'every repository in this tenant is TND — no cross-currency pair exists',
    ).toEqual(['TND'])
  })

  test('MTP-TRE-52 (P1): a virtual repository is excluded from the picker and refused by the API', async ({
    request,
    page,
  }) => {
    const virtualBefore = await balanceOf(request, virtualRepoId)
    const cashBefore = await balanceOf(request, cashRepoId)

    const outbound = await transfer(request, owner, virtualRepoId, cashRepoId, '10.000', uniqueName('T52a'))
    const inbound = await transfer(request, owner, cashRepoId, virtualRepoId, '10.000', uniqueName('T52b'))
    expect(
      { fromVirtual: outbound.status, toVirtual: inbound.status },
      'a virtual bucket cannot take part in a cash transfer, in either direction',
    ).toEqual({ fromVirtual: 422, toVirtual: 422 })
    expect(JSON.stringify(outbound.data)).toContain('virtual')
    expect(await balanceOf(request, virtualRepoId), 'refused: not one millime moved').toBe(virtualBefore)
    expect(await balanceOf(request, cashRepoId)).toBe(cashBefore)

    // FE layer (rule 12, both layers): the picker never offers it.
    await loginAsRole(page, 'owner')
    await page.goto('/treasury/repositories')
    await page.getByRole('button', { name: /transfer|transfert|virement/i }).first().click()
    await expect(page.getByRole('option', { name: /\(BANK-01\)/ }).first()).toBeAttached({
      timeout: 20_000,
    })
    await expect(
      page.getByRole('option', { name: /\(VIRT-01\)/ }),
      'the virtual repository is excluded from both transfer pickers',
    ).toHaveCount(0)
  })

  test('MTP-TRE-53 (P1): transferring more than the balance — recorded actual', async ({ request }) => {
    const repositoryId = await provisionRepository(request, 'cash_register', 'T53')
    try {
      const seeded = await adjust(request, owner, repositoryId, {
        direction: 'in',
        amount: '100.000',
        reason_code: 'correction',
        reason_text: 'W5b MTP-TRE-53 opening float',
      })
      expect(seeded.status).toBeLessThan(300)
      expect(seeded.data.balance_after, 'the fixture starts at exactly 100.000').toBe('100.000')

      const overdraw = await transfer(
        request,
        owner,
        repositoryId,
        bankRepoId,
        '500.000',
        uniqueName('T53'),
      )
      const after = await balanceOf(request, repositoryId)
      // The plan asks for the ACTUAL to be recorded, not a chosen expectation.
      test.info().annotations.push({
        type: 'RECORDED ACTUAL',
        description:
          `Transferring 500.000 out of a repository holding exactly 100.000 -> HTTP ` +
          `${overdraw.status}; balance after = ${after}. There is NO sufficient-funds guard: ` +
          'RepositoryTransferService/TreasuryMovementService validate currency, GL linkage, ' +
          'repository type, frozen state and self-transfer — never the balance. Overdraw is ' +
          'permitted by design and the negative balance is recorded, rendered signed at ' +
          'scale 3, and visible in the append-only movements ledger.',
      })

      if (overdraw.status >= 300) {
        expect(after, 'refused: the balance is untouched').toBe('100.000')
      } else {
        // RECORDED ACTUAL: overdraw is permitted and the balance goes negative.
        // It must then render correctly as a signed scale-3 figure and be
        // visible in the movements ledger.
        expect(after, '100.000 - 500.000').toBe('-400.000')
        expect(after).toMatch(/^-\d+\.\d{3}$/)
        const ledger = await movements(request, repositoryId)
        expect(ledger[0]!.direction).toBe('out')
        expect(ledger[0]!.amount).toBe('500.000')
        expect(ledger[0]!.balance_after, 'the negative balance is recorded, not clamped').toBe('-400.000')
        // Put the money back so the retired fixture does not carry a negative.
        const restored = await transfer(
          request,
          owner,
          bankRepoId,
          repositoryId,
          '500.000',
          `${uniqueName('T53')}-undo`,
        )
        expect(restored.status).toBeLessThan(300)
        expect(await balanceOf(request, repositoryId)).toBe('100.000')
      }
    } finally {
      await retireRepository(request, repositoryId)
    }
  })

  test('MTP-TRE-54 (P0): an `out` adjustment debits the expense tolerance account exactly', async ({
    request,
  }) => {
    const repositoryId = await provisionRepository(request, 'cash_register', 'T54')
    try {
      const seed = await adjust(request, owner, repositoryId, {
        direction: 'in',
        amount: '1000.000',
        reason_code: 'correction',
        reason_text: 'W5b MTP-TRE-54 opening float',
      })
      expect(seed.status).toBeLessThan(300)
      expect(seed.data.balance_after).toBe('1000.000')

      const reasonText = `W5b MTP-TRE-54 ${uniqueName('T54')}`
      const adjusted = await adjust(request, owner, repositoryId, {
        direction: 'out',
        amount: '12.500',
        reason_code: 'count_variance',
        reason_text: reasonText,
      })
      expect(adjusted.status, `adjust out -> ${JSON.stringify(adjusted.data)}`).toBeLessThan(300)
      expect(adjusted.data.balance_after, '1000.000 - 12.500').toBe('987.500')
      expect(await balanceOf(request, repositoryId)).toBe('987.500')

      const ledger = await movements(request, repositoryId)
      const movement = ledger[0]!
      expect(
        {
          direction: movement.direction,
          amount: movement.amount,
          source: movement.source_type,
          reason: movement.reason_code,
        },
        'exactly one Adjustment movement, at the exact amount',
      ).toEqual({ direction: 'out', amount: '12.500', source: 'adjustment', reason: 'count_variance' })
      expect(
        ledger.filter((row) => row.source_type === 'adjustment' && row.reason_code === 'count_variance'),
        'the adjustment produced exactly one movement',
      ).toHaveLength(1)
      expect(movement.journal_entry_id, 'the movement always carries its GL entry').toBeTruthy()

      const lines = await journalLines(request, movement.journal_entry_id!)
      const totals = ledgerTotals(lines)
      expect(
        { debit: totals.debit, credit: totals.credit },
        'balanced at exactly the adjustment amount',
      ).toEqual({ debit: '12.500', credit: '12.500' })
      const debited = lines.find((line) => line.debit !== '0.000')!
      const credited = lines.find((line) => line.credit !== '0.000')!
      expect(debited.debit, 'Dr tolerance expense 12.500').toBe('12.500')
      expect(
        debited.account_code.startsWith('6'),
        `an OUT adjustment debits a CHARGE account, got ${debited.account_code} ${debited.account_name}`,
      ).toBe(true)
      expect(credited.credit, 'Cr the repository account 12.500').toBe('12.500')
      expect(credited.account_code, 'the repository GL account is credited').toBe('512')
    } finally {
      await retireRepository(request, repositoryId)
    }
  })

  test('MTP-TRE-55 (P1): an `in` adjustment credits the INCOME tolerance account, not the expense one', async ({
    request,
  }) => {
    const repositoryId = await provisionRepository(request, 'cash_register', 'T55')
    try {
      const seed = await adjust(request, owner, repositoryId, {
        direction: 'in',
        amount: '1000.000',
        reason_code: 'correction',
        reason_text: 'W5b MTP-TRE-55 opening float',
      })
      expect(seed.status).toBeLessThan(300)

      const adjusted = await adjust(request, owner, repositoryId, {
        direction: 'in',
        amount: '12.500',
        reason_code: 'count_variance',
        reason_text: `W5b MTP-TRE-55 ${uniqueName('T55')}`,
      })
      expect(adjusted.status, `adjust in -> ${JSON.stringify(adjusted.data)}`).toBeLessThan(300)
      expect(adjusted.data.balance_after, '1000.000 + 12.500').toBe('1012.500')
      expect(await balanceOf(request, repositoryId)).toBe('1012.500')

      const ledger = await movements(request, repositoryId)
      const movement = ledger[0]!
      expect(movement.direction).toBe('in')
      expect(movement.amount).toBe('12.500')
      const lines = await journalLines(request, movement.journal_entry_id!)
      expect(ledgerTotals(lines), 'balanced at exactly the adjustment amount').toEqual({
        debit: '12.500',
        credit: '12.500',
      })
      const debited = lines.find((line) => line.debit !== '0.000')!
      const credited = lines.find((line) => line.credit !== '0.000')!
      // The mirror of MTP-TRE-54: the repository account is now DEBITED and the
      // tolerance INCOME (produit, 7xxx) account is credited — never the 6xxx
      // expense account that an `out` adjustment uses.
      expect(debited.account_code, 'Dr the repository GL account').toBe('512')
      expect(debited.debit).toBe('12.500')
      expect(
        credited.account_code.startsWith('7'),
        `an IN adjustment credits a PRODUIT account, got ${credited.account_code} ${credited.account_name}`,
      ).toBe(true)
      expect(credited.credit).toBe('12.500')
      expect(
        credited.account_code.startsWith('6'),
        'the Income purpose is used, NOT the expense purpose',
      ).toBe(false)
    } finally {
      await retireRepository(request, repositoryId)
    }
  })

  test('MTP-TRE-56 (P0): a repository with no GL account refuses adjustments explicitly', async ({
    request,
  }) => {
    const before = await balanceOf(request, noGlRepoId)
    const refused = await adjust(request, owner, noGlRepoId, {
      direction: 'in',
      amount: '25.000',
      reason_code: 'correction',
      reason_text: `W5b MTP-TRE-56 ${uniqueName('T56')}`,
    })
    expect(
      refused.status,
      `adjustment on a GL-less repository -> ${JSON.stringify(refused.data)}`,
    ).toBe(422)
    // "Errors EXPLICITLY" — the refusal names the cause, it is not a bare 422.
    expect(JSON.stringify(refused.data)).toMatch(/GL account/i)
    // Never a silent balance change with no GL counterpart.
    expect(await balanceOf(request, noGlRepoId), 'the balance is untouched').toBe(before)
    const ledger = await movements(request, noGlRepoId)
    expect(
      ledger.filter((row) => row.source_type === 'adjustment' && row.journal_entry_id === null),
      'no GL-less adjustment movement was written',
    ).toHaveLength(0)
  })

  test('MTP-TRE-57 (P1): reason_text is required on an adjustment', async ({ request }) => {
    const repositoryId = await provisionRepository(request, 'cash_register', 'T57')
    try {
      const missing = await adjust(request, owner, repositoryId, {
        direction: 'out',
        amount: '12.500',
        reason_code: 'count_variance',
      })
      const blank = await adjust(request, owner, repositoryId, {
        direction: 'out',
        amount: '12.500',
        reason_code: 'count_variance',
        reason_text: '   ',
      })
      expect(
        { missing: missing.status, blank: blank.status },
        'an unexplained adjustment is refused',
      ).toEqual({ missing: 422, blank: 422 })
      expect(await balanceOf(request, repositoryId), 'nothing moved').toBe('0.000')
      expect(await movements(request, repositoryId), 'no movement was written').toHaveLength(0)

      // Control: the identical payload WITH a reason succeeds, proving the
      // refusal is about the reason text and nothing else.
      const accepted = await adjust(request, owner, repositoryId, {
        direction: 'out',
        amount: '12.500',
        reason_code: 'count_variance',
        reason_text: `W5b MTP-TRE-57 ${uniqueName('T57')}`,
      })
      expect(accepted.status, `control -> ${JSON.stringify(accepted.data)}`).toBeLessThan(300)
      expect(accepted.data.balance_after).toBe('-12.500')
    } finally {
      await retireRepository(request, repositoryId)
    }
  })

  test('MTP-TRE-58 (P1): the movements ledger is append-only and its running balance is exact', async ({
    request,
    page,
  }) => {
    const repositoryId = await provisionRepository(request, 'bank_account', 'T58')
    try {
      const steps: Array<{ direction: 'in' | 'out'; amount: string }> = [
        { direction: 'in', amount: '1000.000' },
        { direction: 'out', amount: '250.125' },
        { direction: 'in', amount: '0.125' },
      ]
      let expected = '0.000'
      for (const step of steps) {
        const res = await adjust(request, owner, repositoryId, {
          direction: step.direction,
          amount: step.amount,
          reason_code: 'correction',
          reason_text: `W5b MTP-TRE-58 ${step.direction} ${step.amount}`,
        })
        expect(res.status, `${step.direction} ${step.amount} -> ${JSON.stringify(res.data)}`).toBeLessThan(300)
        expected = step.direction === 'in' ? addMoney(expected, step.amount) : subMoney(expected, step.amount)
        expect(res.data.balance_after, 'each movement reports the exact running balance').toBe(expected)
      }
      expect(expected, '1000.000 - 250.125 + 0.125').toBe('750.000')

      const header = await balanceOf(request, repositoryId)
      const ledger = await movements(request, repositoryId)
      expect(ledger, 'three appended movements').toHaveLength(3)
      expect(
        ledger[0]!.balance_after,
        'the newest movement reconciles to the header balance exactly',
      ).toBe(header)
      expect(header).toBe('750.000')

      // Gapless, monotonic ordinals newest-first, and an exact running-balance
      // chain: every row's balance_after is the previous one +/- its amount.
      const ordinals = ledger.map((row) => row.ordinal)
      expect(ordinals, 'ordinals are strictly descending and gapless').toEqual([
        ordinals[0],
        ordinals[0]! - 1,
        ordinals[0]! - 2,
      ])
      for (let index = 0; index < ledger.length - 1; index += 1) {
        const newer = ledger[index]!
        const older = ledger[index + 1]!
        const recomputed =
          newer.direction === 'in'
            ? addMoney(older.balance_after, newer.amount)
            : subMoney(older.balance_after, newer.amount)
        expect(newer.balance_after, `chain link ${index} recomputes exactly`).toBe(recomputed)
      }
      const oldest = ledger[ledger.length - 1]!
      expect(oldest.balance_after, 'the first movement starts from a zero opening').toBe(oldest.amount)

      // Append-only at the HTTP layer: there is no mutate route on a movement.
      const editAttempt = await patch(
        request,
        owner,
        `/payment-repositories/${repositoryId}/movements/${ledger[0]!.id}`,
        { amount: '1.000' },
      )
      expect(editAttempt.status, 'no PATCH route exists on a movement').toBeGreaterThanOrEqual(404)

      // FE layer: the Movements tab offers no edit/delete affordance.
      await loginAsRole(page, 'owner')
      await page.goto(`/treasury/repositories/${repositoryId}`)
      await page.getByRole('tab', { name: /movements|mouvements/i }).click()
      await expect(page.getByText(/750,000|750\.000/).first()).toBeVisible({ timeout: 20_000 })
      await expect(
        page.getByRole('button', { name: /delete|supprimer|edit|modifier/i }),
        'an append-only ledger exposes no mutate affordance',
      ).toHaveCount(0)
    } finally {
      await retireRepository(request, repositoryId)
    }
  })

  test('MTP-TRE-59 (P1): a read-only treasury principal cannot adjust or transfer', async ({ request }) => {
    test.info().annotations.push({
      type: 'PARTIAL',
      description:
        "The plan's literal principal — a user holding treasury.view and NOTHING else — does " +
        'not exist in this tenant: RolesAndPermissionsSeeder grants treasury.view only ' +
        'together with treasury.adjust + treasury.transfer (manager, accountant, admin), and ' +
        'grants viewer/cashier no treasury.* at all. This case therefore uses `viewer` — the ' +
        'seeded READ-ONLY treasury principal (repositories.view, instruments.view, no ' +
        'treasury.*) — which is the risk the plan is guarding against: a read-only user must ' +
        'not be able to move money. Splitting treasury.view from adjust/transfer in a seeded ' +
        'role would make the case literal (campaign fixture debt C-3).',
    })
    const repositoryId = await provisionRepository(request, 'cash_register', 'T59')
    try {
      const viewer = await login(request, 'viewer')
      expect(viewer.permissions, 'precondition: viewer can read repositories').toContain(
        'repositories.view',
      )
      expect(
        viewer.permissions.filter((p) => p.startsWith('treasury.')),
        'precondition: viewer holds no treasury.* permission',
      ).toEqual([])

      const read = await get(request, viewer, '/payment-repositories')
      const adjusted = await adjust(request, viewer, repositoryId, {
        direction: 'in',
        amount: '10.000',
        reason_code: 'correction',
        reason_text: 'W5b MTP-TRE-59 must be refused',
      })
      const moved = await transfer(request, viewer, repositoryId, bankRepoId, '10.000', uniqueName('T59'))
      const ledger = await get(request, viewer, `/payment-repositories/${repositoryId}/movements`)
      expect(
        { read: read.status, adjust: adjusted.status, transfer: moved.status, movements: ledger.status },
        'the read stays allowed; both money-moving routes are refused',
      ).toEqual({ read: 200, adjust: 403, transfer: 403, movements: 403 })

      expect(await balanceOf(request, repositoryId), 'the refused writes moved nothing').toBe('0.000')
      expect(await movements(request, repositoryId)).toHaveLength(0)
    } finally {
      await retireRepository(request, repositoryId)
    }
  })
})
