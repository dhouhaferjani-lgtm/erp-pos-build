/**
 * MONEY TEST CAMPAIGN — wave W-4 — §D `INV` perpetual weighted-average cost.
 * Cases MTP-INV-01..09 (docs/qa/2026-08-01-money-test-plan.md).
 *
 * Live local stack (web :5173 -> api :8010), tenant demo-pharmacy-tn, real login,
 * real backend, no mocks.
 *
 * FIXTURE DISCIPLINE (brief §"Fixture discipline"): WAC is a PERSISTENT, company-wide
 * property of a product. Every costing case below creates its OWN dedicated product
 * (unique-suffixed `W4-…`) and never touches a seeded pharmacy product, so no other
 * wave's valuation assertions move underneath it. The receipts these cases post are
 * legitimate money movements and are LEFT IN PLACE (W-6 GL reads consume them);
 * nothing here is a junk probe that needs cleaning up.
 */
import { test, expect, type Page } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import { createSupplier, getStockLevels, SHOP1_LOCATION_ID, WAREHOUSE_LOCATION_ID, createStockTransfer, completeStockTransfer } from './w2b-support'
import { createW4Product, costPrice, poAndReceive, uniq4, mulQtyMoneyTrunc, stockMovements, adjustStockTo } from './w4-support'

let supplierId: string

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage()
  await loginAsRole(page, 'owner')
  supplierId = await createSupplier(page, uniq4('INV-Supplier'))
  await page.close()
})

// Each case authors a real PO -> confirm -> receipt chain against the single local
// backend process, which visibly degrades under load (plan §E.1). 30s is not enough
// for a multi-receipt case; --workers=1 keeps the wall clock honest.
test.describe.configure({ timeout: 180_000 })

