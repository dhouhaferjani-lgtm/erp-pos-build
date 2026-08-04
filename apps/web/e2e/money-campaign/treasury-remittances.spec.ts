/**
 * MONEY TEST CAMPAIGN — wave W-5b — §E.3 `TRE` remittances / bordereaux
 * (MTP-TRE-28..35, plan §B.5 row 66).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real login,
 * real backend, no mocks. Money is compared as EXACT decimal strings computed with
 * integer millime arithmetic (`treasury-support.ts` `addMoney`) — never a float.
 *
 * CONCURRENCY — THIS FILE ASSUMES `--workers=1` (N-5). `MTP-TRE-33` asserts
 * EXACT BANK-01 balance DELTAS around the per-line clear/bounce (`+594.050`,
 * `-11.900`). BANK-01 is a SHARED seeded repository, so any concurrently
 * running case that moves money through it would corrupt those deltas. The
 * campaign's mandated invocation is
 * `--project=chromium --workers=1`; do not parallelise this file, and do not
 * convert the deltas to absolute balances (they drift across the campaign).
 *
 * FIXTURE CHOICE — standalone instruments, not payment-carried ones.
 * `treasury-instruments.spec.ts` (W2a, MTP-TRE-17..27) builds each cheque through
 * invoice -> confirm -> post -> POST /payments with an inline `instrument`, because
 * ITS cases are about the payment/receivable side of the instrument lifecycle. These
 * cases are about the BORDEREAU: composition, the displayed total, and the remit
 * transition. `POST /payment-instruments` (`instruments.create`,
 * PaymentInstrumentController::store) creates exactly the same
 * `received`/`cheque`/`inbound` row in one request, which is what MTP-TRE-30 (>= 20
 * lines) makes practical at all. Verified live: a slip built this way remits
 * (HTTP 200) and every line's instrument lands on `deposited`.
 *
 * PLAN-vs-PRODUCT deviation recorded for MTP-TRE-28/30 (the "displayed total" cases):
 * the plan says `/treasury/remittances/new` -> add lines -> read the total.
 * `RemittanceCreatePage.tsx` renders NO running total and its single
 * "Create and remit" button creates the slip, adds every line AND remits in one go
 * (lines 60-85) — a draft is never visible there. The only screen that renders a
 * remittance total is `RemittanceDetailPage.tsx:78,93`
 * (`slip.lines.reduce(...).toFixed(3)` -> `formatCurrency`), which renders it for a
 * DRAFT slip too. These cases therefore compose the draft over the API and assert
 * the total on the detail page — the same displayed figure the plan targets.
 *
 * The plan's "precision suspicion" for MTP-TRE-30 (a client-side FLOAT reduce) no
 * longer holds on this checkout: `RemittanceDetailPage.tsx:2,78` imports `big.js` and
 * reduces with `new Big(0).plus(...)`. The case is kept as the regression guard that
 * pins it.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import {
  login,
  getPaymentMethods,
  getRepositories,
  get,
  post,
  del,
  addMoney,
  subMoney,
  getRepositoryBalance,
  uniqueName,
  TODAY,
  type Session,
} from './treasury-support'
import { loginAsRole } from './helpers'
import { CLEANUP_THREW, isCleanupSuccess } from './statement-support'

let owner: Session
let bankRepoId: string
let cashRepoId: string
let chequeMethodId: string
let effetMethodId: string

/** Narrow-NBSP / NBSP / plain-space tolerant matcher for a `fr-TN` money render.
 * TND is scale 3 and `formatCurrency` resolves locale `fr-TN`
 * (`currencyMeta.ts`), so `1000.000` renders `1 000,000 TND` with a U+202F
 * group separator and a comma decimal. The regex pins EVERY digit exactly and
 * tolerates only which whitespace codepoint the ICU build emits.
 *
 * Built by slicing the integer part into 3-digit groups and joining them with
 * the separator class directly. An earlier version inserted a placeholder via
 * `String.replace` and split on it, and that placeholder was a literal NUL —
 * which made git classify this whole FILE as binary, so every case in it
 * silently escaped diff review and blame/diff would have been blind forever
 * (fix round 1, I-1). No placeholder is used now: never reintroduce one. */
