/**
 * MONEY TEST CAMPAIGN — wave W-4 — §D `INV` inventory OPENING BALANCE wizard.
 * Cases MTP-INV-10..15 (`/settings/opening-balances/INVENTORY`).
 *
 * Live local stack, tenant demo-pharmacy-tn, real login, real backend.
 *
 * FIXTURE DISCIPLINE — the enter-once guard is scoped per (company, product, location)
 * (OpeningBalancePostingService.php:88-95: an active, non-reversed `Opening` movement for
 * the same product+location), NOT company-wide. Every case therefore uses its OWN fresh
 * products, so posting an opening here does not consume a one-shot for the tenant and does
 * not collide with any other wave.
 *
 * PERSISTENCE — MTP-INV-10's posted batch is a LEGITIMATE money movement (one balanced
 * `Dr Inventory 119.000 / Cr Opening Balance Equity 119.000` journal entry) and is
 * deliberately LEFT IN PLACE: W-6's `MTP-GL-16` asserts exactly that entry on the trial
 * balance. Draft/never-posted probe batches created along the way are DELETED in a
 * finally block so they cannot leak into another wave's list reads.
 */
import { test, expect } from '@playwright/test'
import { loginAsRole } from './helpers'
import { KG_UNIT_ID } from './w2b-support'
import {
  createW4Product,
  costPrice,
  createOpeningBatchOfType,
  requireOpeningBatchSlot,

  importOpeningRowsGeneric,
  validateOpening,
  previewOpening,
  postOpening,
  lockOpening,

  retireOpeningBatch,
  openingRows,
  stockMovements,
  queryRows,
  uniq4,
  sumMoney,
} from './w4-support'

test.describe.configure({ timeout: 180_000 })

const WH = 'WH-01'

