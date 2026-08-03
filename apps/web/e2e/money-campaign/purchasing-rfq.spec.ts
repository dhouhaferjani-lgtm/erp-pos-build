/**
 * MONEY TEST CAMPAIGN — wave W-4 — NEW SURFACE `RFQ`: purchase quote requests.
 * Cases MTP-RFQ-01..06 (`/purchases/quote-requests*`, API `/purchase-quote-requests*`).
 *
 * No definition exists in `2026-08-01-money-test-plan.md`; these are DERIVED from §B.2
 * flow 18 ("RFQ -> quote-request comparison -> PO") and the real route surface.
 *
 * Live local stack, tenant demo-pharmacy-tn, real login, real backend. Own suppliers and
 * own products per case.
 */
import { test, expect } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import { createSupplier, getPurchaseOrder } from './w2b-support'
import { createW4Product, createRfq, getRfq, getRfqGroup, quoteRfq, sendRfq, awardRfq, reopenRfqGroup, uniq4, daysFromToday, subMoney } from './w4-support'

test.describe.configure({ timeout: 180_000 })

interface RfqSibling {
  id: string
  number: string
  group_id: string
  partner: { id: string; name: string }
  status: string
  currency: string
  total: string
  supplier_reference: string | null
  lead_time_days: number | null
  closed_reason: string | null
  lines: Array<{ product_id: string; quantity: string; unit_price: string }>
}

/** Two suppliers + one product + an RFQ addressed to both. */
async function buildRfq(page: import('@playwright/test').Page, base: string) {
  const supplierA = await createSupplier(page, uniq4(`${base}-A`))
  const supplierB = await createSupplier(page, uniq4(`${base}-B`))
  const product = await createW4Product(page, base)
  const res = await createRfq(page, {
    partnerIds: [supplierA, supplierB],
    lines: [{ product_id: product.id, quantity: '10', description: `${base} RFQ line` }],
    validityDate: daysFromToday(30),
  })
  expect(res.status, JSON.stringify(res.body)).toBe(201)
  const data = (res.body as { data: { group_id: string; siblings: RfqSibling[] } }).data
  return { supplierA, supplierB, product, groupId: data.group_id, siblings: data.siblings }
}