const FR_GROUP_SEPARATOR = '[\\s\\u202f\\u00a0]'

function frMoneyPattern(exact: string): RegExp {
  const [whole = '0', fraction = ''] = exact.split('.')
  const groups: string[] = []
  let remaining = whole
  while (remaining.length > 3) {
    groups.unshift(remaining.slice(-3))
    remaining = remaining.slice(0, -3)
  }
  groups.unshift(remaining)
  return new RegExp(
    `^${groups.join(FR_GROUP_SEPARATOR)},${fraction}${FR_GROUP_SEPARATOR}TND$`,
  )
}

/** Creates a standalone `received` inbound paper instrument. */
async function receiveInstrument(
  request: APIRequestContext,
  amount: string,
  reference: string,
  kind: 'cheque' | 'effet' = 'cheque',
): Promise<string> {
  const created = await post(request, owner, '/payment-instruments', {
    payment_method_id: kind === 'cheque' ? chequeMethodId : effetMethodId,
    reference,
    amount,
    received_date: TODAY,
    repository_id: cashRepoId,
    drawer_name: `W5b drawer ${reference}`,
    ...(kind === 'effet' ? { maturity_date: '2026-12-31' } : {}),
  })
  expect(created.ok, `receive ${kind} ${reference} -> ${created.status} ${JSON.stringify(created.data)}`).toBeTruthy()
  expect(created.data.status, 'instrument starts in received').toBe('received')
  expect(created.data.kind).toBe(kind)
  expect(created.data.amount).toBe(amount)
  return String(created.data.id)
}

interface SlipLine {
  id: string
  instrument_id: string
  amount: string
  line_status: string
  instrument: { id: string; status: string; currency: string; reference: string }
}

interface Slip {
  id: string
  number: string
  status: string
  instrument_kind: string
  bank_repository_id: string
  lines: SlipLine[]
}

async function createDraftSlip(
  request: APIRequestContext,
  kind: 'cheque' | 'effet' = 'cheque',
): Promise<Slip> {
  const created = await post(request, owner, '/instrument-remittances', {
    bank_repository_id: bankRepoId,
    remittance_type: 'collection',
    instrument_kind: kind,
  })
  expect(created.ok, `create slip -> ${created.status} ${JSON.stringify(created.data)}`).toBeTruthy()
  expect(created.data.status, 'a new slip is draft').toBe('draft')
  return created.data as unknown as Slip
}

async function readSlip(request: APIRequestContext, slipId: string): Promise<Slip> {
  const res = await get(request, owner, `/instrument-remittances/${slipId}`)
  expect(res.ok, `read slip -> ${res.status}`).toBeTruthy()
  return res.data as unknown as Slip
}

/** The EXACT bcmath-equivalent sum of a slip's line amounts, as a decimal string. */
function sumLines(lines: SlipLine[]): string {
  return lines.reduce((total, line) => addMoney(total, line.amount), '0.000')
}

/**
 * Cleanup (fix round 1, I-2c): a DRAFT slip holds its instruments hostage —
 * `instrument.remittance_id` is set, so `addLine` on any other slip refuses
 * ("Instrument already belongs to an active remittance") and the eligible-
 * instrument picker hides them. Removing the lines returns every instrument to
 * `received` with `remittance_id: null`. There is no DELETE route for the slip
 * itself, so the (now empty) slip row is what genuinely cannot be retired.
 *
 * NEVER THROWS — it returns the observed statuses, using `CLEANUP_THREW` (599)
 * when a call threw rather than answering. A cleanup `expect` inside a `finally`
 * would REPLACE an in-flight exception from the try block (JS semantics: a throw
 * in `finally` discards the original), silently turning a real assertion failure
 * into a cleanup failure. Callers therefore assert these statuses only on the
 * path where the case body already succeeded (M-1).
 *
 * The sentinel is 599, NOT -1: fix round 1 used -1 while every gate read
 * `status < 300`, and `-1 < 300` is TRUE — so a throwing cleanup passed silently
 * and the fixture leaked with no signal (fix round 2, N-1). Gates below check
 * 2xx explicitly.
 */
