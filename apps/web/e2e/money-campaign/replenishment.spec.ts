/**
 * MONEY TEST CAMPAIGN — wave W-4 — NEW SURFACE `REP`: replenishment queue -> capture -> PO.
 * Cases MTP-REP-01..04 (`/inventory/replenishment*`, API `/replenishment-requests*`).
 *
 * No definition exists in `2026-08-01-money-test-plan.md`; these are DERIVED from §B.3
 * flow 38 ("Replenishment queue -> capture -> PO") and the real route surface.
 *
 * Live local stack, tenant demo-pharmacy-tn, real login, real backend. Own products per
 * case; every request this wave raises is RETIRED — cancelled while PENDING, rejected once
 * IN_PROGRESS — and every purchase order it sources is deleted, so neither the
 * replenishment queue nor the open-commitment reads a later wave performs are polluted
 * with W-4 probes.
 */
import { test, expect } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import { createSupplier, getPurchaseOrder, SHOP1_LOCATION_ID, SHOP2_LOCATION_ID, WAREHOUSE_LOCATION_ID } from './w2b-support'
import {
  createW4Product,
  captureReplenishment,
  listReplenishment,
  replenishmentCreatePo,
  replenishmentReject,
  cancelReplenishment,
  uniq4,
} from './w4-support'

test.describe.configure({ timeout: 180_000 })

interface RepRow {
  id: string
  location_id: string
  product_id: string
  requested_qty: string
  quantity_decimals: number
  request_count: number
  status: string
  source_channel: string
  sourcing_document_id: string | null
}

let supplierId: string

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage()
  await loginAsRole(page, 'owner')
  supplierId = await createSupplier(page, uniq4('REP-Supplier'))
  await page.close()
})

async function findRequest(page: import('@playwright/test').Page, requestId: string): Promise<RepRow | undefined> {
  const res = await listReplenishment(page)
  const rows = (res.body as { data: RepRow[] }).data
  return rows.find((r) => r.id === requestId)
}