test.describe('INV — opening balance wizard (INVENTORY)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-INV-10 (P0): 3-row opening posts 119.000 with ONE balanced journal entry', async ({ page }) => {
    // 10 x 5.000 = 50.000 ; 4 x 12.250 = 49.000 ; 2.5 x 8.000 = 20.000  =>  119.000
    const p1 = await createW4Product(page, 'INV10a')
    const p2 = await createW4Product(page, 'INV10b')
    const p3 = await createW4Product(page, 'INV10c', { unitId: KG_UNIT_ID })

    await requireOpeningBatchSlot(page, 'INVENTORY')
    const batch = await createOpeningBatchOfType(page, 'INVENTORY', { name: uniq4('INV10') })
    expect(batch.status, JSON.stringify(batch.body)).toBe(201)
    const batchId = batch.id as string

    const imp = await importOpeningRowsGeneric(page, batchId, [
      { product_code: p1.sku, location_code: WH, quantity: '10', unit_cost: '5.000' },
      { product_code: p2.sku, location_code: WH, quantity: '4', unit_cost: '12.250' },
      { product_code: p3.sku, location_code: WH, quantity: '2.5', unit_cost: '8.000' },
    ])
    expect(imp.status, JSON.stringify(imp.body)).toBe(200)

    const val = await validateOpening(page, batchId)
    const valData = (val.body as { data: { valid: boolean; total_rows: number; valid_rows: number; invalid_rows: number; total_value: string } }).data
    expect(valData.valid).toBe(true)
    expect(valData.total_rows).toBe(3)
    expect(valData.valid_rows).toBe(3)
    expect(valData.invalid_rows).toBe(0)
    expect(valData.total_value, 'exact decimal string at currency scale 3').toBe('119.000')

    const prev = await previewOpening(page, batchId)
    const lines = (prev.body as { data: { lines: Array<{ quantity: string; unit_cost: string; line_value: string }> } }).data.lines
    expect(lines.map((l) => l.line_value)).toEqual(['50.000', '49.000', '20.000'])
    expect(sumMoney(lines.map((l) => l.line_value))).toBe('119.000')
    // Quantities carry the canonical 4-dp quantity scale on the wire.
    expect(lines.map((l) => l.quantity)).toEqual(['10.0000', '4.0000', '2.5000'])

    const posted = await postOpening(page, batchId)
    expect(posted.status, JSON.stringify(posted.body)).toBe(200)

    // cost_price stamped from unit_cost, at rest at 6 dp.
    expect(await costPrice(page, p1.id)).toBe('5.000000')
    expect(await costPrice(page, p2.id)).toBe('12.250000')
    expect(await costPrice(page, p3.id)).toBe('8.000000')

    // One `opening` StockMovement per line.
    for (const p of [p1, p2, p3]) {
      const mv = await stockMovements(page, `?product_id=${p.id}`)
      const rows = (mv.body as { data: Array<{ movement_type: string }> }).data
      expect(rows.filter((r) => r.movement_type === 'opening').length, `${p.sku} has exactly one opening movement`).toBe(1)
    }

    // ONE balanced journal entry: Dr Inventory 119.000 / Cr Opening Balance Equity 119.000.
    // The GL is not exposed per-batch by any web endpoint, so the JE is read from the
    // relevant DB row (plan §3 evidence rule).
    const jeRows = queryRows(
      `select jl.debit, jl.credit from journal_lines jl join journal_entries je on je.id = jl.journal_entry_id where je.source_id = '${batchId}' order by jl.debit desc`
    )
    expect(jeRows.length, 'exactly two legs — one entry, not one per line').toBe(2)
    const debits = jeRows.map((r) => r[0])
    const credits = jeRows.map((r) => r[1])
    expect(sumMoney(debits), 'Dr side').toBe('119.000')
    expect(sumMoney(credits), 'Cr side').toBe('119.000')
    expect(sumMoney(debits)).toBe(sumMoney(credits))
  })

  test('MTP-INV-11 (P0): a second opening for the SAME product+location is refused (enter-once)', async ({ page }) => {
    const p = await createW4Product(page, 'INV11')
    await requireOpeningBatchSlot(page, 'INVENTORY')
    const first = await createOpeningBatchOfType(page, 'INVENTORY', { name: uniq4('INV11-a') })
    const firstId = first.id as string
    expect((await importOpeningRowsGeneric(page, firstId, [{ product_code: p.sku, location_code: WH, quantity: '5', unit_cost: '4.000' }])).status).toBe(200)
    expect((await validateOpening(page, firstId)).status).toBe(200)
    expect((await postOpening(page, firstId)).status, 'first opening posts').toBe(200)
    expect(await costPrice(page, p.id)).toBe('4.000000')

    await requireOpeningBatchSlot(page, 'INVENTORY')
    const second = await createOpeningBatchOfType(page, 'INVENTORY', { name: uniq4('INV11-b') })
    const secondId = second.id as string
    let secondPosted = false
    try {
      expect((await importOpeningRowsGeneric(page, secondId, [{ product_code: p.sku, location_code: WH, quantity: '99', unit_cost: '99.000' }])).status).toBe(200)
      await validateOpening(page, secondId)
      const res = await postOpening(page, secondId)
      secondPosted = res.status < 400
      expect(res.status, `OpeningAlreadyExistsException expected: ${JSON.stringify(res.body)}`).toBeGreaterThanOrEqual(400)

      // No second JE and no duplicated stock: the cost is still the FIRST opening's.
      expect(await costPrice(page, p.id), 'the refused second opening must not restate the cost').toBe('4.000000')
      const je = queryRows(`select count(*) from journal_entries where source_id = '${secondId}'`)
      expect(je[0][0], 'no journal entry for the refused batch').toBe('0')
    } finally {
      // The refused batch never posted -> a DRAFT row that would otherwise leak into
      // every later opening-balance list read. Remove it and assert the removal.
      if (!secondPosted) {
        expect(await retireOpeningBatch(page, secondId), 'cleanup of the refused batch must succeed').toBeLessThan(300)
      }
    }
  })

  test('MTP-INV-12 (P1): a malformed row is marked INVALID and EXCLUDED from the batch total', async ({ page }) => {
    const good1 = await createW4Product(page, 'INV12a')
    const good2 = await createW4Product(page, 'INV12b')
    await requireOpeningBatchSlot(page, 'INVENTORY')
    const batch = await createOpeningBatchOfType(page, 'INVENTORY', { name: uniq4('INV12') })
    const batchId = batch.id as string
    let posted = false
    try {
      const imp = await importOpeningRowsGeneric(page, batchId, [
        { product_code: good1.sku, location_code: WH, quantity: '10', unit_cost: '3.000' },
        // Malformed: a product_code that resolves to nothing in this company.
        { product_code: 'W4-DOES-NOT-EXIST-SKU', location_code: WH, quantity: '7', unit_cost: '100.000' },
        { product_code: good2.sku, location_code: WH, quantity: '2', unit_cost: '5.000' },
      ])
      expect(imp.status, JSON.stringify(imp.body)).toBe(200)

      const val = await validateOpening(page, batchId)
      const d = (val.body as { data: { valid: boolean; total_rows: number; valid_rows: number; invalid_rows: number; total_value: string; errors?: unknown[] } }).data
      expect(d.total_rows).toBe(3)
      expect(d.invalid_rows, 'the unresolvable product code is INVALID').toBe(1)
      expect(d.valid_rows).toBe(2)
      expect(d.valid).toBe(false)
      // 30.000 + 10.000 = 40.000. The 700.000 of the bad row must NOT be in the total.
      // This is the money assertion the case exists for: an invalid row cannot inflate
      // the opening JE.
      expect(d.total_value, 'total counts VALID rows only').toBe('40.000')

      const rows = await openingRows(page, batchId)
      const statuses = ((rows.body as { data: Array<{ status: string }> }).data ?? []).map((r) => r.status)
      expect(statuses.filter((s) => s === 'INVALID' || s === 'ERROR').length).toBeGreaterThanOrEqual(1)
    } finally {
      if (!posted) {
        expect(await retireOpeningBatch(page, batchId), 'cleanup of the never-posted validation batch').toBeLessThan(300)
      }
    }
  })

  test('MTP-INV-13 (P1): unit_cost 0 posts the quantity, contributes 0.000 to the JE, batch stays balanced', async ({ page }) => {
    const zero = await createW4Product(page, 'INV13a')
    const priced = await createW4Product(page, 'INV13b')
    await requireOpeningBatchSlot(page, 'INVENTORY')
    const batch = await createOpeningBatchOfType(page, 'INVENTORY', { name: uniq4('INV13') })
    const batchId = batch.id as string

    expect(
      (
        await importOpeningRowsGeneric(page, batchId, [
          { product_code: zero.sku, location_code: WH, quantity: '6', unit_cost: '0' },
          { product_code: priced.sku, location_code: WH, quantity: '2', unit_cost: '11.000' },
        ])
      ).status
    ).toBe(200)

    const val = await validateOpening(page, batchId)
    const d = (val.body as { data: { total_value: string; valid: boolean } }).data
    expect(d.valid).toBe(true)
    // 0.000 + 22.000
    expect(d.total_value).toBe('22.000')

    expect((await postOpening(page, batchId)).status, 'a zero-cost opening row is legitimate').toBe(200)

    // cost_price is NOT stamped from a zero unit_cost (guard: only stamp when > 0).
    expect(await costPrice(page, zero.id), 'zero unit_cost leaves the WAC untouched').toBe('0.000000')
    expect(await costPrice(page, priced.id)).toBe('11.000000')

    const jeRows = queryRows(
      `select jl.debit, jl.credit from journal_lines jl join journal_entries je on je.id = jl.journal_entry_id where je.source_id = '${batchId}'`
    )
    expect(sumMoney(jeRows.map((r) => r[0])), 'Dr').toBe('22.000')
    expect(sumMoney(jeRows.map((r) => r[1])), 'Cr — still balanced').toBe('22.000')
  })

  test('MTP-INV-14 (P1): unit_cost 5.0001 and quantity 1.00001 are REJECTED by the scale ceilings', async ({ page }) => {
    const p = await createW4Product(page, 'INV14')
    await requireOpeningBatchSlot(page, 'INVENTORY')
    const batch = await createOpeningBatchOfType(page, 'INVENTORY', { name: uniq4('INV14') })
    const batchId = batch.id as string
    try {
      const overPrecise = await importOpeningRowsGeneric(page, batchId, [
        { product_code: p.sku, location_code: WH, quantity: '1.00001', unit_cost: '5.0001' },
      ])
      // RECORDED: enforcement is at the IMPORT boundary (422 on the regex ceilings),
      // not at the later `validate` step the plan names. Either way the requirement is
      // met — the values are never silently truncated into the batch.
      expect(overPrecise.status, JSON.stringify(overPrecise.body)).toBe(422)
      const msg = JSON.stringify(overPrecise.body)
      expect(msg).toMatch(/at most 3 decimal places/i)
      expect(msg).toMatch(/at most 4 decimal places/i)

      const rows = await openingRows(page, batchId)
      expect(((rows.body as { data?: unknown[] }).data ?? []).length, 'nothing imported').toBe(0)
    } finally {
      expect(await retireOpeningBatch(page, batchId), 'cleanup of the empty rejection batch').toBeLessThan(300)
    }
  })

  test('MTP-INV-15 (P1): a LOCKED batch refuses further posting', async ({ page }) => {
    const p = await createW4Product(page, 'INV15')
    await requireOpeningBatchSlot(page, 'INVENTORY')
    const batch = await createOpeningBatchOfType(page, 'INVENTORY', { name: uniq4('INV15') })
    const batchId = batch.id as string
    expect((await importOpeningRowsGeneric(page, batchId, [{ product_code: p.sku, location_code: WH, quantity: '3', unit_cost: '6.000' }])).status).toBe(200)
    expect((await validateOpening(page, batchId)).status).toBe(200)
    expect((await postOpening(page, batchId)).status).toBe(200)
    expect(await costPrice(page, p.id)).toBe('6.000000')

    const lock = await lockOpening(page, batchId)
    expect(lock.status, JSON.stringify(lock.body)).toBeLessThan(400)

    const rePost = await postOpening(page, batchId)
    expect(rePost.status, `a locked batch must refuse posting: ${JSON.stringify(rePost.body)}`).toBeGreaterThanOrEqual(400)

    const reImport = await importOpeningRowsGeneric(page, batchId, [
      { product_code: p.sku, location_code: WH, quantity: '99', unit_cost: '99.000' },
    ])
    expect(reImport.status, `a locked batch must refuse new rows: ${JSON.stringify(reImport.body)}`).toBeGreaterThanOrEqual(400)

    expect(await costPrice(page, p.id), 'cost unchanged by the refused re-post').toBe('6.000000')
  })
})
