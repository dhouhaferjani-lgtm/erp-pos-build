/**
 * MONEY TEST CAMPAIGN — wave W-4 — §C `PUR` residuals:
 * bonus / free goods (MTP-PUR-14..16) and additional costs -> landed cost
 * (MTP-PUR-17..22).
 *
 * Live local stack, tenant demo-pharmacy-tn, real login, real backend.
 * Own supplier + a dedicated product per case (`W4-…`); never touches DEMO-PO-000x
 * or a seeded pharmacy product whose WAC another wave reads.
 *
 * KEY MECHANIC (discovered while authoring; not in the plan text): landed costs are
 * allocated at PO **confirm** (PurchaseOrderService::confirmAndAllocateCosts ->
 * LandedCostService::allocateCostsAndTaxes) and RE-allocated at goods receipt
 * (GoodsReceiptService -> reallocateCosts). Posting an additional cost AFTER confirm
 * leaves `document_lines.allocated_costs` at 0.000000 until something re-allocates.
 * Every case here therefore adds the cost BEFORE confirm.
 *
 * EVIDENCE SOURCE: `GET /documents/{id}/landed-cost-breakdown` recomputes from scratch
 * in FLOAT and returns JSON numbers (20, 12, 0.6666666666666666), so it cannot carry an
 * exact-decimal verdict. The persisted bcmath columns
 * (`document_lines.allocated_costs` / `landed_unit_cost`) are read directly per plan §3
 * ("the relevant DB row" is admissible evidence for fiscal cases).
 */
import { test, expect } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import { createSupplier, createSupplierInvoice, getSupplierInvoice, getPurchaseOrder, WAREHOUSE_LOCATION_ID } from './w2b-support'
import {
  createW4Product,
  costPrice,
  poAndReceive,
  uniq4,
  today,
  persistedLandedCosts,
  sumMoney,
} from './w4-support'

test.describe.configure({ timeout: 180_000 })

let supplierId: string

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage()
  await loginAsRole(page, 'owner')
  supplierId = await createSupplier(page, uniq4('PUR-Supplier'))
  await page.close()
})

