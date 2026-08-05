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
 *   * `pos_receipts` DO exist on this tenant — 4 of them, `300.000` at
 *     STORE-TUN1 and `181.100` at STORE-TUN2 (authored as a side effect of
 *     C-2's `authorTier4CardFiscalSale` card-settlement fixture, not by any
 *     seeder). So the per-location revenue leg of MLC-01/02/03 IS assertable
 *     today, and this spec asserts it.
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

    // ── FINDING F-2 (P1, TRIPWIRE, GREEN: pins TODAY's behaviour) ──────────
    // Every money figure this endpoint family emits goes through
    // `FormatsReportNumbers::decimalString()`
    // (app/Modules/Accounting/Application/Services/Reports/
    // FormatsReportNumbers.php:9-14), which does THREE things wrong for a TND
    // company: it casts to `(float)` (CLAUDE.md rule 19 — never a float on
    // money), it formats at a hardcoded `scale = 2` (TND is scale 3, so a
    // millime is truncated away), and it then `rtrim`s the trailing zeros and
    // the decimal point. The DB holds `300.000` and `181.100`; the API answers
    // `"300"` and `"181.1"`. Affects sales-by-location, top-SKUs,
    // revenue-by-category and the payment-method breakdown — i.e. every tile
    // on the owner dashboard.
    const raw = all.map((r) => r.gross_sales)
    expect(
      raw.some((v) => !/^-?\d+\.\d{3}$/.test(v)),
      'TRIPWIRE F-2: gross_sales is NOT emitted at the TND scale of 3 (decimalString scale-2 + rtrim)',
    ).toBe(true)
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

    // API: the warehouse is `type: warehouse` and has never had a receipt.
    const res = await apiRequest(
      page,
      'GET',
      `/reports/sales/by-location?from=${YEAR_FROM}&to=${YEAR_TO}&location_ids[]=${warehouse}`,
    )
    expect(res.status, 'a non-POS scope is a valid query, not an error').toBe(200)
    const rows = (res.body as { data?: SalesByLocationRow[] }).data ?? []
    expect(rows.length, 'zero POS revenue rows for a non-POS location').toBe(0)

    // UI: the warehouse scope on the location-scoped stock surface (see
    // MLC-02 for why `/reports` is not the fixture here) and on the POS Z
    // list, which is where a non-POS location most plausibly breaks.
    await applyViewScope(page, [warehouse])
    await page.goto('/inventory/stock-by-location')
    await settleAfterNav(page)
    const stockBody = await page.locator('main').innerText()
    expect(stockBody, 'the warehouse column renders').toContain(
      locations.find((l) => l.id === warehouse)!.name,
    )
    expect(stockBody, 'no NaN under a non-POS scope').not.toContain('NaN')
    expect(stockBody, 'no undefined leaks into a cell').not.toContain('undefined')
    expect(stockBody, 'renders rather than erroring').not.toMatch(/something went wrong/i)

    await page.goto('/pos/z-reports')
    await settleAfterNav(page)
    const zBody = await page.locator('body').innerText()
    expect(zBody, 'the Z list renders cleanly under a warehouse scope').not.toContain('NaN')
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

  test('MTP-MLC-08 (P1): FINDING — /reports/cash-movements IGNORES the location scope entirely', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')

    const read = async (query: string): Promise<Array<Record<string, unknown>>> => {
      const res = await apiRequest(page, 'GET', `/reports/cash-movements${query}`)
      expect(res.status, `GET /reports/cash-movements${query}`).toBe(200)
      return (res.body as { data?: Array<Record<string, unknown>> }).data ?? []
    }

    // M6 (review fix round 1): the unscoped payload is read TWICE, before and
    // after the scoped reads, and a scoped payload counts as "unchanged" if it
    // matches EITHER snapshot. A single before-read would make this tripwire
    // fragile on a shared stack: any sibling write landing between the reads
    // would break equality and report a scoping fix that never happened.
    const allBefore = await read('')
    const scopedTunis1 = await read(`?location_ids[]=${tunis1}`)
    const scopedTunis2 = await read(`?location_ids[]=${tunis2}`)
    const scopedWarehouse = await read(`?location_ids[]=${warehouse}`)
    const allAfter = await read('')

    expect(allBefore.length, 'cash movements exist to scope').toBeGreaterThan(0)

    // ── FINDING F-3 (P1, TRIPWIRE, GREEN: pins TODAY's behaviour) ──────────
    // The plan's expectation for this case is "cash figures RE-SCOPE; Σ across
    // all locations == the 'All' figure". The second half holds trivially and
    // the first half does not hold at all: three mutually exclusive
    // single-location scopes — including a warehouse that has never seen a POS
    // receipt — return payloads identical to the unscoped read. The
    // `location_ids[]` parameter is accepted and then dropped.
    //
    // C1 (review fix round 1) — SCOPE OF THE DEFECT, CORRECTED. An earlier
    // version of this comment (and of the ticket) claimed "the parameter the
    // FE sends". It does not: `features/finance/hooks/useCashMovementsReport.ts`
    // has NO location field on `CashMovementsFilters` at all, keys its query
    // with `tenantScopedKey` rather than `locationScopedKey`, and
    // `CashMovementsReportPage.tsx` never imports `useViewScope` — contrast
    // `useAgedReceivables.ts:12-13`, which does all three. So NEITHER LAYER
    // implements location scoping here: the parameter below is one this TEST
    // sends, the server ignores it, and a fix has to span the backend query,
    // the hook's filters and its query key. Nothing LEAKS across tenants or
    // companies — this is an unimplemented filter, not an isolation hole —
    // but the TopBar still offers a location scope on this page, so a
    // multi-shop owner is shown the whole company's cash under a single-shop
    // selection.
    const fingerprint = (rows: Array<Record<string, unknown>>): string => JSON.stringify(rows)
    const unscopedFingerprints = [fingerprint(allBefore), fingerprint(allAfter)]
    expect(
      unscopedFingerprints,
      'TRIPWIRE F-3: a Tunis-Lac-only scope returns the SAME payload as no scope at all',
    ).toContain(fingerprint(scopedTunis1))
    expect(
      unscopedFingerprints,
      'TRIPWIRE F-3: …and so does a Tunis-Centre-only scope',
    ).toContain(fingerprint(scopedTunis2))
    expect(
      unscopedFingerprints,
      'TRIPWIRE F-3: …and so does a NON-POS warehouse scope, which can hold no POS cash at all',
    ).toContain(fingerprint(scopedWarehouse))

    // The additive half of the plan's expectation, stated as the (vacuous but
    // recorded) truth it currently is: Σ over the shops == the All figure
    // BECAUSE each scope returns the All figure.
    const total = (rows: Array<Record<string, unknown>>): string =>
      sumMoney(rows.map((r) => toScale3(String(r.amount ?? '0'))))
    expect(
      [total(allBefore), total(allAfter)],
      'each scope reports the same grand total as All (the arithmetic identity is vacuous today)',
    ).toContain(total(scopedTunis1))

    // UI: the page renders under a single-shop scope without erroring — the
    // figures are simply not scoped.
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
