/**
 * MONEY TEST CAMPAIGN — agent W2b — §C `PUR` purchase-order / goods-receipt /
 * supplier-invoice 3-way matching (docs/qa/2026-08-01-money-test-plan.md,
 * MTP-PUR-01..13).
 *
 * Live local stack (web :5173 -> api :8010), tenant demo-pharmacy-tn, real login,
 * real backend — no mocks. Driven at the API layer (apiRequest, same authenticated
 * session as the SPA) rather than through the PO/receipt/invoice forms: the
 * matching arithmetic is exact-money and boundary-sensitive (§0.2), and the API
 * response is the same payload the UI renders, so API-level assertions are a
 * faithful, more robust test of the same contract.
 *
 * All fixtures are W2b-prefixed (own supplier, own products, own POs) — never
 * touches DEMO-PO-000x. Each price-variance case (04-11) gets its OWN fresh
 * product+PO+receipt rather than re-using MTP-PUR-01's PO, because the matcher
 * permanently consumes `quantity_invoiced` on first invoice — reusing a PO across
 * cases would silently turn case N+1 into an over-clear scenario instead of the
 * price-variance scenario the case is meant to test.
 */
import { test, expect, type Page } from '@playwright/test'
import { loginAsRole } from './helpers'
import {
  createProduct,
  createPurchaseOrder,
  createSupplier,
  setupReceivedPoLine,
  createSupplierInvoice,
  getPurchaseOrder,
  getSupplierInvoice,
  postSupplierInvoice,
  getProcurementPolicy,
  setProcurementPolicy,
  getStockLevels,
  uniq,
} from './w2b-support'

let supplierId: string

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage()
  await loginAsRole(page, 'owner')
  supplierId = await createSupplier(page, uniq('Supplier'))
  await page.close()
})

