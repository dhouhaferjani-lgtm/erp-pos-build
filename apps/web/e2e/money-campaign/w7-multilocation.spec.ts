/**
 * MONEY TEST CAMPAIGN — wave W-7 — `MTP-MLC-01..08`: multi-location money
 * scoping (money-test-plan §I, campaign plan §B.8 row 105).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real
 * login through the real form, real backend, no mocks.
 *
 * ── WHAT IS AND IS NOT REACHABLE FROM THE WEB ──────────────────────────────
 * Plan §B.8 row 105 calls this surface "unblockable except the POS-data half".
 * That is now only PARTLY true and the difference matters:
 *
 *   * `pos_receipts` DO exist on this tenant (authored as a side effect of
 *     C-2's `authorTier4CardFiscalSale` card-settlement fixture, not by any
 *     seeder). So the per-location revenue leg of MLC-01/02/03 IS assertable
 *     today, and this spec asserts it. Their COUNT and their LOCATIONS are not
 *     fixture constants: that fixture clones whichever terminal happens to sort
 *     first, so it has landed receipts at STORE-TUN1, STORE-TUN2 and — since
 *     2026-08-05 — the WH-01 warehouse. Every case below derives its subject
 *     from a live read rather than naming one.
 *   * What remains DEVICE-BLOCKED is authoring NEW POS money per location: no
 *     web path opens a shift, rings a sale, or closes a Z. Every case below
 *     therefore reads the POS figures rather than authoring them, and the
 *     "author a sale at shop A and watch it appear only under shop A's scope"
 *     half of MLC-01..04 is recorded BLOCKED -> §Z in the wave report.
 *
 * ── DELIBERATELY READ-ONLY ────────────────────────────────────────────────
 * This file MUTATES NOTHING. It sets the persisted view scope in
 * localStorage (a per-user UI preference, restored at the end of each case)
 * and reads reports. It asserts no absolute money constant — every figure is
 * compared against the SAME endpoint read in the SAME run — so the documented
 * W-4/W-5/W-6 fixture noise on this tenant cannot make it flake.
 */
import { test, expect } from '@playwright/test'
import { apiRequest } from './helpers'
import { type Session } from './treasury-support'
import {
  LOCATION_CODES,
  PINNED_CASHIERS,
  applyViewScope,
  asSession,
  digitsOf,
  listLocations,
  loginAs,
  loginAsRoleResilient,
  loginPageAs,
  loginResilient,
  settleAfterNav,
  sumMoney,
  toScale3,
  type LocationRow,
} from './w7-support'

interface SalesByLocationRow {
  period: string
  location_id: string
  location_name: string
  gross_sales: string
  receipt_count: number
}

const YEAR_FROM = '2026-01-01'
const YEAR_TO = '2026-12-31'

