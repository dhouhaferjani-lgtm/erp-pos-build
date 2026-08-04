/**
 * MONEY TEST CAMPAIGN — wave W-5b — §E.4 `TRE` reconciliation workspace
 * (MTP-TRE-41..49, plan §B.5 row 68, ruling C-2).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real login,
 * real backend, no mocks. Money is compared as EXACT decimal strings (integer
 * millime arithmetic from `treasury-support.ts`) — never a float.
 *
 * FIXTURE: reuses `statement-support.ts` per ruling C-2 (`discoverOrProvisionRepository`,
 * `createParserProfile`, `uploadStatementPreview`, `confirmStatement`). It deliberately
 * does NOT call `buildReconciliationFixture()`: that builder authors its Tier-4
 * candidate by creating a DEDICATED POS TERMINAL per build, which pollutes the claim
 * list and threatens the launch's 1-active-terminal enable preflight
 * (`docs/superpowers/tickets/2026-08-02-w2-wave-minor-findings.md`). Every case here
 * builds its match candidates from repository ADJUSTMENTS — the same Tier-1 candidate
 * `createTier1Adjustment()` builds, called directly only so the case can capture the
 * produced `movement_id`, which that helper does not return.
 *
 * Each case gets its OWN statement on its OWN repository. Reconciliation state is
 * irreversible in practice (a completed statement stamps a repository checkpoint; a
 * statement carrying executions can never be voided), so cases must never share one —
 * per the W-5b brief.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import { login, get, post, del, addMoney, subMoney, TODAY, type Session } from './treasury-support'
import {
  confirmStatement,
  createParserProfile,
  deactivateRepository,
  discoverOrProvisionRepository,
  getChequeMethod,
  issueTier3OutboundCheque,
  retireParserProfile,
  uploadStatementPreview,
  uniq,
  type BankRepository,
} from './statement-support'

let owner: Session

function displayDate(isoDate: string): string {
  return isoDate.split('-').reverse().join('/')
}

function isoDaysAgo(days: number): string {
  const date = new Date(`${TODAY}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() - days)
  return date.toISOString().slice(0, 10)
}

interface StatementLine {
  id: string
  value_date: string
  direction: string
  amount: string
  reference: string | null
  label: string
  match_status: string
  ignore_reason: string | null
  allocations: Array<{ repository_movement_id: string; matched_amount: string; match_type: string }>
  executions: Array<{ action_type: string; target_type: string | null; target_id: string | null }>
}

interface StatementRow {
  /** Signed: positive credits the account (`in`), negative debits it (`out`). */
  amount: string
  reference: string
  label: string
  valueDate?: string
}

interface BuiltStatement {
  repository: BankRepository
  statementId: string
  profileId: string
  opening: string
  closing: string
  lines: StatementLine[]
}