test.describe('PUR — bonus / free goods (14..16)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-PUR-14 (P0): paid 10 @ 10.000 + free 2 => WAC 100/12 = 8.333333 (TRUNCATED)', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'PUR14')
    const r = await poAndReceive(page, {
      supplierId,
      productId,
      quantity: '10',
      unitPrice: '10.000',
      freeQuantity: '2',
    })
    expect(r.confirmStatus, 'TN is in procurement.bonus_quantity_countries so the free_quantity field is accepted').toBe(200)
    expect(r.receiveStatus, JSON.stringify(r.receiveBody)).toBe(200)

    // 100.000 of value spread over 12 owned units. 100/12 = 8.3333333... -> 8.333333
    // (bcformat truncates; 8.333334 would be a precision-contract violation).
    expect(await costPrice(page, productId)).toBe('8.333333')
  })

  test('MTP-PUR-15 (P1): bonus gate — the allowlist is the enforcement point (TN is IN it here)', async ({ page }) => {
    // The gate is PurchaseBonusGate, driven by config('procurement.bonus_quantity_countries')
    // = ['TN'] and the COMPANY country. demo-pharmacy-tn is a TN company, so this campaign
    // tenant can never exercise the REFUSAL arm — flipping app config would be a
    // product-code/config change (forbidden by the wave's hard rules) and would corrupt
    // sibling waves. What IS provable here is that the gate is wired to the request layer:
    // when the gate is OPEN the bonus fields validate, and the enforcement is a
    // `prohibited` validation rule (CreateDocumentRequest:118-131), not a silent drop.
    const { id: productId } = await createW4Product(page, 'PUR15')
    const ok = await apiRequest(page, 'POST', '/purchase-orders', {
      partner_id: supplierId,
      document_date: today(),
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [
        { product_id: productId, description: 'bonus probe', quantity: '5', unit_price: '3.000', tax_rate: '19.00', free_quantity: '1', is_bonus_line: false },
      ],
    })
    expect(ok.status, 'gate OPEN for TN: free_quantity accepted').toBe(201)
    const po = await getPurchaseOrder(page, (ok.body as { data: { id: string } }).data.id)
    const lines = po.lines as Array<{ free_quantity: string; quantity: string }>
    expect(lines[0].quantity).toBe('5.0000')
    expect(lines[0].free_quantity, 'free qty persisted at the canonical 4-dp quantity scale').toBe('1.0000')

    test.info().annotations.push({
      type: 'PARTIAL',
      description:
        'The REFUSAL arm (a company whose country is NOT in procurement.bonus_quantity_countries) ' +
        'is not exercisable on demo-pharmacy-tn: the allowlist is app config and the tenant has a ' +
        'single TN company. Needs a non-TN company fixture (campaign debt C-9 demo-garage FR) to ' +
        'close. Recorded as PARTIAL, not PASS.',
    })
  })

  test('MTP-PUR-16 (P1) TRIPWIRE: `is_bonus_line` is DROPPED at the supplier-invoice HTTP boundary', async ({ page }) => {
    // DEFECT (P1) — docs/superpowers/tickets/2026-08-03-w4-purchasing-inventory-defects.md #2.
    // CreateSupplierInvoiceRequest's `rules()` never declares `lines.*.is_bonus_line`
    // (CreateSupplierInvoiceRequest.php:110-139), and SupplierInvoiceController::store()
    // passes `$request->validated()` (line 215/228) to CreateSupplierInvoiceService, whose
    // line 115 then reads `$lineInput['is_bonus_line'] ?? false`. Laravel strips every
    // undeclared key from validated(), so the flag can NEVER be true on the documented
    // creation path. Consequence: the matcher's whole bonus arm
    // (SupplierInvoiceMatcher::buildQtyGroupStatuses() bonus branch, ~L295-445, and
    // ReceiptPlanner::freeMatchableQty()) is unreachable from the API, and free units
    // billed on an invoice are counted against the PAID matchable window instead —
    // turning a legitimate bonus invoice into `quantity_variance`, which is a HARD block
    // at post under BOTH match_enforcement modes. i.e. a bonus receipt cannot be invoiced
    // at all through the API today.
    //
    // The plan's intended assertion ("bonus line matched against free_matchable_qty only;
    // no price check") is therefore NOT reachable. This test pins TODAY's behaviour so the
    // fix flips it red.
    const { id: productId } = await createW4Product(page, 'PUR16')
    const r = await poAndReceive(page, {
      supplierId,
      productId,
      quantity: '10',
      unitPrice: '10.000',
      freeQuantity: '2',
    })
    expect(r.receiveStatus, JSON.stringify(r.receiveBody)).toBe(200)

    const inv = await createSupplierInvoice(page, {
      partnerId: supplierId,
      sourceDocumentIds: [r.poId],
      lines: [
        { sourceLineId: r.lineId, quantity: '10', unitPrice: '10.000' },
        // 2 free units billed at a NON-ZERO price, explicitly flagged as a bonus line.
        { sourceLineId: r.lineId, quantity: '2', unitPrice: '10.000', isBonusLine: true },
      ],
    })
    expect(inv.status, JSON.stringify(inv.body)).toBe(201)
    const detail = await getSupplierInvoice(page, inv.id as string)
    const perLine = (detail.match as { per_line: Array<{ price_variance: boolean; matchable: string }> }).per_line

    // The price check itself is clean on both lines (the invoiced price equals the PO price).
    expect(perLine.every((l) => l.price_variance === false)).toBe(true)

    // TRIPWIRE: both lines are matched against the SAME paid window of 10.0000 — the
    // bonus line got no free window of its own, proving the flag was dropped.
    expect(perLine.map((l) => l.matchable)).toEqual(['10.0000', '10.0000'])
    expect(
      detail.match_status,
      'TRIPWIRE: expected `matched` per plan; the dropped bonus flag makes 12 invoiced against a 10 window'
    ).toBe('quantity_variance')

    // And the consequence that actually costs money: the invoice cannot be posted.
    const post = await apiRequest(page, 'POST', `/supplier-invoices/${inv.id as string}/post`)
    expect(post.status, 'TRIPWIRE: quantity_variance is a HARD block — a bonus receipt is un-invoiceable').toBe(422)
  })
})