test.describe('RFQ — quote request -> comparison -> purchase order', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-RFQ-01 (P0): one RFQ per addressed supplier, one shared group, zero money until quoted', async ({ page }) => {
    const { supplierA, supplierB, product, groupId, siblings } = await buildRfq(page, 'RFQ01')

    expect(siblings.length, 'one sibling document per addressed partner').toBe(2)
    expect(new Set(siblings.map((s) => s.group_id)).size, 'a single group binds them').toBe(1)
    expect(siblings[0].group_id).toBe(groupId)
    expect(siblings.map((s) => s.partner.id).sort()).toEqual([supplierA, supplierB].sort())

    for (const s of siblings) {
      expect(s.status).toBe('draft')
      expect(s.currency).toBe('TND')
      // An unquoted RFQ carries a REAL zero at currency scale — never blank, never null.
      expect(s.total).toBe('0.000')
      expect(s.lines.length).toBe(1)
      expect(s.lines[0].product_id).toBe(product.id)
      expect(s.lines[0].quantity, 'canonical 4-dp quantity scale').toBe('10.0000')
      expect(s.lines[0].unit_price).toBe('0.000')
    }
  })

  test('MTP-RFQ-02 (P0): a supplier response prices the RFQ exactly; the money ceiling is enforced', async ({ page }) => {
    const { product, siblings } = await buildRfq(page, 'RFQ02')
    const target = siblings[0]

    expect((await sendRfq(page, target.id)).status, JSON.stringify(target)).toBe(200)

    // Money ceiling first: a 4-dp quote must be REJECTED, not truncated into the quote.
    const overPrecise = await quoteRfq(page, target.id, {
      lines: [{ product_id: product.id, quantity: '10', unit_price: '12.5001' }],
    })
    expect(overPrecise.status, JSON.stringify(overPrecise.body)).toBe(422)
    expect(JSON.stringify(overPrecise.body)).toMatch(/at most 3 decimal places/i)
    // And the quantity ceiling on the same request.
    const overQty = await quoteRfq(page, target.id, {
      lines: [{ product_id: product.id, quantity: '10.00001', unit_price: '12.500' }],
    })
    expect(overQty.status, JSON.stringify(overQty.body)).toBe(422)
    expect(JSON.stringify(overQty.body)).toMatch(/at most 4 decimal places/i)

    const quoted = await quoteRfq(page, target.id, {
      lines: [{ product_id: product.id, quantity: '10', unit_price: '12.500' }],
      supplierReference: 'W4-SUP-REF-A',
      leadTimeDays: 7,
    })
    expect(quoted.status, JSON.stringify(quoted.body)).toBe(200)
    const d = (quoted.body as { data: RfqSibling }).data
    // 10 x 12.500 = 125.000, exact, at currency scale.
    expect(d.total).toBe('125.000')
    expect(d.lines[0].unit_price).toBe('12.500')
    expect(d.status, 'a priced response is a confirmed quote').toBe('confirmed')
    expect(d.supplier_reference).toBe('W4-SUP-REF-A')
    expect(d.lead_time_days).toBe(7)

    const reread = (await getRfq(page, target.id)) as unknown as RfqSibling
    expect(reread.total, 'persisted, not just echoed').toBe('125.000')
  })

  test('MTP-RFQ-03 (P0): the group comparison carries every supplier total exactly', async ({ page }) => {
    const { product, groupId, siblings } = await buildRfq(page, 'RFQ03')
    const [a, b] = siblings
    for (const [rfq, price, ref, lead] of [
      [a, '12.500', 'W4-A', 7],
      [b, '11.750', 'W4-B', 14],
    ] as Array<[RfqSibling, string, string, number]>) {
      expect((await sendRfq(page, rfq.id)).status).toBe(200)
      const q = await quoteRfq(page, rfq.id, {
        lines: [{ product_id: product.id, quantity: '10', unit_price: price }],
        supplierReference: ref,
        leadTimeDays: lead,
      })
      expect(q.status, JSON.stringify(q.body)).toBe(200)
    }

    const group = await getRfqGroup(page, groupId)
    expect(group.status, JSON.stringify(group.body)).toBe(200)
    const g = (group.body as { data: { group_id: string; has_live_purchase_order: boolean; siblings: RfqSibling[] } }).data
    expect(g.group_id).toBe(groupId)
    expect(g.has_live_purchase_order, 'nothing awarded yet').toBe(false)
    expect(g.siblings.length).toBe(2)

    const totals = Object.fromEntries(g.siblings.map((s) => [s.supplier_reference, s.total]))
    expect(totals['W4-A']).toBe('125.000')
    expect(totals['W4-B']).toBe('117.500')
    // The comparison's whole purpose: the saving is exact and computable, never a float.
    expect(subMoney(totals['W4-A'], totals['W4-B'])).toBe('7.500')
    expect(g.siblings.every((s) => s.status === 'confirmed')).toBe(true)
  })

  test('MTP-RFQ-04 (P0): awarding converts the winning quote to a PO byte-identically and closes the losers', async ({ page }) => {
    const { product, groupId, siblings } = await buildRfq(page, 'RFQ04')
    const [loser, winner] = siblings
    for (const [rfq, price] of [
      [loser, '12.500'],
      [winner, '11.750'],
    ] as Array<[RfqSibling, string]>) {
      expect((await sendRfq(page, rfq.id)).status).toBe(200)
      expect((await quoteRfq(page, rfq.id, { lines: [{ product_id: product.id, quantity: '10', unit_price: price }] })).status).toBe(200)
    }

    const award = await awardRfq(page, winner.id)
    expect(award.status, JSON.stringify(award.body)).toBe(200)
    const po = (award.body as { data: { id: string; type: string; status: string } }).data
    expect(po.type).toBe('purchase_order')
    expect(po.status).toBe('draft')

    // Byte-identical carry-over of the quoted line.
    const poDoc = await getPurchaseOrder(page, po.id)
    const poLines = poDoc.lines as Array<{ quantity: string; unit_price: string; line_total: string; product_id: string }>
    expect(poLines.length).toBe(1)
    expect(poLines[0].product_id).toBe(product.id)
    expect(poLines[0].quantity).toBe('10.0000')
    expect(poLines[0].unit_price, 'the WINNING price, not the losing one').toBe('11.750')
    expect(poLines[0].line_total).toBe('117.500')
    expect(poDoc.subtotal).toBe('117.500')

    const group = await getRfqGroup(page, groupId)
    const g = (group.body as { data: { has_live_purchase_order: boolean; siblings: RfqSibling[] } }).data
    expect(g.has_live_purchase_order, 'the group is now bound to a live PO').toBe(true)
    const byId = Object.fromEntries(g.siblings.map((s) => [s.id, s]))
    expect(byId[winner.id].status).toBe('confirmed')
    expect(byId[winner.id].closed_reason).toBeNull()
    expect(byId[loser.id].status, 'the losing quote is closed out, not left live').toBe('cancelled')
    expect(byId[loser.id].closed_reason).toBe('lost')
  })

  test('MTP-RFQ-05 (P0): a group can be awarded ONCE; reopen is the only way back', async ({ page }) => {
    const { product, groupId, siblings } = await buildRfq(page, 'RFQ05')
    const [a, b] = siblings
    for (const [rfq, price] of [
      [a, '9.000'],
      [b, '8.250'],
    ] as Array<[RfqSibling, string]>) {
      expect((await sendRfq(page, rfq.id)).status).toBe(200)
      expect((await quoteRfq(page, rfq.id, { lines: [{ product_id: product.id, quantity: '4', unit_price: price }] })).status).toBe(200)
    }
    const firstAward = await awardRfq(page, b.id)
    expect(firstAward.status, JSON.stringify(firstAward.body)).toBe(200)
    const awardedPoId = (firstAward.body as { data: { id: string } }).data.id

    // Double-award would create a SECOND purchase order for the same requirement — a real
    // duplicate-spend hole. It must fail closed.
    const again = await awardRfq(page, b.id)
    expect(again.status, JSON.stringify(again.body)).toBe(422)
    expect(JSON.stringify(again.body)).toMatch(/ALREADY_AWARDED/i)

    // Awarding the LOSER after the fact must also fail closed.
    const awardLoser = await awardRfq(page, a.id)
    expect(awardLoser.status, JSON.stringify(awardLoser.body)).toBeGreaterThanOrEqual(400)

    // RECORDED BEHAVIOUR: reopen is ALSO refused while the awarded PO is still live —
    // the guard is `has_live_purchase_order`, not merely "was awarded once". Reopening
    // under a live PO would let a second supplier be awarded against the same
    // requirement while the first order is outstanding, so failing closed here is the
    // correct money behaviour. The documented recovery is to retire the PO first.
    const blockedReopen = await reopenRfqGroup(page, groupId)
    expect(blockedReopen.status, JSON.stringify(blockedReopen.body)).toBe(422)
    expect(JSON.stringify(blockedReopen.body)).toMatch(/ALREADY_AWARDED/i)

    // Retire the draft PO, then the group reopens and the losing sibling comes back.
    const del = await apiRequest(page, 'DELETE', `/purchase-orders/${awardedPoId}`)
    expect(del.status, `retiring the draft PO: ${JSON.stringify(del.body)}`).toBeLessThan(400)

    const reopened = await reopenRfqGroup(page, groupId)
    expect(reopened.status, JSON.stringify(reopened.body)).toBeLessThan(400)
    const count = (reopened.body as { data: { reopened: number } }).data.reopened
    expect(count, 'reopening restores the closed sibling(s)').toBeGreaterThanOrEqual(1)

    const group = await getRfqGroup(page, groupId)
    const g = (group.body as { data: { has_live_purchase_order: boolean; siblings: RfqSibling[] } }).data
    expect(g.has_live_purchase_order, 'no live PO after the retirement').toBe(false)
    expect(g.siblings.every((sib) => sib.status !== 'cancelled'), 'the losing sibling is live again').toBe(true)
  })

  test('MTP-RFQ-06 (P1) TRIPWIRE: an RFQ-awarded PO carries NO tax rate — the VAT is silently missing', async ({ page }) => {
    // DEFECT (P1) — docs/superpowers/tickets/2026-08-03-w4-purchasing-inventory-defects.md #3.
    // CreatePurchaseQuoteRequestRequest / UpdatePurchaseQuoteRequestRequest have no
    // `tax_rate` field at all, so an RFQ line can never carry one, and
    // PurchaseQuoteRequestToPurchaseOrderConverter copies the untaxed line straight
    // through. The awarded PO's lines have `tax_rate: null`, so `tax_amount` stays
    // `0.000` even AFTER confirm — where a hand-authored PO of the same line at 19%
    // carries 23.750 of VAT (MTP-PUR-01). Consequences: the PO understates the committed
    // payable by the full VAT, the 3-way match compares an untaxed PO against a taxed
    // supplier invoice, and LandedCostService's non-recoverable-tax allocation sees
    // nothing to allocate.
    //
    // TRIPWIRE: this asserts TODAY's zero-VAT behaviour. When RFQ lines gain a tax rate
    // this goes red and must be updated to the taxed expectation.
    const { product, siblings } = await buildRfq(page, 'RFQ06')
    const winner = siblings[0]
    expect((await sendRfq(page, winner.id)).status).toBe(200)
    expect((await quoteRfq(page, winner.id, { lines: [{ product_id: product.id, quantity: '10', unit_price: '12.500' }] })).status).toBe(200)

    const award = await awardRfq(page, winner.id)
    expect(award.status, JSON.stringify(award.body)).toBe(200)
    const poId = (award.body as { data: { id: string } }).data.id

    const draft = await getPurchaseOrder(page, poId)
    const draftLines = draft.lines as Array<{ tax_rate: string | null }>
    expect(draftLines[0].tax_rate, 'TRIPWIRE: an RFQ line cannot carry a tax rate').toBeNull()
    expect(draft.subtotal).toBe('125.000')
    expect(draft.tax_amount).toBe('0.000')

    // Confirm is the point where a PO's taxes are calculated and snapshotted
    // (PurchaseOrderService::confirmAndAllocateCosts). It cannot invent a rate.
    const confirm = await apiRequest(page, 'POST', `/purchase-orders/${poId}/confirm`)
    expect(confirm.status, JSON.stringify(confirm.body)).toBe(200)
    const confirmed = await getPurchaseOrder(page, poId)
    expect(confirmed.tax_amount, 'TRIPWIRE: expected 23.750 (19% of 125.000); an RFQ-sourced PO gets none').toBe('0.000')
    expect(confirmed.total, 'TRIPWIRE: expected 148.750').toBe('125.000')
  })
})