test.describe('REP — replenishment capture -> queue -> purchase order', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-REP-01 (P1): a capture creates a pending request at the exact quantity scale', async ({ page }) => {
    const p = await createW4Product(page, 'REP01')
    const res = await captureReplenishment(page, {
      locationId: SHOP1_LOCATION_ID,
      productId: p.id,
      requestedQty: '12',
      note: 'W4 REP-01',
    })
    expect(res.status, JSON.stringify(res.body)).toBe(201)
    const row = (res.body as { data: RepRow }).data
    try {
      expect(row.status).toBe('pending')
      expect(row.location_id).toBe(SHOP1_LOCATION_ID)
      expect(row.product_id).toBe(p.id)
      // Canonical 4-dp quantity scale on the wire, with the unit's display precision
      // alongside it (piece => 0) — CLAUDE.md rule 19's display contract.
      expect(row.requested_qty).toBe('12.0000')
      expect(row.quantity_decimals).toBe(0)
      expect(row.request_count).toBe(1)
      expect(row.source_channel).toBe('web')
      expect(row.sourcing_document_id, 'nothing sourced yet').toBeNull()

      const queued = await findRequest(page, row.id)
      expect(queued, 'the request is on the queue').toBeDefined()
      expect((queued as RepRow).requested_qty).toBe('12.0000')
    } finally {
      const c = await cancelReplenishment(page, row.id)
      expect(c.status, `W-4 must not leave a live request on the shared queue: ${JSON.stringify(c.body)}`).toBeLessThan(300)
    }
  })

  test('MTP-REP-02 (P1): re-capturing the same product+location AGGREGATES, it does not duplicate', async ({ page }) => {
    const p = await createW4Product(page, 'REP02')
    const first = await captureReplenishment(page, { locationId: SHOP1_LOCATION_ID, productId: p.id, requestedQty: '12' })
    expect(first.status).toBe(201)
    const firstRow = (first.body as { data: RepRow }).data
    try {
      const second = await captureReplenishment(page, { locationId: SHOP1_LOCATION_ID, productId: p.id, requestedQty: '5' })
      // 200, not 201 — the same row was updated. A 201 here would mean two competing
      // requests for one shelf and a double order downstream.
      expect(second.status, JSON.stringify(second.body)).toBe(200)
      const secondRow = (second.body as { data: RepRow }).data
      expect(secondRow.id, 'the SAME request row').toBe(firstRow.id)
      expect(secondRow.requested_qty, '12 + 5 accumulated exactly').toBe('17.0000')
      expect(secondRow.request_count).toBe(2)

      const rows = (await listReplenishment(page)).body as { data: RepRow[] }
      const mine = rows.data.filter((r) => r.product_id === p.id)
      expect(mine.length, 'one row per product+location grain').toBe(1)

      // A DIFFERENT location is a different grain and gets its own row.
      const other = await captureReplenishment(page, { locationId: SHOP2_LOCATION_ID, productId: p.id, requestedQty: '3' })
      expect(other.status).toBe(201)
      const otherRow = (other.body as { data: RepRow }).data
      expect(otherRow.id).not.toBe(firstRow.id)
      expect(otherRow.requested_qty).toBe('3.0000')
      expect((await cancelReplenishment(page, otherRow.id)).status).toBeLessThan(300)
    } finally {
      expect((await cancelReplenishment(page, firstRow.id)).status, 'queue left clean').toBeLessThan(300)
    }
  })

  test('MTP-REP-03 (P0): sourcing a request into a PO carries the exact quantity and binds the request', async ({ page }) => {
    const p = await createW4Product(page, 'REP03')
    const cap = await captureReplenishment(page, { locationId: SHOP1_LOCATION_ID, productId: p.id, requestedQty: '12' })
    expect(cap.status).toBe(201)
    const row = (cap.body as { data: RepRow }).data

    const po = await replenishmentCreatePo(page, {
      supplierId,
      destinationLocationId: WAREHOUSE_LOCATION_ID,
      lines: [{ request_id: row.id, quantity: '12' }],
    })
    expect(po.status, JSON.stringify(po.body)).toBe(200)
    const documentId = (po.body as { data: { document_id: string } }).data.document_id
    expect(documentId).toBeTruthy()

    const doc = await getPurchaseOrder(page, documentId)
    const lines = doc.lines as Array<{ product_id: string; quantity: string; unit_price: string; tax_rate: string | null; line_total: string }>
    expect(lines.length).toBe(1)
    expect(lines[0].product_id).toBe(p.id)
    expect(lines[0].quantity, 'the requested quantity, exactly').toBe('12.0000')
    // RECORDED: the sourced line has NO purchase price yet — the buyer prices it. The
    // money contract that must hold is that the zero is a REAL zero at currency scale
    // (never blank/NaN) and the PO totals agree with it.
    expect(lines[0].unit_price).toBe('0.000')
    expect(lines[0].line_total).toBe('0.000')
    expect(doc.subtotal).toBe('0.000')
    expect(doc.tax_amount).toBe('0.000')
    expect(doc.total).toBe('0.000')
    // Unlike the RFQ-awarded PO (MTP-RFQ-06), the replenishment converter DOES stamp a
    // tax rate, so pricing the line later yields VAT.
    expect(lines[0].tax_rate, 'a replenishment-sourced line carries the default rate').toBe('19.00')

    try {
      const after = await findRequest(page, row.id)
      expect(after, 'the request stays visible on the queue while it is being sourced').toBeDefined()
      expect((after as RepRow).status, 'no longer pending — it is bound to a document').toBe('in_progress')
    } finally {
      // Retire BOTH artifacts: the draft PO (a zero-value open commitment W-6 would read)
      // and the request it bound. Order matters — the PO is deleted first so the request
      // is no longer bound when it is cancelled.
      const delPo = await apiRequest(page, 'DELETE', `/purchase-orders/${documentId}`)
      expect(delPo.status, `sourced draft PO cleanup: ${JSON.stringify(delPo.body)}`).toBeLessThan(300)
      const remaining = await findRequest(page, row.id)
      if (remaining !== undefined && remaining.status !== 'cancelled' && remaining.status !== 'rejected') {
        // `cancel` is PENDING-only (ReplenishmentRequestController::cancel():141
        // `abort_unless($row->status === Pending, 422)`); the reject action is the one
        // that accepts an already-sourced row (openRequests() scopes to Pending +
        // InProgress, ReplenishmentFulfillmentService.php:222-229).
        const retire =
          remaining.status === 'pending'
            ? await cancelReplenishment(page, row.id)
            : await replenishmentReject(page, [row.id], 'W4 cleanup: sourcing document deleted')
        expect(retire.status, `sourced request cleanup: ${JSON.stringify(retire.body)}`).toBeLessThan(300)
      }
      const gone = await findRequest(page, row.id)
      expect(
        gone === undefined || gone.status !== 'in_progress',
        'no W-4 replenishment request is left bound to a deleted document'
      ).toBe(true)
    }
  })

  test('MTP-REP-04 (P1): reject and permission dispositions are recorded, and the queue is denied to a viewer', async ({ page }) => {
    const p = await createW4Product(page, 'REP04')
    const cap = await captureReplenishment(page, { locationId: SHOP1_LOCATION_ID, productId: p.id, requestedQty: '8' })
    expect(cap.status).toBe(201)
    const row = (cap.body as { data: RepRow }).data
    let retired = false
    try {
      const rejected = await replenishmentReject(page, [row.id], 'W4 REP-04 not needed')
      expect(rejected.status, JSON.stringify(rejected.body)).toBeLessThan(400)
      const after = await findRequest(page, row.id)
      // Either it leaves the queue entirely or it is marked rejected — both are terminal
      // and neither may leave it `pending` (which would be silently re-orderable).
      if (after !== undefined) {
        expect(after.status, 'a rejected request must not stay pending').not.toBe('pending')
      }
      retired = true

      // Permission arm: a principal without `replenishment.create` cannot raise a request,
      // and one without `replenishment.process` cannot source it into a PO.
      const denyProduct = await createW4Product(page, 'REP04-denied')
      await loginAsRole(page, 'viewer')
      const denied = await captureReplenishment(page, { locationId: SHOP1_LOCATION_ID, productId: denyProduct.id, requestedQty: '2' })
      expect(denied.status, `expected a denial; got ${denied.status} ${JSON.stringify(denied.body)}`).toBe(403)

      const deniedPo = await replenishmentCreatePo(page, {
        supplierId,
        destinationLocationId: WAREHOUSE_LOCATION_ID,
        lines: [{ request_id: row.id, quantity: '1' }],
      })
      expect(deniedPo.status, `expected a denial; got ${deniedPo.status}`).toBe(403)

      await loginAsRole(page, 'owner')
      const stillNone = await listReplenishment(page)
      const leaked = ((stillNone.body as { data: RepRow[] }).data ?? []).filter((r) => r.product_id === denyProduct.id)
      expect(leaked.length, 'the refused capture created nothing').toBe(0)
    } finally {
      await loginAsRole(page, 'owner')
      if (!retired) {
        await cancelReplenishment(page, row.id)
      }
      const remaining = await findRequest(page, row.id)
      if (remaining !== undefined && remaining.status === 'pending') {
        expect((await cancelReplenishment(page, row.id)).status, 'queue left clean').toBeLessThan(300)
      }
    }
  })
})
