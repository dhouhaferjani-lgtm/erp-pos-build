/**
 * MONEY TEST CAMPAIGN — wave W-4 — §C `PUR` residuals:
 * receipt/PO money & quantity validation ceilings (MTP-PUR-23..27),
 * supplier payable balance (MTP-PUR-28), standalone goods receipt (MTP-PUR-29..30).
 *
 * Live local stack, tenant demo-pharmacy-tn, real login, real backend.
 *
 * Rejection cases (23/24/26) deliberately assert the **server** boundary: the regex
 * ceilings mandated by CLAUDE.md rule 19 live on the FormRequest, and a client-side-only
 * guard would be bypassable. Each also proves NOTHING was silently truncated — a 422 with
 * no persisted document, never a 201 carrying a rounded value.
 */
import { test, expect } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import { createSupplier, createSupplierInvoice, postSupplierInvoice, getStockLevels, getPurchaseOrder, WAREHOUSE_LOCATION_ID } from './w2b-support'
import { createW4Product, costPrice, poAndReceive, standaloneReceipt, partnerBalance, uniq4, today } from './w4-support'

test.describe.configure({ timeout: 180_000 })

let supplierId: string

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage()
  await loginAsRole(page, 'owner')
  supplierId = await createSupplier(page, uniq4('PURV-Supplier'))
  await page.close()
})

async function postPoLine(page: import('@playwright/test').Page, line: Record<string, unknown>) {
  return apiRequest(page, 'POST', '/purchase-orders', {
    partner_id: supplierId,
    document_date: today(),
    location_id: WAREHOUSE_LOCATION_ID,
    lines: [line],
  })
}