async function readStatement(
  request: APIRequestContext,
  statementId: string,
): Promise<{ status: string; opening_balance: string; closing_balance: string; lines: StatementLine[] }> {
  const res = await get(request, owner, `/bank-statements/${statementId}`)
  expect(res.ok, `read statement -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as {
    status: string
    opening_balance: string
    closing_balance: string
    lines: StatementLine[]
  }
}

/** Posts a repository adjustment and returns the movement it produced. */
async function adjustment(
  request: APIRequestContext,
  repositoryId: string,
  direction: 'in' | 'out',
  amount: string,
  reasonText: string,
): Promise<string> {
  const res = await post(request, owner, `/payment-repositories/${repositoryId}/adjustments`, {
    direction,
    amount,
    reason_code: 'count_variance',
    reason_text: reasonText,
  })
  expect(res.ok, `adjustment ${direction} ${amount} -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  expect(res.data.balance_after, 'the adjustment reports a balance at scale 3').toMatch(/^-?\d+\.\d{3}$/)
  return String(res.data.movement_id)
}

/**
 * Imports a dedicated statement carrying exactly `rows`, on a repository that
 * currently holds no open statement. `opening` is read AFTER any candidate
 * movements the caller already created, and `closing` is `opening + Σ signed
 * rows` — the identity `StatementCompletionService` re-checks at completion.
 */
async function buildStatement(
  request: APIRequestContext,
  repository: BankRepository,
  rows: StatementRow[],
  profileOverrides: Record<string, unknown> = {},
): Promise<BuiltStatement> {
  const profileId = await createParserProfile(request, owner, repository.id, profileOverrides)
  fixtures.profileIds.push(profileId)
  const fileLabel = uniq('stmt')
  const csv = [
    'Date,Amount,Reference,Transaction ID,Label',
    ...rows.map(
      (row) =>
        `${displayDate(row.valueDate ?? TODAY)},${row.amount},${row.reference},TX-${row.reference},${row.label}`,
    ),
    '',
  ].join('\n')

  const preview = await uploadStatementPreview(
    request,
    owner,
    repository.id,
    profileId,
    csv,
    `${fileLabel}.csv`,
  )
  expect(preview.acceptedLineCount, 'every authored row is accepted').toBe(rows.length)

  const balance = await get(request, owner, `/payment-repositories/${repository.id}`)
  const opening = String(balance.data.balance)
  const closing = rows.reduce((total, row) => addMoney(total, row.amount), opening)
  const earliest = rows
    .map((row) => row.valueDate ?? TODAY)
    .sort()[0]!

  const statementId = await confirmStatement(
    request,
    owner,
    repository.id,
    repository.currency,
    preview.previewToken,
    opening,
    closing,
    { period_start: earliest, period_end: TODAY },
  )
  const statement = await readStatement(request, statementId)
  expect(statement.lines).toHaveLength(rows.length)
  return { repository, statementId, profileId, opening, closing, lines: statement.lines }
}

/**
 * Fixture retirement (fix round 1, I-2a/I-2b). Every profile and every
 * self-provisioned `C2-STMT-*` repository this file creates is retired after
 * its case: profiles via DELETE (or `is_active:false` when a statement
 * references them — the fallback the API itself prescribes), repositories via
 * `is_active:false` (no DELETE route exists). Retiring a consumed repository
 * also keeps it out of `discoverOrProvisionRepository`'s O(repositories)
 * candidate scan, which is what made each successive run slower.
 *
 * Retirement runs in `afterEach`, NOT in a `finally`: a throwing cleanup
 * inside `finally` REPLACES the in-flight exception (JS discards the original),
 * whereas Playwright reports an afterEach failure ALONGSIDE the test's own
 * error. The assertion is additionally gated on the test having passed, so a
 * cleanup problem can never be mistaken for the case's verdict (M-1).
 *
 * Balances created by the Tier-1/2 adjustment candidates are NOT unwound —
 * each is backed by a real balanced GL entry on the 6xxx/7xxx tolerance
 * accounts, so reversing them would post more entries, not fewer. They are
 * disclosed by exact amount in the wave ledger instead.
 */
interface CaseFixtures {
  profileIds: string[]
  repositoryIds: string[]
}

let fixtures: CaseFixtures = { profileIds: [], repositoryIds: [] }

interface Suggestion {
  tier: number
  kind: string
  movement_ids: string[]
  amount: string
  reason_code: string | null
  action_type: string | null
  target_type: string | null
  target_id: string | null
}

async function suggestionsFor(
  request: APIRequestContext,
  lineId: string,
): Promise<Suggestion[]> {
  const res = await get(request, owner, `/bank-statement-lines/${lineId}/suggestions`)
  expect(res.ok, `suggestions -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as Suggestion[]
}

async function allocate(
  request: APIRequestContext,
  session: Session,
  lineId: string,
  movementId: string,
  amount: string,
): Promise<{ status: number; data: Record<string, unknown> }> {
  const res = await post(request, session, `/bank-statement-lines/${lineId}/allocations`, {
    allocations: [{ repository_movement_id: movementId, amount }],
  })
  return { status: res.status, data: res.data }
}

async function freshRepository(request: APIRequestContext): Promise<BankRepository> {
  const { repository } = await discoverOrProvisionRepository(request, owner)
  // Only retire what this wave provisioned. A SEEDED repository (BANK-0x) that
  // discovery happened to pick must never be deactivated — it belongs to the
  // tenant, not to the fixture.
  if (repository.code.startsWith('C2-STMT-')) fixtures.repositoryIds.push(repository.id)
  return repository
}

test.describe('MTP-TRE — reconciliation workspace (W-5b §E.4)', () => {
  test.describe.configure({ timeout: 240_000 })

  test.beforeAll(async ({ request }) => {
    owner = await login(request, 'owner')
  })

  test.beforeEach(() => {
    fixtures = { profileIds: [], repositoryIds: [] }
  })

  test.afterEach(async ({ request }, testInfo) => {
    const statuses: number[] = []
    for (const profileId of fixtures.profileIds) {
      statuses.push(await retireParserProfile(request, owner, profileId))
    }
    for (const repositoryId of fixtures.repositoryIds) {
      statuses.push(await deactivateRepository(request, owner, repositoryId))
    }
    // Only assert the retirement when the case itself passed, so a cleanup
    // status can never be confused with the case's verdict (M-1).
    if (testInfo.status === testInfo.expectedStatus) {
      expect(
        statuses.every((status) => status < 300),
        `fixture retirement statuses: ${JSON.stringify(statuses)}`,
      ).toBe(true)
    }
  })

  test('MTP-TRE-41 (P0): an exact same-amount movement is suggested and allocates once', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    const reference = uniq('T41')
    const movementId = await adjustment(request, repository.id, 'in', '500.000', reference)
    const built = await buildStatement(request, repository, [
      { amount: '500.000', reference, label: `W5b TRE-41 ${reference}` },
    ])
    const line = built.lines[0]!
    expect(line.amount).toBe('500.000')
    expect(line.direction).toBe('in')
    expect(line.match_status).toBe('unmatched')

    const suggestions = await suggestionsFor(request, line.id)
    const exact = suggestions.find((s) => s.movement_ids.includes(movementId))
    expect(exact, `the exact-amount movement is suggested: ${JSON.stringify(suggestions)}`).toBeTruthy()
    expect(exact!.tier, 'reference + exact remaining amount is Tier 1').toBe(1)
    expect(exact!.kind).toBe('movement')
    expect(exact!.amount, 'the suggested amount is the exact line amount').toBe('500.000')
    expect(exact!.reason_code).toBe('reference_amount_match')

    const accepted = await allocate(request, owner, line.id, movementId, exact!.amount)
    expect(accepted.status, `accept -> ${JSON.stringify(accepted.data)}`).toBeLessThan(300)

    const afterMatch = await readStatement(request, built.statementId)
    const matchedLine = afterMatch.lines[0]!
    expect(matchedLine.match_status, 'the line resolves as matched').toBe('matched')
    expect(matchedLine.allocations, 'the movement is allocated exactly once').toHaveLength(1)
    expect(matchedLine.allocations[0]!.repository_movement_id).toBe(movementId)
    expect(matchedLine.allocations[0]!.matched_amount, 'allocated at the exact amount').toBe('500.000')
    expect(afterMatch.status, 'the statement moves to reconciling on first match').toBe('reconciling')

    // Allocating the same movement a second time is refused — never double-matched.
    const repeat = await allocate(request, owner, line.id, movementId, '500.000')
    expect(repeat.status, 'a movement cannot be allocated twice to the same line').toBe(422)
    const afterRepeat = await readStatement(request, built.statementId)
    expect(afterRepeat.lines[0]!.allocations).toHaveLength(1)
  })

  test('MTP-TRE-41b (P1, I-4): Tier 2 — a unique amount inside the window is suggested without a reference', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    // Deliberately UNRELATED texts: Tier 1 needs a reference match, so keeping
    // the movement's notes out of the line text is what forces Tier 2 to be the
    // only thing that can fire. The amount is deliberately odd so it is unique
    // among the repository's movements inside the window.
    const movementReference = uniq('T41bMOV')
    const lineReference = uniq('T41bLINE')
    const movementId = await adjustment(request, repository.id, 'in', '777.321', movementReference)
    const built = await buildStatement(request, repository, [
      { amount: '777.321', reference: lineReference, label: `W5b TRE-41b ${lineReference}` },
    ])
    const line = built.lines[0]!
    expect(line.amount).toBe('777.321')

    const suggestions = await suggestionsFor(request, line.id)
    const unique = suggestions.find((s) => s.movement_ids.includes(movementId))
    expect(unique, `the unique in-window amount is suggested: ${JSON.stringify(suggestions)}`).toBeTruthy()
    expect(unique!.tier, 'no reference match, so this is Tier 2 — not Tier 1').toBe(2)
    expect(unique!.reason_code).toBe('unique_amount_window')
    expect(unique!.amount, 'suggested at the exact remaining line amount').toBe('777.321')
    expect(
      suggestions.filter((s) => s.tier === 1),
      'Tier 1 must NOT fire — the reference deliberately does not match',
    ).toHaveLength(0)

    const accepted = await allocate(request, owner, line.id, movementId, unique!.amount)
    expect(accepted.status, `accept Tier 2 -> ${JSON.stringify(accepted.data)}`).toBeLessThan(300)
    const after = await readStatement(request, built.statementId)
    expect(after.lines[0]!.match_status).toBe('matched')
    expect(after.lines[0]!.allocations[0]!.matched_amount, 'allocated at the exact amount').toBe('777.321')
  })

  test('MTP-TRE-41c (P1, I-4): Tier 3 — an outbound cheque is offered as an `outbound_clear` action', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    const chequeMethodId = await getChequeMethod(request, owner)
    const reference = uniq('T41c')
    // A REAL outbound instrument: expense -> post -> pay by cheque drawn on this
    // repository. Reachable with no POS terminal, which is why Tier 3 is in
    // scope here while Tier 4 (acquirer-fee card settlement) stays deferred —
    // Tier 4's only fixture recipe creates a dedicated terminal per build and
    // threatens the 1-active-terminal enable preflight
    // (docs/superpowers/tickets/2026-08-02-w2-wave-minor-findings.md).
    const instrumentId = await issueTier3OutboundCheque(
      request,
      owner,
      repository.id,
      chequeMethodId,
      '37.125',
      reference,
    )
    // The bank debit the cheque will produce when it clears.
    const built = await buildStatement(request, repository, [
      { amount: '-37.125', reference, label: `W5b TRE-41c ${reference}` },
    ])
    const line = built.lines[0]!
    expect(line.direction, 'an issued cheque leaves the bank').toBe('out')
    expect(line.amount).toBe('37.125')

    const suggestions = await suggestionsFor(request, line.id)
    const instrumentSuggestion = suggestions.find((s) => s.target_id === instrumentId)
    expect(
      instrumentSuggestion,
      `the pending outbound instrument is suggested: ${JSON.stringify(suggestions)}`,
    ).toBeTruthy()
    expect(instrumentSuggestion!.tier, 'instrument matching is Tier 3').toBe(3)
    expect(instrumentSuggestion!.kind, 'Tier 3 resolves through an ACTION, not a movement').toBe('action')
    expect(instrumentSuggestion!.action_type).toBe('outbound_clear')
    expect(instrumentSuggestion!.target_type).toBe('payment_instrument')
    expect(instrumentSuggestion!.amount, 'offered at the exact line amount').toBe('37.125')

    const executed = await post(request, owner, `/bank-statement-lines/${line.id}/actions`, {
      action: 'outbound_clear',
      params: { instrument_id: instrumentId },
    })
    expect(executed.status, `outbound_clear -> ${JSON.stringify(executed.data)}`).toBeLessThan(300)

    const after = await readStatement(request, built.statementId)
    const resolved = after.lines[0]!
    expect(resolved.match_status, 'clearing an instrument MATCHES the line').toBe('matched')
    expect(resolved.executions, 'exactly one execution recorded').toHaveLength(1)
    expect(resolved.executions[0]!.action_type).toBe('outbound_clear')
    expect(resolved.executions[0]!.target_id).toBe(instrumentId)
    expect(resolved.allocations, 'the clearing produced exactly one allocated movement').toHaveLength(1)
    expect(resolved.allocations[0]!.matched_amount, 'allocated at the exact line amount').toBe('37.125')

    // The instrument itself is now cleared.
    const instrument = await get(request, owner, `/payment-instruments/${instrumentId}`)
    expect(instrument.data.status, 'the outbound cheque is cleared').toBe('cleared')
  })

  test('MTP-TRE-42 (P0): a one-millime difference is never matched — no amount tolerance', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    const reference = uniq('T42')
    // The movement is ONE MILLIME larger than the statement line.
    const movementId = await adjustment(request, repository.id, 'in', '500.001', reference)
    const built = await buildStatement(request, repository, [
      { amount: '500.000', reference, label: `W5b TRE-42 ${reference}` },
    ])
    const line = built.lines[0]!
    expect(line.amount).toBe('500.000')

    const suggestions = await suggestionsFor(request, line.id)
    expect(
      suggestions.filter((s) => s.movement_ids.includes(movementId)),
      `matching is exact bccomp — a 500.001 movement is never offered for a 500.000 line: ${JSON.stringify(suggestions)}`,
    ).toHaveLength(0)

    // A manual match cannot absorb the millime either: allocating the movement's
    // FULL 500.001 against a 500.000 line is refused.
    const overAllocation = await allocate(request, owner, line.id, movementId, '500.001')
    expect(
      overAllocation.status,
      `allocating 500.001 to a 500.000 line -> ${JSON.stringify(overAllocation.data)}`,
    ).toBe(422)

    // A 500.000 partial allocation IS permitted — and it does not silently
    // absorb the millime: exactly 0.001 of the movement stays unallocated and
    // therefore still unreconciled. Record the residual explicitly.
    const partial = await allocate(request, owner, line.id, movementId, '500.000')
    expect(partial.status, `partial allocation -> ${JSON.stringify(partial.data)}`).toBeLessThan(300)
    const afterPartial = await readStatement(request, built.statementId)
    const matchedLine = afterPartial.lines[0]!
    expect(matchedLine.match_status).toBe('matched')
    expect(matchedLine.allocations[0]!.matched_amount, 'exactly the line amount, not the movement amount').toBe(
      '500.000',
    )
    const movement = await get(
      request,
      owner,
      `/payment-repositories/${repository.id}/movements?search=${reference}&per_page=100`,
    )
    const rows = movement.data as unknown as Array<{ id: string; amount: string }>
    expect(rows.find((row) => row.id === movementId)!.amount, 'the movement keeps its full amount').toBe(
      '500.001',
    )
    expect(
      subMoney('500.001', matchedLine.allocations[0]!.matched_amount),
      'the unreconciled residual is exactly one millime — visible, not absorbed',
    ).toBe('0.001')
  })

  test('MTP-TRE-43 (P1): a movement 6 days outside a 5-day window is not suggested but stays manually reachable', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    // Deliberately UNRELATED texts so Tier 1 (reference match, which is
    // window-INDEPENDENT by design) can never fire and mask the window test.
    const movementReference = uniq('T43MOV')
    const lineReference = uniq('T43LINE')
    const movementId = await adjustment(request, repository.id, 'in', '321.000', movementReference)
    const lineDate = isoDaysAgo(6)
    const built = await buildStatement(
      request,
      repository,
      [{ amount: '321.000', reference: lineReference, label: `W5b TRE-43 ${lineReference}`, valueDate: lineDate }],
      { matching_window_days: 5 },
    )
    const line = built.lines[0]!
    expect(line.value_date, 'the line is dated 6 days before the movement').toBe(lineDate)

    const suggestions = await suggestionsFor(request, line.id)
    expect(
      suggestions.filter((s) => s.movement_ids.includes(movementId)),
      `outside ±5 days the movement is not suggested: ${JSON.stringify(suggestions)}`,
    ).toHaveLength(0)

    // Still reachable manually — the window bounds SUGGESTIONS, not matching.
    const manual = await allocate(request, owner, line.id, movementId, '321.000')
    expect(manual.status, `manual allocation -> ${JSON.stringify(manual.data)}`).toBeLessThan(300)
    const afterManual = await readStatement(request, built.statementId)
    expect(afterManual.lines[0]!.match_status).toBe('matched')
    expect(afterManual.lines[0]!.allocations[0]!.matched_amount).toBe('321.000')
    expect(afterManual.lines[0]!.allocations[0]!.match_type, 'recorded as a manual match').toBe('manual')
  })

  test('MTP-TRE-44 (P1): create-from-line resolves the line by creation at the exact amount', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    const reference = uniq('T44')
    // An `out` line with no counterpart anywhere — the create-from-line case.
    const built = await buildStatement(request, repository, [
      { amount: '-42.750', reference, label: `W5b TRE-44 ${reference}` },
    ])
    const line = built.lines[0]!
    expect(line.direction).toBe('out')
    expect(line.amount).toBe('42.750')
    expect(line.match_status).toBe('unmatched')

    const categories = await get(request, owner, '/expense-categories')
    const categoryId = (categories.data as unknown as Array<{ id: string }>)[0]!.id

    const created = await post(request, owner, `/bank-statement-lines/${line.id}/actions`, {
      action: 'create_expense',
      params: {
        expense_category_id: categoryId,
        vendor_name: `W5b TRE-44 vendor ${reference}`,
        notes: 'W5b MTP-TRE-44 create-from-line',
      },
    })
    expect(created.status, `create-from-line -> ${JSON.stringify(created.data)}`).toBeLessThan(300)

    const afterCreate = await readStatement(request, built.statementId)
    const resolved = afterCreate.lines[0]!
    expect(resolved.match_status, 'the line resolves by creation').toBe('resolved_by_creation')
    expect(resolved.executions, 'exactly one execution recorded').toHaveLength(1)
    expect(resolved.executions[0]!.action_type).toBe('create_expense')
    expect(resolved.executions[0]!.target_type).toBe('expense_document')
    expect(resolved.executions[0]!.target_id, 'the created document is linked').toBeTruthy()
    expect(resolved.allocations, 'the created expense produced exactly one allocated movement').toHaveLength(1)
    expect(resolved.allocations[0]!.matched_amount, 'allocated at the exact line amount').toBe('42.750')
  })

  test('MTP-TRE-45 (P1): an allocated line cannot be ignored', async ({ request }) => {
    const repository = await freshRepository(request)
    const allocatedRef = uniq('T45A')
    const freeRef = uniq('T45B')
    const movementId = await adjustment(request, repository.id, 'in', '88.500', allocatedRef)
    const built = await buildStatement(request, repository, [
      { amount: '88.500', reference: allocatedRef, label: `W5b TRE-45 allocated ${allocatedRef}` },
      { amount: '11.500', reference: freeRef, label: `W5b TRE-45 free ${freeRef}` },
    ])
    const allocatedLine = built.lines.find((l) => l.reference === allocatedRef)!
    const freeLine = built.lines.find((l) => l.reference === freeRef)!

    const allocated = await allocate(request, owner, allocatedLine.id, movementId, '88.500')
    expect(allocated.status).toBeLessThan(300)

    const refusedIgnore = await post(request, owner, `/bank-statement-lines/${allocatedLine.id}/ignore`, {
      reason: 'informational',
      text: 'W5b MTP-TRE-45 — must be refused',
    })
    expect(
      refusedIgnore.status,
      `ignoring an allocated line -> ${JSON.stringify(refusedIgnore.data)}`,
    ).toBe(422)
    expect(JSON.stringify(refusedIgnore.data)).toContain('allocations')

    // The control: an unallocated line ignores fine, proving the refusal is
    // about the allocation and not about the payload or the permission.
    const acceptedIgnore = await post(request, owner, `/bank-statement-lines/${freeLine.id}/ignore`, {
      reason: 'informational',
      text: 'W5b MTP-TRE-45 control — no allocations',
    })
    expect(acceptedIgnore.status, `ignoring a free line -> ${JSON.stringify(acceptedIgnore.data)}`).toBeLessThan(
      300,
    )

    const after = await readStatement(request, built.statementId)
    expect(after.lines.find((l) => l.reference === allocatedRef)!.match_status).toBe('matched')
    expect(after.lines.find((l) => l.reference === freeRef)!.match_status).toBe('ignored')
    expect(after.lines.find((l) => l.reference === freeRef)!.ignore_reason).toBe('informational')

    // Cleanup: un-ignore so the statement is not left in a shape that needs a
    // reopen-privileged acknowledgment to ever complete.
    const unignored = await del(request, owner, `/bank-statement-lines/${freeLine.id}/ignore`)
    expect(unignored.status, 'the ignore is reversible').toBeLessThan(300)
  })

  test('MTP-TRE-46 (P0): completion is refused while any line is unresolved', async ({ request }) => {
    const repository = await freshRepository(request)
    const resolvedRef = uniq('T46A')
    const danglingRef = uniq('T46B')
    const movementId = await adjustment(request, repository.id, 'in', '300.000', resolvedRef)
    const built = await buildStatement(request, repository, [
      { amount: '300.000', reference: resolvedRef, label: `W5b TRE-46 resolved ${resolvedRef}` },
      { amount: '25.000', reference: danglingRef, label: `W5b TRE-46 dangling ${danglingRef}` },
    ])
    const resolvedLine = built.lines.find((l) => l.reference === resolvedRef)!
    expect((await allocate(request, owner, resolvedLine.id, movementId, '300.000')).status).toBeLessThan(300)

    const refused = await post(request, owner, `/bank-statements/${built.statementId}/complete`, {})
    expect(refused.status, `complete with one unresolved line -> ${JSON.stringify(refused.data)}`).toBe(422)
    expect(JSON.stringify(refused.data)).toContain('terminal')

    const after = await readStatement(request, built.statementId)
    expect(after.status, 'the statement stays reconciling — never silently closed').toBe('reconciling')
    expect(after.lines.find((l) => l.reference === danglingRef)!.match_status).toBe('unmatched')
  })

  test('MTP-TRE-47 (P1): a fully resolved statement completes, and reopen is its own permission', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    const firstRef = uniq('T47A')
    const secondRef = uniq('T47B')
    const firstMovement = await adjustment(request, repository.id, 'in', '640.125', firstRef)
    const secondMovement = await adjustment(request, repository.id, 'in', '359.875', secondRef)
    const built = await buildStatement(request, repository, [
      { amount: '640.125', reference: firstRef, label: `W5b TRE-47 a ${firstRef}` },
      { amount: '359.875', reference: secondRef, label: `W5b TRE-47 b ${secondRef}` },
    ])
    expect(
      addMoney('640.125', '359.875'),
      'the two lines sum to exactly 1000.000',
    ).toBe('1000.000')
    for (const [reference, movementId, amount] of [
      [firstRef, firstMovement, '640.125'],
      [secondRef, secondMovement, '359.875'],
    ] as const) {
      const line = built.lines.find((l) => l.reference === reference)!
      expect((await allocate(request, owner, line.id, movementId, amount)).status).toBeLessThan(300)
    }

    // The accountant holds bank-statements.view/import/reconcile but NOT .reopen.
    const accountant = await login(request, 'accountant')
    expect(accountant.permissions, 'precondition: accountant can reconcile').toContain(
      'bank-statements.reconcile',
    )
    expect(accountant.permissions, 'precondition: accountant cannot reopen').not.toContain(
      'bank-statements.reopen',
    )

    const completed = await post(request, accountant, `/bank-statements/${built.statementId}/complete`, {})
    expect(completed.status, `complete -> ${JSON.stringify(completed.data)}`).toBeLessThan(300)
    expect((await readStatement(request, built.statementId)).status).toBe('reconciled')

    // A reconciled statement is frozen for matching.
    const frozen = await post(request, owner, `/bank-statement-lines/${built.lines[0]!.id}/ignore`, {
      reason: 'informational',
      text: 'W5b MTP-TRE-47 — reconciled statements are frozen',
    })
    expect(frozen.status, 'a reconciled statement cannot be changed').toBe(422)

    // Reopen is gated by its OWN distinct permission.
    const refusedReopen = await post(request, accountant, `/bank-statements/${built.statementId}/reopen`, {})
    expect(refusedReopen.status, 'reconcile does not imply reopen').toBe(403)
    expect((await readStatement(request, built.statementId)).status).toBe('reconciled')

    const reopened = await post(request, owner, `/bank-statements/${built.statementId}/reopen`, {})
    expect(reopened.status, `reopen as owner -> ${JSON.stringify(reopened.data)}`).toBeLessThan(300)
    const afterReopen = await readStatement(request, built.statementId)
    expect(afterReopen.status, 'reopen restores editability').toBe('reconciling')
    expect(afterReopen.closing_balance, 'balances survive the round trip unrounded').toBe(built.closing)

    // Editability really is restored: unallocate and re-allocate one line.
    const line = afterReopen.lines.find((l) => l.reference === firstRef)!
    const unallocated = await del(
      request,
      owner,
      `/bank-statement-lines/${line.id}/allocations/${firstMovement}`,
    )
    expect(unallocated.status, 'the reopened statement accepts mutations again').toBeLessThan(300)
    expect((await allocate(request, owner, line.id, firstMovement, '640.125')).status).toBeLessThan(300)
    const recompleted = await post(request, owner, `/bank-statements/${built.statementId}/complete`, {})
    expect(recompleted.status, 'and can be completed again').toBeLessThan(300)
  })

  test('MTP-TRE-48 (P1): the bank-statement permission gate — import and reconcile are separate', async ({
    request,
  }) => {
    test.info().annotations.push({
      type: 'PARTIAL',
      description:
        "The plan's literal principal — a user holding bank-statements.view and NOTHING " +
        'else — does not exist in this tenant: RolesAndPermissionsSeeder grants the ' +
        'accountant view+import+reconcile (never reopen) and grants manager/cashier/viewer ' +
        'no bank-statement permission at all. This case therefore asserts the two ' +
        'principals that DO exist: accountant (import+reconcile allowed, reopen refused — ' +
        'proving the four permissions are genuinely distinct) and manager (no ' +
        'bank-statement permission: every route refused, including the list). The ' +
        '"list stays visible while writes are refused" half needs a view-only role ' +
        '(campaign fixture debt C-3).',
    })
    const repository = await freshRepository(request)
    const reference = uniq('T48')
    const movementId = await adjustment(request, repository.id, 'in', '64.000', reference)
    const built = await buildStatement(request, repository, [
      { amount: '64.000', reference, label: `W5b TRE-48 ${reference}` },
    ])
    const line = built.lines[0]!

    const accountant = await login(request, 'accountant')
    const manager = await login(request, 'manager')
    expect(manager.permissions.some((p) => p.startsWith('bank-statements.')), 'precondition').toBe(false)

    const managerList = await get(request, manager, '/bank-statements')
    const managerRead = await get(request, manager, `/bank-statements/${built.statementId}`)
    const managerSuggest = await get(request, manager, `/bank-statement-lines/${line.id}/suggestions`)
    const managerAllocate = await allocate(request, manager, line.id, movementId, '64.000')
    expect(
      {
        list: managerList.status,
        read: managerRead.status,
        suggestions: managerSuggest.status,
        allocate: managerAllocate.status,
      },
      'a principal with no bank-statement permission is refused everywhere',
    ).toEqual({ list: 403, read: 403, suggestions: 403, allocate: 403 })

    const accountantList = await get(request, accountant, '/bank-statements')
    const accountantSuggest = await get(request, accountant, `/bank-statement-lines/${line.id}/suggestions`)
    const accountantReopen = await post(request, accountant, `/bank-statements/${built.statementId}/reopen`, {})
    expect(
      { list: accountantList.status, suggestions: accountantSuggest.status, reopen: accountantReopen.status },
      'reconcile is granted, reopen is a separate permission and is refused',
    ).toEqual({ list: 200, suggestions: 200, reopen: 403 })

    // The refused writes changed nothing.
    const after = await readStatement(request, built.statementId)
    expect(after.status).toBe('imported')
    expect(after.lines[0]!.match_status).toBe('unmatched')
    expect(after.lines[0]!.allocations).toHaveLength(0)
  })

  test('MTP-TRE-49 (P1): a completed statement satisfies closing == opening + Σ signed lines exactly', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    const inRef = uniq('T49IN')
    const outRef = uniq('T49OUT')
    const inMovement = await adjustment(request, repository.id, 'in', '1234.567', inRef)
    const outMovement = await adjustment(request, repository.id, 'out', '234.567', outRef)
    const built = await buildStatement(request, repository, [
      { amount: '1234.567', reference: inRef, label: `W5b TRE-49 credit ${inRef}` },
      { amount: '-234.567', reference: outRef, label: `W5b TRE-49 debit ${outRef}` },
    ])
    for (const [reference, movementId, amount] of [
      [inRef, inMovement, '1234.567'],
      [outRef, outMovement, '234.567'],
    ] as const) {
      const line = built.lines.find((l) => l.reference === reference)!
      expect((await allocate(request, owner, line.id, movementId, amount)).status).toBeLessThan(300)
    }
    const completed = await post(request, owner, `/bank-statements/${built.statementId}/complete`, {})
    expect(completed.status, `complete -> ${JSON.stringify(completed.data)}`).toBeLessThan(300)

    const statement = await readStatement(request, built.statementId)
    expect(statement.status).toBe('reconciled')
    // Σ signed lines, recomputed from the PERSISTED rows with integer millimes.
    const signedTotal = statement.lines.reduce(
      (total, line) => (line.direction === 'in' ? addMoney(total, line.amount) : subMoney(total, line.amount)),
      '0.000',
    )
    expect(signedTotal, '1234.567 - 234.567').toBe('1000.000')
    expect(
      addMoney(statement.opening_balance, signedTotal),
      'closing == opening + Σ signed line amounts, exactly at scale 3',
    ).toBe(statement.closing_balance)
    expect(
      subMoney(statement.closing_balance, statement.opening_balance),
      'and the reverse identity holds too',
    ).toBe(signedTotal)
    expect(statement.opening_balance).toMatch(/^-?\d+\.\d{3}$/)
    expect(statement.closing_balance).toMatch(/^-?\d+\.\d{3}$/)
  })
})
