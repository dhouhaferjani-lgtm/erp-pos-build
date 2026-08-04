/**
 * MONEY TEST CAMPAIGN — wave W-5c — `/expenses/*` lifecycle:
 * `MTP-TRE-60..67`, `MTP-TRE-71/72`, `MTP-TRE-77` (plan §B.5 row 71).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real login,
 * real backend, no mocks. Money is compared as EXACT decimal strings (integer
 * millime arithmetic from `treasury-support.ts`) — never a float.
 *
 * CONCURRENCY — THIS FILE ASSUMES `--workers=1`. `MTP-TRE-62/63/64/71` assert exact
 * DELTAS on the SHARED seeded `CASH-01` repository (the plan's own precondition is
 * "pay from a cash repository"), captured immediately before and after the action.
 * A concurrently running case moving money through `CASH-01` would corrupt those
 * deltas. Run only as `--project=chromium --workers=1`.
 *
 * PLAN-vs-TENANT deviation: the plan states absolute repository balances as
 * preconditions; `DemoPharmacySeeder` seeds different ones and every other money case
 * in this campaign moves them. Every balance assertion here is therefore a
 * before/after DELTA against a value read in the same case — strictly stronger than
 * an absolute assertion against a shared, drifting balance.
 *
 * Junk hygiene: DRAFT fixtures are DELETEd in a `finally` whose `expect` is GATED on
 * the case body having succeeded (a throw inside `finally` REPLACES the in-flight
 * exception, so an ungated cleanup assertion would hide the real defect). POSTED
 * expenses are permanent — `documents` has no delete path past Draft — so they are
 * real, deliberate, documented money the wave leaves behind; every one carries a
 * `W5C-` vendor name so a single predicate excludes them downstream.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import { login, get, post, patch, del, subMoney, type Session } from './treasury-support'
import { loginAsRole } from './helpers'
import { isCleanupSuccess } from './statement-support'
import {
  BANK_REPOSITORY_CODE,
  CASH_REPOSITORY_CODE,
  CHEQUE_METHOD_CODE,
  PIECE_UNIT_ID,
  TODAY,
  WAREHOUSE_LOCATION_ID,
  accountsByPurpose,
  createExpense,
  getExpense,
  ledgerLinesFor,
  movementsFor,
  allPaymentIds,
  paymentMethodIdByCode,
  postExpense,
  payExpense,
  repositoryBalance,
  repositoryIdByCode,
  retireDraftExpense,
  scale4,
  uniq,
} from './w5c-support'

let owner: Session
let cashRepoId: string
let bankRepoId: string
let chequeMethodId: string
let accounts: Record<string, { id: string; code: string; name: string }>

test.describe('MTP-TRE — expense lifecycle (W-5c §B.5 row 71)', () => {
  test.describe.configure({ timeout: 180_000 })

  test.beforeAll(async ({ request }, workerInfo) => {
    // The `--workers=1` requirement in this file's header is a RUNTIME
    // precondition, not a convention (fix round 1, M-2): the Playwright config
    // default off-CI is parallel, so a plain `pnpm exec playwright test <file>`
    // would silently interleave the CASH-01 delta cases and produce a confident,
    // wrong verdict. Fail loudly instead.
    expect(
      workerInfo.config.workers,
      'this file asserts exact deltas on the SHARED seeded CASH-01 repository — '
        + 'run it as `--project=chromium --workers=1`',
    ).toBe(1)

    owner = await login(request, 'owner')
    cashRepoId = await repositoryIdByCode(request, owner, CASH_REPOSITORY_CODE)
    bankRepoId = await repositoryIdByCode(request, owner, BANK_REPOSITORY_CODE)
    chequeMethodId = await paymentMethodIdByCode(request, owner, CHEQUE_METHOD_CODE)
    accounts = await accountsByPurpose(request, owner)
    for (const purpose of ['supplier_payable', 'general_expense', 'vat_deductible', 'cash']) {
      expect(accounts[purpose], `system account purpose '${purpose}' is configured`).toBeTruthy()
    }
  })

  test('MTP-TRE-60/61/62/63 (P0): create -> post -> pay -> replay pay, on one 119.000 VAT expense', async ({
    request,
  }) => {
    const vendor = uniq('TRE60')

    // --- MTP-TRE-60: draft carries the exact attested figures, net = 100.000 ---
    const created = await createExpense(request, owner, {
      vendor_name: vendor,
      total: '119.000',
      vat_amount: '19.000',
      vat_rate: '19.00',
      vat_deductible_percent: '100.00',
      // EXPLICIT: ExpenseService::create() defaults is_paid to TRUE (ExpenseService.php:146).
      // The plan's TRE-61/62 chain requires an UNPAID, AP-booking expense.
      is_paid: false,
    })
    expect(created.status, `create expense -> ${created.status} ${JSON.stringify(created.data)}`).toBe(201)
    const expenseId = String(created.data.id)
    expect(
      {
        status: created.data.status,
        total: created.data.total,
        tax_amount: created.data.tax_amount,
        subtotal: created.data.subtotal,
        document_number: created.data.document_number,
      },
      'draft: total 119.000, VAT 19.000, net = total - VAT = exactly 100.000, no number yet',
    ).toEqual({
      status: 'draft',
      total: '119.000',
      tax_amount: '19.000',
      subtotal: subMoney('119.000', '19.000'),
      document_number: null,
    })
    const draftMeta = (created.data.metadata as Record<string, unknown>)
    expect({ vat_rate: draftMeta.vat_rate, vat_deductible_percent: draftMeta.vat_deductible_percent }).toEqual({
      vat_rate: '19.00',
      vat_deductible_percent: '100.00',
    })

    // --- MTP-TRE-61: post books Dr expense + Dr deductible VAT / Cr AP, no cash ---
    const cashBeforePost = await repositoryBalance(request, owner, cashRepoId)
    const paymentsBefore = await allPaymentIds(request, owner)
    const posted = await postExpense(request, owner, expenseId)
    expect(posted.status, `post -> ${posted.status} ${JSON.stringify(posted.data)}`).toBe(200)
    expect(posted.data.status, 'posted').toBe('posted')
    expect(String(posted.data.document_number), 'a document number is assigned on post').toMatch(
      /^EXP-\d{4}-\d{6}$/,
    )

    const expenseLegs = await ledgerLinesFor(request, owner, accounts.general_expense!.id, expenseId)
    const vatLegs = await ledgerLinesFor(request, owner, accounts.vat_deductible!.id, expenseId)
    const apLegs = await ledgerLinesFor(request, owner, accounts.supplier_payable!.id, expenseId)
    expect(
      {
        expense: expenseLegs.map((l) => [l.account_code, l.debit, l.credit]),
        vat: vatLegs.map((l) => [l.account_code, l.debit, l.credit]),
        ap: apLegs.map((l) => [l.account_code, l.debit, l.credit]),
      },
      'Dr 65 net 100.000 + Dr 4456 deductible VAT 19.000 / Cr 401 AP 119.000 — the GL renders at scale 4',
    ).toEqual({
      expense: [[accounts.general_expense!.code, scale4('100.000'), scale4('0.000')]],
      vat: [[accounts.vat_deductible!.code, scale4('19.000'), scale4('0.000')]],
      ap: [[accounts.supplier_payable!.code, scale4('0.000'), scale4('119.000')]],
    })
    expect(expenseLegs[0]!.entry_number, 'all three legs are one balanced entry').toBe(apLegs[0]!.entry_number)
    expect(vatLegs[0]!.entry_number).toBe(apLegs[0]!.entry_number)
    expect(
      await repositoryBalance(request, owner, cashRepoId),
      'posting an UNPAID expense moves no cash — it books a liability',
    ).toBe(cashBeforePost)
    expect(
      await movementsFor(request, owner, cashRepoId, expenseId),
      'and writes no repository movement',
    ).toHaveLength(0)

    // --- MTP-TRE-62: pay from CASH-01 -> Dr AP / Cr Cash + one out movement ---
    const cashBeforePay = await repositoryBalance(request, owner, cashRepoId)
    const paid = await payExpense(request, owner, expenseId, {
      mode: 'cash',
      payment_repository_id: cashRepoId,
    })
    expect(paid.status, `pay -> ${paid.status} ${JSON.stringify(paid.data)}`).toBe(200)
    const cashAfterPay = await repositoryBalance(request, owner, cashRepoId)
    expect(cashAfterPay, 'CASH-01 debited by exactly 119.000').toBe(subMoney(cashBeforePay, '119.000'))

    const settlementMovements = await movementsFor(request, owner, cashRepoId, expenseId)
    expect(settlementMovements, 'exactly one settlement movement').toHaveLength(1)
    expect(
      {
        direction: settlementMovements[0]!.direction,
        amount: settlementMovements[0]!.amount,
        balance_after: settlementMovements[0]!.balance_after,
        source_type: settlementMovements[0]!.source_type,
      },
      'one `out` movement of exactly 119.000, sourced on the expense',
    ).toEqual({
      direction: 'out',
      amount: '119.000',
      balance_after: cashAfterPay,
      source_type: 'expense',
    })
    expect(settlementMovements[0]!.journal_entry_id, 'linked to a real settlement JE').toMatch(
      /^[0-9a-f-]{36}$/,
    )

    const paidMeta = (await getExpense(request, owner, expenseId)).metadata
    expect(paidMeta.is_paid, 'metadata.is_paid carries paid state').toBe(true)
    expect(paidMeta.paid_at, 'paid_at stamped').toBeTruthy()

    const apAfterPay = await ledgerLinesFor(request, owner, accounts.supplier_payable!.id, expenseId)
    const cashLegs = await ledgerLinesFor(request, owner, accounts.cash!.id, expenseId)
    expect(
      {
        ap: apAfterPay.map((l) => [l.debit, l.credit]).sort(),
        cash: cashLegs.map((l) => [l.debit, l.credit]),
      },
      'settlement adds Dr AP 119.000 / Cr Cash 119.000 — the liability nets to zero',
    ).toEqual({
      ap: [
        [scale4('0.000'), scale4('119.000')],
        [scale4('119.000'), scale4('0.000')],
      ].sort(),
      cash: [[scale4('0.000'), scale4('119.000')]],
    })

    // --- MTP-TRE-63: replay the SAME pay request ---
    // EXTENDS `idempotency.spec.ts` MTP-IDEM-04, which already proves the second
    // /pay returns 200 and does not flip metadata twice. What IDEM-04 does NOT
    // assert — and what the plan actually asks for ("exactly one movement, one
    // GL entry") — is the ledger/movement side. That is this block; the status
    // assertion is deliberately not repeated in depth.
    const replay = await payExpense(request, owner, expenseId, {
      mode: 'cash',
      payment_repository_id: cashRepoId,
    })
    expect(replay.status, `replayed pay is an idempotent no-op, not a conflict -> ${replay.status}`).toBe(200)
    expect(
      await repositoryBalance(request, owner, cashRepoId),
      'the replay moves no further money',
    ).toBe(cashAfterPay)
    expect(
      await movementsFor(request, owner, cashRepoId, expenseId),
      'STILL exactly one movement after the replay',
    ).toHaveLength(1)
    const apAfterReplay = await ledgerLinesFor(request, owner, accounts.supplier_payable!.id, expenseId)
    const cashAfterReplayLegs = await ledgerLinesFor(request, owner, accounts.cash!.id, expenseId)
    expect(
      { ap: apAfterReplay.length, cash: cashAfterReplayLegs.length },
      'STILL exactly two AP legs and one cash leg — no second settlement JE',
    ).toEqual({ ap: 2, cash: 1 })
    expect(
      new Set(apAfterReplay.map((l) => l.entry_number)).size,
      'exactly two distinct journal entries touched AP: the post and the single settlement',
    ).toBe(2)

    // The plan's "**No** `Payment` entity created" caveat (§B.5 row 71): the whole
    // post -> pay -> replay chain moved 119.000 of real money without producing a
    // single `payments` row, so any report that counts payments under-counts
    // expense spend. `allPaymentIds` omits `page`, which puts
    // PaymentController::index on its `->get()` branch: this is a comparison of
    // the COMPLETE company payment set, sorted, not a page-1 heuristic.
    expect(
      await allPaymentIds(request, owner),
      'post + pay + replay created NO Payment entity — the whole payment set is unchanged',
    ).toEqual(paymentsBefore)
  })

  test('MTP-TRE-64 (P1): an expense created already-paid moves cash at POST, and /pay is then refused', async ({
    request,
  }) => {
    const created = await createExpense(request, owner, {
      vendor_name: uniq('TRE64'),
      total: '40.000',
      is_paid: true,
      payment_repository_id: cashRepoId,
      payment_date: TODAY,
    })
    expect(created.status, `create -> ${created.status} ${JSON.stringify(created.data)}`).toBe(201)
    const expenseId = String(created.data.id)

    const before = await repositoryBalance(request, owner, cashRepoId)
    const posted = await postExpense(request, owner, expenseId)
    expect(posted.status, `post -> ${posted.status} ${JSON.stringify(posted.data)}`).toBe(200)
    const after = await repositoryBalance(request, owner, cashRepoId)
    expect(after, 'post() itself writes the out movement — CASH-01 down exactly 40.000').toBe(
      subMoney(before, '40.000'),
    )

    const movements = await movementsFor(request, owner, cashRepoId, expenseId)
    expect(movements, 'exactly one movement, written by post()').toHaveLength(1)
    expect({ direction: movements[0]!.direction, amount: movements[0]!.amount }).toEqual({
      direction: 'out',
      amount: '40.000',
    })
    expect(
      await ledgerLinesFor(request, owner, accounts.supplier_payable!.id, expenseId),
      'a PAID expense credits Cash directly — it never books an AP liability',
    ).toHaveLength(0)

    const pay = await payExpense(request, owner, expenseId, {
      mode: 'cash',
      payment_repository_id: cashRepoId,
    })
    expect(pay.status, `/pay on an already-paid expense -> ${pay.status}`).toBe(422)
    expect(String(pay.data)).toContain('already been paid')
    expect(
      await repositoryBalance(request, owner, cashRepoId),
      'the refused /pay moved no money',
    ).toBe(after)
    expect(await movementsFor(request, owner, cashRepoId, expenseId), 'still one movement').toHaveLength(1)
  })

  test('MTP-TRE-65 (P1): instrument settlement issues an OUTBOUND cheque and moves no cash yet', async ({
    request,
  }) => {
    const created = await createExpense(request, owner, {
      vendor_name: uniq('TRE65'),
      total: '250.000',
      is_paid: false,
    })
    expect(created.status).toBe(201)
    const expenseId = String(created.data.id)
    expect((await postExpense(request, owner, expenseId)).status).toBe(200)

    // Product behaviour pinned deliberately: an outbound instrument can only be
    // drawn on a BANK repository. Passing CASH-01 is a 422, not a silent success.
    const wrongRepository = await payExpense(request, owner, expenseId, {
      mode: 'instrument',
      payment_repository_id: cashRepoId,
      payment_method_id: chequeMethodId,
      instrument: { kind: 'cheque', reference: uniq('TRE65CASH'), drawer_name: 'PharmaBio' },
    })
    expect(wrongRepository.status, 'cheque drawn on a cash register -> 422').toBe(422)
    expect(String(wrongRepository.data)).toContain('bank-account repository')

    const bankBefore = await repositoryBalance(request, owner, bankRepoId)
    const reference = uniq('TRE65')
    const settled = await payExpense(request, owner, expenseId, {
      mode: 'instrument',
      payment_repository_id: bankRepoId,
      payment_method_id: chequeMethodId,
      instrument: { kind: 'cheque', reference, drawer_name: 'PharmaBio' },
    })
    expect(settled.status, `instrument pay -> ${settled.status} ${JSON.stringify(settled.data)}`).toBe(200)

    const meta = (await getExpense(request, owner, expenseId)).metadata
    expect(meta.payment_instrument_id, 'an instrument is attached').toMatch(/^[0-9a-f-]{36}$/)
    expect(
      meta.is_paid,
      'is_paid stays FALSE — a cheque is a promise, not a settlement (ExpenseService::settleByInstrument)',
    ).toBe(false)

    const instrument = await get(request, owner, `/payment-instruments/${meta.payment_instrument_id}`)
    expect(instrument.ok, `read instrument -> ${instrument.status}`).toBeTruthy()
    expect(
      {
        direction: instrument.data.direction,
        kind: instrument.data.kind,
        amount: instrument.data.amount,
        currency: instrument.data.currency,
        reference: instrument.data.reference,
        repository_id: instrument.data.repository_id,
      },
      'an OUTBOUND cheque for exactly the expense total, drawn on BANK-01',
    ).toEqual({
      direction: 'outbound',
      kind: 'cheque',
      amount: '250.000',
      currency: 'TND',
      reference,
      repository_id: bankRepoId,
    })

    expect(
      await repositoryBalance(request, owner, bankRepoId),
      'issuing the cheque moves NO bank cash — that happens on clear',
    ).toBe(bankBefore)
    expect(
      await movementsFor(request, owner, bankRepoId, expenseId),
      'and writes no repository movement against the expense',
    ).toHaveLength(0)
  })

  test('MTP-TRE-66 (P1): paying a DRAFT expense is refused', async ({ request }) => {
    let caseSucceeded = false
    const created = await createExpense(request, owner, {
      vendor_name: uniq('TRE66'),
      total: '30.000',
      is_paid: false,
    })
    expect(created.status).toBe(201)
    const expenseId = String(created.data.id)
    try {
      const before = await repositoryBalance(request, owner, cashRepoId)
      const pay = await payExpense(request, owner, expenseId, {
        mode: 'cash',
        payment_repository_id: cashRepoId,
      })
      expect(pay.status, `/pay on a draft -> ${pay.status} ${JSON.stringify(pay.data)}`).toBe(422)
      expect(String(pay.data)).toContain('Only a posted expense can be settled')
      expect(await repositoryBalance(request, owner, cashRepoId), 'no money moved').toBe(before)
      expect(await movementsFor(request, owner, cashRepoId, expenseId)).toHaveLength(0)
      expect((await getExpense(request, owner, expenseId)).status, 'still a draft').toBe('draft')
      caseSucceeded = true
    } finally {
      const status = await retireDraftExpense(request, owner, expenseId)
      if (caseSucceeded) {
        expect(isCleanupSuccess(status), `retire draft expense -> ${status}`).toBe(true)
      }
    }
  })

  test("MTP-TRE-67 (P1): a posted expense's amount cannot be edited, nor the document deleted", async ({
    request,
  }) => {
    const created = await createExpense(request, owner, {
      vendor_name: uniq('TRE67'),
      total: '55.000',
      is_paid: false,
    })
    expect(created.status).toBe(201)
    const expenseId = String(created.data.id)
    expect((await postExpense(request, owner, expenseId)).status).toBe(200)

    const edit = await patch(request, owner, `/expenses/${expenseId}`, { total: '999.000' })
    expect(edit.status, `edit a posted expense -> ${edit.status} ${JSON.stringify(edit.data)}`).toBe(422)
    const remove = await del(request, owner, `/expenses/${expenseId}`)
    expect(remove.status, 'nor deleted').toBe(422)

    expect(
      (await getExpense(request, owner, expenseId)).total,
      'the money is untouched — still exactly 55.000',
    ).toBe('55.000')

    // TRIPWIRE (W-5c finding D2, GREEN — pins TODAY's behaviour, not the desired one).
    // Both refusals return the RAW translation key as the user-facing error string:
    // `messages.cannot_edit_posted_document` / `messages.cannot_delete_posted_document`
    // are absent from BOTH `apps/api/lang/en/messages.php` and `lang/fr/messages.php`,
    // so `__()` echoes the key. Verified live 2026-08-04. When the keys are added this
    // assertion goes red — that is the point.
    expect(String(edit.data), 'TRIPWIRE D2: untranslated key leaks as the edit error').toBe(
      'messages.cannot_edit_posted_document',
    )
    expect(String(remove.data), 'TRIPWIRE D2: untranslated key leaks as the delete error').toBe(
      'messages.cannot_delete_posted_document',
    )

    const rePost = await postExpense(request, owner, expenseId)
    expect(rePost.status, 'and re-posting is refused').toBe(422)
    expect(String(rePost.data), 'TRIPWIRE D2: untranslated key leaks as the re-post error').toBe(
      'messages.document_already_posted',
    )
  })

  test('MTP-TRE-72 (P1): reverse is unavailable for a non-linked-cost expense (and for a draft)', async ({
    request,
  }) => {
    let caseSucceeded = false
    const draft = await createExpense(request, owner, {
      vendor_name: uniq('TRE72draft'),
      total: '12.000',
      is_paid: false,
    })
    expect(draft.status).toBe(201)
    const draftId = String(draft.data.id)
    try {
      const draftReverse = await post(request, owner, `/expenses/${draftId}/reverse`)
      expect(draftReverse.status, `reverse a draft -> ${draftReverse.status}`).toBe(422)
      expect({ code: draftReverse.data.code }, 'refused on status first').toEqual({ code: 'NOT_POSTED' })

      const generic = await createExpense(request, owner, {
        vendor_name: uniq('TRE72'),
        total: '77.000',
        is_paid: false,
      })
      expect(generic.status).toBe(201)
      const genericId = String(generic.data.id)
      expect((await postExpense(request, owner, genericId)).status).toBe(200)

      const reverse = await post(request, owner, `/expenses/${genericId}/reverse`)
      expect(reverse.status, `reverse a generic expense -> ${reverse.status}`).toBe(422)
      expect({ code: reverse.data.code }).toEqual({ code: 'NOT_LINKED_COST' })
      expect(
        (await getExpense(request, owner, genericId)).status,
        'the refused reverse left the expense posted and untouched',
      ).toBe('posted')
      expect(
        await ledgerLinesFor(request, owner, accounts.supplier_payable!.id, genericId),
        'exactly the one AP leg from the original post — no reversal leg was written',
      ).toHaveLength(1)
      caseSucceeded = true
    } finally {
      const status = await retireDraftExpense(request, owner, draftId)
      if (caseSucceeded) {
        expect(isCleanupSuccess(status), `retire draft expense -> ${status}`).toBe(true)
      }
    }
  })

  test('MTP-TRE-71 (P1): a linked-cost expense reverses to a net GL/WAC/cash effect of exactly zero', async ({
    request,
  }) => {
    // Full purchase chain, authored by this case so it never touches W-4's fixtures.
    const supplier = await post(request, owner, '/partners', {
      name: uniq('TRE71-Sup'),
      type: 'supplier',
      country_code: 'TN',
    })
    expect(supplier.status, `supplier -> ${supplier.status} ${JSON.stringify(supplier.data)}`).toBe(201)
    const product = await post(request, owner, '/products', {
      name: uniq('TRE71-Prod'),
      sku: uniq('TRE71SKU'),
      type: 'part',
      unit_id: PIECE_UNIT_ID,
      requires_batch_tracking: false,
    })
    expect(product.status, `product -> ${product.status} ${JSON.stringify(product.data)}`).toBe(201)
    const productId = String(product.data.id)

    const purchaseOrder = await post(request, owner, '/purchase-orders', {
      partner_id: supplier.data.id,
      document_date: TODAY,
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [
        { product_id: productId, description: 'W5C TRE-71 line', quantity: '10', unit_price: '20.000', tax_rate: '0.00' },
      ],
    })
    expect(purchaseOrder.status, `PO -> ${purchaseOrder.status} ${JSON.stringify(purchaseOrder.data)}`).toBe(201)
    const poId = String(purchaseOrder.data.id)
    expect((await post(request, owner, `/purchase-orders/${poId}/confirm`)).status).toBe(200)
    expect((await post(request, owner, `/purchase-orders/${poId}/receive`, {})).status).toBe(200)

    const po = await get(request, owner, `/purchase-orders/${poId}`)
    const poLineId = String((po.data as unknown as { lines: Array<{ id: string }> }).lines[0]!.id)

    const supplierInvoice = await post(request, owner, '/supplier-invoices', {
      partner_id: supplier.data.id,
      source_document_ids: [poId],
      currency: 'TND',
      issue_date: TODAY,
      lines: [{ source_line_id: poLineId, product_id: productId, quantity: '10', unit_price: '20.000', vat_rate: '0.00' }],
    })
    expect(
      supplierInvoice.status,
      `supplier invoice -> ${supplierInvoice.status} ${JSON.stringify(supplierInvoice.data)}`,
    ).toBe(201)

    const costBefore = String((await get(request, owner, `/products/${productId}`)).data.cost_price)
    expect(costBefore, 'WAC after a 10 @ 20.000 receipt').toBe('20.000000')
    const cashBefore = await repositoryBalance(request, owner, cashRepoId)

    const linkedCost = await createExpense(request, owner, {
      vendor_name: uniq('TRE71'),
      total: '50.000',
      expense_kind: 'linked_cost',
      linked_invoice_id: supplierInvoice.data.id,
      cost_type: 'shipping',
      split_method: 'by_value',
      is_paid: true,
      payment_repository_id: cashRepoId,
      payment_date: TODAY,
    })
    expect(
      linkedCost.status,
      `linked-cost expense -> ${linkedCost.status} ${JSON.stringify(linkedCost.data)}`,
    ).toBe(201)
    const expenseId = String(linkedCost.data.id)

    const posted = await postExpense(request, owner, expenseId)
    expect(posted.status, `post -> ${posted.status} ${JSON.stringify(posted.data)}`).toBe(200)
    expect(
      String((await get(request, owner, `/products/${productId}`)).data.cost_price),
      '50.000 capitalized over 10 units -> WAC exactly 25.000000',
    ).toBe('25.000000')
    const cashAfterPost = await repositoryBalance(request, owner, cashRepoId)
    expect(cashAfterPost, 'CASH-01 down exactly 50.000').toBe(subMoney(cashBefore, '50.000'))

    const reverse = await post(request, owner, `/expenses/${expenseId}/reverse`)
    expect(reverse.status, `reverse -> ${reverse.status} ${JSON.stringify(reverse.data)}`).toBe(200)
    expect(reverse.data.cash_reversed, 'the cash leg was reversed too').toBe(true)
    expect(String(reverse.data.reversal_expense_id), 'a mirrored reversal expense document').toMatch(
      /^[0-9a-f-]{36}$/,
    )
    const contras = reverse.data.wac_contras as Array<{ product_id: string; inventory_portion: string; cogs_portion: string }>
    expect(contras, 'one contra per costed product').toHaveLength(1)
    expect(
      { product_id: contras[0]!.product_id, inventory: contras[0]!.inventory_portion, cogs: contras[0]!.cogs_portion },
      'the whole 50.000 is contra-ed back out of inventory (nothing was sold)',
    ).toEqual({ product_id: productId, inventory: '50.000', cogs: '0.000' })

    expect(
      String((await get(request, owner, `/products/${productId}`)).data.cost_price),
      'WAC back to exactly 20.000000 — net inventory effect zero',
    ).toBe(costBefore)
    expect(
      await repositoryBalance(request, owner, cashRepoId),
      'CASH-01 back to its exact pre-capitalization balance — net cash effect zero',
    ).toBe(cashBefore)

    const movements = await movementsFor(request, owner, cashRepoId, expenseId)
    expect(movements, 'exactly two movements against this expense: the outflow and its reversal').toHaveLength(2)
    expect(
      movements.map((m) => [m.direction, m.amount]).sort(),
      'out 50.000 then in 50.000 — Σ exactly zero',
    ).toEqual([
      ['in', '50.000'],
      ['out', '50.000'],
    ].sort())
    expect(
      subMoney(
        movements.find((m) => m.direction === 'in')!.amount,
        movements.find((m) => m.direction === 'out')!.amount,
      ),
      'Σ of the two legs, as an exact decimal string',
    ).toBe('0.000')

    const secondReverse = await post(request, owner, `/expenses/${expenseId}/reverse`)
    expect(secondReverse.status, 'a second reverse is refused').toBe(422)
    expect({ code: secondReverse.data.code }).toEqual({ code: 'ALREADY_REVERSED' })
    expect(
      await repositoryBalance(request, owner, cashRepoId),
      'and moved no further money',
    ).toBe(cashBefore)
  })

  test('MTP-TRE-77 (P1): a cashier can create an expense but cannot post or pay it', async ({
    request,
    page,
  }) => {
    const cashier = await login(request, 'cashier')
    expect(
      cashier.permissions.includes('expenses.create') && cashier.permissions.includes('expenses.view'),
      `the cashier holds expenses.view/create — got ${JSON.stringify(cashier.permissions.filter((p) => p.startsWith('expenses.')))}`,
    ).toBe(true)
    expect(
      cashier.permissions.includes('expenses.pay'),
      'and NOT expenses.pay (the precondition this case asserts against)',
    ).toBe(false)

    let caseSucceeded = false
    const vendorName = uniq('TRE77')
    const created = await createExpense(request, cashier, {
      vendor_name: vendorName,
      total: '25.000',
      is_paid: false,
    })
    expect(created.status, `cashier create -> ${created.status} ${JSON.stringify(created.data)}`).toBe(201)
    const expenseId = String(created.data.id)
    try {
      const cashBefore = await repositoryBalance(request, owner, cashRepoId)
      const postAttempt = await postExpense(request, cashier, expenseId)
      const payAttempt = await payExpense(request, cashier, expenseId, {
        mode: 'cash',
        payment_repository_id: cashRepoId,
      })
      const read = await get(request, cashier, `/expenses/${expenseId}`)
      expect(
        { post: postAttempt.status, pay: payAttempt.status, read: read.status },
        'post and pay are BOTH 403; view still works; create already succeeded above',
      ).toEqual({ post: 403, pay: 403, read: 200 })
      expect(await repositoryBalance(request, owner, cashRepoId), 'no money moved').toBe(cashBefore)
      expect((await getExpense(request, owner, expenseId)).status, 'still a draft').toBe('draft')

      // Both layers (plan §I.4): the FE must not offer the actions either.
      //
      // The two absence checks are ANCHORED on a positive first (fix round 1, M-1):
      // `toHaveCount(0)` passes just as happily on a blank page, a 403 shell or a
      // route that redirected, so without proof the detail page actually RENDERED
      // this expense the gate assertion proves nothing.
      // ROUTE (found by M-1's anchor): the expense DETAIL page — the one carrying
      // the Post/Pay buttons — is `/expenses/:id/view`. `/expenses/:id` is the
      // EDIT FORM (routes/index.tsx). Before the anchor existed, the two
      // `toHaveCount(0)` checks were passing against the form page, which has no
      // Post/Pay buttons for anyone — proving nothing about the permission gate.
      await loginAsRole(page, 'cashier')
      await page.goto(`/expenses/${expenseId}/view`)
      await expect(
        // `.first()`: the detail page renders the vendor twice (PageHeader
        // subtitle + the vendor `<dd>` in the summary list).
        page.getByText(vendorName, { exact: false }).first(),
        'the cashier really is looking at THIS expense detail page (anchor for the absence checks below)',
      ).toBeVisible({ timeout: 15_000 })
      // Real accessible names (fix round 2, N-1): the Post button's label is
      // t('expenses:postExpense') = "Post Expense" / "Comptabiliser la Dépense" —
      // the old anchored /^post$/i matched NOBODY's button, cashier or owner.
      const postButton = /post expense|comptabiliser la dépense/i
      await expect(
        page.getByRole('button', { name: postButton }),
        'the cashier (no expenses.post) is offered no Post button'
      ).toHaveCount(0)
      // The Pay button renders only for POSTED expenses (ExpenseDetailPage
      // isPosted gate), so its absence on this draft is structural, not a
      // permission proof — the API-layer 403 above carries the pay assertion
      // (fix round 2, N-1). No locator check is made for it here.

      // Differential proof the locator is real: the OWNER sees Post on the very
      // same page (fix round 2, N-1) — so the cashier's zero-count above cannot
      // be a wrong-label artifact.
      await loginAsRole(page, 'owner')
      await page.goto(`/expenses/${expenseId}/view`)
      await expect(page.getByText(vendorName, { exact: false }).first()).toBeVisible({
        timeout: 15_000,
      })
      await expect(
        page.getByRole('button', { name: postButton }).first(),
        'the owner (expenses.post) IS offered Post on the same draft — the locator finds real buttons'
      ).toBeVisible({ timeout: 10_000 })
      caseSucceeded = true
    } finally {
      // The cashier lacks expenses.delete — retire as owner.
      const status = await retireDraftExpense(request, owner, expenseId)
      if (caseSucceeded) {
        expect(isCleanupSuccess(status), `retire draft expense -> ${status}`).toBe(true)
      }
    }
  })
})
