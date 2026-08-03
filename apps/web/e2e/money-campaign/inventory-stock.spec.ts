/**
 * MONEY TEST CAMPAIGN — wave W-4 — §D `INV` batches/expiry write-off, stock-by-location,
 * and the entry/exit-note read model. Cases MTP-INV-21..27.
 *
 * Live local stack, tenant demo-pharmacy-tn, real login, real backend.
 *
 * MTP-INV-25..27 have no definition in `2026-08-01-money-test-plan.md` — like DOC-25..27
 * in W-3 they are DERIVED from the §B.3 flow row ("Stock movements ledger + entry/exit
 * notes", flow 31) and the real route surface (`/inventory/entry-exit-notes`,
 * `GET /api/v1/entry-exit-notes`). The intent asserted here is: the entry/exit read model
 * must faithfully re-present the underlying stock movements (direction, quantities,
 * before/after, movement identity) without inventing money it does not have.
 *
 * MODULE GATING — `BatchExpiry` is a DEFAULT module for the parapharmacy vertical
 * (config/verticals.php: "Parapharmacy products are expiry-tracked
 * (requires_batch_tracking), so batch/expiry management is a default capability, not an
 * upgrade"), and `GET /batches` answers 200 for this tenant. INV-22 therefore records the
 * PERMISSION arm of the gate, which is the arm that is live here; the module arm is noted.
 */
import { test, expect } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import {
  createSupplier,
  getStockLevels,
  createStockTransfer,
  completeStockTransfer,
  WAREHOUSE_LOCATION_ID,
  SHOP1_LOCATION_ID,
  KG_UNIT_ID,
} from './w2b-support'
import {
  createW4Product,
  costPrice,
  poAndReceive,
  stockMatrix,
  entryExitNotes,
  stockMovements,
  listBatches,
  uniq4,
  today,
  mulQtyMoneyTrunc,
  addMoney,
} from './w4-support'

test.describe.configure({ timeout: 240_000 })

let supplierId: string

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage()
  await loginAsRole(page, 'owner')
  supplierId = await createSupplier(page, uniq4('STK-Supplier'))
  await page.close()
})

/** Receive a batch-tracked product so an expiry lot exists. */
async function receiveWithLot(
  page: import('@playwright/test').Page,
  opts: { quantity: string; unitPrice: string; expiry: string }
): Promise<{ productId: string; batchUuid: string }> {
  const { id: productId } = await createW4Product(page, 'LOT', { requiresBatchTracking: true })
  const poRes = await apiRequest(page, 'POST', '/purchase-orders', {
    partner_id: supplierId,
    document_date: today(),
    location_id: WAREHOUSE_LOCATION_ID,
    lines: [{ product_id: productId, description: 'lot line', quantity: opts.quantity, unit_price: opts.unitPrice, tax_rate: '19.00' }],
  })
  const poId = (poRes.body as { data: { id: string } }).data.id
  expect((await apiRequest(page, 'POST', `/purchase-orders/${poId}/confirm`)).status).toBe(200)
  const po = await apiRequest(page, 'GET', `/purchase-orders/${poId}`)
  const lineId = (po.body as { data: { lines: Array<{ id: string }> } }).data.lines[0].id
  const rec = await apiRequest(page, 'POST', `/purchase-orders/${poId}/receive`, {
    quantities: { [lineId]: opts.quantity },
    batches: { [lineId]: { batch_number: uniq4('LOT').slice(0, 60), expiry_date: opts.expiry } },
  })
  expect(rec.status, JSON.stringify(rec.body)).toBe(200)

  const stock = await apiRequest(page, 'GET', `/products/${productId}/batch-stock`)
  const rows = (stock.body as { data: Array<{ uuid: string }> }).data
  expect(rows.length, 'the receipt created a lot').toBeGreaterThanOrEqual(1)
  return { productId, batchUuid: rows[0].uuid }
}

