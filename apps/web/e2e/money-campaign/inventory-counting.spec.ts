/**
 * MONEY TEST CAMPAIGN — wave W-4 — §D `INV` inventory COUNTING -> discrepancy report
 * -> apply. Cases MTP-INV-16..20 (`/inventory/counting/*`).
 *
 * Live local stack, tenant demo-pharmacy-tn, real login, real backend.
 *
 * FIXTURE DISCIPLINE — every counting is scoped (`scope_type: product_location`) to this
 * wave's OWN freshly-created products, so no seeded pharmacy stock is ever counted or
 * adjusted. Countings that must not apply are CANCELLED in a finally block (a counting
 * left in `pending_review` holds a stock block over its scope and would be visible to
 * every later wave's counting dashboard read).
 *
 * MECHANICS discovered while authoring (not in the plan text):
 *  - `requires_count_2` DEFAULTS TO TRUE; a single-counter count never reaches
 *    `pending_review` (and therefore never produces a report) unless the counting is
 *    created with `requires_count_2: false`.
 *  - There is no `/apply` route: the apply step is `POST /inventory/countings/{id}/finalize`,
 *    and the stock effect is applied by a QUEUED listener (redis), so post-finalize
 *    assertions poll.
 */
import { test, expect, type Page } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import { KG_UNIT_ID, OWNER_USER_ID, WAREHOUSE_LOCATION_ID, createSupplier, createStockTransfer, completeStockTransfer, SHOP1_LOCATION_ID, getStockLevels } from './w2b-support'
import {
  createW4Product,
  costPrice,
  poAndReceive,
  finalizeCounting,
  getCounting,
  cancelCounting,
  stockMovements,
  queryRows,
  addMoney,
  uniq4,
} from './w4-support'

test.describe.configure({ timeout: 240_000 })

let supplierId: string

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage()
  await loginAsRole(page, 'owner')
  supplierId = await createSupplier(page, uniq4('CNT-Supplier'))
  await page.close()
})

interface CountingSummary {
  total_items_counted: number
  items_no_variance: number
  items_with_variance: number
  variance_breakdown: Record<string, number>
  total_variance_value: { positive: string; negative: string; net: string; currency: string }
}

/** Create a single-counter counting over the given products at a location, activated. */
async function startCounting(
  page: Page,
  productIds: string[],
  locationId: string = WAREHOUSE_LOCATION_ID
): Promise<string> {
  const res = await apiRequest(page, 'POST', '/inventory/countings', {
    scope_type: 'product_location',
    scope_filters: { product_ids: productIds, location_id: locationId },
    include_zero_stock: true,
    // Default is TRUE; a second counter would leave the count stuck in
    // `count_2_in_progress` and no report would ever be produced.
    requires_count_2: false,
    count_1_user_id: OWNER_USER_ID,
  })
  if (res.status !== 201) throw new Error(`startCounting: ${res.status} ${JSON.stringify(res.body)}`)
  const id = (res.body as { data: { id: string } }).data.id
  const act = await apiRequest(page, 'POST', `/inventory/countings/${id}/activate`)
  if (act.status !== 200) throw new Error(`activate: ${act.status} ${JSON.stringify(act.body)}`)
  return id
}

async function submitCounts(page: Page, countingId: string, byProduct: Record<string, string>): Promise<void> {
  const items = await apiRequest(page, 'GET', `/inventory/countings/${countingId}/items/to-count`)
  const rows = (items.body as { data: Array<{ id: string; product: { id: string } }> }).data
  for (const row of rows) {
    const qty = byProduct[row.product.id]
    if (qty === undefined) continue
    const res = await apiRequest(page, 'POST', `/inventory/countings/${countingId}/items/${row.id}/count`, { quantity: qty })
    if (res.status !== 200) throw new Error(`count ${row.id}: ${res.status} ${JSON.stringify(res.body)}`)
  }
}

async function report(page: Page, countingId: string): Promise<{ status: number; summary: CountingSummary }> {
  const res = await apiRequest(page, 'GET', `/inventory/countings/${countingId}/report`)
  return { status: res.status, summary: (res.body as { data?: { summary: CountingSummary } }).data?.summary as CountingSummary }
}