test.describe('MLC — multi-location money scoping', () => {
  test.setTimeout(150_000)

  let owner: Session
  let locations: LocationRow[]
  let tunis1: string
  let tunis2: string
  let warehouse: string

  test.beforeAll(async ({ request }) => {
    owner = await loginResilient(request, 'owner')
    locations = await listLocations(request, owner)
    expect(locations.length, 'the tenant is genuinely multi-location').toBeGreaterThan(1)
    tunis1 = locations.find((l) => l.code === LOCATION_CODES.tunis1)!.id
    tunis2 = locations.find((l) => l.code === LOCATION_CODES.tunis2)!.id
    warehouse = locations.find((l) => l.code === LOCATION_CODES.warehouse)!.id
    expect(tunis1 && tunis2 && warehouse, 'the three fixture locations resolve by code').toBeTruthy()
  })

  test('MTP-MLC-01 (P0): Σ per-location revenue == the all-locations total, exactly', async ({ page }) => {
    await loginAsRoleResilient(page, 'owner')

    const allRes = await apiRequest(
      page,
      'GET',
      `/reports/sales/by-location?from=${YEAR_FROM}&to=${YEAR_TO}`,
    )
    expect(allRes.status, 'GET /reports/sales/by-location (all locations)').toBe(200)
    const all = ((allRes.body as { data?: SalesByLocationRow[] }).data ?? [])
    expect(
      all.length,
      'POS revenue exists on this tenant to scope (4 receipts across 2 shops — see the file header)',
    ).toBeGreaterThan(0)

    // The report is a per-(period, location) breakdown, so "the all-locations
    // total" is Σ of its own rows. Re-read it PER LOCATION and prove the two
    // decompositions agree to the millime.
    const distinctLocations = [...new Set(all.map((r) => r.location_id))]
    const perLocationTotals: string[] = []
    for (const locationId of distinctLocations) {
      const res = await apiRequest(
        page,
        'GET',
        `/reports/sales/by-location?from=${YEAR_FROM}&to=${YEAR_TO}&location_ids[]=${locationId}`,
      )
      expect(res.status, `scoped read for ${locationId}`).toBe(200)
      const rows = (res.body as { data?: SalesByLocationRow[] }).data ?? []
      expect(
        rows.every((r) => r.location_id === locationId),
        'a single-location scope returns ONLY that location — never another shop`s money',
      ).toBe(true)
      perLocationTotals.push(sumMoney(rows.map((r) => toScale3(r.gross_sales))))
    }

    expect(
      sumMoney(perLocationTotals),
      'Σ per-location revenue == the all-locations total, exactly at scale 3',
    ).toBe(sumMoney(all.map((r) => toScale3(r.gross_sales))))

    // ── W-7 F-2 — FIXED (fix lane L4) ────────────────────────────────────
    // Every money figure this endpoint family emits goes through
    // `FormatsReportNumbers::decimalString()`
    // (app/Modules/Accounting/Application/Services/Reports/
    // FormatsReportNumbers.php), which used to do THREE things wrong for a TND
    // company: it cast to `(float)` (CLAUDE.md rule 19 — never a float on
    // money), it formatted at a hardcoded `scale = 2` (TND is scale 3, so a
    // millime was truncated away), and it then `rtrim`med the trailing zeros
    // and the decimal point. The DB held `300.000` and `181.100`; the API
    // answered `"300"` and `"181.1"`. It affected sales-by-location, top-SKUs,
    // revenue-by-category and the payment-method breakdown — i.e. every tile on
    // the owner dashboard — plus the cash-count reconciliation variance on the
    // Z/EOD surface.
    //
    // The scale is now resolved per report from the company currency
    // (`CurrencyScaleResolverInterface`), the value never touches a float, and
    // nothing is trimmed. This assertion is the regression net.
    const raw = all.map((r) => r.gross_sales)
    const offenders = raw.filter((v) => !/^-?\d+\.\d{3}$/.test(v))
    expect(
      offenders,
      `F-2 FIXED: every gross_sales is emitted at the TND scale of 3 (offenders: ${offenders.join(', ')})`,
    ).toEqual([])
  })

  test('MTP-MLC-02 (P0): scoping to one shop re-queries every money tile and the other shops` figures are ABSENT', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')

    const unscoped = await apiRequest(
      page,
      'GET',
      `/reports/sales/by-location?from=${YEAR_FROM}&to=${YEAR_TO}`,
    )
    const unscopedRows = (unscoped.body as { data?: SalesByLocationRow[] }).data ?? []
    const otherShopRows = unscopedRows.filter((r) => r.location_id !== tunis1)
    expect(
      otherShopRows.length,
      'another shop really does carry revenue, so "absent" is a meaningful assertion',
    ).toBeGreaterThan(0)

    // API layer: scope to Tunis Lac only.
    const scoped = await apiRequest(
      page,
      'GET',
      `/reports/sales/by-location?from=${YEAR_FROM}&to=${YEAR_TO}&location_ids[]=${tunis1}`,
    )
    expect(scoped.status).toBe(200)
    const scopedRows = (scoped.body as { data?: SalesByLocationRow[] }).data ?? []
    expect(scopedRows.every((r) => r.location_id === tunis1), 'only Tunis Lac rows').toBe(true)

    // UI layer. SUBSTITUTED SURFACE, recorded not silently swapped: the
    // plan names `/reports`, but the owner dashboard defaults to TODAY and
    // this tenant's POS receipts are 1-2 days old, so the money tiles render
    // "No data for this period" — and widening the range makes the page
    // unusable as a fixture because its tiles carry `refetchInterval: 60000`
    // (useOwnerReports.ts:34-36) and sit in a "Loading report…" state on and
    // off indefinitely. `/inventory/stock-by-location` is the deterministic
    // location-scoped surface on this tenant: it renders ONE COLUMN PER
    // IN-SCOPE LOCATION, so "the other shop is absent, not merely hidden" is
    // directly observable. The money half of MLC-02 is asserted at the API
    // layer above.
    await applyViewScope(page, [tunis1])
    await page.goto('/inventory/stock-by-location')
    await settleAfterNav(page)
    const body = await page.locator('main').innerText()

    const scopedLocation = locations.find((l) => l.id === tunis1)!
    expect(body, 'the in-scope shop renders').toContain(scopedLocation.name)
    for (const other of locations.filter((l) => l.id !== tunis1)) {
      expect(
        body,
        `MLC-02: ${other.code} must be ABSENT under a Tunis-Lac-only scope, not merely hidden`,
      ).not.toContain(other.name)
    }

    await applyViewScope(page, 'all')
  })

  test('MTP-MLC-03 (P0): two single-shop scopes are disjoint and sum to the two-shop subtotal (no stale cache)', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')

    const readScoped = async (locationIds: string[]): Promise<SalesByLocationRow[]> => {
      const query = locationIds.map((id) => `&location_ids[]=${id}`).join('')
      const res = await apiRequest(
        page,
        'GET',
        `/reports/sales/by-location?from=${YEAR_FROM}&to=${YEAR_TO}${query}`,
      )
      expect(res.status).toBe(200)
      return (res.body as { data?: SalesByLocationRow[] }).data ?? []
    }

    const first = await readScoped([tunis1])
    const second = await readScoped([tunis2])
    const both = await readScoped([tunis1, tunis2])

    // Disjoint.
    expect(
      first.every((r) => r.location_id === tunis1) && second.every((r) => r.location_id === tunis2),
      'the two scoped result sets are disjoint',
    ).toBe(true)

    // …and additive, exactly.
    expect(
      sumMoney([
        sumMoney(first.map((r) => toScale3(r.gross_sales))),
        sumMoney(second.map((r) => toScale3(r.gross_sales))),
      ]),
      'Σ of the two single-shop scopes == the two-shop subtotal',
    ).toBe(sumMoney(both.map((r) => toScale3(r.gross_sales))))

    // The key-scoping half. Every scoped hook builds its TanStack key with
    // `locationScopedKey(..., scope)` (e.g. `useOwnerReports.ts:50`,
    // `useAgedReceivables.ts:16`), so switching scope must produce a
    // DIFFERENT cache key and a fresh fetch. Driven on
    // `/inventory/stock-by-location` for the reason recorded in MLC-02: shop
    // 1's COLUMN must be gone after switching to shop 2. A surviving column
    // is the `locationScopedKey` defect the plan names.
    const shop1 = locations.find((l) => l.id === tunis1)!
    const shop2 = locations.find((l) => l.id === tunis2)!

    await applyViewScope(page, [tunis1])
    await page.goto('/inventory/stock-by-location')
    await settleAfterNav(page)
    const firstBody = await page.locator('main').innerText()
    expect(firstBody, 'scope 1 renders shop 1').toContain(shop1.name)

    await applyViewScope(page, [tunis2])
    await page.goto('/inventory/stock-by-location')
    await settleAfterNav(page)
    const secondBody = await page.locator('main').innerText()
    expect(secondBody, 'scope 2 renders shop 2').toContain(shop2.name)
    expect(
      secondBody,
      'MLC-03: after switching scope, shop 1 must NOT survive in the rendered page (stale cache = key-scoping defect)',
    ).not.toContain(shop1.name)

    await applyViewScope(page, 'all')
  })

  test('MTP-MLC-04 (P0): a shop-pinned cashier sees only their own shop, and another shop`s id is refused', async ({
    request,
    page,
  }) => {
    const pinned = asSession(await loginAs(request, PINNED_CASHIERS.tunis1))

    // 1) The grant itself: `GET /company/locations` is the list the UI's scope
    //    picker is built from, and for a pinned principal it is exactly one
    //    shop.
    const granted = await request.get('http://127.0.0.1:8010/api/v1/company/locations', {
      headers: { Authorization: `Bearer ${pinned.token}`, Accept: 'application/json' },
    })
    expect(granted.status()).toBe(200)
    const grantedRows = (await granted.json()).data as Array<{ id: string; code: string }>
    expect(grantedRows.map((l) => l.code), 'the pinned cashier is granted exactly one shop').toEqual([
      LOCATION_CODES.tunis1,
    ])

    // 2) An UNSCOPED money-adjacent read is auto-clamped to the grant — the
    //    server does not need the client to ask nicely.
    const unscoped = await request.get('http://127.0.0.1:8010/api/v1/stock-levels', {
      headers: { Authorization: `Bearer ${pinned.token}`, Accept: 'application/json' },
    })
    expect(unscoped.status()).toBe(200)
    const stockRows = (await unscoped.json()).data as Array<{ location_id: string }>
    expect(stockRows.length, 'the pinned cashier sees stock').toBeGreaterThan(0)
    expect(
      stockRows.every((r) => r.location_id === tunis1),
      'an UNSCOPED read returns ONLY the granted shop — never the whole company',
    ).toBe(true)

    // 3) Asking for someone else's shop is REFUSED, not silently answered.
    for (const path of [
      `/stock-levels?location_id=${tunis2}`,
      `/inventory/stock-matrix?location_ids[]=${tunis2}`,
    ]) {
      const res = await request.get(`http://127.0.0.1:8010/api/v1${path}`, {
        headers: { Authorization: `Bearer ${pinned.token}`, Accept: 'application/json' },
      })
      expect(res.status(), `${path} for a non-granted location -> 403`).toBe(403)
      expect(
        await res.text(),
        'the refusal body carries no other-shop money',
      ).not.toMatch(/\d+\.\d{2,4}/)
    }

    // 4) The owner money reports are closed to this principal outright
    //    (`dashboard.owner`), so there is no path by which they reach another
    //    shop's revenue.
    const ownerReport = await request.get(
      `http://127.0.0.1:8010/api/v1/reports/sales/by-location?from=${YEAR_FROM}&to=${YEAR_TO}&location_ids[]=${tunis2}`,
      { headers: { Authorization: `Bearer ${pinned.token}`, Accept: 'application/json' } },
    )
    expect(ownerReport.status(), 'owner sales reports are dashboard.owner-gated').toBe(403)

    // 5) UI: the same principal, through the real login form, lands on a
    //    dashboard that offers no other-shop figure.
    await loginPageAs(page, PINNED_CASHIERS.tunis1)
    await page.goto('/reports')
    await settleAfterNav(page)
    expect(page.url(), '/reports is dashboard.owner-gated for a cashier').toContain('/dashboard')
    const shop2Name = locations.find((l) => l.id === tunis2)!.name
    expect(
      await page.locator('body').innerText(),
      'no other-shop name leaks onto the pinned cashier`s landing page',
    ).not.toContain(shop2Name)
  })

  test('MTP-MLC-05 (P1): a persisted scope naming a location the principal cannot see is CLAMPED, not 403-ed', async ({
    page,
  }) => {
    // The plan's literal setup ("admin revokes that location grant") mutates a
    // membership on shared infra. The SAME condition — a persisted scope
    // containing an id outside `allowedIds` — is reproduced without touching
    // anyone's grant by persisting a scope for a location the principal was
    // never granted. `useViewScope` (features/locations/hooks/
    // useViewScope.ts:16-24) is the clamp under test and it cannot tell the
    // two origins apart.
    await loginPageAs(page, PINNED_CASHIERS.tunis1)

    // Persist a scope naming ANOTHER shop (never granted to this cashier).
    await applyViewScope(page, [tunis2])
    await page.goto('/inventory/stock-by-location')
    await settleAfterNav(page)

    // No error page, no 403 screen, no spinner-forever.
    const body = await page.locator('body').innerText()
    expect(body, 'no permission-denied screen').not.toMatch(/permission denied/i)
    expect(body, 'no raw error surfaced').not.toMatch(/\b(403|forbidden|something went wrong)\b/i)
    expect(page.url(), 'the page itself still renders (inventory.view is held)').toContain(
      '/inventory/stock-by-location',
    )

    // …and the stale id is gone from the persisted scope: `useViewScope`
    // rewrites it (to 'all' when the clamp empties the list, which for a
    // single-grant principal is the same thing).
    const persisted = await page.evaluate(() => {
      const entries: Record<string, string> = {}
      for (let i = 0; i < localStorage.length; i += 1) {
        const key = localStorage.key(i)
        if (key !== null && key.startsWith('autoerp-view-scope:')) entries[key] = localStorage.getItem(key) ?? ''
      }
      return entries
    })
    const values = Object.values(persisted)
    expect(values.length, 'a view scope is persisted for this principal').toBeGreaterThan(0)
    expect(
      values.some((v) => v.includes(tunis2)),
      'MLC-05: the un-grantable location id must be CLAMPED out of the persisted scope',
    ).toBe(false)
  })

  test('MTP-MLC-06 (P1): a non-POS warehouse scope yields zero POS revenue and a clean empty state', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')

    // FIXTURE PREMISE, REPAIRED (2026-08-06). This case used to hardcode
    // `warehouse` (WH-01) and assert it "has never had a receipt". That premise
    // DIED: `statement-support.ts` `authorTier4CardFiscalSale()` picks
    // `terminals.find((t) => t.is_active)` — the first active terminal in list
    // order, with NO location predicate — and clones its `location_id` onto the
    // dedicated terminal it then rings a real SALE_RECEIPT on. Once the
    // warehouse-resident `VADMIN` terminal sorted first, that fixture started
    // minting POS receipts AT THE WAREHOUSE (`FE-C2-001d096a-2026-00000001`,
    // `100.000`, 2026-08-05). The earlier C-2 terminals all landed at
    // STORE-TUN1, so this case's long green was ORDER-LUCK, not a property of
    // the tenant. See docs/superpowers/tickets/2026-08-06-c2-fixture-terminal-location.md.
    //
    // So resolve the subject from the DATA instead of naming it: any location
    // with zero POS revenue in the window, preferring a non-POS one (the
    // sharper subject — a warehouse *cannot* legitimately hold POS money).
    // Skip with a reason rather than fail if the tenant has no quiet location
    // left: that is a fixture fact about the stack, not a product defect.
    const unscoped = await apiRequest(
      page,
      'GET',
      `/reports/sales/by-location?from=${YEAR_FROM}&to=${YEAR_TO}`,
    )
    expect(unscoped.status, 'the unscoped report reads').toBe(200)
    const unscopedRows = (unscoped.body as { data?: SalesByLocationRow[] }).data ?? []
    // NON-VACUITY GUARD: the zero-rows assertion below only means something if
    // the report is capable of returning rows at all in this window.
    expect(
      unscopedRows.length,
      'the window holds POS revenue somewhere, so an empty scoped read is a real filter result',
    ).toBeGreaterThan(0)

    const noisy = new Set(unscopedRows.map((r) => r.location_id))
    const quiet = locations.filter((l) => !noisy.has(l.id))
    const subject = quiet.find((l) => l.type !== 'shop') ?? quiet[0]
    test.skip(
      subject === undefined,
      'every location on this tenant now carries POS revenue in the window — no zero-revenue '
        + 'scope left to assert against (fixture state, not a product defect)',
    )

    // API: a location with no POS receipts is a valid scope that returns nothing.
    const res = await apiRequest(
      page,
      'GET',
      `/reports/sales/by-location?from=${YEAR_FROM}&to=${YEAR_TO}&location_ids[]=${subject!.id}`,
    )
    expect(res.status, 'a zero-revenue scope is a valid query, not an error').toBe(200)
    const rows = (res.body as { data?: SalesByLocationRow[] }).data ?? []
    expect(
      rows.length,
      `zero POS revenue rows for ${subject!.code} (${subject!.type}) — and NOT the `
        + `${unscopedRows.length}-row unscoped dump, which is what an ignored scope would return`,
    ).toBe(0)

    // UI: the same scope on the location-scoped stock surface (see MLC-02 for
    // why `/reports` is not the fixture here) and on the POS Z list, which is
    // where a location holding no POS data most plausibly breaks.
    await applyViewScope(page, [subject!.id])
    await page.goto('/inventory/stock-by-location')
    await settleAfterNav(page)
    const stockBody = await page.locator('main').innerText()
    expect(stockBody, 'the scoped location`s column renders').toContain(subject!.name)
    expect(stockBody, 'no NaN under a zero-revenue scope').not.toContain('NaN')
    expect(stockBody, 'no undefined leaks into a cell').not.toContain('undefined')
    expect(stockBody, 'renders rather than erroring').not.toMatch(/something went wrong/i)

    await page.goto('/pos/z-reports')
    await settleAfterNav(page)
    const zBody = await page.locator('body').innerText()
    expect(zBody, 'the Z list renders cleanly under a zero-revenue scope').not.toContain('NaN')
    expect(zBody, 'no error boundary').not.toMatch(/something went wrong/i)

    await applyViewScope(page, 'all')
  })

  test('MTP-MLC-07 (P1): an EMPTY scope selection disables the query — a prompt, not a spinner and not an unscoped dump', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')

    // The documented behaviour: with no location selected the scoped query is
    // DISABLED rather than fired unscoped.
    await applyViewScope(page, [])
    await page.goto('/inventory/stock-by-location')
    await settleAfterNav(page)

    const body = await page.locator('main').innerText()
    expect(body, 'not a spinner-forever').not.toMatch(/loading/i)
    expect(body, 'no NaN').not.toContain('NaN')

    // The documented behaviour, observed: an explicit prompt…
    expect(
      /no stock found|no data available|select a location/i.test(body),
      'MLC-07: an empty selection shows a prompt, not a silent blank',
    ).toBe(true)

    // …and crucially NOT a whole-company dump: with no location selected, no
    // location column may render at all. This is the observable difference
    // between a DISABLED query and an unscoped one.
    const renderedLocationNames = locations.filter((l) => body.includes(l.name))
    expect(
      renderedLocationNames.length,
      `MLC-07: an empty scope must not dump the whole company (found: ${renderedLocationNames
        .map((l) => l.code)
        .join(', ')})`,
    ).toBe(0)

    await applyViewScope(page, 'all')
  })

  test('MTP-MLC-08 (P1): FIXED — /reports/cash-movements honours the location scope', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')

    // MAJOR-2 (web gate 2026-08-06): the row-level set assertions further down
    // compare payloads as complete SETS, and that is only sound on a SINGLE
    // page. The endpoint defaults to `per_page=50` ordered `date DESC` and caps
    // at 200, while this tenant carries ~630 cash movements — so a branch's most
    // recent page reaches further back in time than the global one, and a
    // CORRECT implementation reads as a scoping defect (false RED).
    //
    // The case is therefore split in two:
    //
    //   1. PAGE-INDEPENDENT evidence, always asserted, taken from `meta` —
    //      which the service computes over the WHOLE filtered set
    //      (`CashMovementsReportService::generate`), not over the page. This
    //      half alone falsifies F-3: under the old accepted-and-dropped
    //      behaviour every branch scope returned the unscoped `meta.total`, so
    //      Σ over three scopes was 3x the All figure.
    //   2. ROW-LEVEL set evidence (subset / pairwise-disjoint), asserted only on
    //      a window narrow enough to fit one page, and ANNOTATED rather than
    //      asserted when no such window is available. It never silently
    //      disappears, and it can never mis-assert across a page boundary.
    interface CashMovementsMeta {
      total: number
      last_page: number
      totals: Record<string, { in: string; out: string; net: string }>
    }
    interface CashMovementsRead {
      rows: Array<Record<string, unknown>>
      meta: CashMovementsMeta
    }

    const read = async (query: string, window = ''): Promise<CashMovementsRead> => {
      const path = `/reports/cash-movements?per_page=200${window}${query}`
      const res = await apiRequest(page, 'GET', path)
      expect(res.status, `GET ${path}`).toBe(200)
      const body = res.body as { data?: Array<Record<string, unknown>>; meta?: CashMovementsMeta }
      return { rows: body.data ?? [], meta: body.meta ?? { total: 0, last_page: 1, totals: {} } }
    }

    const scopeQueries = [
      ['a Tunis-Lac-only scope', `&location_ids[]=${tunis1}`],
      ['a Tunis-Centre-only scope', `&location_ids[]=${tunis2}`],
      ['a warehouse-only scope', `&location_ids[]=${warehouse}`],
    ] as const

    // M6 (review fix round 1): the unscoped payload is read TWICE, before and
    // after the scoped reads, and a scoped payload counts as "unchanged" if it
    // matches EITHER snapshot. A single before-read would make this tripwire
    // fragile on a shared stack: any sibling write landing between the reads
    // would break equality and report a scoping fix that never happened.
    const readAllBefore = await read('')
    const scopedReads = []
    for (const [, query] of scopeQueries) scopedReads.push(await read(query))
    const readAllAfter = await read('')
    const [readTunis1, readTunis2, readWarehouse] = scopedReads

    expect(readAllBefore.meta.total, 'cash movements exist to scope').toBeGreaterThan(0)

    // ── 1. PAGE-INDEPENDENT EVIDENCE ───────────────────────────────────────
    // MAJOR-3 (web gate): the row-level assertions are all satisfied by three
    // EMPTY payloads, so an over-filtering regression could stay green. Require
    // that the scope actually returns cash — the failure mode the journal-lines
    // leg can produce is "silently nothing", and it must be visible here.
    expect(
      scopedReads.reduce((sum, r) => sum + r.meta.total, 0),
      'F-3 FIXED: the branch scopes return cash — a scope that over-filters to nothing would make every set assertion below vacuous',
    ).toBeGreaterThan(0)

    // THE falsifying assertion, and the one that cannot false-red: the branch
    // scopes are disjoint subsets, so Σ of their counts can never exceed the
    // unscoped count. Under the F-3 defect each scope returned the whole
    // company, i.e. Σ = 3 x All.
    expect(
      scopedReads.reduce((sum, r) => sum + r.meta.total, 0),
      `F-3 FIXED: Σ of the branch movement counts (${scopedReads.map((r) => r.meta.total).join(' + ')}) does not exceed the unscoped count (${readAllBefore.meta.total}) — under the old accepted-and-dropped behaviour it was 3x it`,
    ).toBeLessThanOrEqual(readAllBefore.meta.total)

    // ── FINDING F-3 — FIXED (fix lane L3, 2026-08-05) ──────────────────────
    // WAS: three mutually exclusive single-location scopes returned payloads
    // identical to the unscoped read, byte for byte. NEITHER layer implemented
    // scoping — the server accepted `location_ids[]` and dropped it, and
    // `useCashMovementsReport` had no location field on `CashMovementsFilters`,
    // keyed with `tenantScopedKey`, and never read `useViewScope`. The TopBar
    // offered a location picker over it regardless, so a multi-shop owner was
    // shown the whole company's cash under a single-shop selection.
    //
    // NOW: `GetCashMovementsRequest` validates `location_ids[]`,
    // `ReportsController::cashMovements` resolves it through the same
    // `reportLocationScope()` helper the aged-* reports use (clamp an unscoped
    // read to the grant, 403 an explicit out-of-grant id), and
    // `CashMovementsReportService` filters the payments leg on
    // `payments.location_id` and the journal-lines leg through the owning
    // `payment_repositories.location_id`. The hook sends the scope and keys
    // with `locationScopedKey`.
    //
    // Money half, driven off `meta.totals` rather than the page's rows: the
    // service computes those over the WHOLE filtered set, so they are
    // page-independent (web gate MAJOR-2). Stated as the sub-total it honestly
    // is — the branch scopes are disjoint subsets, so Σ over them can never
    // EXCEED the All figure. (Equality would require every cash row to be
    // location-attributed, which company-level cash and cash on a GL account
    // shared by several branches' registers are not — see above.)
    const negate = (value: string): string =>
      value.startsWith('-') ? value.slice(1) : `-${value}`
    const legTotal = (meta: CashMovementsMeta, currency: string, leg: 'in' | 'out'): string =>
      toScale3(meta.totals[currency]?.[leg] ?? '0')

    for (const currency of Object.keys(readAllBefore.meta.totals)) {
      for (const leg of ['in', 'out'] as const) {
        const branchTotal = sumMoney(
          [readTunis1.meta, readTunis2.meta, readWarehouse.meta].map((meta) =>
            legTotal(meta, currency, leg),
          ),
        )
        const allTotal = legTotal(readAllBefore.meta, currency, leg)
        expect(
          sumMoney([allTotal, negate(branchTotal)]).startsWith('-'),
          `F-3 FIXED: Σ of the branch scopes' ${currency} ${leg} (${branchTotal}) does not exceed the All figure (${allTotal})`,
        ).toBe(false)
      }
    }

    // ── 2. ROW-LEVEL SET EVIDENCE, on a single-page window ─────────────────
    // The payload carries no location field, so per-ROW scoping is observable
    // only set-theoretically: every scoped row must exist in the unscoped read
    // (subset), and two mutually exclusive scopes must not both claim the same
    // movement (disjoint). Both statements are about complete sets, so they are
    // asserted on the newest single day — narrow enough to fit one page on any
    // realistic tenant — rather than on the paginated full range. If that day
    // does not fit one page, or does not carry cash for at least two of the
    // three scopes, the block is ANNOTATED and skipped: the page-independent
    // evidence above has already run, so the case never passes vacuously.
    const newestDate = String(readAllBefore.rows[0]?.date ?? '')
    const dayWindow = newestDate === '' ? '' : `&from=${newestDate}&to=${newestDate}`
    const dayAll = newestDate === '' ? null : await read('', dayWindow)
    const dayScoped =
      dayAll === null ? [] : await Promise.all(scopeQueries.map(([, q]) => read(q, dayWindow)))

    const singlePage =
      dayAll !== null &&
      dayAll.meta.last_page === 1 &&
      dayScoped.every((r) => r.meta.last_page === 1)
    const populatedScopes = dayScoped.filter((r) => r.rows.length > 0).length

    if (!singlePage || populatedScopes < 2) {
      test.info().annotations.push({
        type: 'mlc-08-row-evidence-skipped',
        description:
          `row-level set assertions not applicable on ${newestDate || '(no movements)'}: ` +
          `singlePage=${singlePage}, scopes with rows=${populatedScopes}/3 ` +
          `(unscoped total=${dayAll?.meta.total ?? 0}). The page-independent meta evidence above still ran ` +
          `over the full range (${readAllBefore.meta.total} movements).`,
      })
    } else {
      const rowKey = (row: Record<string, unknown>): string =>
        [row.source_type, row.source_id, row.direction, row.gl_account, row.amount].join('|')
      const dayAllKeys = new Set(dayAll.rows.map(rowKey))

      for (const [index, [label]] of scopeQueries.entries()) {
        expect(
          dayScoped[index].rows.map(rowKey).filter((key) => !dayAllKeys.has(key)),
          `F-3 FIXED: on ${newestDate}, ${label} returns only movements the unscoped read also reports`,
        ).toEqual([])
      }

      const keySets = dayScoped.map((r) => new Set(r.rows.map(rowKey)))
      for (const [left, right] of [
        [0, 1],
        [0, 2],
        [1, 2],
      ] as const) {
        expect(
          [...keySets[left]].filter((key) => keySets[right].has(key)),
          `F-3 FIXED: on ${newestDate}, ${scopeQueries[left][0]} and ${scopeQueries[right][0]} are mutually exclusive and share no movement`,
        ).toEqual([])
      }

      // The direct negation of the old tripwire, asserted PER SCOPE rather than
      // "at least one of the three differs" (web gate). With ≥2 populated,
      // pairwise-disjoint scopes, a scope that still returned the whole
      // unscoped payload would contradict disjointness — so this can only fire
      // on the real defect.
      const fingerprint = (rows: Array<Record<string, unknown>>): string => JSON.stringify(rows)
      const unscopedFingerprint = fingerprint(dayAll.rows)
      for (const [index, [label]] of scopeQueries.entries()) {
        if (dayScoped[index].rows.length === 0) continue
        expect(
          fingerprint(dayScoped[index].rows),
          `F-3 FIXED: on ${newestDate}, ${label} no longer returns the unscoped payload — \`location_ids[]\` is not accepted-and-dropped`,
        ).not.toBe(unscopedFingerprint)
      }
    }

    // UI: the page renders under a single-shop scope, and now really is scoped
    // — the hook sends the view scope and keys the cache by it.
    await applyViewScope(page, [tunis1])
    await page.goto('/finance/cash-movements')
    await settleAfterNav(page)
    const body = await page.locator('body').innerText()
    expect(body, 'no NaN under a scoped cash-movements read').not.toContain('NaN')
    await applyViewScope(page, 'all')

    // ── CROSS-REFERENCE, NOT A NEW DEFECT: W-6 finding D5 ─────────────────
    // (docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md). This
    // report is gated on `reports.view`, which `RolesAndPermissionsSeeder`
    // grants to `admin` ONLY — so the finance persona who most needs a cash
    // report cannot read it. Re-confirmed live here on THIS surface so the
    // scoping defect above is not mistaken for the access one; NOT re-filed.
    await loginAsRoleResilient(page, 'accountant')
    const asAccountant = await apiRequest(page, 'GET', '/reports/cash-movements')
    expect(
      asAccountant.status,
      'TRIPWIRE (W-6 D5, cross-referenced): the accountant is 403 on /reports/cash-movements — reports.view is admin-only',
    ).toBe(403)
  })
})