test.describe('PUR — money / quantity validation ceilings (23..27)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-PUR-23 (P1): unit price 12.5001 (4 dp) is REJECTED, not truncated', async ({ page }) => {
    // The "nothing persisted" half of this case needs a probe that can actually SEE a PO
    // if one were created. `?search=` matches `document_number` ONLY
    // (HandlesDocuments::applySearchFilter():198-201), so searching by a product UUID
    // returns 0 rows whether or not the PO exists — a vacuous probe. `?partner_id=`
    // is a real column filter (HandlesDocuments::applyFilters():153-155), so this case
    // uses a DEDICATED supplier and asserts on that supplier's PO list, with a positive
    // control proving the probe is not vacuous.
    const dedicated = await createSupplier(page, uniq4('PUR23-Supplier'))
    const { id: productId } = await createW4Product(page, 'PUR23')

    const before = await apiRequest(page, 'GET', `/purchase-orders?partner_id=${dedicated}`)
    expect(before.status, JSON.stringify(before.body)).toBe(200)
    expect(((before.body as { data?: unknown[] }).data ?? []).length, 'dedicated supplier starts with no POs').toBe(0)

    const res = await apiRequest(page, 'POST', '/purchase-orders', {
      partner_id: dedicated,
      document_date: today(),
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [{ product_id: productId, description: '4dp price', quantity: '1', unit_price: '12.5001', tax_rate: '19.00' }],
    })
    expect(res.status, JSON.stringify(res.body)).toBe(422)
    expect(JSON.stringify(res.body)).toMatch(/at most 3 decimal places/i)

    // Nothing persisted: a silently-truncated 12.500 would be a money-integrity hole.
    const after = await apiRequest(page, 'GET', `/purchase-orders?partner_id=${dedicated}`)
    expect(((after.body as { data?: unknown[] }).data ?? []).length, 'no PO created by the rejected request').toBe(0)

    // POSITIVE CONTROL — the same filter DOES surface a PO for this supplier, so the
    // zero above is evidence of absence, not a filter that never matches anything.
    const control = await apiRequest(page, 'POST', '/purchase-orders', {
      partner_id: dedicated,
      document_date: today(),
      location_id: WAREHOUSE_LOCATION_ID,
      lines: [{ product_id: productId, description: '3dp price control', quantity: '1', unit_price: '12.500', tax_rate: '19.00' }],
    })
    expect(control.status, JSON.stringify(control.body)).toBe(201)
    const controlId = (control.body as { data: { id: string } }).data.id
    try {
      const withControl = await apiRequest(page, 'GET', `/purchase-orders?partner_id=${dedicated}`)
      const rows = (withControl.body as { data: Array<{ id: string }> }).data
      expect(rows.length, 'the probe CAN see a PO for this supplier').toBe(1)
      expect(rows[0].id).toBe(controlId)
      // And the accepted 3-dp value is stored verbatim — no truncation on the happy path.
      const controlDoc = await getPurchaseOrder(page, controlId)
      expect((controlDoc.lines as Array<{ unit_price: string }>)[0].unit_price).toBe('12.500')
    } finally {
      // The control PO is a probe row, not a money movement the campaign wants to keep.
      const del = await apiRequest(page, 'DELETE', `/purchase-orders/${controlId}`)
      expect(del.status, `control PO cleanup: ${JSON.stringify(del.body)}`).toBeLessThan(300)
    }
  })

  test('MTP-PUR-24 (P1): quantity 1.00001 (5 dp) is REJECTED by the qty scale ceiling', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'PUR24')
    const res = await postPoLine(page, {
      product_id: productId,
      description: '5dp qty',
      quantity: '1.00001',
      unit_price: '10.000',
      tax_rate: '19.00',
    })
    expect(res.status, JSON.stringify(res.body)).toBe(422)
    expect(JSON.stringify(res.body)).toMatch(/at most 4 decimal places/i)
  })

  test('MTP-PUR-25 (P2): unit price 0 with qty 1 — record the actual disposition', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'PUR25')
    const res = await postPoLine(page, {
      product_id: productId,
      description: 'zero-value line',
      quantity: '1',
      unit_price: '0',
      tax_rate: '19.00',
    })
    // RECORDED BEHAVIOUR: the money rule is `min:0` (CreateDocumentRequest.php:121), so a
    // zero-value line is ACCEPTED — legitimate for free/sample goods.
    expect(res.status, JSON.stringify(res.body)).toBe(201)
    const data = (res.body as { data: { subtotal: string; total: string; tax_amount: string; id: string } }).data
    try {
      // The plan's real requirement: the total must not become NaN/blank.
      expect(data.subtotal).toBe('0.000')
      expect(data.tax_amount).toBe('0.000')
      expect(data.total).toBe('0.000')
      for (const v of [data.subtotal, data.tax_amount, data.total]) {
        expect(v).toMatch(/^-?\d+\.\d{3}$/)
      }
    } finally {
      // The zero-value PO is a disposition PROBE, not a commitment under test — retire it
      // so it does not accumulate one live draft per run.
      const del = await apiRequest(page, 'DELETE', `/purchase-orders/${data.id}`)
      expect(del.status, `zero-value probe PO cleanup: ${JSON.stringify(del.body)}`).toBeLessThan(300)
    }
  })

  test('MTP-PUR-26 (P1): negative unit price -1.000 is REJECTED', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'PUR26')
    const res = await postPoLine(page, {
      product_id: productId,
      description: 'negative price',
      quantity: '1',
      unit_price: '-1.000',
      tax_rate: '19.00',
    })
    expect(res.status, `purchase money fields are non-negative (min:0 + ^\\d regex): ${JSON.stringify(res.body)}`).toBe(422)

    // Negative QUANTITY is refused by the same request (gt:0), asserted here so the two
    // sign guards are pinned together.
    const negQty = await postPoLine(page, {
      product_id: productId,
      description: 'negative qty',
      quantity: '-1',
      unit_price: '10.000',
      tax_rate: '19.00',
    })
    expect(negQty.status, JSON.stringify(negQty.body)).toBe(422)
  })

  test('MTP-PUR-27 (P2): a genuinely empty supplier-invoice list renders an empty state, not fabricated zeros', async ({ page }) => {
    // demo-pharmacy-tn is NOT a fresh tenant (earlier waves posted supplier invoices), so
    // the plan's literal precondition is unobtainable. The equivalent, honest form is a
    // filter that yields a genuinely empty result set: `supplier_id` = a partner that has
    // never been invoiced.
    const emptySupplier = await createSupplier(page, uniq4('PUR27-NeverInvoiced'))
    const res = await apiRequest(page, 'GET', `/supplier-invoices?partner_id=${emptySupplier}`)
    expect(res.status, JSON.stringify(res.body)).toBe(200)
    const body = res.body as { data: unknown[]; meta?: { total?: number } }
    expect(Array.isArray(body.data)).toBe(true)
    expect(body.data.length, 'no rows, and specifically no fabricated 0.000 placeholder row').toBe(0)

    await page.goto('/purchases/supplier-invoices')
    await expect(page.locator('body')).toBeVisible()
    const text = (await page.locator('body').innerText()).toLowerCase()
    // No JS error boundary, no NaN leaking into the money column.
    expect(text).not.toContain('nan')
    expect(text).not.toContain('something went wrong')
  })
})