async function releaseDraftLines(
  request: APIRequestContext,
  slipId: string,
): Promise<number[]> {
  try {
    const res = await get(request, owner, `/instrument-remittances/${slipId}`)
    if (!res.ok) return [res.status]
    const slip = res.data as unknown as Slip
    if (slip.status !== 'draft') return []
    const statuses: number[] = []
    for (const line of slip.lines) {
      const removed = await del(request, owner, `/instrument-remittances/${slipId}/lines/${line.id}`)
      statuses.push(removed.status)
    }
    return statuses
  } catch {
    return [CLEANUP_THREW]
  }
}

/** Runs `body`, then always releases every draft slip in `slipIds`.
 *
 * The release itself ALWAYS RUNS. Its statuses are asserted only when `body`
 * succeeded — that gating is what stops a cleanup problem from REPLACING the
 * case's own failure (M-1); the `finally` alone would do the opposite. The
 * assertion checks 2xx explicitly so a thrown cleanup (`CLEANUP_THREW`) fails
 * loudly instead of slipping through a `< 300` comparison (N-1). */
async function withDraftCleanup(
  request: APIRequestContext,
  slipIds: () => string[],
  body: () => Promise<void>,
): Promise<void> {
  let bodySucceeded = false
  try {
    await body()
    bodySucceeded = true
  } finally {
    const statuses: number[] = []
    for (const slipId of slipIds()) {
      statuses.push(...(await releaseDraftLines(request, slipId)))
    }
    if (bodySucceeded) {
      expect(
        statuses.every(isCleanupSuccess),
        `draft-line cleanup statuses: ${JSON.stringify(statuses)}`,
      ).toBe(true)
    }
  }
}

