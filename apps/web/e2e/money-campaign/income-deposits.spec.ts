/**
 * MONEY TEST CAMPAIGN — wave W-5c — `/income/*` and back-office partner deposits:
 * `MTP-TRE-76`, `MTP-DEP-01..03` (plan §B.5 rows 72 and 74).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real login,
 * real backend, no mocks. Money is compared as EXACT decimal strings.
 *
 * CONCURRENCY — THIS FILE ASSUMES `--workers=1`. Every case asserts exact DELTAS on
 * the SHARED seeded `CASH-01` repository, captured immediately before and after the
 * action. Run only as `--project=chromium --workers=1`.
 *
 * `DEP` is a NEW surface for this campaign. The concrete cases are derived from the
 * route + backend, per the brief:
 *   - `MTP-DEP-01` — the money leg: record a deposit for a customer with nothing
 *     open; the whole amount is CREDITED as advance, the receiving repository rises
 *     by exactly the amount, and the receipt appears in the deposit history.
 *   - `MTP-DEP-02` — FIFO settlement: with one open posted invoice, `settled` +
 *     `credited` == the amount exactly, the invoice goes to `paid` with a zero
 *     balance, and the balances render at scale 3.
 *   - `MTP-DEP-03` — the validation ceiling + the partner-kind gate, and a FINDING.
 *
 * NOT device-gated / feature-gated: `POST|GET /partners/{id}/deposits`
 * (`Partner/routes.php`) is reachable for this tenant under `payments.create` /
 * `payments.view`, confirmed live. No C-16-style gate refusal to record.
 *
 * Junk hygiene: deposits have NO delete route — a recorded `DEPOSIT_RECEIPT` is a
 * sealed fiscal event by design. Every DEP case therefore records against a
 * partner it creates itself (`W5C-DEP*`), so the money it moves is attributable and
 * excludable by a single predicate. `MTP-TRE-76`'s income is POSTED on purpose (that
 * is the case), so it is permanent, deliberate, documented money.
 *
 * `MTP-DEP-03`'s D1 block was ENV-GATED while the defect was open, because pinning
 * it required minting a PERMANENTLY-UNDELETABLE sealed `DEPOSIT_RECEIPT` per run.
 * D1 is FIXED (fix lane L1), the block is flipped to the expected 422-at-the-
 * boundary behaviour, and it mints nothing — so the gate is gone and the block
 * always runs, safely, even against a tenant whose hash chain is E-7 evidence.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import { login, get, post, addMoney, subMoney, type Session } from './treasury-support'
import {
  CASH_METHOD_CODE,
  CASH_REPOSITORY_CODE,
  TODAY,
  accountsByPurpose,
  createIncome,
  ledgerLinesFor,
  movementsFor,
  postIncome,
  repositoryBalance,
  repositoryIdByCode,
  scale4,
  uniq,
} from './w5c-support'

let owner: Session
let cashRepoId: string
let accounts: Record<string, { id: string; code: string; name: string }>

interface DepositResult {
  fiscal_event_id: string
  deposit_receipt_uuid: string
  amount: string
  currency_code: string
  settled_amount: string
  credited_amount: string
  receivable_balance: string
  credit_balance: string
  net_balance: string
}

async function createCustomer(request: APIRequestContext, label: string): Promise<string> {
  const res = await post(request, owner, '/partners', { name: uniq(label), type: 'customer' })
  expect(res.status, `create customer -> ${res.status} ${JSON.stringify(res.data)}`).toBe(201)
  return String(res.data.id)
}

async function recordDeposit(
  request: APIRequestContext,
  partnerId: string,
  payload: Record<string, unknown>,
): Promise<{ status: number; data: Record<string, unknown> }> {
  return post(request, owner, `/partners/${partnerId}/deposits`, {
    payment_method_code: CASH_METHOD_CODE,
    repository_id: cashRepoId,
    ...payload,
  })
}

async function depositHistory(
  request: APIRequestContext,
  partnerId: string,
): Promise<Array<{ amount: string; payment_method_code: string; fiscal_event_id: string; note: string | null }>> {
  const res = await get(request, owner, `/partners/${partnerId}/deposits`)
  expect(res.ok, `deposit history -> ${res.status} ${JSON.stringify(res.data)}`).toBeTruthy()
  return res.data as unknown as Array<{
    amount: string
    payment_method_code: string
    fiscal_event_id: string
    note: string | null
  }>
}

test.describe('MTP-TRE/DEP — income and partner deposits (W-5c §B.5 rows 72, 74)', () => {
  test.describe.configure({ timeout: 180_000 })

  test.beforeAll(async ({ request }, workerInfo) => {
    // Runtime precondition, not a convention (fix round 1, M-2): every case here
    // asserts exact deltas on the SHARED seeded CASH-01, and the Playwright config
    // default off-CI is parallel.
    expect(
      workerInfo.config.workers,
      'this file asserts exact deltas on the SHARED seeded CASH-01 repository — '
        + 'run it as `--project=chromium --workers=1`',
    ).toBe(1)

    owner = await login(request, 'owner')
    cashRepoId = await repositoryIdByCode(request, owner, CASH_REPOSITORY_CODE)
    accounts = await accountsByPurpose(request, owner)
    expect(accounts.service_revenue, 'a class-7 revenue account is configured').toBeTruthy()
    expect(accounts.cash, 'a cash account is configured').toBeTruthy()
  })

  test('MTP-TRE-76 (P1): posting an income is the GL mirror of an expense and shows as an `in`', async ({
    request,
  }) => {
    const source = uniq('TRE76')
    const created = await createIncome(request, owner, {
      source_name: source,
      reference_number: source,
      total: '320.000',
      income_account_id: accounts.service_revenue!.id,
      payment_repository_id: cashRepoId,
      payment_date: TODAY,
      is_received: true,
    })
    expect(created.status, `create income -> ${created.status} ${JSON.stringify(created.data)}`).toBe(201)
    const incomeId = String(created.data.id)
    expect(
      { status: created.data.status, total: created.data.total, document_number: created.data.document_number },
      'a draft income holds the exact amount and has no number yet',
    ).toEqual({ status: 'draft', total: '320.000', document_number: null })

    const cashBefore = await repositoryBalance(request, owner, cashRepoId)
    expect(
      await movementsFor(request, owner, cashRepoId, incomeId),
      'a DRAFT income has moved no money',
    ).toHaveLength(0)

    const posted = await postIncome(request, owner, incomeId)
    expect(posted.status, `post income -> ${posted.status} ${JSON.stringify(posted.data)}`).toBe(200)
    expect(posted.data.status).toBe('posted')
    expect(String(posted.data.document_number), 'an income document number is assigned').toMatch(
      /^INC-\d{4}-\d{6}$/,
    )

    const cashAfter = await repositoryBalance(request, owner, cashRepoId)
    expect(cashAfter, 'CASH-01 credited by exactly 320.000 — the mirror of the expense outflow').toBe(
      addMoney(cashBefore, '320.000'),
    )

    const movements = await movementsFor(request, owner, cashRepoId, incomeId)
    expect(movements, 'exactly one movement').toHaveLength(1)
    expect(
      {
        direction: movements[0]!.direction,
        amount: movements[0]!.amount,
        balance_after: movements[0]!.balance_after,
        source_type: movements[0]!.source_type,
      },
      'one `in` movement of exactly 320.000, sourced on the income document',
    ).toEqual({ direction: 'in', amount: '320.000', balance_after: cashAfter, source_type: 'income' })
    expect(movements[0]!.journal_entry_id, 'linked to a real JE').toMatch(/^[0-9a-f-]{36}$/)

    const cashLegs = await ledgerLinesFor(request, owner, accounts.cash!.id, incomeId)
    const revenueLegs = await ledgerLinesFor(request, owner, accounts.service_revenue!.id, incomeId)
    expect(
      {
        cash: cashLegs.map((l) => [l.account_code, l.debit, l.credit]),
        revenue: revenueLegs.map((l) => [l.account_code, l.debit, l.credit]),
      },
      'Dr 53 Caisse 320.000 / Cr 706 revenue 320.000 — the exact mirror of the expense entry',
    ).toEqual({
      cash: [[accounts.cash!.code, scale4('320.000'), scale4('0.000')]],
      revenue: [[accounts.service_revenue!.code, scale4('0.000'), scale4('320.000')]],
    })
    expect(cashLegs[0]!.entry_number, 'one balanced entry').toBe(revenueLegs[0]!.entry_number)

    // The plan's "`/finance/cash-movements` shows it as an **in**".
    const report = await get(
      request,
      owner,
      `/reports/cash-movements?date_from=${TODAY}&date_to=${TODAY}`,
    )
    expect(report.ok, `cash-movements -> ${report.status}`).toBeTruthy()
    const rows = (report.data as unknown as Array<{
      direction: string
      amount: string
      source_type: string
      source_id: string
      gl_account: string
    }>).filter((row) => row.source_id === incomeId)
    expect(rows, 'exactly one cash-movements row for this income').toHaveLength(1)
    expect(
      { direction: rows[0]!.direction, amount: rows[0]!.amount, source_type: rows[0]!.source_type, gl_account: rows[0]!.gl_account },
      'rendered as an `in` of exactly 320.000 against the cash GL account',
    ).toEqual({ direction: 'in', amount: '320.000', source_type: 'income', gl_account: accounts.cash!.code })

    const rePost = await postIncome(request, owner, incomeId)
    expect(rePost.status, 'a posted income cannot be re-posted').toBe(422)
    expect(
      await repositoryBalance(request, owner, cashRepoId),
      'and the refused re-post moved no further money',
    ).toBe(cashAfter)
    expect(await movementsFor(request, owner, cashRepoId, incomeId), 'still one movement').toHaveLength(1)
  })

  test('MTP-DEP-01 (P0): a deposit for a customer with nothing open is CREDITED in full and lands in the repository', async ({
    request,
  }) => {
    const partnerId = await createCustomer(request, 'DEP01')
    const note = uniq('DEP01-note')
    const cashBefore = await repositoryBalance(request, owner, cashRepoId)

    const recorded = await recordDeposit(request, partnerId, { amount: '150.000', note })
    expect(recorded.status, `record deposit -> ${recorded.status} ${JSON.stringify(recorded.data)}`).toBe(201)
    const result = recorded.data as unknown as DepositResult

    expect(
      {
        amount: result.amount,
        currency_code: result.currency_code,
        settled_amount: result.settled_amount,
        credited_amount: result.credited_amount,
        receivable_balance: result.receivable_balance,
        credit_balance: result.credit_balance,
        net_balance: result.net_balance,
      },
      'nothing open -> the whole 150.000 is credited as advance; net balance is the credit, signed negative',
    ).toEqual({
      amount: '150.000',
      currency_code: 'TND',
      settled_amount: '0.000',
      credited_amount: '150.000',
      receivable_balance: '0.000',
      credit_balance: '150.000',
      net_balance: '-150.000',
    })
    expect(
      addMoney(result.settled_amount, result.credited_amount),
      'settled + credited == the deposited amount, to the millime',
    ).toBe(result.amount)
    expect(result.fiscal_event_id, 'a sealed fiscal event').toMatch(/^[0-9a-f-]{36}$/)
    expect(result.deposit_receipt_uuid, 'and a printable receipt identity').toMatch(/^[0-9a-f-]{36}$/)

    expect(
      await repositoryBalance(request, owner, cashRepoId),
      'CASH-01 rises by exactly 150.000',
    ).toBe(addMoney(cashBefore, '150.000'))

    const history = await depositHistory(request, partnerId)
    expect(history, 'exactly one receipt in the history').toHaveLength(1)
    expect(
      {
        amount: history[0]!.amount,
        payment_method_code: history[0]!.payment_method_code,
        fiscal_event_id: history[0]!.fiscal_event_id,
        note: history[0]!.note,
      },
      'the history row is the receipt just written',
    ).toEqual({
      amount: '150.000',
      payment_method_code: CASH_METHOD_CODE,
      fiscal_event_id: result.fiscal_event_id,
      note,
    })
  })

  test('MTP-DEP-02 (P0): a deposit settles open invoices FIFO and overflows the remainder to credit', async ({
    request,
  }) => {
    const partnerId = await createCustomer(request, 'DEP02')

    // One posted invoice of exactly 200.000. `createPostedInvoice`'s mechanism:
    // tax_rate 0.00 on the line and a unit price 1.000 below target, because
    // confirm() applies the Tunisia 1.000 document-level stamp duty.
    const invoice = await post(request, owner, '/invoices', {
      partner_id: partnerId,
      document_date: TODAY,
      lines: [{ description: 'W5C DEP-02 fixture', quantity: '1', unit_price: '199.000', tax_rate: '0.00' }],
    })
    expect(invoice.status, `invoice -> ${invoice.status} ${JSON.stringify(invoice.data)}`).toBe(201)
    const invoiceId = String(invoice.data.id)
    expect((await post(request, owner, `/invoices/${invoiceId}/confirm`)).status).toBe(200)
    const postedInvoice = await post(request, owner, `/invoices/${invoiceId}/post`)
    expect(postedInvoice.status).toBe(200)
    expect(
      { total: postedInvoice.data.total, balance_due: postedInvoice.data.balance_due },
      'the fixture invoice is exactly 200.000 and fully open',
    ).toEqual({ total: '200.000', balance_due: '200.000' })

    const cashBefore = await repositoryBalance(request, owner, cashRepoId)
    const recorded = await recordDeposit(request, partnerId, { amount: '250.000', note: uniq('DEP02-note') })
    expect(recorded.status, `record deposit -> ${recorded.status} ${JSON.stringify(recorded.data)}`).toBe(201)
    const result = recorded.data as unknown as DepositResult

    expect(
      {
        amount: result.amount,
        settled_amount: result.settled_amount,
        credited_amount: result.credited_amount,
        receivable_balance: result.receivable_balance,
        credit_balance: result.credit_balance,
      },
      '200.000 settles the open invoice; the 50.000 remainder overflows to credit',
    ).toEqual({
      amount: '250.000',
      settled_amount: '200.000',
      credited_amount: '50.000',
      receivable_balance: '0.000',
      credit_balance: '50.000',
    })
    expect(
      addMoney(result.settled_amount, result.credited_amount),
      'settled + credited == the deposited amount exactly — no money is created or lost in the split',
    ).toBe(result.amount)
    expect(
      subMoney(result.amount, '200.000'),
      'and the credited remainder is exactly amount - invoice total',
    ).toBe(result.credited_amount)
    expect(result.net_balance, 'net = credit - receivable, signed').toBe(
      subMoney(result.receivable_balance, result.credit_balance),
    )

    expect(
      await repositoryBalance(request, owner, cashRepoId),
      'the FULL 250.000 lands in CASH-01 — settling and crediting are the same cash receipt',
    ).toBe(addMoney(cashBefore, '250.000'))

    const settledInvoice = await get(request, owner, `/invoices/${invoiceId}`)
    expect(settledInvoice.ok).toBeTruthy()
    expect(
      { status: settledInvoice.data.status, balance_due: settledInvoice.data.balance_due },
      'the FIFO-settled invoice is paid with a zero balance',
    ).toEqual({ status: 'paid', balance_due: '0.000' })
  })

  test('MTP-DEP-03 (P1): amount ceilings and the customer-only gate — plus the unvalidated-reference FINDING', async ({
    request,
  }) => {
    const partnerId = await createCustomer(request, 'DEP03')

    // --- refusals that are genuinely refused, and move no money ---
    const cashBefore = await repositoryBalance(request, owner, cashRepoId)
    const zero = await recordDeposit(request, partnerId, { amount: '0' })
    const zeroScaled = await recordDeposit(request, partnerId, { amount: '0.000' })
    const overPrecise = await recordDeposit(request, partnerId, { amount: '10.0001' })
    const negative = await recordDeposit(request, partnerId, { amount: '-5.000' })
    expect(
      {
        zero: [zero.status, (zero.data as { code?: string }).code],
        zeroScaled: [zeroScaled.status, (zeroScaled.data as { code?: string }).code],
        overPrecise: [overPrecise.status, (overPrecise.data as { code?: string }).code],
        negative: [negative.status, (negative.data as { code?: string }).code],
      },
      'zero (both spellings) and over-precise amounts are INVALID_AMOUNT; a negative amount fails the unsigned format rule',
    ).toEqual({
      zero: [422, 'INVALID_AMOUNT'],
      zeroScaled: [422, 'INVALID_AMOUNT'],
      overPrecise: [422, 'INVALID_AMOUNT'],
      negative: [422, 'VALIDATION_ERROR'],
    })
    expect((overPrecise.data as { message?: string }).message).toBe(
      'Amount precision exceeds the TND currency scale (3 decimals).',
    )
    expect(
      await repositoryBalance(request, owner, cashRepoId),
      'none of the four refusals moved money',
    ).toBe(cashBefore)
    expect(await depositHistory(request, partnerId), 'and none of them wrote a receipt').toHaveLength(0)

    // A 4th decimal that is a TRAILING ZERO is not over-precision — it is accepted
    // and stored truncated to the currency scale (PartnerDepositController::
    // amountError rtrims the excess before refusing). Pinned so a future tightening
    // of that rule is a deliberate decision, not an accident.
    const trailingZero = await recordDeposit(request, partnerId, { amount: '10.0000' })
    expect(trailingZero.status, `'10.0000' is accepted -> ${JSON.stringify(trailingZero.data)}`).toBe(201)
    expect(
      (trailingZero.data as unknown as DepositResult).amount,
      'and stored at exactly the currency scale',
    ).toBe('10.000')

    // --- the customer-only gate ---
    const supplier = await post(request, owner, '/partners', {
      name: uniq('DEP03-Sup'),
      type: 'supplier',
      country_code: 'TN',
    })
    expect(supplier.status).toBe(201)
    const supplierDeposit = await recordDeposit(request, String(supplier.data.id), { amount: '10.000' })
    expect(
      { status: supplierDeposit.status, code: (supplierDeposit.data as { code?: string }).code },
      'a deposit against a supplier account is refused',
    ).toEqual({ status: 422, code: 'PARTNER_NOT_CUSTOMER' })

    // --- FINDING W5C-D1 (FIXED — flipped to the EXPECTED behaviour, fix lane L1) ---
    //
    // WAS: `RecordDepositRequest` validated `payment_method_code` as a bare
    // `string|max:64` and `repository_id` as a bare `uuid` — NEITHER carried an
    // existence check, and `RecordCustomerDepositService::record()` AUTHORED AND
    // SEALED the `DEPOSIT_RECEIPT` fiscal event BEFORE the projection pipeline
    // resolved either reference. A plain client input error therefore returned a
    // 500 AND left a permanently-undeletable sealed receipt in the customer's
    // deposit history, over-stating what the customer had paid.
    //
    // NOW: both fields carry `ScopedExists::tenantAndCompany(...)->where('is_active',
    // true)` — the SAME predicate `TreasuryDepositBridge` resolves on — so a bad
    // reference is a 422 at the boundary, before anything is authored. The service
    // resolves both references before `appendDepositReceipt(...)` as well, so a
    // caller that bypasses the FormRequest still cannot seal an unprojectable
    // receipt.
    //
    // The env gate is GONE with the defect: this block no longer mints an orphan,
    // so it is safe to run unattended against a chain that is E-7 evidence — and
    // the previously-dropped unknown-`repository_id` twin probe is restored for
    // the same reason.
    const beforeRefusal = await repositoryBalance(request, owner, cashRepoId)
    const historyBeforeRefusal = await depositHistory(request, partnerId)

    const unknownMethod = await recordDeposit(request, partnerId, {
      amount: '11.111',
      payment_method_code: uniq('NOSUCHMETHOD').slice(0, 60),
    })
    expect(
      { status: unknownMethod.status, code: (unknownMethod.data as { code?: string }).code },
      'FIXED D1: an unknown method code is refused at the boundary as a 422 validation error, not a 500',
    ).toEqual({ status: 422, code: 'VALIDATION_ERROR' })
    expect(
      Object.keys((unknownMethod.data as { errors?: Record<string, unknown> }).errors ?? {}),
      'FIXED D1: and the refusal names the offending field',
    ).toContain('payment_method_code')

    const unknownRepository = await recordDeposit(request, partnerId, {
      amount: '11.111',
      repository_id: '00000000-0000-0000-0000-000000000000',
    })
    expect(
      { status: unknownRepository.status, code: (unknownRepository.data as { code?: string }).code },
      'FIXED D1: the repository leg of the same ordering hole is refused identically',
    ).toEqual({ status: 422, code: 'VALIDATION_ERROR' })
    expect(
      Object.keys((unknownRepository.data as { errors?: Record<string, unknown> }).errors ?? {}),
      'FIXED D1: naming `repository_id`',
    ).toContain('repository_id')

    expect(
      await repositoryBalance(request, owner, cashRepoId),
      'FIXED D1: no money moved (unchanged from before the fix)',
    ).toBe(beforeRefusal)

    const historyAfterRefusal = await depositHistory(request, partnerId)
    expect(
      historyAfterRefusal.length - historyBeforeRefusal.length,
      'FIXED D1: and — the fix — NO receipt was sealed, so the deposit history did not grow',
    ).toBe(0)

    const partner = await get(request, owner, `/partners/${partnerId}`)
    expect(partner.ok).toBeTruthy()
    expect(
      String(partner.data.credit_balance),
      "FIXED D1: the credit balance still reflects only the deposit that really landed (the accepted '10.0000')",
    ).toBe('10.000')
    expect(
      historyAfterRefusal.map((row) => row.amount).reduce((sum, amount) => addMoney(sum, amount), '0.000'),
      'FIXED D1: receipts on record now equal the real credit exactly — the over-statement is gone',
    ).toBe('10.000')
  })
})