test.describe('INV — batches / expiry write-off (21..22)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-INV-21 (P1): write-off of 3.0000 posts value = 3 x WAC exactly (6.000000 => 18.000000)', async ({ page }) => {
    const { productId, batchUuid } = await receiveWithLot(page, { quantity: '10', unitPrice: '6.000', expiry: '2026-09-30' })
    expect(await costPrice(page, productId)).toBe('6.000000')

    const wo = await apiRequest(page, 'POST', '/batches/write-off-grouped', {
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [{ batch_id: batchUuid, quantity: '3' }],
      reason: 'expiry',
      idempotency_key: uniq4('wo').slice(0, 64),
    })
    expect(wo.status, JSON.stringify(wo.body)).toBe(201)
    const movements = (wo.body as { data: { replayed: boolean; movements: Array<{ quantity: string; unit_cost: string; total_cost: string }> } }).data
    expect(movements.replayed).toBe(false)
    expect(movements.movements.length).toBe(1)

    // The money the page does not show, proven server-side: 3 x 6.000000.
    expect(movements.movements[0].unit_cost, 'write-off is valued at the WAC at rest (6 dp)').toBe('6.000000')
    expect(movements.movements[0].total_cost).toBe('18.000000')
    expect(mulQtyMoneyTrunc('3', '6.000000', 6), 'the same product computed with exact millime arithmetic').toBe('18.000')

    // Stock drops by exactly the written-off quantity; the WAC is NOT restated by a
    // write-off (value leaves at the current average, so the average is unchanged).
    const levels = await getStockLevels(page, productId)
    expect(levels.reduce((s, l) => s + Number(l.quantity), 0)).toBe(7)
    expect(await costPrice(page, productId), 'a write-off consumes value at WAC; it must not move WAC').toBe('6.000000')

    const mv = await stockMovements(page, `?product_id=${productId}`)
    const rows = (mv.body as { data: Array<{ movement_type: string; quantity: string; reason: string | null }> }).data
    const issue = rows.find((r) => r.movement_type === 'issue')
    expect(issue?.quantity).toBe('3.0000')
    expect(issue?.reason).toBe('expiry')

    // DOCUMENTED GAP (plan MTP-INV-21): the write-off page carries no money total. Pinned
    // by asserting the value is only reachable from the mutation response / movement row,
    // never rendered on /inventory/expiry-write-off.
    await page.goto('/inventory/expiry-write-off')
    await expect(page.locator('body')).toBeVisible()
    const text = await page.locator('body').innerText()
    expect(text).not.toContain('18.000')
    expect(text.toLowerCase()).not.toContain('nan')
  })

  test('MTP-INV-22 (P1): a principal without batches.write-off cannot reach the write-off mutation', async ({ page }) => {
    const { productId, batchUuid } = await receiveWithLot(page, { quantity: '5', unitPrice: '4.000', expiry: '2026-09-30' })

    // MODULE ARM (recorded): BatchExpiry is ON for this tenant — GET /batches answers 200
    // for the owner, so a module-gate refusal is NOT the expected verdict here (per
    // docs/architecture/vertical-module-gating.md a 403 would be legitimate only if the
    // module were off).
    const asOwner = await listBatches(page, '?per_page=1')
    expect(asOwner.status, 'BatchExpiry module is active for demo-pharmacy-tn').toBe(200)

    await loginAsRole(page, 'viewer')
    const denied = await apiRequest(page, 'POST', '/batches/write-off-grouped', {
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [{ batch_id: batchUuid, quantity: '1' }],
      reason: 'expiry',
      idempotency_key: uniq4('wo-denied').slice(0, 64),
    })
    expect(denied.status, `expected a denial; got ${denied.status} ${JSON.stringify(denied.body)}`).toBe(403)

    await loginAsRole(page, 'owner')
    const levels = await getStockLevels(page, productId)
    expect(levels.reduce((s, l) => s + Number(l.quantity), 0), 'no stock written off by the refused request').toBe(5)
    expect(await costPrice(page, productId)).toBe('4.000000')
  })
})

