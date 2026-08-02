/**
 * MONEY TEST CAMPAIGN — W-2 execution agent — surface `RET` (return notes +
 * delivery notes + delivery-note consolidation), P0 per orchestrator ruling
 * F-7 (docs/qa/2026-08-02-full-e2e-campaign-plan.md §F.7).
 *
 * Scope per plan §B.1 flow 16: `/sales/return-notes*`,
 * `/inventory/delivery-notes*` — previously zero e2e coverage (confirmed by
 * research: no Playwright spec anywhere references return-notes or
 * delivery-notes; only backend PHPUnit).
 *
 * Key structural fact this whole file leans on (`DocumentType::affectsReceivable()`,
 * DocumentType.php): DeliveryNote and ReturnNote are PURE STOCK/FISCAL
 * documents — confirming either one never touches AR/`balance_due`/GL. Only
 * a CreditNote does that. RET-03 pins this contrast explicitly since it is
 * easy to assume (wrongly) that a "return" behaves like a "refund".
 *
 * Real login (`loginAsRole`) + real API (`apiRequest`), no mocking. All
 * fixtures (products/suppliers/customers) carry the `W2c` prefix.
 */
import { test, expect, type Page } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import {
  createProduct,
  createSupplier,
  createPurchaseOrder,
  confirmPurchaseOrder,
  receivePurchaseOrder,
  getPurchaseOrder,
  getStockLevels,
  WAREHOUSE_LOCATION_ID,
} from './w2b-support'
import {
  createReturnNote,
  confirmReturnNote,
  createDeliveryNote,
  confirmDeliveryNote,
  getDeliveryNote,
  consolidateDeliveryNotes,
  uniq,
} from './w2c-support'

async function createCustomer(page: Page, name: string): Promise<string> {
  const res = await apiRequest(page, 'POST', '/partners', { name, type: 'customer' })
  expect(res.status, `create customer -> ${res.status} ${JSON.stringify(res.body)}`).toBe(201)
  return ((res.body as { data: { id: string } }).data).id
}

async function stockAt(page: Page, productId: string, locationId: string): Promise<string> {
  const levels = await getStockLevels(page, productId)
  return levels.find((l) => l.location_id === locationId)?.quantity ?? '0.0000'
}

/** Product + 20 units received into WAREHOUSE_LOCATION_ID via a real PO receipt. */
async function stockedProduct(page: Page, skuBase: string, qty = '20'): Promise<{ productId: string; supplierId: string }> {
  const supplierId = await createSupplier(page, uniq(`${skuBase}-supplier`))
  const { id: productId } = await createProduct(page, { name: uniq(skuBase), sku: uniq(skuBase) })
  const po = await createPurchaseOrder(page, {
    partnerId: supplierId,
    lines: [{ productId, quantity: qty, unitPrice: '10.000' }],
  })
  expect(po.status, `create PO -> ${po.status} ${JSON.stringify(po.body)}`).toBe(201)
  const confirmRes = await confirmPurchaseOrder(page, po.id!)
  expect(confirmRes.status).toBeLessThan(300)
  const poBody = await getPurchaseOrder(page, po.id!)
  const lineId = (poBody.lines as Array<{ id: string }>)[0].id
  const receiveRes = await receivePurchaseOrder(page, po.id!, { quantities: { [lineId]: qty } })
  expect(receiveRes.status).toBeLessThan(300)
  return { productId, supplierId }
}