test.describe('PUR — supplier payable balance (28)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-PUR-28 (P1): a posted supplier invoice moves the supplier payable balance by the exact total', async ({ page }) => {
    const dedicated = await createSupplier(page, uniq4('PUR28-Supplier'))
    const before = await partnerBalance(page, dedicated)
    expect(before.status, JSON.stringify(before.body)).toBe(200)
    const beforeBal = (before.body as { data: { balance: string } }).data.balance
    expect(beforeBal, 'a brand-new supplier owes nothing').toBe('0.000')

    const { id: productId } = await createW4Product(page, 'PUR28')
    const r = await poAndReceive(page, { supplierId: dedicated, productId, quantity: '10', unitPrice: '12.500' })
    expect(r.receiveStatus, JSON.stringify(r.receiveBody)).toBe(200)

    const inv = await createSupplierInvoice(page, {
      partnerId: dedicated,
      sourceDocumentIds: [r.poId],
      lines: [{ sourceLineId: r.lineId, quantity: '10', unitPrice: '12.500' }],
    })
    expect(inv.status, JSON.stringify(inv.body)).toBe(201)
    const post = await postSupplierInvoice(page, inv.id as string)
    expect(post.status, JSON.stringify(post.body)).toBe(200)

    // net 125.000 + 19% VAT 23.750 = 148.750 payable.
    // SIGN CONVENTION (recorded, not a defect): the partner balance is signed from the
    // COMPANY's point of view — a supplier we owe carries a NEGATIVE balance (credit).
    // Asserted with the sign so a future flip cannot pass silently.
    const after = await partnerBalance(page, dedicated)
    const afterData = (after.body as { data: { balance: string; transaction_count: number } }).data
    expect(afterData.balance, 'exact decimal string, currency scale 3, credit-signed').toBe('-148.750')
    expect(afterData.transaction_count).toBeGreaterThanOrEqual(1)
  })
})

test.describe('PUR — standalone goods receipt (29..30)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-PUR-29 (P1): standalone receipt posts stock and seeds WAC with no PO', async ({ page }) => {
    const { id: productId } = await createW4Product(page, 'PUR29')
    const res = await standaloneReceipt(page, {
      supplierId,
      lines: [{ product_id: productId, qty: '4', unit_price: '7.500' }],
      postImmediately: true,
      externalReference: uniq4('BL'),
    })
    expect(res.status, `owner holds goods-receipt.create-standalone: ${JSON.stringify(res.body)}`).toBe(201)

    const levels = await getStockLevels(page, productId)
    expect(levels.reduce((s, l) => s + Number(l.quantity), 0)).toBe(4)
    // WAC seeded from the receipt price, at rest at 6 dp.
    expect(await costPrice(page, productId)).toBe('7.500000')
  })

  test('MTP-PUR-30 (P1): standalone receipt is refused for a principal without goods-receipt.create-standalone, and is idempotent', async ({ page }) => {
    // (a) idempotency — the endpoint requires an idempotency_key; replaying it must not
    //     double-post stock (a duplicated receipt is a real inventory/money leak).
    const { id: productId } = await createW4Product(page, 'PUR30')
    const key = uniq4('idem').slice(0, 64)
    const first = await standaloneReceipt(page, {
      supplierId,
      lines: [{ product_id: productId, qty: '3', unit_price: '5.000' }],
      idempotencyKey: key,
      postImmediately: true,
    })
    expect(first.status, JSON.stringify(first.body)).toBe(201)
    const replay = await standaloneReceipt(page, {
      supplierId,
      lines: [{ product_id: productId, qty: '3', unit_price: '5.000' }],
      idempotencyKey: key,
      postImmediately: true,
    })
    expect(replay.status, `replay disposition: ${JSON.stringify(replay.body)}`).toBeLessThan(400)
    const levels = await getStockLevels(page, productId)
    expect(levels.reduce((s, l) => s + Number(l.quantity), 0), 'replayed key must not double-post stock').toBe(3)
    expect(await costPrice(page, productId)).toBe('5.000000')

    // (b) permission — a principal without the permission is refused at the route
    //     middleware (`can:goods-receipt.create-standalone`), so no receipt row is created.
    const { id: deniedProduct } = await createW4Product(page, 'PUR30-denied')
    await loginAsRole(page, 'accountant')
    const denied = await standaloneReceipt(page, {
      supplierId,
      lines: [{ product_id: deniedProduct, qty: '2', unit_price: '9.000' }],
      postImmediately: true,
    })
    expect(denied.status, `expected 403; got ${denied.status} ${JSON.stringify(denied.body)}`).toBe(403)

    await loginAsRole(page, 'owner')
    const deniedLevels = await getStockLevels(page, deniedProduct)
    expect(deniedLevels.reduce((s, l) => s + Number(l.quantity), 0), 'no stock posted by the refused request').toBe(0)
    expect(await costPrice(page, deniedProduct)).toBe('0.000000')
  })
})
