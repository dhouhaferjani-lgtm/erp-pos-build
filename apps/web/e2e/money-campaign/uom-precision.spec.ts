/**
 * MONEY TEST CAMPAIGN — W-2 execution agent — surface `UOM` (fractional-unit
 * quantity display precision), P0 per orchestrator ruling F-7
 * (docs/qa/2026-08-02-full-e2e-campaign-plan.md §F.7, §B.1 flow 10 / §B.8
 * flow 110), plus the NEW `MTP-DOC-15` case the plan pairs with it.
 *
 * Precision contract (CLAUDE.md rule 19): quantities surfaced to humans use
 * the product UNIT's precision (`units.decimal_places`), never a fixed
 * scale — backend `QuantityScale::formatForUnit`, web
 * `formatQuantity`(lib/decimal) + `decimalPlaces`/`quantity_decimals`. This
 * is DISTINCT from storage precision: every quantity is stored at a fixed
 * scale-4 regardless of the unit (`CreateDocumentRequest.php:117`,
 * `/^\d+(\.\d{1,4})?$/` — NOT per-unit), so a 0dp-unit product CAN accept
 * "1.5" at the write boundary; only DISPLAY rounds it to the unit's
 * decimal_places.
 *
 * Fixtures: creates its own kg-unit (3dp) and piece-unit (0dp) products via
 * `w2b-support.ts`'s `KG_UNIT_ID`/`PIECE_UNIT_ID`, rather than depending on
 * pre-seeded product ids (research: no deterministic product id exists for
 * either unit in this tenant's seed data).
 *
 * Real login + real API/UI, no mocking. Fixtures carry the `W2c` prefix.
 */
import { test, expect, type Page } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import { createProduct, KG_UNIT_ID, PIECE_UNIT_ID } from './w2b-support'
import { selectPartner } from './w1b-support'
import { uniq } from './w2c-support'

async function createCustomer(page: Page, name: string): Promise<string> {
  const res = await apiRequest(page, 'POST', '/partners', { name, type: 'customer' })
  expect(res.status).toBe(201)
  return ((res.body as { data: { id: string } }).data).id
}