test.describe('MTP-RET — return notes / delivery notes / consolidation (W-2)', () => {
  test.setTimeout(90_000)

  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-RET-01 (P0): standalone return note (no source document) confirms and receives stock back', async ({ page }) => {
    const { productId } = await stockedProduct(page, 'RET01')
    const customerId = await createCustomer(page, uniq('RET01'))
    const before = await stockAt(page, productId, WAREHOUSE_LOCATION_ID)

    const rn = await createReturnNote(page, {
      partnerId: customerId,
      returnReason: 'defective',
      returnCondition: 'unopened',
      lines: [{ productId, quantity: '3', unitPrice: '10.000', locationId: WAREHOUSE_LOCATION_ID, taxRate: '19.00' }],
    })
    expect(rn.status, `create standalone return note -> ${rn.status} ${JSON.stringify(rn.body)}`).toBe(201)

    const confirm = await confirmReturnNote(page, rn.id!)
    expect(confirm.status, `confirm return note -> ${confirm.status} ${JSON.stringify(confirm.body)}`).toBeLessThan(300)

    const after = await stockAt(page, productId, WAREHOUSE_LOCATION_ID)
    expect(Number(after) - Number(before), 'confirming receives the returned quantity back into stock').toBeCloseTo(3, 4)
  })

  test('MTP-RET-02 (P0): source-linked return note is capped to invoiced-minus-already-returned quantity', async ({ page }) => {
    const { productId } = await stockedProduct(page, 'RET02')
    const customerId = await createCustomer(page, uniq('RET02'))

    // Draft invoice with an invoiced quantity of 5 — assertWithinReturnableQuantities
    // reads $source->lines regardless of the source document's own status.
    const invoice = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ product_id: productId, description: 'RET-02 source line', quantity: '5', unit_price: '10.000', tax_rate: '19.00' }],
    })
    expect(invoice.status).toBe(201)
    const invoiceId = ((invoice.body as { data: { id: string } }).data).id

    // Attempt to return 6 (> 5 invoiced) — refused.
    const overReturn = await createReturnNote(page, {
      partnerId: customerId,
      sourceDocumentId: invoiceId,
      returnReason: 'defective',
      lines: [{ productId, quantity: '6', unitPrice: '10.000', locationId: WAREHOUSE_LOCATION_ID }],
    })
    expect(overReturn.status, `return exceeding invoiced qty must 422 -> ${overReturn.status}`).toBe(422)
    const overBody = overReturn.body as { error?: { code?: string } }
    expect(overBody.error?.code).toBe('RETURN_EXCEEDS_INVOICED_QUANTITY')

    // Return exactly 5 — the full invoiced amount — succeeds.
    const fullReturn = await createReturnNote(page, {
      partnerId: customerId,
      sourceDocumentId: invoiceId,
      returnReason: 'defective',
      lines: [{ productId, quantity: '5', unitPrice: '10.000', locationId: WAREHOUSE_LOCATION_ID }],
    })
    expect(fullReturn.status, `return of the exact invoiced qty -> ${fullReturn.status} ${JSON.stringify(fullReturn.body)}`).toBe(201)

    // Now NOTHING remains returnable — even 1 more unit is refused, proving
    // the cap accounts for prior (non-cancelled) return notes, not just the
    // source invoice's own line quantity.
    const secondReturn = await createReturnNote(page, {
      partnerId: customerId,
      sourceDocumentId: invoiceId,
      returnReason: 'defective',
      lines: [{ productId, quantity: '1', unitPrice: '10.000', locationId: WAREHOUSE_LOCATION_ID }],
    })
    expect(secondReturn.status, 'nothing remains returnable after the first return exhausted the cap').toBe(422)
  })

  test('MTP-RET-03 (P0, RULING): confirming a return note has NO AR/GL effect on the source invoice — contrast with a credit note', async ({ page }) => {
    const { productId } = await stockedProduct(page, 'RET03')
    const customerId = await createCustomer(page, uniq('RET03'))

    const invoice = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ product_id: productId, description: 'RET-03 source line', quantity: '4', unit_price: '25.000', tax_rate: '0.00' }],
    })
    const invoiceId = ((invoice.body as { data: { id: string } }).data).id
    await apiRequest(page, 'POST', `/invoices/${invoiceId}/confirm`)
    const posted = await apiRequest(page, 'POST', `/invoices/${invoiceId}/post`)
    const totalBefore = (posted.body as { data: { total: string; balance_due: string } }).data.total
    const balanceBefore = (posted.body as { data: { total: string; balance_due: string } }).data.balance_due

    const rn = await createReturnNote(page, {
      partnerId: customerId,
      sourceDocumentId: invoiceId,
      returnReason: 'defective',
      lines: [{ productId, quantity: '2', unitPrice: '25.000', locationId: WAREHOUSE_LOCATION_ID, taxRate: '0.00' }],
    })
    expect(rn.status).toBe(201)
    const confirm = await confirmReturnNote(page, rn.id!)
    expect(confirm.status).toBeLessThan(300)

    const invAfter = await apiRequest(page, 'GET', `/invoices/${invoiceId}`)
    const afterData = (invAfter.body as { data: { total: string; balance_due: string; status: string } }).data
    expect(afterData.total, 'RULING: a return note NEVER touches the source invoice total').toBe(totalBefore)
    expect(afterData.balance_due, 'RULING: a return note NEVER touches balance_due — only a CreditNote does (DocumentType::affectsReceivable)').toBe(balanceBefore)
    expect(afterData.status, 'invoice status unaffected by a linked return note').toBe('posted')
  })

  test('MTP-RET-04 (P1): delivery note create + confirm issues (outbound) stock', async ({ page }) => {
    const { productId } = await stockedProduct(page, 'RET04', '15')
    const customerId = await createCustomer(page, uniq('RET04'))
    const before = await stockAt(page, productId, WAREHOUSE_LOCATION_ID)

    const dn = await createDeliveryNote(page, {
      partnerId: customerId,
      locationId: WAREHOUSE_LOCATION_ID,
      lines: [{ productId, quantity: '4', unitPrice: '10.000', locationId: WAREHOUSE_LOCATION_ID, taxRate: '0.00' }],
    })
    expect(dn.status, `create delivery note -> ${dn.status} ${JSON.stringify(dn.body)}`).toBe(201)
    const confirm = await confirmDeliveryNote(page, dn.id!)
    expect(confirm.status, `confirm delivery note -> ${confirm.status} ${JSON.stringify(confirm.body)}`).toBeLessThan(300)

    const after = await stockAt(page, productId, WAREHOUSE_LOCATION_ID)
    expect(Number(before) - Number(after), 'confirming a DN issues (removes) the delivered quantity').toBeCloseTo(4, 4)

    const dnBody = await getDeliveryNote(page, dn.id!)
    expect(dnBody.total, 'delivery notes carry no GL entries — but DO snapshot a total for consolidation math').toBeTruthy()
  })

  test('MTP-RET-05 (P0): consolidating 2 confirmed delivery notes into one invoice sums the totals; no second stock movement', async ({ page }) => {
    const { productId } = await stockedProduct(page, 'RET05', '30')
    const customerId = await createCustomer(page, uniq('RET05'))

    const dn1 = await createDeliveryNote(page, {
      partnerId: customerId,
      locationId: WAREHOUSE_LOCATION_ID,
      lines: [{ productId, quantity: '3', unitPrice: '20.000', locationId: WAREHOUSE_LOCATION_ID, taxRate: '0.00' }],
    })
    await confirmDeliveryNote(page, dn1.id!)
    const dn2 = await createDeliveryNote(page, {
      partnerId: customerId,
      locationId: WAREHOUSE_LOCATION_ID,
      lines: [{ productId, quantity: '2', unitPrice: '20.000', locationId: WAREHOUSE_LOCATION_ID, taxRate: '0.00' }],
    })
    await confirmDeliveryNote(page, dn2.id!)

    const stockAfterBothDNs = await stockAt(page, productId, WAREHOUSE_LOCATION_ID)

    const consolidate = await consolidateDeliveryNotes(page, [dn1.id!, dn2.id!])
    expect(consolidate.status, `consolidate -> ${consolidate.status} ${JSON.stringify(consolidate.body)}`).toBeLessThan(300)
    const invoiceBody = (consolidate.body as { data: { total: string; balance_due: string; status: string } }).data
    // TRIPWIRE (docs/superpowers/tickets/2026-08-02-documents-gate-followups.md
    // F3): the consolidated invoice is created via the SAME conversion
    // machinery (CopiesDocumentData::recalculateTotals(),
    // Conversion/Concerns/CopiesDocumentData.php:272-298) that F3 already
    // flags as omitting document-level taxes (the 1.000 TND stamp) on a
    // DRAFT produced by conversion — 3*20.000 + 2*20.000 = 100.000 is the
    // CURRENT total, not 101.000. When F3 lands this flips to 101.000 and
    // this assertion must be updated in the same change.
    expect(invoiceBody.status, 'consolidation lands as a Draft invoice, not auto-confirmed').toBe('draft')
    expect(invoiceBody.total, 'TRIPWIRE (F3): stamp duty omitted on this conversion-created draft').toBe('100.000')
    expect(invoiceBody.balance_due).toBe(invoiceBody.total)

    const stockAfterConsolidation = await stockAt(page, productId, WAREHOUSE_LOCATION_ID)
    expect(stockAfterConsolidation, 'consolidation is a pure invoicing event — no second stock movement').toBe(stockAfterBothDNs)
  })

  test('MTP-RET-06 (P1): consolidation refuses cross-partner delivery notes and an already-invoiced delivery note', async ({ page }) => {
    const { productId } = await stockedProduct(page, 'RET06', '20')
    const customerA = await createCustomer(page, uniq('RET06-A'))
    const customerB = await createCustomer(page, uniq('RET06-B'))

    const dnA = await createDeliveryNote(page, {
      partnerId: customerA,
      locationId: WAREHOUSE_LOCATION_ID,
      lines: [{ productId, quantity: '1', unitPrice: '10.000', locationId: WAREHOUSE_LOCATION_ID, taxRate: '0.00' }],
    })
    await confirmDeliveryNote(page, dnA.id!)
    const dnB = await createDeliveryNote(page, {
      partnerId: customerB,
      locationId: WAREHOUSE_LOCATION_ID,
      lines: [{ productId, quantity: '1', unitPrice: '10.000', locationId: WAREHOUSE_LOCATION_ID, taxRate: '0.00' }],
    })
    await confirmDeliveryNote(page, dnB.id!)

    const crossPartner = await consolidateDeliveryNotes(page, [dnA.id!, dnB.id!])
    expect(crossPartner.status, `cross-partner consolidation must 422 -> ${crossPartner.status}`).toBe(422)

    // Consolidate dnA alone (succeeds), then try to consolidate it AGAIN —
    // already-invoiced refusal.
    const firstConsolidate = await consolidateDeliveryNotes(page, [dnA.id!])
    expect(firstConsolidate.status).toBeLessThan(300)
    const reConsolidate = await consolidateDeliveryNotes(page, [dnA.id!])
    expect(reConsolidate.status, `already-invoiced DN must be refused a second consolidation -> ${reConsolidate.status}`).toBe(422)
  })

  test('MTP-RET-07 (P0): cashier holds no deliveries.create/confirm — both return-note and delivery-note creation 403', async ({ page }) => {
    const { productId } = await stockedProduct(page, 'RET07')
    await loginAsRole(page, 'owner')
    const customerId = await createCustomer(page, uniq('RET07'))

    await loginAsRole(page, 'cashier')
    const rnAttempt = await createReturnNote(page, {
      partnerId: customerId,
      returnReason: 'defective',
      lines: [{ productId, quantity: '1', unitPrice: '10.000', locationId: WAREHOUSE_LOCATION_ID }],
    })
    expect(rnAttempt.status, 'cashier holds deliveries.view only, not deliveries.create').toBe(403)

    const dnAttempt = await createDeliveryNote(page, {
      partnerId: customerId,
      locationId: WAREHOUSE_LOCATION_ID,
      lines: [{ productId, quantity: '1', unitPrice: '10.000', locationId: WAREHOUSE_LOCATION_ID }],
    })
    expect(dnAttempt.status).toBe(403)
  })

  test('MTP-RET-08 (P1, RULING): operator can create/edit a return note but cannot confirm it (asymmetric deliveries.* grant)', async ({ page }) => {
    // NOT test.setTimeout(60_000) — this test does more sequential
    // login/role-API round-trips than any other case in this file (PO
    // chain + 4 logins + role create/assign/delete); the previous 60s
    // override was TIGHTER than the describe-level 90s default and starved
    // it under normal shared-dev-server latency.
    test.setTimeout(120_000)
    const { productId } = await stockedProduct(page, 'RET08')
    await loginAsRole(page, 'owner')
    const customerId = await createCustomer(page, uniq('RET08'))

    // RolesAndPermissionsSeeder.php: 'operator' holds 'deliveries.view',
    // 'deliveries.create', 'deliveries.edit' but NOT 'deliveries.confirm' or
    // 'deliveries.delete' — the only role shaped this way (cashier/viewer/
    // technician/accountant hold at most 'deliveries.view').
    // No 'operator' persona is seeded with credentials in ROLE_CREDENTIALS
    // (helpers.ts) — construct the check via the real Roles API against a
    // throwaway grant on the `viewer` user, same established pattern as
    // MTP-PERM-15 / MTP-FRD-05.
    await loginAsRole(page, 'viewer')
    const meAsViewer = await apiRequest(page, 'GET', '/auth/me')
    const viewerId = ((meAsViewer.body as { data: { id: string } }).data).id

    await loginAsRole(page, 'owner')
    const roleName = `ret08-operator-shape-${Date.now()}`
    const roleCreate = await apiRequest(page, 'POST', '/roles', {
      name: roleName,
      permissions: ['deliveries.view', 'deliveries.create', 'deliveries.edit', 'partners.view'],
    })
    expect(roleCreate.status, `role create -> ${roleCreate.status} ${JSON.stringify(roleCreate.body)}`).toBe(201)
    const assign = await apiRequest(page, 'POST', `/users/${viewerId}/roles`, { role: roleName })
    expect(assign.status).toBeLessThan(300)

    try {
      await loginAsRole(page, 'viewer')
      const rn = await createReturnNote(page, {
        partnerId: customerId,
        returnReason: 'defective',
        lines: [{ productId, quantity: '1', unitPrice: '10.000', locationId: WAREHOUSE_LOCATION_ID }],
      })
      expect(rn.status, `deliveries.create holder can create -> ${rn.status} ${JSON.stringify(rn.body)}`).toBe(201)

      const confirmAttempt = await confirmReturnNote(page, rn.id!)
      expect(confirmAttempt.status, 'RULING: deliveries.create/edit does NOT imply deliveries.confirm — must 403').toBe(403)
    } finally {
      await loginAsRole(page, 'owner')
      await apiRequest(page, 'DELETE', `/users/${viewerId}/roles`, { role: roleName })
      const roles = await apiRequest(page, 'GET', '/roles')
      const roleRow = ((roles.body as { data?: Array<{ id: number; name: string }> }).data ?? []).find((r) => r.name === roleName)
      if (roleRow) {
        await apiRequest(page, 'DELETE', `/roles/${roleRow.id}`)
      }
    }
  })
})