test.describe('PUR — additional costs -> landed cost (17..22)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-PUR-17 (P0): freight 30.000 over lines 100.000 / 50.000 — Sigma is exact; the SPLIT drifts 1 millime', async ({ page }) => {
    const a = await createW4Product(page, 'PUR17a')
    const b = await createW4Product(page, 'PUR17b')
    const poRes = await apiRequest(page, 'POST', '/purchase-orders', {
      partner_id: supplierId,
      document_date: today(),
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [
        { product_id: a.id, description: 'L1', quantity: '10', unit_price: '10.000', tax_rate: '19.00' },
        { product_id: b.id, description: 'L2', quantity: '5', unit_price: '10.000', tax_rate: '19.00' },
      ],
    })
    expect(poRes.status, JSON.stringify(poRes.body)).toBe(201)
    const poId = (poRes.body as { data: { id: string; subtotal: string } }).data.id
    expect((poRes.body as { data: { subtotal: string } }).data.subtotal).toBe('150.000')

    const add = await apiRequest(page, 'POST', `/documents/${poId}/additional-costs`, {
      cost_type: 'shipping',
      description: 'W4 freight',
      amount: '30.000',
    })
    expect(add.status, JSON.stringify(add.body)).toBe(201)
    const confirm = await apiRequest(page, 'POST', `/purchase-orders/${poId}/confirm`)
    expect(confirm.status, JSON.stringify(confirm.body)).toBe(200)

    const rows = persistedLandedCosts(poId)
    expect(rows.length).toBe(2)

    // The plan's non-negotiable invariant HOLDS: the allocated shares sum to the pool
    // exactly. No 29.999, no 30.001.
    expect(sumMoney([rows[0].allocatedCosts, rows[1].allocatedCosts])).toBe('30.000')

    // FINDING (P1) — docs/superpowers/tickets/2026-08-03-w4-landed-cost-proportion-truncation.md
    // The plan expects the exact proportional split L1 = 30.000 x 100/150 = 20.000 and
    // L2 = 10.000, with L1 landed unit cost 12.000000. The system produces 19.999 /
    // 10.001 (and 11.999900 / 12.000200) because ProportionalMoneyAllocator computes
    // `proportion = base / subtotal` TRUNCATED to the working scale FIRST
    // (0.6666666 at scale 7) and only then multiplies by the total
    // (30.000 x 0.6666666 = 19.999998 -> 19.999); the absorber line silently swallows
    // the +0.001 residue. One-step `total x base / subtotal` would give exactly 20.000.
    // TRIPWIRE: these are today's values. When the allocator is fixed this test goes red
    // and must be updated to the plan's 20.000 / 10.000 / 12.000000.
    expect(rows[0].allocatedCosts, 'TRIPWIRE: expected 20.000000 per plan; truncated proportion yields 19.999').toBe('19.999000')
    expect(rows[1].allocatedCosts, 'TRIPWIRE: absorber swallows the +0.001 residue').toBe('10.001000')
    expect(rows[0].landedUnitCost, 'TRIPWIRE: expected 12.000000 per plan').toBe('11.999900')
    expect(rows[1].landedUnitCost).toBe('12.000200')
  })

  test('MTP-PUR-18 (P0): 3 equal lines, pool 10.000 — shares 3.333/3.333/3.334, sum EXACTLY 10.000', async ({ page }) => {
    const p1 = await createW4Product(page, 'PUR18a')
    const p2 = await createW4Product(page, 'PUR18b')
    const p3 = await createW4Product(page, 'PUR18c')
    const poRes = await apiRequest(page, 'POST', '/purchase-orders', {
      partner_id: supplierId,
      document_date: today(),
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [
        { product_id: p1.id, description: 'L1', quantity: '10', unit_price: '10.000', tax_rate: '19.00' },
        { product_id: p2.id, description: 'L2', quantity: '10', unit_price: '10.000', tax_rate: '19.00' },
        { product_id: p3.id, description: 'L3', quantity: '10', unit_price: '10.000', tax_rate: '19.00' },
      ],
    })
    expect(poRes.status, JSON.stringify(poRes.body)).toBe(201)
    const poId = (poRes.body as { data: { id: string } }).data.id

    const add = await apiRequest(page, 'POST', `/documents/${poId}/additional-costs`, {
      cost_type: 'shipping',
      description: 'W4 freight',
      amount: '10.000',
    })
    expect(add.status, JSON.stringify(add.body)).toBe(201)
    expect((await apiRequest(page, 'POST', `/purchase-orders/${poId}/confirm`)).status).toBe(200)

    const rows = persistedLandedCosts(poId)
    expect(rows.map((r) => r.allocatedCosts)).toEqual(['3.333000', '3.333000', '3.334000'])
    // The whole point of the largest-remainder reconciliation: no 9.999, no 10.001.
    expect(sumMoney(rows.map((r) => r.allocatedCosts))).toBe('10.000')
  })

  test('MTP-PUR-19 (P0): additional costs cannot be re-allocated after goods are fully received', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'PUR19')
    const poRes = await apiRequest(page, 'POST', '/purchase-orders', {
      partner_id: supplierId,
      document_date: today(),
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [{ product_id: productId, description: 'L1', quantity: '10', unit_price: '10.000', tax_rate: '19.00' }],
    })
    const poId = (poRes.body as { data: { id: string } }).data.id
    const add = await apiRequest(page, 'POST', `/documents/${poId}/additional-costs`, {
      cost_type: 'shipping',
      description: 'W4 freight',
      amount: '20.000',
    })
    expect(add.status).toBe(201)
    const costId = (add.body as { data: { id: string } }).data.id
    expect((await apiRequest(page, 'POST', `/purchase-orders/${poId}/confirm`)).status).toBe(200)

    const po = await getPurchaseOrder(page, poId)
    const lineId = (po.lines as Array<{ id: string }>)[0].id
    const rec = await apiRequest(page, 'POST', `/purchase-orders/${poId}/receive`, { quantities: { [lineId]: '10' } })
    expect(rec.status, JSON.stringify(rec.body)).toBe(200)

    const before = persistedLandedCosts(poId)[0]

    // LandedCostService::canModifyCosts() returns false once payload.goods_received_at
    // is set (fully received). A post-receipt reallocation would silently restate an
    // already-capitalized WAC, so it must be refused.
    const edit = await apiRequest(page, 'PATCH', `/documents/${poId}/additional-costs/${costId}`, { amount: '50.000' })
    const del = edit.status < 400 ? null : await apiRequest(page, 'DELETE', `/documents/${poId}/additional-costs/${costId}`)

    const after = persistedLandedCosts(poId)[0]
    expect(after.allocatedCosts, 'allocated costs are frozen after receipt').toBe(before.allocatedCosts)
    expect(after.landedUnitCost, 'landed unit cost is frozen after receipt').toBe(before.landedUnitCost)
    expect(
      edit.status >= 400 || (del !== null && del.status >= 400) || after.allocatedCosts === before.allocatedCosts,
      `post-receipt cost mutation must not change the allocation (edit=${edit.status})`
    ).toBe(true)
  })

  test('MTP-PUR-20 (P1): cost modified BEFORE the receipt — reallocation reflects the new pool, Sigma still exact', async ({ page }) => {
    const a = await createW4Product(page, 'PUR20a')
    const b = await createW4Product(page, 'PUR20b')
    const poRes = await apiRequest(page, 'POST', '/purchase-orders', {
      partner_id: supplierId,
      document_date: today(),
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [
        { product_id: a.id, description: 'L1', quantity: '10', unit_price: '10.000', tax_rate: '19.00' },
        { product_id: b.id, description: 'L2', quantity: '10', unit_price: '10.000', tax_rate: '19.00' },
      ],
    })
    const poId = (poRes.body as { data: { id: string } }).data.id
    const add = await apiRequest(page, 'POST', `/documents/${poId}/additional-costs`, {
      cost_type: 'shipping',
      description: 'W4 freight',
      amount: '10.000',
    })
    expect(add.status).toBe(201)
    const costId = (add.body as { data: { id: string } }).data.id
    expect((await apiRequest(page, 'POST', `/purchase-orders/${poId}/confirm`)).status).toBe(200)
    expect(sumMoney(persistedLandedCosts(poId).map((r) => r.allocatedCosts))).toBe('10.000')

    const patch = await apiRequest(page, 'PATCH', `/documents/${poId}/additional-costs/${costId}`, { amount: '24.000' })
    expect(patch.status, JSON.stringify(patch.body)).toBeLessThan(400)

    const po = await getPurchaseOrder(page, poId)
    const lines = po.lines as Array<{ id: string }>
    const rec = await apiRequest(page, 'POST', `/purchase-orders/${poId}/receive`, {
      quantities: { [lines[0].id]: '10', [lines[1].id]: '10' },
    })
    expect(rec.status, JSON.stringify(rec.body)).toBe(200)

    // reallocateCosts() re-runs on the MODIFIED pool: 24.000 across two equal lines.
    const rows = persistedLandedCosts(poId)
    expect(sumMoney(rows.map((r) => r.allocatedCosts)), 'Sigma allocated == the modified pool exactly').toBe('24.000')
    // Two EQUAL bases: proportion = 100/200 = 0.5 is exactly representable at the working
    // scale, so no truncation residue arises and the split is exact. (Contrast MTP-PUR-17,
    // where 100/150 is NOT exactly representable and the truncated-proportion bug bites.)
    expect(rows.map((r) => r.allocatedCosts)).toEqual(['12.000000', '12.000000'])
  })

  test('MTP-PUR-21 (P1): principal WITHOUT goods-receipt.edit-price cannot send received_unit_prices', async ({ page }) => {
    // Author the PO as owner, then attempt the override as a principal that lacks the
    // permission. ReceiveGoodsRequest makes the field `prohibited` for such a user
    // (ReceiveGoodsRequest.php:34) and GoodsReceiptService:345 re-checks server-side —
    // a two-layer denial, which is exactly what plan §I.4 requires.
    const { id: productId } = await createW4Product(page, 'PUR21')
    const setup = await poAndReceive(page, { supplierId, productId, quantity: '5', unitPrice: '12.500', skipReceive: true })
    expect(setup.confirmStatus).toBe(200)

    await loginAsRole(page, 'accountant')
    const res = await apiRequest(page, 'POST', `/purchase-orders/${setup.poId}/receive`, {
      quantities: { [setup.lineId]: '5' },
      received_unit_prices: { [setup.lineId]: '13.000' },
      price_override_reason: 'unauthorized attempt',
    })
    expect(res.status, `denial expected, got ${res.status} ${JSON.stringify(res.body)}`).toBeGreaterThanOrEqual(400)

    await loginAsRole(page, 'owner')
    expect(await costPrice(page, productId), 'no cost written by the refused receipt').toBe('0.000000')
  })

  test('MTP-PUR-22 (P1): authorized override 12.500 -> 13.000 persists the audit trio and WAC blends on 13.000', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'PUR22')
    const r = await poAndReceive(page, {
      supplierId,
      productId,
      quantity: '4',
      unitPrice: '12.500',
      receivedUnitPrice: '13.000',
      priceOverrideReason: 'supplier price list updated after PO',
    })
    expect(r.receiveStatus, JSON.stringify(r.receiveBody)).toBe(200)

    // WAC blends on the OVERRIDDEN price, not the PO price.
    expect(await costPrice(page, productId), 'blend uses 13.000, not 12.500').toBe('13.000000')

    const audit = (await apiRequest(page, 'GET', `/goods-receipts?purchase_order_id=${r.poId}`)).body as {
      data: Array<{ id: string }>
    }
    expect(audit.data.length, 'receipt row exists').toBeGreaterThanOrEqual(1)
    const receipt = await apiRequest(page, 'GET', `/goods-receipts/${audit.data[0].id}`)
    const rLines = (receipt.body as { data: { lines: Array<Record<string, unknown>> } }).data.lines
    const line = rLines[0]
    expect(line.price_override_by, 'audit trio: who').not.toBeNull()
    expect(line.price_override_at, 'audit trio: when').not.toBeNull()
    expect(line.price_override_old_basis, 'audit trio: what it replaced').toBe('12.500000')
    expect(line.received_unit_price ?? line.unit_price).toBe('13.000')
  })
})
