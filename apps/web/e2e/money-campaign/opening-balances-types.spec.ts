/**
 * MONEY TEST CAMPAIGN — wave W-4 — NEW SURFACE `OPB`: opening balances for the
 * NON-inventory types. Cases MTP-OPB-01..06.
 *
 * These cases have no definition in `2026-08-01-money-test-plan.md`. They are DERIVED, as
 * the wave brief directs, from §B.3 flow 36 ("Opening balances — other types (AR / AP /
 * GL)", route `/settings/opening-balances/:type`) after FIRST enumerating the real `:type`
 * values from the server (`GET /api/v1/opening-batches/types` — OPB-01 does exactly that
 * and pins the answer).
 *
 * The INVENTORY type is covered by MTP-INV-10..15 in `inventory-opening.spec.ts` and is
 * not re-tested here.
 *
 * FIXTURE DISCIPLINE — AR/AP open items are attached to partners CREATED BY THIS WAVE
 * (with an explicit `code`, which the AR/AP importer keys on and which the partner API
 * does not auto-assign). A seeded pharmacy customer is never given a legacy open item,
 * because W-6's aged-receivables assertions read those partners.
 *
 * SLOT DISCIPLINE — a company may hold at most ONE *unlocked* batch per type
 * (OpeningBalanceBatchService::createBatch():62-72). Every case clears the slot first and
 * retires what it created, so the next case (and the next wave) starts clean.
 */
import { test, expect } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import {
  openingBatchTypes,
  ensureAccount,
  W4_GL_DEBIT_ACCOUNT_CODE,
  W4_GL_CREDIT_ACCOUNT_CODE,
  createOpeningBatchOfType,
  requireOpeningBatchSlot,
  clearOpeningBatchSlot,

  importOpeningRowsGeneric,
  validateOpening,
  previewOpening,
  postOpening,
  retireOpeningBatch,
  partnerBalance,
  queryRows,
  sumMoney,
  uniq4,
  daysFromToday,
  type OpeningBatchType,
} from './w4-support'

test.describe.configure({ timeout: 180_000 })

/**
 * AR/AP opening rows are keyed by `partner_code`. `POST /partners` leaves `code` NULL
 * unless it is supplied explicitly, so every OPB partner is created with one.
 */
async function createCodedPartner(
  page: import('@playwright/test').Page,
  type: 'customer' | 'supplier',
  prefix: string
): Promise<{ id: string; code: string }> {
  const code = `${prefix}${Date.now().toString().slice(-8)}`
  const res = await apiRequest(page, 'POST', '/partners', {
    name: uniq4(`${prefix}-Partner`),
    type,
    country_code: 'TN',
    code,
  })
  expect(res.status, JSON.stringify(res.body)).toBe(201)
  const body = (res.body as { data: { id: string; code: string } }).data
  expect(body.code, 'explicit code round-trips').toBe(code)
  return { id: body.id, code }
}