test.describe('PUR — purchase order / goods receipt / supplier invoice matching', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-PUR-01 (P0): PO line qty10 @ 12.500, VAT 19% — net/VAT/total exact', async ({ page }) => {
    // net = 10 * 12.500 = 125.000 ; VAT = 125.000 * 0.19 = 23.750 ; total = 148.750
    const { poId } = await setupPoOnly(page, { quantity: '10', unitPrice: '12.500' })
    const po = await getPurchaseOrder(page, poId)
    expect(po.subtotal).toBe('125.000')
    expect(po.tax_amount).toBe('23.750')
    expect(po.total).toBe('148.750')
  })

  test('MTP-PUR-02 (P0): full receipt of a confirmed PO line posts stock +qty', async ({ page }) => {
    const { productId } = await setupReceivedPoLine(page, { supplierId, quantity: '10', unitPrice: '12.500', skuBase: 'PUR02' })
    const levels = await getStockLevels(page, productId)
    const total = levels.reduce((sum, l) => sum + Number(l.quantity), 0)
    expect(total).toBe(10)
  })

  test('MTP-PUR-03 (P0): invoice at PO price — matched, price_variance=false', async ({ page }) => {
    const { poId, lineId } = await setupReceivedPoLine(page, { supplierId, quantity: '10', unitPrice: '12.500', skuBase: 'PUR03' })
    const inv = await createSupplierInvoice(page, {
      partnerId: supplierId,
      sourceDocumentIds: [poId],
      lines: [{ sourceLineId: lineId, quantity: '10', unitPrice: '12.500' }],
    })
    expect(inv.status, JSON.stringify(inv.body)).toBe(201)
    const detail = await getSupplierInvoice(page, inv.id as string)
    expect(detail.match_status).toBe('matched')
    const match = detail.match as { per_line: Array<{ price_variance: boolean }> }
    expect(match.per_line[0].price_variance).toBe(false)
  })

  test('MTP-PUR-04 (P0): variance 0.500 within BOTH thresholds — matched', async ({ page }) => {
    // variance = |12.550-12.500| * 10 = 0.500 ; pctThreshold = 125.000*0.02 = 2.500
    // 0.500 <= 2.500 AND 0.500 <= 1.000 (max amount) -> matched
    const { poId, lineId } = await setupReceivedPoLine(page, { supplierId, quantity: '10', unitPrice: '12.500', skuBase: 'PUR04' })
    const inv = await createSupplierInvoice(page, {
      partnerId: supplierId,
      sourceDocumentIds: [poId],
      lines: [{ sourceLineId: lineId, quantity: '10', unitPrice: '12.550' }],
    })
    const detail = await getSupplierInvoice(page, inv.id as string)
    expect(detail.match_status).toBe('matched')
  })

  test('MTP-PUR-05 (P0): variance exactly at max_amount boundary (1.000) — inclusive <=, matched', async ({ page }) => {
    // variance = |12.600-12.500| * 10 = 1.000 == variance_tolerance_max_amount exactly
    const { poId, lineId } = await setupReceivedPoLine(page, { supplierId, quantity: '10', unitPrice: '12.500', skuBase: 'PUR05' })
    const inv = await createSupplierInvoice(page, {
      partnerId: supplierId,
      sourceDocumentIds: [poId],
      lines: [{ sourceLineId: lineId, quantity: '10', unitPrice: '12.600' }],
    })
    const detail = await getSupplierInvoice(page, inv.id as string)
    expect(detail.match_status, 'inclusive boundary: exactly at max_amount must still be matched').toBe('matched')
  })

  test('MTP-PUR-06 (P0): variance 1.010 > max_amount even though % passes — price_variance', async ({ page }) => {
    // variance = |12.601-12.500| * 10 = 1.010 ; pctThreshold = 2.500 (passes) but 1.010 > 1.000 (amount ceiling binds)
    const { poId, lineId } = await setupReceivedPoLine(page, { supplierId, quantity: '10', unitPrice: '12.500', skuBase: 'PUR06' })
    const inv = await createSupplierInvoice(page, {
      partnerId: supplierId,
      sourceDocumentIds: [poId],
      lines: [{ sourceLineId: lineId, quantity: '10', unitPrice: '12.601' }],
    })
    const detail = await getSupplierInvoice(page, inv.id as string)
    expect(detail.match_status).toBe('price_variance')
  })

  test('MTP-PUR-07 (P0): AND semantics — % fails, amount passes — price_variance', async ({ page }) => {
    // PO qty1 @ 10.000 (extended 10.000) ; invoice qty1 @ 10.250
    // pctThreshold = 10.000*0.02 = 0.200 ; variance = 0.250 -> 0.250 > 0.200 (fails)
    // but 0.250 <= 1.000 (amount passes). AND semantics -> price_variance.
    const { poId, lineId } = await setupReceivedPoLine(page, { supplierId, quantity: '1', unitPrice: '10.000', skuBase: 'PUR07' })
    const inv = await createSupplierInvoice(page, {
      partnerId: supplierId,
      sourceDocumentIds: [poId],
      lines: [{ sourceLineId: lineId, quantity: '1', unitPrice: '10.250' }],
    })
    const detail = await getSupplierInvoice(page, inv.id as string)
    expect(detail.match_status, 'AND semantics: % ceiling binds even though amount ceiling passes').toBe('price_variance')
  })

  test('MTP-PUR-08 (P1): both thresholds pass — matched', async ({ page }) => {
    // variance = |10.150-10.000| = 0.150 <= 0.200 (pct) AND <= 1.000 (amount) -> matched
    const { poId, lineId } = await setupReceivedPoLine(page, { supplierId, quantity: '1', unitPrice: '10.000', skuBase: 'PUR08' })
    const inv = await createSupplierInvoice(page, {
      partnerId: supplierId,
      sourceDocumentIds: [poId],
      lines: [{ sourceLineId: lineId, quantity: '1', unitPrice: '10.150' }],
    })
    const detail = await getSupplierInvoice(page, inv.id as string)
    expect(detail.match_status).toBe('matched')
  })

  test('MTP-PUR-09 (P0): match_enforcement=block — POST is refused for a price-variance invoice', async ({ page }) => {
    // FINDING vs. plan wording: the block happens at POST time
    // (SupplierInvoiceMatcher::assertPostable / SupplierInvoicePostingService),
    // NOT at invoice creation. CreateSupplierInvoiceService::create() always
    // persists a Draft (auto-matching sets match_status), matching plan's own
    // §F.1 pattern "match_status computed but enforcement gates only posting".
    // Restores the tenant-wide procurement policy immediately after the
    // assertion (this setting is shared company config, not per-test state).
    const original = await getProcurementPolicy(page)
    try {
      const setRes = await setProcurementPolicy(page, {
        match_mode: original.match_mode,
        match_enforcement: 'block',
        variance_tolerance_percent: original.variance_tolerance_percent,
        variance_tolerance_max_amount: original.variance_tolerance_max_amount,
      })
      expect(setRes.ok, JSON.stringify(setRes.body)).toBeTruthy()

      const { poId, lineId } = await setupReceivedPoLine(page, { supplierId, quantity: '10', unitPrice: '12.500', skuBase: 'PUR09' })
      const inv = await createSupplierInvoice(page, {
        partnerId: supplierId,
        sourceDocumentIds: [poId],
        lines: [{ sourceLineId: lineId, quantity: '10', unitPrice: '12.601' }],
      })
      expect(inv.status, 'create still succeeds as Draft').toBe(201)
      const created = await getSupplierInvoice(page, inv.id as string)
      expect(created.match_status).toBe('price_variance')
      expect(created.status).toBe('draft')

      const postRes = await postSupplierInvoice(page, inv.id as string)
      expect(postRes.status, JSON.stringify(postRes.body)).toBe(422)

      const afterFailedPost = await getSupplierInvoice(page, inv.id as string)
      expect(afterFailedPost.status, 'no GL leg: invoice must remain Draft after refused post').toBe('draft')
    } finally {
      await setProcurementPolicy(page, {
        match_mode: original.match_mode,
        match_enforcement: original.match_enforcement,
        variance_tolerance_percent: original.variance_tolerance_percent,
        variance_tolerance_max_amount: original.variance_tolerance_max_amount,
      })
    }
  })

  test('MTP-PUR-10 (P1): match_enforcement=warn — price-variance invoice posts with a warning', async ({ page }) => {
    const original = await getProcurementPolicy(page)
    expect(original.match_enforcement, 'sanity: tenant default is warn per §C preconditions').toBe('warn')

    const { poId, lineId } = await setupReceivedPoLine(page, { supplierId, quantity: '10', unitPrice: '12.500', skuBase: 'PUR10' })
    const inv = await createSupplierInvoice(page, {
      partnerId: supplierId,
      sourceDocumentIds: [poId],
      lines: [{ sourceLineId: lineId, quantity: '10', unitPrice: '12.601' }],
    })
    const created = await getSupplierInvoice(page, inv.id as string)
    expect(created.match_status).toBe('price_variance')

    const postRes = await postSupplierInvoice(page, inv.id as string)
    expect(postRes.status, JSON.stringify(postRes.body)).toBe(200)
    const body = postRes.body as { data: { warning?: string; status: string } }
    expect(body.data.warning).toMatch(/price variance/i)
    expect(body.data.status).toBe('posted')
  })

  test('MTP-PUR-11 (P0): over-clear (further qty against an already-fully-invoiced receipt) — HARD block under both enforcements', async ({ page }) => {
    const { poId, lineId } = await setupReceivedPoLine(page, { supplierId, quantity: '10', unitPrice: '12.500', skuBase: 'PUR11' })
    const firstInv = await createSupplierInvoice(page, {
      partnerId: supplierId,
      sourceDocumentIds: [poId],
      lines: [{ sourceLineId: lineId, quantity: '10', unitPrice: '12.500' }],
    })
    expect(firstInv.status).toBe(201)
    const firstPost = await postSupplierInvoice(page, firstInv.id as string)
    expect(firstPost.status, JSON.stringify(firstPost.body)).toBe(200)

    // Second invoice against the same (now fully-invoiced) receipt line: matchable = 0.
    const secondInv = await createSupplierInvoice(page, {
      partnerId: supplierId,
      sourceDocumentIds: [poId],
      lines: [{ sourceLineId: lineId, quantity: '1', unitPrice: '12.500' }],
    })
    expect(secondInv.status, 'create still succeeds as Draft; the HARD block is enforced at post').toBe(201)
    const secondCreated = await getSupplierInvoice(page, secondInv.id as string)
    expect(secondCreated.match_status).toBe('quantity_variance')

    const secondPost = await postSupplierInvoice(page, secondInv.id as string)
    expect(secondPost.status, 'HARD block applies under warn too (match_enforcement does not govern quantity violations)').toBe(422)
  })

  test('MTP-PUR-12 (P1): BLOCKED — unlinked-line "exception" match status is unreachable through the documented API', async () => {
    test.info().annotations.push({
      type: 'BLOCKED',
      description:
        'SupplierInvoiceMatcher::match() treats a null source_line_id as a HARD ' +
        '"exception" (SupplierInvoiceMatcher.php ~L204-230), but ' +
        'CreateSupplierInvoiceRequest requires lines.*.source_line_id (and validates ' +
        'it belongs to one of the referenced POs) on the normal creation path — a ' +
        'request omitting it 422s before the matcher ever runs. The only path that ' +
        'makes source_line_id nullable is pending_receipt=true / invoice_first_delivered ' +
        '(CreateSupplierInvoiceService::create(): `$matchStatus = $pendingReceipt ? ' +
        'Unmatched : $this->matcher->match($document)`), which SKIPS the matcher ' +
        'entirely and leaves match_status=unmatched, never exception. There is no ' +
        'PATCH-lines endpoint on a supplier invoice to null out a line after ' +
        'creation, and no route re-runs match() on an invoice-first document. FINDING: ' +
        'the "unlinked line -> exception" branch appears to be unreachable dead code ' +
        'from the API surface as currently wired — worth a follow-up ticket, not a ' +
        'money-arithmetic defect.',
    })
    test.skip(true, 'no API path reaches the exception/unlinked-line branch of the matcher')
  })

  test('MTP-PUR-13 (P1): BLOCKED — cross-company receipt reference not exercisable', async () => {
    test.info().annotations.push({
      type: 'BLOCKED',
      description:
        'demo-pharmacy-tn (tenant019fbe86-944a-7252-8a3b-8c341dfa9de9) has exactly one ' +
        'company (PharmaBio Tunisie SARL). This campaign agent was supplied only ' +
        'owner@pharmabio.tn / manager@ / cashier@ credentials scoped to that tenant — no ' +
        'second company or second-tenant login was provided, so a genuine cross-company ' +
        'receipt reference cannot be constructed to prove the exception/refusal path.',
    })
    test.skip(true, 'no second company/tenant credentials available to this agent')
  })
})

/** MTP-PUR-01 only needs a Draft PO — no receive/invoice. */
async function setupPoOnly(page: Page, opts: { quantity: string; unitPrice: string }) {
  const { id: productId } = await createProduct(page, { name: uniq('PUR01'), sku: uniq('PUR01') })
  const poRes = await createPurchaseOrder(page, {
    partnerId: supplierId,
    lines: [{ productId, quantity: opts.quantity, unitPrice: opts.unitPrice }],
  })
  expect(poRes.status, JSON.stringify(poRes.body)).toBe(201)
  return { poId: poRes.id as string, productId }
}