test.describe('INV — stock by location (23..24)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-INV-23 (P1): per-location quantities sum to the product total; unit precision is respected', async ({ page }) => {
    // A piece product (units.decimal_places = 0) and a kilogram product
    // (decimal_places = 3) so the display-precision contract is exercised on both.
    const pieces = await createW4Product(page, 'INV23-pc')
    const kilos = await createW4Product(page, 'INV23-kg', { unitId: KG_UNIT_ID })
    expect((await poAndReceive(page, { supplierId, productId: pieces.id, quantity: '10', unitPrice: '2.000' })).receiveStatus).toBe(200)
    expect((await poAndReceive(page, { supplierId, productId: kilos.id, quantity: '2.505', unitPrice: '3.000' })).receiveStatus).toBe(200)

    // Split the piece product across two locations: 3 to shop 1, 7 stay in the warehouse.
    const tr = await createStockTransfer(page, {
      sourceLocationId: WAREHOUSE_LOCATION_ID,
      destinationLocationId: SHOP1_LOCATION_ID,
      lines: [{ productId: pieces.id, quantity: '3' }],
    })
    expect(tr.status, JSON.stringify(tr.body)).toBe(201)
    expect((await completeStockTransfer(page, tr.id as string)).status).toBe(200)

    const matrix = await stockMatrix(page, `?search=${encodeURIComponent(pieces.sku)}`)
    expect(matrix.status, JSON.stringify(matrix.body)).toBe(200)
    const row = ((matrix.body as { data: Array<{ product_id: string; cells: Record<string, { on_hand: string }> }> }).data ?? []).find(
      (r) => r.product_id === pieces.id
    )
    expect(row, 'the product appears in the stock matrix').toBeDefined()
    const cells = row as { cells: Record<string, { on_hand: string }> }
    expect(cells.cells[WAREHOUSE_LOCATION_ID].on_hand).toBe('7.0000')
    expect(cells.cells[SHOP1_LOCATION_ID].on_hand).toBe('3.0000')

    // Sigma per-location == the product's total on-hand, asserted as an EXACT quantity
    // string (scale 4) rather than a float sum.
    const perLocation = Object.values(cells.cells).map((c) => c.on_hand)
    const sum = perLocation.reduce((acc, q) => addMoney(acc, q.slice(0, q.length - 1)), '0.000')
    expect(sum, 'Sigma of every location cell').toBe('10.000')
    const levels = await getStockLevels(page, pieces.id)
    expect(levels.reduce((s, l) => s + Number(l.quantity), 0)).toBe(10)

    // Fractional unit: the kg product keeps its 3-dp value intact on the wire.
    const kgMatrix = await stockMatrix(page, `?search=${encodeURIComponent(kilos.sku)}`)
    const kgRow = ((kgMatrix.body as { data: Array<{ product_id: string; cells: Record<string, { on_hand: string }> }> }).data ?? []).find(
      (r) => r.product_id === kilos.id
    )
    expect((kgRow as { cells: Record<string, { on_hand: string }> }).cells[WAREHOUSE_LOCATION_ID].on_hand).toBe('2.5050')

    // The movements read model carries the unit's display precision alongside the
    // canonical 4-dp value, which is what the UI must format with (CLAUDE.md rule 19).
    const mv = await stockMovements(page, `?product_id=${kilos.id}`)
    const mvRows = (mv.body as { data: Array<{ quantity: string; quantity_decimals: number }> }).data
    expect(mvRows[0].quantity).toBe('2.5050')
    expect(mvRows[0].quantity_decimals, 'kilogram => 3 decimal places').toBe(3)
    const pcMv = await stockMovements(page, `?product_id=${pieces.id}`)
    expect((pcMv.body as { data: Array<{ quantity_decimals: number }> }).data[0].quantity_decimals, 'piece => 0 decimal places').toBe(0)
  })

  test('MTP-INV-24 (P2): a product with no stock anywhere renders zero cells, never a fabricated valuation', async ({ page }) => {
    // demo-pharmacy-tn is not a fresh tenant, so the honest equivalent of the plan's
    // "empty state" precondition is a product that genuinely has no stock row anywhere.
    const empty = await createW4Product(page, 'INV24')
    const levels = await getStockLevels(page, empty.id)
    expect(levels.length, 'no stock_levels rows at all').toBe(0)

    const matrix = await stockMatrix(page, `?search=${encodeURIComponent(empty.sku)}`)
    expect(matrix.status).toBe(200)
    const row = ((matrix.body as { data: Array<{ product_id: string; cells: Record<string, { on_hand: string }> }> }).data ?? []).find(
      (r) => r.product_id === empty.id
    )
    if (row !== undefined) {
      for (const [locationId, cell] of Object.entries(row.cells)) {
        expect(cell.on_hand, `location ${locationId} must read a real 0, not blank/NaN`).toBe('0.0000')
      }
    }

    await page.goto('/inventory/stock-by-location')
    await expect(page.locator('body')).toBeVisible()
    const text = (await page.locator('body').innerText()).toLowerCase()
    expect(text).not.toContain('nan')
    expect(text).not.toContain('something went wrong')
  })
})