test.describe('OPB — opening balances, non-inventory types', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-OPB-01 (P0): the real `:type` values are exactly the four the wizard route accepts', async ({ page }) => {
    const res = await openingBatchTypes(page)
    expect(res.status, JSON.stringify(res.body)).toBe(200)
    const types = (res.body as { data: Array<{ value: string; label: string; row_type: string; affects_gl: boolean }> }).data

    // Enumerated first, asserted second (wave brief: "FIRST enumerate the real :type
    // values ... then author"). Matches App\Modules\Accounting\Domain\Enums\OpeningBatchType.
    expect(types.map((t) => t.value)).toEqual(['ACCOUNTING', 'INVENTORY', 'AR_OPEN_ITEMS', 'AP_OPEN_ITEMS'])

    // The money-relevant half of the contract: which types move the GL.
    const byValue = Object.fromEntries(types.map((t) => [t.value, t]))
    expect(byValue.ACCOUNTING.affects_gl, 'GL opening posts journal entries').toBe(true)
    expect(byValue.INVENTORY.affects_gl, 'inventory opening posts Dr Inventory / Cr OBE').toBe(true)
    expect(byValue.AR_OPEN_ITEMS.affects_gl, 'AR open items are sub-ledger only — GL comes from ACCOUNTING').toBe(false)
    expect(byValue.AP_OPEN_ITEMS.affects_gl, 'AP open items are sub-ledger only').toBe(false)
    expect(byValue.AR_OPEN_ITEMS.row_type).toBe('AR')
    expect(byValue.AP_OPEN_ITEMS.row_type).toBe('AP')

    // Every enumerated value must actually be routable in the SPA.
    for (const t of types) {
      await page.goto(`/settings/opening-balances/${t.value}`)
      await expect(page.locator('body')).toBeVisible()
      const text = (await page.locator('body').innerText()).toLowerCase()
      expect(text, `${t.value} wizard must render`).not.toContain('something went wrong')
      expect(text).not.toContain('nan')
    }
  })

  test('MTP-OPB-02 (P0): AR open items — totals exact at currency scale, open_amount independent of total', async ({ page }) => {
    const customer = await createCodedPartner(page, 'customer', 'W4AR')
    await requireOpeningBatchSlot(page, 'AR_OPEN_ITEMS')
    const batch = await createOpeningBatchOfType(page, 'AR_OPEN_ITEMS', { name: uniq4('OPB02') })
    expect(batch.status, JSON.stringify(batch.body)).toBe(201)
    const batchId = batch.id as string

    const imp = await importOpeningRowsGeneric(page, batchId, [
      {
        partner_code: customer.code,
        document_date: daysFromToday(-60),
        due_date: daysFromToday(-30),
        total: '1200.000',
        open_amount: '450.000', // partially settled before cutover
        external_invoice_number: uniq4('LEG-A').slice(0, 40),
        document_type: 'invoice',
        currency: 'TND',
      },
      {
        partner_code: customer.code,
        document_date: daysFromToday(-45),
        due_date: daysFromToday(-15),
        total: '300.000',
        open_amount: '300.000',
        external_invoice_number: uniq4('LEG-B').slice(0, 40),
        document_type: 'invoice',
        currency: 'TND',
      },
    ])
    expect(imp.status, JSON.stringify(imp.body)).toBe(200)

    const val = await validateOpening(page, batchId)
    const v = (val.body as { data: { valid: boolean; total_rows: number; valid_rows: number; total_amount: string; total_open_amount: string } }).data
    expect(v.valid).toBe(true)
    expect(v.total_rows).toBe(2)
    expect(v.valid_rows).toBe(2)
    // The two totals are INDEPENDENT: face value vs still-open. Conflating them would
    // silently overstate the opening receivable by 750.000.
    expect(v.total_amount, '1200.000 + 300.000').toBe('1500.000')
    expect(v.total_open_amount, '450.000 + 300.000').toBe('750.000')
    expect(sumMoney(['1200.000', '300.000'])).toBe(v.total_amount)
    expect(sumMoney(['450.000', '300.000'])).toBe(v.total_open_amount)

    const prev = await previewOpening(page, batchId)
    const p = (prev.body as { data: { documents: Array<{ total: string; open_amount: string }>; totals: { total_documents: number; total_amount: string; total_open_amount: string }; note: string } }).data
    expect(p.documents.map((d) => d.total)).toEqual(['1200.000', '300.000'])
    expect(p.documents.map((d) => d.open_amount)).toEqual(['450.000', '300.000'])
    expect(p.totals.total_documents).toBe(2)
    expect(p.totals.total_amount).toBe('1500.000')
    expect(p.totals.total_open_amount).toBe('750.000')
    // The surface's own stated contract: no GL leg from an AR opening.
    expect(p.note).toMatch(/No GL entry will be created/i)

    const posted = await postOpening(page, batchId)
    expect(posted.status, JSON.stringify(posted.body)).toBe(200)

    // No journal entry for an AR opening — the GL side is the ACCOUNTING type's job.
    expect(queryRows(`select count(*) from journal_entries where source_id = '${batchId}'`)[0][0]).toBe('0')

    // The SUB-LEDGER moved: both historical invoices are open for their exact
    // still-open amounts, and they sum to the batch's total_open_amount.
    const open = await apiRequest(page, 'GET', `/partners/${customer.id}/open-invoices`)
    expect(open.status, JSON.stringify(open.body)).toBe(200)
    const invoices = (open.body as { data: Array<{ total: string; balance_due: string; document_number: string }> }).data
    expect(invoices.length).toBe(2)
    expect(invoices.map((i) => i.total)).toEqual(['1200.000', '300.000'])
    expect(invoices.map((i) => i.balance_due)).toEqual(['450.000', '300.000'])
    expect(sumMoney(invoices.map((i) => i.balance_due)), 'sub-ledger agrees with the batch').toBe('750.000')
    expect(invoices.every((i) => i.document_number.startsWith('HIST-INV-'))).toBe(true)

    // RULING / documented asymmetry (NOT filed as a defect): the partner BALANCE
    // projection is GL-derived (`debit_total`/`credit_total`/`transaction_count`), and an
    // AR opening writes no journal entry by design — the preview's own note says
    // "GL balance should be handled via Accounting Opening". So the balance stays 0.000
    // while the sub-ledger shows 750.000 outstanding, even after an explicit
    // `balance/refresh`. Pinned here so the asymmetry is a deliberate, visible contract:
    // if the balance ever starts to include opening open-items this goes red and the
    // ACCOUNTING-opening double-count risk must be re-examined.
    const bal = await partnerBalance(page, customer.id)
    expect(bal.status, JSON.stringify(bal.body)).toBe(200)
    const balData = (bal.body as { data: { balance: string; transaction_count: number } }).data
    expect(balData.balance, 'RULING: GL-derived balance is untouched by an AR opening').toBe('0.000')
    expect(balData.transaction_count).toBe(0)
  })

  test('MTP-OPB-03 (P0): AP open items — supplier sub-ledger moves, still no GL leg', async ({ page }) => {
    const supplier = await createCodedPartner(page, 'supplier', 'W4AP')
    await requireOpeningBatchSlot(page, 'AP_OPEN_ITEMS')
    const batch = await createOpeningBatchOfType(page, 'AP_OPEN_ITEMS', { name: uniq4('OPB03') })
    expect(batch.status, JSON.stringify(batch.body)).toBe(201)
    const batchId = batch.id as string

    expect(
      (
        await importOpeningRowsGeneric(page, batchId, [
          {
            partner_code: supplier.code,
            document_date: daysFromToday(-90),
            due_date: daysFromToday(-60),
            total: '880.500',
            open_amount: '880.500',
            external_invoice_number: uniq4('AP-LEG').slice(0, 40),
            document_type: 'invoice',
            currency: 'TND',
          },
        ])
      ).status
    ).toBe(200)

    const val = await validateOpening(page, batchId)
    const v = (val.body as { data: { valid: boolean; total_amount: string; total_open_amount: string } }).data
    expect(v.valid).toBe(true)
    // Currency scale 3, exact — an 880.5 or 880.50 here would be a scale violation.
    expect(v.total_amount).toBe('880.500')
    expect(v.total_open_amount).toBe('880.500')

    expect((await postOpening(page, batchId)).status).toBe(200)
    expect(queryRows(`select count(*) from journal_entries where source_id = '${batchId}'`)[0][0], 'AP opening posts no JE').toBe('0')

    // Same GL-vs-sub-ledger asymmetry as MTP-OPB-02: the AP opening writes no journal
    // entry, so the GL-derived partner balance stays flat while the historical supplier
    // document exists. Pinned on both sides.
    const bal = await partnerBalance(page, supplier.id)
    expect((bal.body as { data: { balance: string } }).data.balance, 'RULING: GL-derived balance untouched by an AP opening').toBe('0.000')
    const histRows = queryRows(
      `select total, balance_due from documents where partner_id = '${supplier.id}' and is_historical = true`
    )
    expect(histRows.length, 'the historical supplier document was created').toBe(1)
    expect(histRows[0][0]).toBe('880.500')
    expect(histRows[0][1], 'still fully open').toBe('880.500')
  })

  test('MTP-OPB-04 (P0): GL opening always posts a BALANCED entry; any imbalance is plugged to Opening Balance Equity by exactly the difference', async ({ page }) => {
    // DETERMINISTIC ACCOUNTS (review I-7): W-4 posts its GL openings ONLY to two
    // dedicated, W4-owned accounts, so the amounts are assertable per-account by W-6 and
    // never land on whichever real account happens to sort first.
    const debit = await ensureAccount(page, { code: W4_GL_DEBIT_ACCOUNT_CODE, name: 'W4 campaign GL debit', type: 'asset' })
    const credit = await ensureAccount(page, { code: W4_GL_CREDIT_ACCOUNT_CODE, name: 'W4 campaign GL credit', type: 'liability' })
    const debitAccount = debit.code
    const creditAccount = credit.code
    expect(debitAccount).toBe('W4GL1')
    expect(creditAccount).toBe('W4GL2')

    // (a) BALANCED rows -> a two-leg entry, no plug.
    await requireOpeningBatchSlot(page, 'ACCOUNTING')
    const balanced = await createOpeningBatchOfType(page, 'ACCOUNTING', { name: uniq4('OPB04-balanced') })
    expect(balanced.status, JSON.stringify(balanced.body)).toBe(201)
    const balancedId = balanced.id as string
    expect(
      (
        await importOpeningRowsGeneric(page, balancedId, [
          { account_code: debitAccount, debit: '640.250', credit: '0', description: 'W4 OPB04 balanced Dr' },
          { account_code: creditAccount, debit: '0', credit: '640.250', description: 'W4 OPB04 balanced Cr' },
        ])
      ).status
    ).toBe(200)
    expect((await validateOpening(page, balancedId)).status).toBe(200)
    expect((await postOpening(page, balancedId)).status, 'a balanced GL opening posts').toBe(200)
    try {
      const balancedLegs = queryRows(
        `select jl.debit, jl.credit, jl.description from journal_lines jl join journal_entries je on je.id = jl.journal_entry_id where je.source_id = '${balancedId}'`
      )
      expect(balancedLegs.length, 'exactly the two imported legs — no plug line').toBe(2)
      expect(sumMoney(balancedLegs.map((r) => r[0])), 'Dr').toBe('640.250')
      expect(sumMoney(balancedLegs.map((r) => r[1])), 'Cr').toBe('640.250')
      expect(balancedLegs.some((r) => /Opening Balance Equity offset/i.test(r[2])), 'no offset needed').toBe(false)
    } finally {
      // Retire it: a posted-but-VALIDATED batch still occupies the company's single
      // ACCOUNTING slot (review I-5). Locking is the wizard's own terminal step and
      // un-posts nothing.
      expect(await retireOpeningBatch(page, balancedId), 'balanced batch retired, slot freed').toBeLessThan(300)
    }

    // (b) UNBALANCED rows -> RECORDED BEHAVIOUR (not a refusal, and NOT a defect): a
    //     carried-over trial balance is expected to be incomplete, so
    //     AccountingOpeningService plugs the residual to Opening Balance Equity. The
    //     money contract that MUST hold is that the plug equals the imbalance EXACTLY and
    //     the resulting entry is balanced to the last millime — a plug that rounded, or a
    //     posted entry with Dr != Cr, would corrupt the trial balance for the whole
    //     fiscal year.
    await requireOpeningBatchSlot(page, 'ACCOUNTING')
    const skewed = await createOpeningBatchOfType(page, 'ACCOUNTING', { name: uniq4('OPB04-plugged') })
    expect(skewed.status, JSON.stringify(skewed.body)).toBe(201)
    const skewedId = skewed.id as string
    expect(
      (
        await importOpeningRowsGeneric(page, skewedId, [
          { account_code: debitAccount, debit: '500.000', credit: '0', description: 'W4 OPB04 plug Dr' },
          { account_code: creditAccount, debit: '0', credit: '400.000', description: 'W4 OPB04 plug Cr' },
        ])
      ).status
    ).toBe(200)
    expect((await validateOpening(page, skewedId)).status).toBe(200)
    const skewedPost = await postOpening(page, skewedId)
    expect(skewedPost.status, JSON.stringify(skewedPost.body)).toBe(200)
    try {
      const legs = queryRows(
        `select jl.debit, jl.credit, jl.description from journal_lines jl join journal_entries je on je.id = jl.journal_entry_id where je.source_id = '${skewedId}'`
      )
      expect(legs.length, 'the two imported legs plus one Opening Balance Equity plug').toBe(3)
      const plug = legs.find((r) => /Opening Balance Equity offset/i.test(r[2]))
      expect(plug, 'the residual is named, not silently absorbed into a real account').toBeDefined()
      // 500.000 Dr - 400.000 Cr = 100.000 of missing credit.
      expect((plug as string[])[1], 'plug credit == the exact imbalance').toBe('100.000')
      expect((plug as string[])[0], 'the plug is one-sided').toBe('0.000')

      // The posted entry is balanced to the millime — the invariant the trial balance rests on.
      expect(sumMoney(legs.map((r) => r[0]))).toBe('500.000')
      expect(sumMoney(legs.map((r) => r[1]))).toBe('500.000')
      expect(sumMoney(legs.map((r) => r[0]))).toBe(sumMoney(legs.map((r) => r[1])))
    } finally {
      expect(await retireOpeningBatch(page, skewedId), 'plugged batch retired, ACCOUNTING slot freed').toBeLessThan(300)
    }
  })

  test('MTP-OPB-05 (P1): AR money fields honour the 3-decimal ceiling — never silently truncated', async ({ page }) => {
    const customer = await createCodedPartner(page, 'customer', 'W4ARX')
    await requireOpeningBatchSlot(page, 'AR_OPEN_ITEMS')
    const batch = await createOpeningBatchOfType(page, 'AR_OPEN_ITEMS', { name: uniq4('OPB05') })
    const batchId = batch.id as string
    try {
      const over = await importOpeningRowsGeneric(page, batchId, [
        {
          partner_code: customer.code,
          document_date: daysFromToday(-10),
          due_date: daysFromToday(20),
          total: '100.0001',
          open_amount: '50.0001',
          document_type: 'invoice',
          currency: 'TND',
        },
      ])
      expect(over.status, JSON.stringify(over.body)).toBe(422)
      const msg = JSON.stringify(over.body)
      expect(msg).toMatch(/Total must have at most 3 decimal places/i)
      expect(msg).toMatch(/Open amount must have at most 3 decimal places/i)

      // A NEGATIVE total is also refused (`gt:0`) — an opening receivable of -X would be
      // a credit smuggled in through the AR importer.
      const negative = await importOpeningRowsGeneric(page, batchId, [
        {
          partner_code: customer.code,
          document_date: daysFromToday(-10),
          due_date: daysFromToday(20),
          total: '-100.000',
          open_amount: '0',
          document_type: 'invoice',
          currency: 'TND',
        },
      ])
      expect(negative.status, JSON.stringify(negative.body)).toBe(422)

      const val = await validateOpening(page, batchId)
      const v = (val.body as { data: { total_rows: number; total_amount: string } }).data
      expect(v.total_rows, 'nothing was imported by the rejected requests').toBe(0)
    } finally {
      expect(await retireOpeningBatch(page, batchId), 'cleanup of the rejection batch').toBeLessThan(300)
    }
  })

  test('MTP-OPB-06 (P1): one unlocked batch per type, and each type refuses another type\'s row shape', async ({ page }) => {
    await requireOpeningBatchSlot(page, 'AR_OPEN_ITEMS')
    const first = await createOpeningBatchOfType(page, 'AR_OPEN_ITEMS', { name: uniq4('OPB06-a') })
    expect(first.status, JSON.stringify(first.body)).toBe(201)
    const firstId = first.id as string
    try {
      // (a) slot guard — a second unlocked batch of the SAME type is refused, so two
      //     concurrent openings can never both post.
      const second = await createOpeningBatchOfType(page, 'AR_OPEN_ITEMS', { name: uniq4('OPB06-b') })
      expect(second.status, `a second unlocked AR batch must be refused: ${JSON.stringify(second.body)}`).toBeGreaterThanOrEqual(400)
      expect(JSON.stringify(second.body)).toMatch(/already has an unlocked/i)

      // (b) a DIFFERENT type is unaffected by the AR slot — the guard is per-type.
      await requireOpeningBatchSlot(page, 'AP_OPEN_ITEMS')
      const otherType = await createOpeningBatchOfType(page, 'AP_OPEN_ITEMS', { name: uniq4('OPB06-ap') })
      expect(otherType.status, 'the slot guard is per-type, not per-company').toBe(201)
      expect(await retireOpeningBatch(page, otherType.id as string), 'cleanup of the AP probe batch').toBeLessThan(300)

      // (c) row-shape guard — an AR batch refuses INVENTORY/GL row shapes outright, so a
      //     mis-mapped CSV cannot post as the wrong kind of money.
      const wrongShape = await importOpeningRowsGeneric(page, firstId, [
        { product_code: 'ANY-SKU', location_code: 'WH-01', quantity: '1', unit_cost: '5.000' },
      ])
      expect(wrongShape.status, JSON.stringify(wrongShape.body)).toBe(422)
      expect(JSON.stringify(wrongShape.body)).toMatch(/partner_code/i)

      const glShape = await importOpeningRowsGeneric(page, firstId, [
        { account_code: '1', debit: '10.000', credit: '0' },
      ])
      expect(glShape.status, JSON.stringify(glShape.body)).toBe(422)
    } finally {
      expect(await retireOpeningBatch(page, firstId), 'cleanup of the slot-guard batch').toBeLessThan(300)
      // NON-THROWING on purpose (review N-4): `requireOpeningBatchSlot()` THROWS when a
      // foreign batch holds the slot. Inside a finally that would replace the original
      // assertion failure with the slot error and hide why the case really failed. The
      // best-effort variant is correct here; the throwing one guards the SETUP paths.
      for (const t of ['AR_OPEN_ITEMS', 'AP_OPEN_ITEMS'] as OpeningBatchType[]) {
        await clearOpeningBatchSlot(page, t)
      }
    }
  })
})