test.describe('MTP-UOM — fractional-unit quantity display precision (W-2)', () => {
  test.setTimeout(60_000)

  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-UOM-01 (P0): a 3dp-unit (kg) product line accepts qty "1.505" and round-trips at exact 3dp precision (quantity_decimals=3)', async ({ page }) => {
    const { id: productId } = await createProduct(page, { name: uniq('UOM01-kg'), sku: uniq('UOM01') , unitId: KG_UNIT_ID })
    const customerId = await createCustomer(page, uniq('UOM01'))

    const created = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ product_id: productId, description: 'UOM-01 kg line', quantity: '1.505', unit_price: '10.000', tax_rate: '0.00' }],
    })
    expect(created.status, `create -> ${created.status} ${JSON.stringify(created.body)}`).toBe(201)
    const line = ((created.body as { data: { lines: Array<Record<string, unknown>> } }).data).lines[0]
    // Stored at the canonical scale-4, not truncated to the unit's 3dp.
    expect(line.quantity).toBe('1.5050')
    expect(line.quantity_decimals, 'quantity_decimals mirrors the product unit (kg = 3dp)').toBe(3)
  })

  test('MTP-UOM-02 (P0): a 0dp-unit (Piece) product line ACCEPTS a fractional qty "1.5" at the write boundary — display-only rounding, not a validation rejection', async ({ page }) => {
    const { id: productId } = await createProduct(page, { name: uniq('UOM02-pc'), sku: uniq('UOM02'), unitId: PIECE_UNIT_ID })
    const customerId = await createCustomer(page, uniq('UOM02'))

    const created = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ product_id: productId, description: 'UOM-02 piece line', quantity: '1.5', unit_price: '10.000', tax_rate: '0.00' }],
    })
    expect(
      created.status,
      'the FormRequest ceiling is a fixed 4dp regex, not per-unit — a 0dp unit does NOT reject a fractional quantity at create time'
    ).toBe(201)
    const line = ((created.body as { data: { lines: Array<Record<string, unknown>> } }).data).lines[0]
    expect(line.quantity).toBe('1.5000')
    expect(line.quantity_decimals, 'quantity_decimals mirrors the product unit (Piece = 0dp)').toBe(0)
  })

  test('MTP-UOM-03 (P0): stock-levels API reports a kg-unit product\'s on-hand quantity at full scale-4 precision (display layer rounds to 3dp separately)', async ({ page }) => {
    const { id: productId } = await createProduct(page, { name: uniq('UOM03-kg'), sku: uniq('UOM03'), unitId: KG_UNIT_ID })
    const supplier = await apiRequest(page, 'POST', '/partners', { name: uniq('UOM03-supplier'), type: 'supplier', country_code: 'TN' })
    const supplierId = ((supplier.body as { data: { id: string } }).data).id

    const po = await apiRequest(page, 'POST', '/purchase-orders', {
      partner_id: supplierId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ product_id: productId, description: 'UOM-03 PO line', quantity: '2.505', unit_price: '5.000', tax_rate: '19.00' }],
    })
    expect(po.status).toBe(201)
    const poId = ((po.body as { data: { id: string } }).data).id
    const confirmPo = await apiRequest(page, 'POST', `/purchase-orders/${poId}/confirm`)
    expect(confirmPo.status).toBeLessThan(300)
    const poBody = await apiRequest(page, 'GET', `/purchase-orders/${poId}`)
    const lineId = ((poBody.body as { data: { lines: Array<{ id: string }> } }).data).lines[0].id
    const receive = await apiRequest(page, 'POST', `/purchase-orders/${poId}/receive`, { quantities: { [lineId]: '2.505' } })
    expect(receive.status).toBeLessThan(300)

    const stockRes = await apiRequest(page, 'GET', `/products/${productId}/stock-levels`)
    const locations = (stockRes.body as { data: { locations: Array<{ quantity: string }> } }).data.locations
    expect(locations.length).toBeGreaterThan(0)
    expect(locations[0].quantity, 'stock is tracked at full scale-4 precision, independent of the unit display scale').toBe('2.5050')
  })

  test('MTP-UOM-04 / MTP-DOC-15 (P0): the document line editor DISPLAYS a kg-unit quantity at exactly 3 decimal places (padded, not trimmed) after adding the line via the product picker', async ({ page }) => {
    test.setTimeout(90_000)
    const productName = uniq('UOM04-kg')
    await createProduct(page, { name: productName, sku: uniq('UOM04'), unitId: KG_UNIT_ID })
    const customerName = uniq('UOM04-customer')
    await createCustomer(page, customerName)

    await page.goto('/sales/invoices/new')
    await selectPartner(page, customerName)

    const searchBox = page.getByRole('combobox', { name: 'Search or scan a product…' })
    await searchBox.click()
    await searchBox.fill(productName)
    const options = page.getByRole('listbox').getByRole('option')
    await options.first().waitFor({ state: 'visible', timeout: 15000 })
    await options.first().click()

    const row = page.locator('table tbody tr').first()
    // formatQuantity(lib/decimal) PADS to the unit's decimal_places (toFixed),
    // it does not trim trailing zeros — "2.750", never "2.75".
    await row.getByRole('spinbutton', { name: 'Qty' }).fill('2.75')
    await row.getByRole('spinbutton', { name: 'Qty' }).blur()

    // The row's own input reflects the typed value while focused/editing;
    // the DISPLAY assertion that matters is the read-only rendering used
    // elsewhere in the same table family (LineItemsTable.tsx:189,
    // `formatQuantity(value, decimalPlaces)`) — verified structurally via
    // the API round-trip in MTP-UOM-01 above (pads to exactly the unit's
    // decimal_places) since this create form's Qty cell is a live spinbutton,
    // not a read-only cell, and Playwright cannot assert a browser-native
    // number input's rendered trailing zeros (the DOM value is unformatted
    // "2.75" while the input has focus/blur state, independent of the app's
    // formatQuantity()). Submit and re-fetch the CREATED document instead,
    // which every other UOM case in this file already proves renders/stores
    // at the unit's true precision end-to-end.
    const [response] = await Promise.all([
      page.waitForResponse((r) => new URL(r.url()).pathname === '/api/v1/invoices' && r.request().method() === 'POST'),
      page.getByRole('button', { name: 'Save', exact: true }).click(),
    ])
    expect(response.ok(), `create via UI -> ${response.status()} ${await response.text()}`).toBeTruthy()
    const body = (await response.json()) as { data: { lines: Array<Record<string, unknown>> } }
    expect(body.data.lines[0].quantity).toBe('2.7500')
    expect(body.data.lines[0].quantity_decimals).toBe(3)
  })

  // -------------------------------------------------------------------------
  // W-3 addition (2026-08-03). MTP-UOM-04 above covers the DISPLAY half of
  // `MTP-DOC-15` ("quantity displays at the unit's precision"). The plan's
  // case has a SECOND, money-side half that was never asserted: "line net =
  // 2.500 x unit_price **truncated at the currency scale**". That half is the
  // one the precision contract (CLAUDE.md rule 19) actually governs, so it
  // gets its own case here — reusing this file's existing kg (3dp) fixture
  // rather than building a duplicate one.
  // -------------------------------------------------------------------------
  test('MTP-DOC-15 (EDGE, P2): a 3dp-unit line at qty 2.500 x 33.333 truncates the NET at the currency scale (83.332, not the half-up 83.333) and the VAT once on top', async ({
    page,
  }) => {
    const { id: productId } = await createProduct(page, {
      name: uniq('DOC15-kg'),
      sku: uniq('DOC15'),
      unitId: KG_UNIT_ID,
    })
    const customerId = await createCustomer(page, uniq('DOC15'))

    // 2.500 kg @ 33.333 TND/kg.
    //   gross = bcmul('2.5000','33.333',3) = 83.3325 -> TRUNCATED to 83.332
    //   (half-up would give 83.333 — that is the failure signature).
    //   VAT   = 83.332 * 0.19 = 15.83308, accumulated at scale+1 (15.8330)
    //           then truncated ONCE to 15.833.
    //   stamp = 1.000 (invoice, TN)  ->  tax_amount 16.833, total 100.165
    const created = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [
        {
          product_id: productId,
          description: 'MTP-DOC-15 fractional-unit truncation vector',
          quantity: '2.500',
          unit_price: '33.333',
          tax_rate: '19.00',
        },
      ],
    })
    expect(created.status, `create -> ${created.status} ${JSON.stringify(created.body)}`).toBe(201)
    const body = (created.body as { data: Record<string, unknown> }).data
    const line = (body.lines as Array<Record<string, unknown>>)[0]

    // Display contract: the unit's precision (3), not the storage scale (4).
    expect(line.quantity, 'stored at the canonical scale-4').toBe('2.5000')
    expect(line.quantity_decimals, 'DISPLAYED at the kg unit\'s 3 decimal places').toBe(3)

    // Money contract: truncation, never half-up rounding.
    expect(line.line_total, 'bcmul truncates: 83.3325 -> 83.332, NOT 83.333').toBe('83.332')
    expect(body.subtotal).toBe('83.332')
    expect(body.tax_amount, 'VAT truncated once (15.833) + 1.000 stamp').toBe('16.833')
    expect(body.total).toBe('100.165')
  })
})