test.describe('INV — entry / exit notes read model (25..27)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-INV-25 (P1): a goods receipt surfaces as an INBOUND note whose lines mirror the movement exactly', async ({ page }) => {
    const p = await createW4Product(page, 'INV25')
    const r = await poAndReceive(page, { supplierId, productId: p.id, quantity: '9', unitPrice: '1.500' })
    expect(r.receiveStatus, JSON.stringify(r.receiveBody)).toBe(200)

    const mv = await stockMovements(page, `?product_id=${p.id}`)
    const movement = (mv.body as { data: Array<{ id: string; quantity: string }> }).data[0]

    const notes = await entryExitNotes(page, '?direction=in')
    expect(notes.status, JSON.stringify(notes.body)).toBe(200)
    const rows = (notes.body as {
      data: Array<{ direction: string; source_type: string; lines: Array<{ movement_id: string; quantity: string; quantity_before: string; quantity_after: string; product: { id: string } }> }>
    }).data
    const note = rows.find((n) => n.lines.some((l) => l.product.id === p.id))
    expect(note, 'the receipt has an inbound entry note').toBeDefined()
    const n = note as NonNullable<typeof note>
    expect(n.direction).toBe('in')
    expect(n.source_type).toBe('purchase_order')

    const line = n.lines.find((l) => l.product.id === p.id) as NonNullable<(typeof n.lines)[number]>
    // Byte-identical re-presentation of the movement — not a recomputation.
    expect(line.movement_id).toBe(movement.id)
    expect(line.quantity).toBe('9.0000')
    expect(line.quantity).toBe(movement.quantity)
    expect(line.quantity_before).toBe('0.0000')
    expect(line.quantity_after).toBe('9.0000')
  })

  test('MTP-INV-26 (P1): a write-off surfaces as an OUTBOUND note carrying the same movement identity', async ({ page }) => {
    const { productId, batchUuid } = await receiveWithLot(page, { quantity: '8', unitPrice: '2.500', expiry: '2026-09-30' })
    const wo = await apiRequest(page, 'POST', '/batches/write-off-grouped', {
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [{ batch_id: batchUuid, quantity: '2' }],
      reason: 'expiry',
      idempotency_key: uniq4('wo26').slice(0, 64),
    })
    expect(wo.status, JSON.stringify(wo.body)).toBe(201)
    const movementId = (wo.body as { data: { movements: Array<{ movement_id: string }> } }).data.movements[0].movement_id

    const notes = await entryExitNotes(page, '?direction=out')
    const rows = (notes.body as {
      data: Array<{ direction: string; lines: Array<{ movement_id: string; quantity: string; quantity_before: string; quantity_after: string }> }>
    }).data
    const note = rows.find((n) => n.lines.some((l) => l.movement_id === movementId))
    expect(note, 'the write-off has an outbound exit note').toBeDefined()
    const n = note as NonNullable<typeof note>
    expect(n.direction).toBe('out')

    const line = n.lines.find((l) => l.movement_id === movementId) as NonNullable<(typeof n.lines)[number]>
    expect(line.quantity).toBe('2.0000')
    expect(line.quantity_before).toBe('8.0000')
    expect(line.quantity_after).toBe('6.0000')

    // The read model's own arithmetic must close: before - out == after.
    expect(addMoney(line.quantity_after.slice(0, -1), line.quantity.slice(0, -1))).toBe(
      line.quantity_before.slice(0, -1)
    )

    // And it must agree with the ledger it re-presents.
    const levels = await getStockLevels(page, productId)
    expect(levels.reduce((s, l) => s + Number(l.quantity), 0)).toBe(6)
  })

  test('MTP-INV-27 (P2): entry/exit notes expose NO money columns — the documented gap, pinned', async ({ page }) => {
    const p = await createW4Product(page, 'INV27', { unitId: KG_UNIT_ID })
    expect((await poAndReceive(page, { supplierId, productId: p.id, quantity: '1.250', unitPrice: '40.000' })).receiveStatus).toBe(200)

    const notes = await entryExitNotes(page, '?direction=in')
    const rows = (notes.body as {
      data: Array<{ lines: Array<Record<string, unknown> & { product: { id: string } }> }>
    }).data
    const note = rows.find((n) => n.lines.some((l) => l.product.id === p.id))
    expect(note, 'the receipt is represented').toBeDefined()
    const line = (note as NonNullable<typeof note>).lines.find((l) => l.product.id === p.id) as Record<string, unknown>

    // Same documented gap as MTP-INV-08: no unit_cost / total_cost / value anywhere, so a
    // reader cannot reconcile inventory VALUE from this surface. Pinned so adding (or
    // removing) money here is a deliberate, visible decision.
    for (const key of ['unit_cost', 'total_cost', 'value', 'line_value', 'amount']) {
      expect(key in line, `DOCUMENTED GAP: entry/exit notes must not silently gain a '${key}' column`).toBe(false)
    }

    // What it DOES carry must be exact and unit-aware.
    expect(line.quantity).toBe('1.2500')
    expect(line.quantity_decimals, 'kilogram => 3 decimal places for display').toBe(3)
  })
})