test.describe('INV — counting -> discrepancy report -> apply', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-INV-16 (P0): variance -1.5000 on a 10.571428 basis => -15.857 (TRUNCATED toward zero)', async ({ page }) => {
    // Fractional variance needs a fractional-capable unit (Kilogram, decimal_places=3).
    const p = await createW4Product(page, 'INV16', { unitId: KG_UNIT_ID })
    expect((await poAndReceive(page, { supplierId, productId: p.id, quantity: '3', unitPrice: '10.000' })).receiveStatus).toBe(200)
    expect((await poAndReceive(page, { supplierId, productId: p.id, quantity: '4', unitPrice: '11.000' })).receiveStatus).toBe(200)
    expect(await costPrice(page, p.id), 'basis cost from INV-03').toBe('10.571428')

    const countingId = await startCounting(page, [p.id])
    let applied = false
    try {
      await submitCounts(page, countingId, { [p.id]: '5.5' })
      const counting = await getCounting(page, countingId)
      expect((counting.body as { data: { status: string } }).data.status).toBe('pending_review')

      const r = await report(page, countingId)
      expect(r.status).toBe(200)

      // -1.5000 x 10.571428 = -15.857142  ->  bcformatStrict at scale 3 truncates TOWARD
      // ZERO: -15.857. A half-up implementation would give -15.858 and over-state the
      // write-down by a millime.
      expect(r.summary.total_variance_value.negative).toBe('-15.857')
      expect(r.summary.total_variance_value.negative).not.toBe('-15.858')
      expect(r.summary.total_variance_value.positive).toBe('0.000')
      expect(r.summary.total_variance_value.net).toBe('-15.857')
      expect(r.summary.total_variance_value.currency).toBe('TND')
    } finally {
      if (!applied) {
        const c = await cancelCounting(page, countingId)
        expect(c.status, `report-only counting must be cancelled so it does not hold a stock block: ${JSON.stringify(c.body)}`).toBeLessThan(300)
      }
    }
  })

  test('MTP-INV-17 (P0): net == positive + negative EXACTLY; breakdown sums to the line count', async ({ page }) => {
    const over = await createW4Product(page, 'INV17-over')
    const under = await createW4Product(page, 'INV17-under')
    expect((await poAndReceive(page, { supplierId, productId: over.id, quantity: '10', unitPrice: '3.500' })).receiveStatus).toBe(200)
    expect((await poAndReceive(page, { supplierId, productId: under.id, quantity: '10', unitPrice: '7.250' })).receiveStatus).toBe(200)

    const countingId = await startCounting(page, [over.id, under.id])
    try {
      // +3 x 3.500 = +10.500 ; -4 x 7.250 = -29.000
      await submitCounts(page, countingId, { [over.id]: '13', [under.id]: '6' })
      const r = await report(page, countingId)
      expect(r.status).toBe(200)

      const v = r.summary.total_variance_value
      expect(v.positive).toBe('10.500')
      expect(v.negative).toBe('-29.000')
      // The identity the case exists for — computed with exact millime arithmetic,
      // never a float sum.
      expect(v.net).toBe(addMoney(v.positive, v.negative))
      expect(v.net).toBe('-18.500')

      const breakdownTotal = Object.values(r.summary.variance_breakdown).reduce((s, n) => s + n, 0)
      expect(breakdownTotal, 'resolution-method counts sum to the counted line count').toBe(r.summary.total_items_counted)
      expect(r.summary.total_items_counted).toBe(2)
      expect(r.summary.items_with_variance).toBe(2)
    } finally {
      const c = await cancelCounting(page, countingId)
      expect(c.status, 'report-only counting cancelled').toBeLessThan(300)
    }
  })

  test('MTP-INV-18 (P0) PARTIAL: a correction that would drive stock negative posts NOTHING — the pre-apply guard holds', async ({ page }) => {
    // Arrange the only deterministic web construction of the scenario: count a shortage,
    // then consume the stock BEFORE finalizing, so the recorded correction (-5) is larger
    // than what remains (1) at apply time.
    const p = await createW4Product(page, 'INV18')
    expect((await poAndReceive(page, { supplierId, productId: p.id, quantity: '7', unitPrice: '2.000' })).receiveStatus).toBe(200)

    const countingId = await startCounting(page, [p.id])
    await submitCounts(page, countingId, { [p.id]: '2' }) // counted 2 vs theoretical 7 => -5

    const r = await report(page, countingId)
    expect(r.status).toBe(200)
    expect(r.summary.total_variance_value.negative).toBe('-10.000') // -5 x 2.000

    // Consume 6 of the 7 between count and apply.
    const tr = await createStockTransfer(page, {
      sourceLocationId: WAREHOUSE_LOCATION_ID,
      destinationLocationId: SHOP1_LOCATION_ID,
      lines: [{ productId: p.id, quantity: '6' }],
    })
    expect(tr.status, JSON.stringify(tr.body)).toBe(201)
    expect((await completeStockTransfer(page, tr.id as string)).status).toBe(200)

    const fin = await finalizeCounting(page, countingId)
    // A real assertion, not `toBeDefined()`: on this path finalize itself SUCCEEDS — the
    // guard lives in the apply, not in the transition.
    expect(fin.status, `finalize: ${fin.status} ${JSON.stringify(fin.body)}`).toBeLessThan(400)

    // The apply runs in ApplyStockAdjustmentsOnCountingCompleted, which is ShouldQueue —
    // a fixed sleep would let this case "pass" under load having tested nothing. Poll for
    // a signal that the apply actually RAN, and FAIL if neither terminal signal arrives:
    //   (a) the item carries a blocking flag  -> the apply ran and held the line, OR
    //   (b) an `adjustment` movement exists   -> the apply ran and posted.
    // "Neither" means the worker never processed the job, which must be a failure.
    // The flag is read from the item row: the report's `flagged_items` surfaces the item
    // as soon as `is_flagged` flips but was observed carrying a null `flag_reasons`
    // moments later, so the row is the definitive source (plan §3 evidence rule).
    const flagReasons = (): string[] => {
      const rows = queryRows(
        `select coalesce(i.flag_reasons::text, '') from inventory_counting_items i where i.counting_id = '${countingId}'`
      )
      return rows.flatMap((row) => {
        try {
          return JSON.parse(row[0] || '[]') as string[]
        } catch {
          return []
        }
      })
    }
    const adjustmentPosted = async (): Promise<boolean> => {
      const mv = await stockMovements(page, `?product_id=${p.id}`)
      return ((mv.body as { data?: Array<{ movement_type: string }> }).data ?? []).some((m) => m.movement_type === 'adjustment')
    }

    await expect
      .poll(async () => flagReasons().length > 0 || (await adjustmentPosted()), {
        message:
          'the queued apply never produced a terminal signal (no blocking flag, no adjustment movement) — ' +
          'the job did not run, so this case proved nothing',
        timeout: 90_000,
        intervals: [1000, 2000, 3000, 5000],
      })
      .toBe(true)

    const reasons = flagReasons()
    const posted = await adjustmentPosted()

    // THE MONEY INVARIANT — nothing was posted, and no location is negative.
    expect(posted, 'a blocked line posts no movement — nothing was applied').toBe(false)
    const levels = await getStockLevels(page, p.id)
    for (const l of levels) {
      expect(Number(l.quantity), `location ${l.location_id} must not be negative`).toBeGreaterThanOrEqual(0)
    }
    const wh = levels.find((l) => l.location_id === WAREHOUSE_LOCATION_ID)
    expect(wh === undefined ? 0 : Number(wh.quantity), 'the warehouse row is untouched at the 1 unit the transfer left').toBe(1)
    expect(reasons.length, 'the line is held for manual review, not silently dropped').toBeGreaterThanOrEqual(1)

    // PARTIAL — which guard fired, disclosed rather than glossed.
    //
    // The plan names the `negative_at_apply` arm
    // (ApplyStockAdjustmentsOnCountingCompleted.php:259-262). That arm is SHADOWED on
    // every construction this surface allows: `applyReplay()` runs the basket-window
    // pre-check FIRST (:224-230), and making the correction exceed the remaining stock
    // REQUIRES moving stock between count and apply — which is exactly what the
    // basket-window guard detects. Verified live: with the default 15-minute window AND
    // with `ambiguity_window_minutes: 0`, the recorded reason is `basket_window` both
    // times. Both arms have the same money contract (nothing posted, line flagged for
    // review, stock never negative), which is what is asserted above.
    expect(reasons, 'the pre-apply guard is what holds the line on this construction').toContain('basket_window')
    test.info().annotations.push({
      type: 'PARTIAL',
      description:
        'The specific `negative_at_apply` flag arm is unreachable from the web surface: the basket-window ' +
        'pre-check (ApplyStockAdjustmentsOnCountingCompleted.php:224-230) fires first for any scenario in ' +
        'which stock moves between count and apply, and that movement is what makes the correction exceed ' +
        'remaining stock in the first place. Confirmed with ambiguity_window_minutes 15 and 0. The money ' +
        'contract (nothing posted, no negative stock, line held for review) IS asserted.',
    })
  })

  test('MTP-INV-19 (P1): BLOCKED — onboarding opening via counting is not reachable on a bounded scope', async () => {
    // The onboarding/opening arm of the counting apply
    // (InventoryCountingService::assertOpeningCostsResolved(), called :1040 / defined :1187,
    // + the `openingUnitCost`
    // blend) requires the counting to SEED an item for a product that has no
    // `stock_levels` row yet. Only the whole-location seeding path does that:
    // `catalogItemSeeds()` (InventoryCountingService.php:451-495) is reached exclusively
    // for `full_inventory` / `location` scope types, and `resolveIncludesZeroStock()`
    // (:195-210) documents that "only whole-location scopes qualify". A
    // `product_location` scope — the only bounded scope this campaign may safely use —
    // seeds from `stock_levels` alone, so a zero-stock product yields ZERO items and the
    // count cannot be entered. Verified live: a fresh product in a freshly created
    // `onboarding_mode: true` location produced `items/to-count` = [].
    //
    // The alternative (`scope_type: location` on the onboarding location) seeds the
    // CARTESIAN of every active company product x that location — on demo-pharmacy-tn
    // that is a tenant-wide counting whose finalize would post an opening movement for
    // every product in the catalog. That is exactly the kind of tenant-wide mutation the
    // wave brief forbids, and it would poison W-6's GL reads.
    //
    // The other creation path that CAN add an arbitrary product
    // (`POST /inventory/countings/{id}/add-product`) refuses anything that is not a
    // DRAFT counting (InventoryCountingController.php:752-756), and the draft flow is a
    // separate endpoint family with its own activation semantics.
    //
    // UNBLOCKED BY: an isolated tenant (campaign debt C-1/C-4) where a whole-location
    // onboarding count is safe, OR a bounded onboarding seeding path.
    test.info().annotations.push({
      type: 'BLOCKED',
      description:
        'Onboarding opening-balance-by-counting needs whole-location (catalog cartesian) seeding; the only ' +
        'bounded scope (product_location) seeds from stock_levels and yields no item for a zero-stock ' +
        'product. Whole-location scope on this shared tenant would post an opening for the entire catalog.',
    })
    test.skip(true, 'no bounded web path to an onboarding opening count on a shared tenant')
  })

  test('MTP-INV-20 (P2): a zero-discrepancy count reports 0.000 everywhere, no blanks, no NaN', async ({ page }) => {
    const p = await createW4Product(page, 'INV20')
    expect((await poAndReceive(page, { supplierId, productId: p.id, quantity: '8', unitPrice: '1.750' })).receiveStatus).toBe(200)

    const countingId = await startCounting(page, [p.id])
    try {
      await submitCounts(page, countingId, { [p.id]: '8' }) // exact match
      const r = await report(page, countingId)
      expect(r.status).toBe(200)

      const v = r.summary.total_variance_value
      expect(v.positive).toBe('0.000')
      expect(v.negative).toBe('0.000')
      expect(v.net).toBe('0.000')
      for (const s of [v.positive, v.negative, v.net]) {
        // Not blank, not 'NaN', not '0' — a real, currency-scaled decimal string.
        expect(s).toMatch(/^-?\d+\.\d{3}$/)
      }
      expect(r.summary.items_with_variance).toBe(0)
      expect(r.summary.items_no_variance).toBe(1)
    } finally {
      const c = await cancelCounting(page, countingId)
      expect(c.status, 'report-only counting cancelled').toBeLessThan(300)
    }
  })
})