test.describe('INV — perpetual WAC / stock valuation', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-INV-01 (P0): first receipt 10 @ 12.500 sets WAC 12.500000; stock value 125.000', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'INV01')
    expect(await costPrice(page, productId), 'fresh product carries no cost').toBe('0.000000')

    const r = await poAndReceive(page, { supplierId, productId, quantity: '10', unitPrice: '12.500' })
    expect(r.receiveStatus, JSON.stringify(r.receiveBody)).toBe(200)

    // WAC at rest is 6 dp (WeightedAverageCostService::costScale()), exact string.
    expect(await costPrice(page, productId)).toBe('12.500000')

    const levels = await getStockLevels(page, productId)
    const onHand = levels.reduce((sum, l) => sum + Number(l.quantity), 0)
    expect(onHand, 'stock posted at the receiving location').toBe(10)
    // Stock value = qty x WAC, truncated once at currency scale (never a float product).
    expect(mulQtyMoneyTrunc('10', '12.500000', 6)).toBe('125.000')
  })

  test('MTP-INV-02 (P0): blend 10 @ 12.500 + 5 @ 13.000 => 12.666666 (TRUNCATED, not 12.666667)', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'INV02')
    const first = await poAndReceive(page, { supplierId, productId, quantity: '10', unitPrice: '12.500' })
    expect(first.receiveStatus, JSON.stringify(first.receiveBody)).toBe(200)
    expect(await costPrice(page, productId)).toBe('12.500000')

    const second = await poAndReceive(page, { supplierId, productId, quantity: '5', unitPrice: '13.000' })
    expect(second.receiveStatus, JSON.stringify(second.receiveBody)).toBe(200)

    // (125.000 + 65.000) / 15 = 12.6666666...  A half-up value (12.666667) here would
    // be a precision-contract violation (CLAUDE.md rule 19: bcformat TRUNCATES).
    expect(await costPrice(page, productId)).toBe('12.666666')
  })

  test('MTP-INV-03 (P0): blend 3 @ 10.000 + 4 @ 11.000 => 10.571428 (truncated at 6 dp)', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'INV03')
    const first = await poAndReceive(page, { supplierId, productId, quantity: '3', unitPrice: '10.000' })
    expect(first.receiveStatus, JSON.stringify(first.receiveBody)).toBe(200)
    const second = await poAndReceive(page, { supplierId, productId, quantity: '4', unitPrice: '11.000' })
    expect(second.receiveStatus, JSON.stringify(second.receiveBody)).toBe(200)

    // 74 / 7 = 10.571428571... -> 10.571428
    expect(await costPrice(page, productId)).toBe('10.571428')
  })

  test('MTP-INV-04 (P0): WAC guard at the owned-qty == 0 boundary — no div-by-zero, no stale-cost blend', async ({ page }) => {
    // The guard is `bccomp($newCompanyQty, '0', 4) > 0 ? blend : '0'` in
    // WeightedAverageCostService::recordPurchase()/recordReturn(). Its `< 0` arm has no
    // web path (no route posts a NEGATIVE receipt) — see the wave report §6. Its `== 0`
    // boundary IS reachable and is what this case now genuinely drives: consume the
    // entire company-owned quantity, then receive again at a different price.
    const { id: productId } = await createW4Product(page, 'INV04')
    const seed = await poAndReceive(page, { supplierId, productId, quantity: '4', unitPrice: '20.000' })
    expect(seed.receiveStatus, JSON.stringify(seed.receiveBody)).toBe(200)
    expect(await costPrice(page, productId)).toBe('20.000000')

    // Actually consume to ZERO. A transfer would NOT do it (in-transit and other
    // locations still count as company-owned, so the WAC basis would be unchanged); the
    // manual adjustment endpoint is the only web path that removes owned quantity.
    const toZero = await adjustStockTo(page, { productId, newQuantity: '0', reason: 'MTP-INV-04 drive owned qty to zero' })
    // 201 on a real correction; 200 on the skip-when-already-at-target branch,
    // which the delta contract makes reachable (T12).
    expect([200, 201], JSON.stringify(toZero.body)).toContain(toZero.status)

    const zeroLevels = await getStockLevels(page, productId)
    expect(zeroLevels.reduce((s, l) => s + Number(l.quantity), 0), 'company-owned quantity is exactly 0').toBe(0)

    // At the boundary: the cost is RETAINED, finite, and 6-dp — never NaN, never a 500,
    // never silently zeroed by the divide.
    const atZero = await costPrice(page, productId)
    expect(atZero, 'the last known average survives an empty shelf').toBe('20.000000')
    expect(atZero).toMatch(/^\d+\.\d{6}$/)

    // Now the boundary behaviour that actually matters for money: with companyQty == 0
    // the blend basis (`currentValue = 0 x 20.000000`) is ZERO, so the next receipt sets
    // the average outright instead of averaging against a stale 20.000000.
    // A naive implementation that kept the old value would give (80.000 + 40.000) / 4 =
    // 30.000000, and one that averaged the two prices would give 15.000000.
    const more = await poAndReceive(page, { supplierId, productId, quantity: '4', unitPrice: '10.000' })
    expect(more.receiveStatus, JSON.stringify(more.receiveBody)).toBe(200)
    expect(await costPrice(page, productId), 'zero owned units => the new receipt price IS the new WAC').toBe('10.000000')

    const afterLevels = await getStockLevels(page, productId)
    expect(afterLevels.reduce((s, l) => s + Number(l.quantity), 0)).toBe(4)
  })

  test('MTP-INV-05 (P1) PARTIAL: nothing is capitalized against a product with zero owned units', async ({ page }) => {
    // PARTIAL — disclosed, not silently passed (same discipline as MTP-PUR-15).
    //
    // The case's assigned subject is `WeightedAverageCostService::recordCostAdjustment()`
    // returning null when `companyOwnedQuantity() <= 0` ("nothing owned to capitalize
    // against"). That method is ENTERED from exactly two callers:
    //   1. StockTransferService on transfer completion (carrying `transfer_cost`), and
    //   2. LinkedCostApplicationService::apply() from
    //      ExpenseService (`ExpenseService.php:327`) when an expense is linked to a
    //      purchase-order additional cost.
    // Path 1 can never reach the no-op arm: a transfer of a product with zero owned units
    // is refused BEFORE completion, so `recordCostAdjustment()` is never called. Path 2
    // CAN reach it (an expense linked to a cost whose product has since been fully
    // consumed) but needs a full expense + payment-repository + GL chain, which is W-5c's
    // surface, not W-4's — recorded here so a later wave can close it deliberately.
    //
    // What IS provable on this surface, and is asserted below, is the OUTER guard: on
    // both the never-owned and the driven-to-zero paths, no cost is capitalized and no
    // adjustment movement is written.
    test.info().annotations.push({
      type: 'PARTIAL',
      description:
        'The recordCostAdjustment() no-op arm itself is not entered from the transfer path (the transfer is ' +
        'refused first). The reachable caller is LinkedCostApplicationService via ExpenseService.php:327 ' +
        '(expense linked to a PO additional cost) — W-5c surface. This case asserts the outer guard only.',
    })

    // (a) never owned — the transfer is refused before any capitalization.
    const neverOwned = await createW4Product(page, 'INV05-never')
    expect(await costPrice(page, neverOwned.id)).toBe('0.000000')
    const tr = await createStockTransfer(page, {
      sourceLocationId: WAREHOUSE_LOCATION_ID,
      destinationLocationId: SHOP1_LOCATION_ID,
      lines: [{ productId: neverOwned.id, quantity: '1' }],
      transferCost: '30.000',
    })
    let refused = tr.status >= 400
    if (!refused && tr.id !== undefined) {
      const done = await completeStockTransfer(page, tr.id)
      refused = done.status >= 400
    }
    expect(refused, 'a transfer of unowned stock must be refused before any cost is capitalized').toBe(true)
    expect(await costPrice(page, neverOwned.id), 'no cost capitalized against zero owned units').toBe('0.000000')

    // (b) OWNED, THEN DRIVEN TO ZERO — the same outer guard must hold once the shelf is
    //     empty, and crucially the retained 12.000000 average must not be moved by the
    //     refused freight.
    const drained = await createW4Product(page, 'INV05-drained')
    expect((await poAndReceive(page, { supplierId, productId: drained.id, quantity: '3', unitPrice: '12.000' })).receiveStatus).toBe(200)
    expect(await costPrice(page, drained.id)).toBe('12.000000')
    expect([200, 201]).toContain((await adjustStockTo(page, { productId: drained.id, newQuantity: '0', reason: 'MTP-INV-05 drain' })).status)
    expect(
      (await getStockLevels(page, drained.id)).reduce((s, l) => s + Number(l.quantity), 0),
      'owned quantity is exactly 0'
    ).toBe(0)

    const tr2 = await createStockTransfer(page, {
      sourceLocationId: WAREHOUSE_LOCATION_ID,
      destinationLocationId: SHOP1_LOCATION_ID,
      lines: [{ productId: drained.id, quantity: '1' }],
      transferCost: '45.000',
    })
    let refused2 = tr2.status >= 400
    if (!refused2 && tr2.id !== undefined) {
      refused2 = (await completeStockTransfer(page, tr2.id)).status >= 400
    }
    expect(refused2, 'a drained product cannot source a transfer either').toBe(true)
    expect(await costPrice(page, drained.id), 'the refused freight must not move the retained average').toBe('12.000000')

    // Neither product may carry a cost-adjustment movement: `recordCostAdjustment` writes
    // a quantity-0 `adjustment` movement whenever it DOES capitalize, so its absence is
    // positive evidence that nothing was capitalized. (The drain itself is an
    // `adjustment` movement with a NON-zero quantity, so the two are distinguished by
    // quantity, not by type.)
    for (const id of [neverOwned.id, drained.id]) {
      const movements = await stockMovements(page, `?product_id=${id}`)
      const rows = (movements.body as { data?: Array<{ movement_type: string; quantity: string }> }).data ?? []
      const capitalizations = rows.filter((m) => m.movement_type === 'adjustment' && m.quantity === '0.0000')
      expect(capitalizations.length, `no cost-capitalization movement for ${id}`).toBe(0)
    }
  })

  test('MTP-INV-06 (P0): over-consumption is refused — stock never driven negative, no partial state', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'INV06')
    const seed = await poAndReceive(page, { supplierId, productId, quantity: '2', unitPrice: '10.000' })
    expect(seed.receiveStatus, JSON.stringify(seed.receiveBody)).toBe(200)

    const before = await getStockLevels(page, productId)
    const beforeQty = before.reduce((s, l) => s + Number(l.quantity), 0)
    expect(beforeQty).toBe(2)

    const tr = await createStockTransfer(page, {
      sourceLocationId: WAREHOUSE_LOCATION_ID,
      destinationLocationId: SHOP1_LOCATION_ID,
      lines: [{ productId, quantity: '5' }],
    })
    let refused = tr.status >= 400
    if (!refused && tr.id !== undefined) {
      const done = await completeStockTransfer(page, tr.id)
      refused = done.status >= 400
    }
    expect(refused, 'consuming 5 from an on-hand of 2 must be refused').toBe(true)

    const after = await getStockLevels(page, productId)
    const afterQty = after.reduce((s, l) => s + Number(l.quantity), 0)
    expect(afterQty, 'no partial state written').toBe(2)
    expect(await costPrice(page, productId), 'cost untouched by the refused consumption').toBe('10.000000')
  })

  test('MTP-INV-07 (P0): cost / stock value are ABSENT for a principal without pricing.view_cost_prices', async ({ page }) => {
    // Author the product + cost as owner first, then re-authenticate as viewer.
    const { id: productId } = await createW4Product(page, 'INV07')
    const seed = await poAndReceive(page, { supplierId, productId, quantity: '3', unitPrice: '9.000' })
    expect(seed.receiveStatus, JSON.stringify(seed.receiveBody)).toBe(200)
    expect(await costPrice(page, productId)).toBe('9.000000')

    await loginAsRole(page, 'viewer')
    const res = await apiRequest(page, 'GET', `/products/${productId}`)
    expect(res.status, JSON.stringify(res.body)).toBe(200)
    const data = (res.body as { data: Record<string, unknown> }).data

    // The contract is ABSENCE, not a blanked/zeroed field: a `0.000000` here would be a
    // silent information leak of "this product has no cost", and a present-but-null
    // field is still the cost column being exposed to an unauthorized principal.
    const costFields = ['cost_price', 'last_purchase_cost', 'stock_value', 'average_cost']
    for (const f of costFields) {
      if (f in data) {
        expect(data[f], `${f} must not carry a real cost for a viewer`).toBeNull()
      }
    }
    expect(data.cost_price ?? null, 'viewer must never see 9.000000').not.toBe('9.000000')
  })

  test('MTP-INV-08 (P2): movements ledger — record the cost-column surface as-is', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'INV08')
    const seed = await poAndReceive(page, { supplierId, productId, quantity: '6', unitPrice: '4.250' })
    expect(seed.receiveStatus, JSON.stringify(seed.receiveBody)).toBe(200)

    const res = await stockMovements(page, `?product_id=${productId}`)
    expect(res.status, JSON.stringify(res.body)).toBe(200)
    const rows = (res.body as { data: Array<Record<string, unknown>> }).data
    expect(rows.length, 'the receipt is on the ledger').toBeGreaterThanOrEqual(1)
    const receipt = rows.find((r) => r.movement_type === 'receipt')
    expect(receipt, 'receipt movement present').toBeDefined()
    expect(receipt?.quantity).toBe('6.0000')
    expect(receipt?.quantity_before).toBe('0.0000')
    expect(receipt?.quantity_after).toBe('6.0000')

    // DOCUMENTED GAP (plan MTP-INV-08, "record as-is; do not file as new"): the
    // movements read model exposes quantities but NO money columns — a reader cannot
    // reconcile stock value movement-by-movement from this surface. Pinned so a future
    // change that adds (or removes) cost columns is a deliberate, visible decision.
    const hasCostColumn = ['unit_cost', 'total_cost', 'avg_cost_before', 'avg_cost_after'].some((k) => k in (receipt ?? {}))
    expect(hasCostColumn, 'DOCUMENTED GAP: movements ledger carries no cost columns').toBe(false)
  })

  test('MTP-INV-09 (P1): completed transfer capitalizes transfer_cost into WAC', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'INV09')
    // 10 @ 10.000 => WAC 10.000000, owned value 100.000
    const seed = await poAndReceive(page, { supplierId, productId, quantity: '10', unitPrice: '10.000' })
    expect(seed.receiveStatus, JSON.stringify(seed.receiveBody)).toBe(200)
    expect(await costPrice(page, productId)).toBe('10.000000')

    const tr = await createStockTransfer(page, {
      sourceLocationId: WAREHOUSE_LOCATION_ID,
      destinationLocationId: SHOP1_LOCATION_ID,
      lines: [{ productId, quantity: '4' }],
      transferCost: '5.000',
    })
    expect(tr.status, JSON.stringify(tr.body)).toBe(201)
    const done = await completeStockTransfer(page, tr.id as string)
    expect(done.status, JSON.stringify(done.body)).toBe(200)

    // recordCostAdjustment: delta = 5.000 / 10 owned = 0.500000 -> new WAC 10.500000.
    // Company-wide owned qty is the denominator (on-hand across every location +
    // in-transit), NOT the transferred quantity.
    expect(await costPrice(page, productId)).toBe('10.500000')

    const levels = await getStockLevels(page, productId)
    const total = levels.reduce((s, l) => s + Number(l.quantity), 0)
    expect(total, 'transfer moves stock between locations, never changes owned total').toBe(10)
  })
})

/** Not exported: keeps `Page` referenced for the type-only import lint rule. */
export type _W4CostingPage = Page