test.describe('MTP-TRE — remittances / bordereaux (W-5b §E.3)', () => {
  test.describe.configure({ timeout: 180_000 })

  test.beforeAll(async ({ request }) => {
    owner = await login(request, 'owner')
    const repos = await getRepositories(request, owner)
    bankRepoId = repos.find((r) => r.code === 'BANK-01')!.id
    cashRepoId = repos.find((r) => r.code === 'CASH-01')!.id
    const methods = await getPaymentMethods(request, owner)
    chequeMethodId = methods.find((m) => m.code === 'CHECK')!.id
    effetMethodId = methods.find((m) => m.code === 'TRAITE')!.id
  })

  test('MTP-TRE-28 (P0): 333.333 + 333.333 + 333.334 displays exactly 1000.000', async ({
    request,
    page,
  }) => {
    const amounts = ['333.333', '333.333', '333.334']
    const slip = await createDraftSlip(request)
    await withDraftCleanup(request, () => [slip.id], async () => {
    for (const amount of amounts) {
      const instrumentId = await receiveInstrument(request, amount, uniqueName('T28'))
      const line = await post(request, owner, `/instrument-remittances/${slip.id}/lines`, {
        instrument_id: instrumentId,
      })
      expect(line.ok, `add line ${amount} -> ${line.status} ${JSON.stringify(line.data)}`).toBeTruthy()
      expect(line.data.amount, 'the line carries the instrument amount unrounded').toBe(amount)
    }

    const composed = await readSlip(request, slip.id)
    expect(composed.lines).toHaveLength(3)
    expect(composed.lines.map((l) => l.amount).sort()).toEqual(['333.333', '333.333', '333.334'])
    // Exact integer-millime sum: 333333 + 333333 + 333334 = 1000000 millimes.
    expect(sumLines(composed.lines), 'exact bcmath-equivalent slip total').toBe('1000.000')
    expect(composed.status, 'still a draft — the total is read before remitting').toBe('draft')

    await loginAsRole(page, 'owner')
    await page.goto(`/treasury/remittances/${slip.id}`)
    await expect(page.getByRole('heading', { name: composed.number })).toBeVisible({ timeout: 20_000 })
    // The rendered total — the figure the plan asserts. `1 000,000 TND`, never
    // `999,999` or `1 000,001` (a float `.toFixed(3)` artefact). TWO nodes match
    // by design: the on-screen slip footer (RemittanceDetailPage.tsx:93) and the
    // printable bordereau (BordereauPrintView.tsx:54, always in the DOM, hidden
    // outside print). Both are money renders of the same figure, so both are
    // pinned — a mismatch between them would drop the count below 2.
    await expect(
      page.getByText(frMoneyPattern('1000.000')),
      'the slip footer AND the printable bordereau both render exactly 1000.000',
    ).toHaveCount(2)
    await expect(page.getByText(/999,99\d|1[\s\u202f\u00a0]000,001/)).toHaveCount(0)
    })
  })

  test('MTP-TRE-29 (P0): remit deposits every instrument and closes composition', async ({ request }) => {
    const slip = await createDraftSlip(request)
    await withDraftCleanup(request, () => [slip.id], async () => {
    const instrumentIds: string[] = []
    for (const amount of ['120.500', '80.250', '99.250']) {
      const instrumentId = await receiveInstrument(request, amount, uniqueName('T29'))
      instrumentIds.push(instrumentId)
      const line = await post(request, owner, `/instrument-remittances/${slip.id}/lines`, {
        instrument_id: instrumentId,
      })
      expect(line.ok).toBeTruthy()
    }
    const beforeRemit = await readSlip(request, slip.id)
    expect(sumLines(beforeRemit.lines), '120.500 + 80.250 + 99.250').toBe('300.000')

    const remitted = await post(request, owner, `/instrument-remittances/${slip.id}/remit`, {})
    expect(remitted.ok, `remit -> ${remitted.status} ${JSON.stringify(remitted.data)}`).toBeTruthy()
    expect(remitted.data.status, 'slip status remitted').toBe('remitted')
    expect(remitted.data.remitted_at, 'remitted_at stamped').toBeTruthy()

    for (const instrumentId of instrumentIds) {
      const instrument = await get(request, owner, `/payment-instruments/${instrumentId}`)
      expect(instrument.ok).toBeTruthy()
      expect(instrument.data.status, `instrument ${instrumentId} deposited`).toBe('deposited')
      expect(instrument.data.deposited_to_id, 'deposited into the slip bank repository').toBe(bankRepoId)
      expect(instrument.data.deposited_at, 'deposited_at stamped').toBeTruthy()
    }

    // Lines can no longer be added.
    const lateInstrument = await receiveInstrument(request, '10.000', uniqueName('T29late'))
    const lateLine = await post(request, owner, `/instrument-remittances/${slip.id}/lines`, {
      instrument_id: lateInstrument,
    })
    expect(lateLine.status, 'adding a line to a remitted slip is refused').toBe(422)
    expect(JSON.stringify(lateLine.data)).toContain('draft')

    // The total is unchanged by the refusal.
    const afterRemit = await readSlip(request, slip.id)
    expect(sumLines(afterRemit.lines)).toBe('300.000')
    })
  })

  test('MTP-TRE-30 (P1): 20 x 10.005 totals exactly 200.100 — no float artefact', async ({
    request,
    page,
  }) => {
    const slip = await createDraftSlip(request)
    await withDraftCleanup(request, () => [slip.id], async () => {
    for (let index = 0; index < 20; index += 1) {
      const instrumentId = await receiveInstrument(request, '10.005', uniqueName(`T30-${index}`))
      const line = await post(request, owner, `/instrument-remittances/${slip.id}/lines`, {
        instrument_id: instrumentId,
      })
      expect(line.ok, `add line ${index} -> ${line.status}`).toBeTruthy()
    }

    const composed = await readSlip(request, slip.id)
    expect(composed.lines).toHaveLength(20)
    expect(new Set(composed.lines.map((l) => l.amount))).toEqual(new Set(['10.005']))
    // 20 x 10005 millimes = 200100 millimes. In IEEE-754 doubles the naive
    // reduce of twenty 10.005s lands on 200.09999999999997 and `.toFixed(3)`
    // would then render `200.100` anyway — but any FE that formatted at a
    // different scale, or summed in a different order, would drift. This pins
    // the rendered string.
    expect(sumLines(composed.lines), 'exact bcmath-equivalent slip total').toBe('200.100')

    await loginAsRole(page, 'owner')
    await page.goto(`/treasury/remittances/${slip.id}`)
    await expect(page.getByRole('heading', { name: composed.number })).toBeVisible({ timeout: 20_000 })
    // Two nodes by design — slip footer + printable bordereau (see MTP-TRE-28).
    await expect(
      page.getByText(frMoneyPattern('200.100')),
      'the slip footer AND the printable bordereau both render exactly 200.100',
    ).toHaveCount(2)
    // Any surviving float artefact would render one of these.
    await expect(page.getByText(/200,09\d|200,101|200,1(?![0-9])/)).toHaveCount(0)
    })
  })

  test('MTP-TRE-31 (P1): removing a draft line recomputes the total and frees the instrument', async ({
    request,
  }) => {
    const slip = await createDraftSlip(request)
    let secondSlipId = ''
    await withDraftCleanup(request, () => [slip.id, secondSlipId].filter((id) => id !== ''), async () => {
    const kept = await receiveInstrument(request, '250.125', uniqueName('T31keep'))
    const removed = await receiveInstrument(request, '99.875', uniqueName('T31drop'))
    for (const instrumentId of [kept, removed]) {
      const line = await post(request, owner, `/instrument-remittances/${slip.id}/lines`, {
        instrument_id: instrumentId,
      })
      expect(line.ok).toBeTruthy()
    }
    const composed = await readSlip(request, slip.id)
    expect(sumLines(composed.lines), '250.125 + 99.875').toBe('350.000')

    const removedLine = composed.lines.find((l) => l.instrument_id === removed)!
    const removal = await del(request, owner, `/instrument-remittances/${slip.id}/lines/${removedLine.id}`)
    expect(removal.status, 'line removal succeeds on a draft').toBe(204)

    const afterRemoval = await readSlip(request, slip.id)
    expect(afterRemoval.lines).toHaveLength(1)
    expect(sumLines(afterRemoval.lines), 'total recomputes to exactly 250.125').toBe('250.125')

    // The freed instrument is back to `received` and remittable again.
    const freed = await get(request, owner, `/payment-instruments/${removed}`)
    expect(freed.data.status, 'the removed instrument is still received').toBe('received')
    expect(freed.data.remittance_id, 'and is no longer attached to a slip').toBeNull()

    const secondSlip = await createDraftSlip(request)
    secondSlipId = secondSlip.id
    const reAdd = await post(request, owner, `/instrument-remittances/${secondSlip.id}/lines`, {
      instrument_id: removed,
    })
    expect(reAdd.ok, `re-add to a new slip -> ${reAdd.status} ${JSON.stringify(reAdd.data)}`).toBeTruthy()
    expect(reAdd.data.amount).toBe('99.875')
    })
  })

  test('MTP-TRE-32 (P1): a remitted slip refuses both add and remove', async ({ request }) => {
    const slip = await createDraftSlip(request)
    await withDraftCleanup(request, () => [slip.id], async () => {
    const first = await receiveInstrument(request, '55.500', uniqueName('T32a'))
    const second = await receiveInstrument(request, '44.500', uniqueName('T32b'))
    for (const instrumentId of [first, second]) {
      expect((await post(request, owner, `/instrument-remittances/${slip.id}/lines`, {
        instrument_id: instrumentId,
      })).ok).toBeTruthy()
    }
    const composed = await readSlip(request, slip.id)
    expect(sumLines(composed.lines)).toBe('100.000')
    expect((await post(request, owner, `/instrument-remittances/${slip.id}/remit`, {})).ok).toBeTruthy()

    const outsider = await receiveInstrument(request, '10.000', uniqueName('T32c'))
    const add = await post(request, owner, `/instrument-remittances/${slip.id}/lines`, {
      instrument_id: outsider,
    })
    const remove = await del(
      request,
      owner,
      `/instrument-remittances/${slip.id}/lines/${composed.lines[0]!.id}`,
    )
    expect(
      { add: add.status, remove: remove.status },
      'composition is closed on a remitted slip',
    ).toEqual({ add: 422, remove: 422 })

    const afterAttempts = await readSlip(request, slip.id)
    expect(afterAttempts.lines).toHaveLength(2)
    expect(sumLines(afterAttempts.lines), 'total untouched by the refused mutations').toBe('100.000')
    })
  })

  test('MTP-TRE-33 (P1): per-line clear and bounce leave the slip total unchanged', async ({
    request,
  }) => {
    const slip = await createDraftSlip(request)
    await withDraftCleanup(request, () => [slip.id], async () => {
    const toClear = await receiveInstrument(request, '600.000', uniqueName('T33clear'))
    const toBounce = await receiveInstrument(request, '400.000', uniqueName('T33bounce'))
    for (const instrumentId of [toClear, toBounce]) {
      expect((await post(request, owner, `/instrument-remittances/${slip.id}/lines`, {
        instrument_id: instrumentId,
      })).ok).toBeTruthy()
    }
    expect((await post(request, owner, `/instrument-remittances/${slip.id}/remit`, {})).ok).toBeTruthy()

    const remitted = await readSlip(request, slip.id)
    const totalBefore = sumLines(remitted.lines)
    expect(totalBefore, '600.000 + 400.000').toBe('1000.000')
    const clearLine = remitted.lines.find((l) => l.instrument_id === toClear)!
    const bounceLine = remitted.lines.find((l) => l.instrument_id === toBounce)!

    // Same fee fields as the single-instrument path (POST /payment-instruments/{id}/clear).
    // The API echoes no fee field back, so the fees are asserted by their only
    // observable effect: the exact bank-balance delta (M-8). This is the same
    // mechanism W2a's MTP-TRE-19 uses for the single-instrument clear.
    const bankBeforeClear = await getRepositoryBalance(request, owner, bankRepoId)
    const cleared = await post(
      request,
      owner,
      `/instrument-remittances/${slip.id}/lines/${clearLine.id}/clear`,
      { fee_amount: '5.000', fee_vat_amount: '0.950', value_date: TODAY },
    )
    expect(cleared.ok, `clear line -> ${cleared.status} ${JSON.stringify(cleared.data)}`).toBeTruthy()
    expect(cleared.data.line_status).toBe('cleared')
    expect((cleared.data.instrument as { status: string }).status).toBe('cleared')
    const bankAfterClear = await getRepositoryBalance(request, owner, bankRepoId)
    expect(
      subMoney(bankAfterClear, bankBeforeClear),
      'net bank credit is 600.000 - 5.000 fee - 0.950 fee VAT = 594.050',
    ).toBe('594.050')

    const bounced = await post(
      request,
      owner,
      `/instrument-remittances/${slip.id}/lines/${bounceLine.id}/bounce`,
      { routing: 're_present', fee_amount: '10.000', fee_vat_amount: '1.900', reason: 'W5b MTP-TRE-33' },
    )
    expect(bounced.ok, `bounce line -> ${bounced.status} ${JSON.stringify(bounced.data)}`).toBeTruthy()
    expect(bounced.data.line_status).toBe('bounced')
    expect((bounced.data.instrument as { status: string }).status).toBe('bounced')
    const bankAfterBounce = await getRepositoryBalance(request, owner, bankRepoId)
    // Remitting does NOT credit the bank with the face value (the credit happens
    // on clear), so a bounce debits ONLY the bounce fees: -(10.000 + 1.900).
    expect(
      subMoney(bankAfterBounce, bankAfterClear),
      'the bounce debits exactly its 10.000 fee + 1.900 fee VAT, and no principal',
    ).toBe('-11.900')

    const afterOutcomes = await readSlip(request, slip.id)
    expect(afterOutcomes.lines.map((l) => l.amount).sort()).toEqual(['400.000', '600.000'])
    expect(
      sumLines(afterOutcomes.lines),
      'the bordereau total is the face value of its lines — outcomes and fees never move it',
    ).toBe(totalBefore)
    })
  })

  test('MTP-TRE-34 (P1): a user without instruments.remit gets 403 and no remittance screen', async ({
    request,
    page,
  }) => {
    const slip = await createDraftSlip(request)
    await withDraftCleanup(request, () => [slip.id], async () => {
    const instrumentId = await receiveInstrument(request, '77.000', uniqueName('T34'))
    expect((await post(request, owner, `/instrument-remittances/${slip.id}/lines`, {
      instrument_id: instrumentId,
    })).ok).toBeTruthy()

    const cashier = await login(request, 'cashier')
    expect(
      cashier.permissions.includes('instruments.remit'),
      'the cashier role genuinely lacks instruments.remit (precondition)',
    ).toBe(false)

    const read = await get(request, cashier, `/instrument-remittances/${slip.id}`)
    const remit = await post(request, cashier, `/instrument-remittances/${slip.id}/remit`, {})
    const list = await get(request, cashier, '/instrument-remittances')
    expect(
      { read: read.status, remit: remit.status, list: list.status },
      'every instruments.remit route is gated at the API',
    ).toEqual({ read: 403, remit: 403, list: 403 })

    // FE layer (rule 12, both layers): the route guard redirects to /dashboard.
    await loginAsRole(page, 'cashier')
    await page.goto(`/treasury/remittances/${slip.id}`)
    await expect(page, 'the remittance screen is not reachable without the permission').toHaveURL(
      /\/dashboard/,
      { timeout: 20_000 },
    )

    // The draft is still intact and remittable by the owner.
    const stillDraft = await readSlip(request, slip.id)
    expect(stillDraft.status).toBe('draft')
    expect(sumLines(stillDraft.lines)).toBe('77.000')
    })
  })

  test('MTP-TRE-35 (P1): a cheque slip refuses an effet (instrument_kind is per-remittance)', async ({
    request,
  }) => {
    const chequeSlip = await createDraftSlip(request, 'cheque')
    let effetSlipId = ''
    await withDraftCleanup(request, () => [chequeSlip.id, effetSlipId].filter((id) => id !== ''), async () => {
    const cheque = await receiveInstrument(request, '150.000', uniqueName('T35chq'), 'cheque')
    const effet = await receiveInstrument(request, '150.000', uniqueName('T35eft'), 'effet')

    expect((await post(request, owner, `/instrument-remittances/${chequeSlip.id}/lines`, {
      instrument_id: cheque,
    })).ok, 'the matching kind is accepted').toBeTruthy()

    const mixed = await post(request, owner, `/instrument-remittances/${chequeSlip.id}/lines`, {
      instrument_id: effet,
    })
    expect(mixed.status, 'mixing an effet into a cheque slip is refused').toBe(422)
    expect(JSON.stringify(mixed.data)).toContain('kind')

    // And the mirror: an effet slip refuses a cheque.
    const effetSlip = await createDraftSlip(request, 'effet')
    effetSlipId = effetSlip.id
    expect((await post(request, owner, `/instrument-remittances/${effetSlip.id}/lines`, {
      instrument_id: effet,
    })).ok, 'the effet is accepted by an effet slip').toBeTruthy()
    const mirrored = await post(request, owner, `/instrument-remittances/${effetSlip.id}/lines`, {
      instrument_id: cheque,
    })
    expect(mirrored.status, 'mixing a cheque into an effet slip is refused').toBe(422)

    const chequeComposed = await readSlip(request, chequeSlip.id)
    expect(chequeComposed.lines, 'the refused line never landed').toHaveLength(1)
    expect(sumLines(chequeComposed.lines)).toBe('150.000')
    })
  })
})
