/**
 * MONEY TEST CAMPAIGN — W-2 execution agent — wave "W-2: config & pricing"
 * (docs/qa/2026-08-02-full-e2e-campaign-plan.md §E.2 row W-2): `CFG-12..16`,
 * `PMT-01..05`, a scoped slice of `PRC` (01/02/03/06/17 — the rest is
 * reported as remaining backlog, see the campaign results ledger).
 *
 * PRC scope note: `e2e/sales/margin-warnings.spec.ts` and
 * `e2e/products/pricing-card.spec.ts` already own green/yellow/orange/red
 * margin indicators + below-cost-permission end-to-end (confirmed by
 * research — 20 tests between them). This file targets what they do NOT:
 * price-list CRUD, resolution PRECEDENCE, quantity breaks, and the
 * regulatory-floor-is-advisory-only ruling — not duplicated here.
 *
 * MUTATION SAFETY: `PUT /companies/{id}` and `PATCH /settings/company` both
 * mutate COMPANY-WIDE config with no location/user scope. Every mutating
 * test captures the pre-test value and restores it in a `finally` block.
 *
 * Real login + real API, no mocking. Fixtures carry the `W2c` prefix.
 */
import { test, expect } from '@playwright/test'
import { loginAsRole, apiRequest } from './helpers'
import {
  getCompany,
  putCompany,
  patchCompanySettings,
  listPaymentMethods,
  createPaymentMethod,
  updatePaymentMethod,
  listAccounts,
  listPaymentRepositories,
  createPriceList,
  addPriceListItem,
  assignPriceListToPartner,
  getPrice,
  getQuantityBreaks,
  checkMargin,
  uniq,
} from './w2c-support'
import { createProduct, queryScalar } from './w2b-support'

test.describe('MTP-CFG — company money settings / setup checklist (W-2)', () => {
  test.setTimeout(60_000)
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-CFG-12 (P0): PATCH /settings/company is gated by settings.update — viewer (settings.view only) is refused', async ({ page }) => {
    await loginAsRole(page, 'viewer')
    const res = await patchCompanySettings(page, { locale: 'fr' })
    expect(res.status, 'viewer holds settings.view but not settings.update').toBe(403)
  })

  test('MTP-CFG-13 (P0, FIXED): PUT /companies/{id} is now gated by settings.update — viewer (no settings/company permission at all) is refused', async ({ page }) => {
    test.setTimeout(60_000)
    // Originally a FINDING: UpdateCompanyRequest::authorize() returned
    // `true` unconditionally (UpdateCompanyRequest.php:19-21) and
    // `PUT companies/{companyId}` (Company/routes.php:29) carried no `can:`
    // middleware — unlike `PATCH /settings/company`
    // (CompanySettingsController, gated on settings.update), a DIFFERENT
    // resource controlling the SAME money-relevant fields
    // (discount_floor_mode, price_entry_mode, default_target_margin,
    // default_minimum_margin, default_max_discount_percent).
    //
    // Fixed 2026-08-02 (docs/superpowers/tickets/
    // 2026-08-02-company-update-route-unauthorized.md): the route now
    // carries `->middleware('can:settings.update')`, mirroring the sibling
    // PATCH route. This case flips from tripwire (asserting the bug) to
    // regression assertion (asserting the fix holds).
    await loginAsRole(page, 'owner')
    const before = await getCompany(page)

    // 'viewer' is the LOWEST-privilege seeded role (read-only by design,
    // no settings.* mutate permission whatsoever).
    await loginAsRole(page, 'viewer')
    const attempt = await putCompany(page, {
      default_max_discount_percent: String(Number(before.default_max_discount_percent ?? '10.00') + 1),
    })
    expect(
      attempt.status,
      `viewer (no settings.update) must be refused -> ${attempt.status} ${JSON.stringify(attempt.body)}`
    ).toBe(403)

    await loginAsRole(page, 'owner')
    const after = await getCompany(page)
    expect(after.default_max_discount_percent, 'refused PUT leaves company state untouched').toBe(
      before.default_max_discount_percent
    )
  })

  test('MTP-CFG-15 (P1): default_target_margin/default_minimum_margin enforce a 2dp ceiling — a 3rd decimal is refused (422)', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const before = await getCompany(page)
    const res = await putCompany(page, { default_target_margin: '25.123' })
    expect(res.status, `3dp margin must 422 -> ${res.status} ${JSON.stringify(res.body)}`).toBe(422)
    const after = await getCompany(page)
    expect(after.default_target_margin).toBe(before.default_target_margin)
  })

  test('MTP-CFG-16 (P1): margin-band cross-field rule — default_minimum_margin > default_target_margin is refused (422)', async ({ page }) => {
    await loginAsRole(page, 'owner')
    const before = await getCompany(page)
    try {
      const res = await putCompany(page, { default_minimum_margin: '50.00', default_target_margin: '10.00' })
      expect(res.status, `minimum > target must 422 -> ${res.status} ${JSON.stringify(res.body)}`).toBe(422)
    } finally {
      const after = await getCompany(page)
      expect(after.default_minimum_margin, 'refused PATCH leaves state untouched').toBe(before.default_minimum_margin)
    }
  })
})

// MTP-CFG-14 lives in its OWN describe block, deliberately WITHOUT the
// MTP-CFG describe's owner `beforeEach`: the case immediately re-logs in as
// `cashier` as its first statement, so the shared owner login was pure
// redundant overhead — and `loginAsRole` navigates to `/login` fresh each
// call, so the double-login (owner, then cashier) doubled the exposure to
// the auth-throttle-shaped flake observed live (gate review m4,
// docs/superpowers/reviews/2026-08-02-fe-batch-gate.md — 1 failure in 2
// consecutive runs, stuck on /login inside the redundant owner login).
test.describe('MTP-CFG-14 — onboarding/status permission gate (W-2, isolated)', () => {
  test.setTimeout(60_000)

  test('MTP-CFG-14 (P1, FIXED): GET onboarding/status now requires settings.view — cashier is refused at both layers (API 403 + FE redirect)', async ({ page }) => {
    // Originally this case asserted "onboarding status has no permission gate
    // of its own" — that became FALSE once commit
    // 10ad37743 (docs/superpowers/tickets/2026-08-02-settings-setup-route-ungated.md
    // items 1+2) landed: GET /onboarding/status now carries `can:settings.view`
    // middleware (Tenant/routes.php), and /settings/setup itself is wrapped in
    // <RequirePermission moduleKey="settings"> (= settings.view) on the FE
    // (routes/index.tsx). This case was flagged (gate review F5) as stale —
    // re-pointed at `cashier` (holds NO settings.* permission per
    // RolesAndPermissionsSeeder.php) to assert the fixed behaviour explicitly
    // at both layers, instead of passing only incidentally because `viewer`
    // happens to hold settings.view.
    await loginAsRole(page, 'cashier')

    const res = await apiRequest(page, 'GET', '/onboarding/status')
    expect(res.status, 'cashier holds no settings.* permission -> onboarding/status must 403').toBe(403)

    // FE layer: RequirePermission redirects an unauthorized deep-link to
    // /dashboard (RequirePermission.tsx) — /dashboard itself is not gated, so
    // the redirect always lands cleanly.
    await page.goto('/settings/setup')
    await expect(page, 'cashier deep-linking to /settings/setup must be redirected to /dashboard').toHaveURL(
      /\/dashboard/,
      { timeout: 15_000 }
    )
  })
})

test.describe('MTP-PMT — payment methods + GL routing (W-2)', () => {
  test.setTimeout(60_000)
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-PMT-01 (P0, FINDING): create a payment method with GL routing (default_account_id, default_repository_id) — accepted, PERSISTED, but never surfaced back by the API', async ({ page }) => {
    const accounts = await listAccounts(page)
    const repos = await listPaymentRepositories(page)
    expect(accounts.length).toBeGreaterThan(0)
    expect(repos.length).toBeGreaterThan(0)

    const created = await createPaymentMethod(page, {
      code: uniq('PMT01'),
      name: 'PMT-01 GL-routed method',
      is_physical: true,
      default_account_id: accounts[0].id,
      default_repository_id: repos[0].id,
    })
    expect(created.status, `create -> ${created.status} ${JSON.stringify(created.body)}`).toBe(201)
    const createdBody = created.body as { data: Record<string, unknown> }
    // FINDING: PaymentMethodController::formatMethod()
    // (PaymentMethodController.php:264-286) — the shape used by BOTH
    // store()'s 201 response and index() — never includes
    // `default_account_id` or `fee_account_id`. Only `default_repository_id`
    // survives (confirmed present below). The API therefore accepts and
    // persists GL-account routing on write but is structurally unable to
    // report it back on ANY read path — a client (or this very test) cannot
    // verify which account a method routes to without a direct DB read.
    expect(Object.keys(createdBody.data)).not.toContain('default_account_id')
    expect(createdBody.data.default_repository_id, 'default_repository_id DOES survive formatMethod()').toBe(repos[0].id)

    // Verify default_account_id was still genuinely PERSISTED (not silently
    // dropped) via a direct DB read — same evidence pattern as C-11
    // (stamp_duty_amount not exposed by DocumentData).
    const persistedAccountId = queryScalar(`SELECT default_account_id FROM payment_methods WHERE id = '${created.id}'`)
    expect(persistedAccountId, 'default_account_id IS written to the row, just never read back via the API').toBe(accounts[0].id)
  })

  test('MTP-PMT-02 (P0): cashier lacks treasury.manage — create/update both 403', async ({ page }) => {
    await loginAsRole(page, 'cashier')
    const createAttempt = await createPaymentMethod(page, { code: uniq('PMT02'), name: 'PMT-02 probe' })
    expect(createAttempt.status).toBe(403)

    await loginAsRole(page, 'owner')
    const methods = await listPaymentMethods(page)
    const anyMethodId = methods[0]?.id as string
    await loginAsRole(page, 'cashier')
    const updateAttempt = await updatePaymentMethod(page, anyMethodId, { name: 'should be refused' })
    expect(updateAttempt.status).toBe(403)
  })

  test('MTP-PMT-03 (P1, FINDING): PATCH updates GL routing fields (default_account_id, fee_account_id) — persisted per direct DB read, same read-path gap as PMT-01', async ({ page }) => {
    const accounts = await listAccounts(page)
    expect(accounts.length).toBeGreaterThanOrEqual(2)
    const created = await createPaymentMethod(page, { code: uniq('PMT03'), name: 'PMT-03 method' })
    expect(created.status).toBe(201)

    const patch = await updatePaymentMethod(page, created.id!, {
      default_account_id: accounts[0].id,
      fee_account_id: accounts[1].id,
    })
    expect(patch.status, `patch GL routing -> ${patch.status} ${JSON.stringify(patch.body)}`).toBe(200)
    const patchBody = patch.body as { data: Record<string, unknown> }
    expect(Object.keys(patchBody.data)).not.toContain('default_account_id')
    expect(Object.keys(patchBody.data)).not.toContain('fee_account_id')

    const persistedDefaultAccount = queryScalar(`SELECT default_account_id FROM payment_methods WHERE id = '${created.id}'`)
    const persistedFeeAccount = queryScalar(`SELECT fee_account_id FROM payment_methods WHERE id = '${created.id}'`)
    expect(persistedDefaultAccount).toBe(accounts[0].id)
    expect(persistedFeeAccount).toBe(accounts[1].id)
  })

  test('MTP-PMT-04 (P1): accountant (holds treasury.manage) CAN create a payment method — contrast with cashier (PMT-02)', async ({ page }) => {
    await loginAsRole(page, 'accountant')
    const created = await createPaymentMethod(page, { code: uniq('PMT04'), name: 'PMT-04 accountant method' })
    expect(created.status, 'accountant holds treasury.manage (RolesAndPermissionsSeeder.php accountant block)').toBe(201)
  })

  test('MTP-PMT-05 (P1, plan-premise correction): "treasury:configure-method-routing" is a console command, not an HTTP permission — never appears in a live permissions list', async ({ page }) => {
    // The plan's §B.5 flow 70 note ("card routing needs
    // treasury:configure-method-routing") is imprecise: that string is the
    // Artisan signature of ConfigureMethodRepositoryRoutingCommand, not a
    // Spatie permission. The real HTTP gate for editing routing fields is
    // plain treasury.manage (same as any other payment-method field, proven
    // by PMT-01/02/04 above). This case pins the correction structurally.
    await loginAsRole(page, 'owner')
    const me = await apiRequest(page, 'GET', '/auth/me')
    const permissions = ((me.body as { data?: { permissions?: string[] } }).data?.permissions ?? []) as string[]
    expect(permissions).not.toContain('treasury:configure-method-routing')
    expect(permissions).not.toContain('treasury.configure-method-routing')
  })
})

test.describe('MTP-PRC — pricing (price lists, resolution precedence, quantity breaks) (W-2)', () => {
  test.setTimeout(60_000)
  test.beforeEach(async ({ page }) => {
    await loginAsRole(page, 'owner')
  })

  test('MTP-PRC-01 (P0): create a price list, add an item, assign to a partner', async ({ page }) => {
    const { id: productId } = await createProduct(page, { name: uniq('PRC01-product'), sku: uniq('PRC01') })
    const customer = await apiRequest(page, 'POST', '/partners', { name: uniq('PRC01-customer'), type: 'customer' })
    const customerId = ((customer.body as { data: { id: string } }).data).id

    const listRes = await createPriceList(page, {
      code: uniq('PRC01-LIST'),
      name: 'PRC-01 price list',
      currency: 'TND',
      is_active: true,
    })
    expect(listRes.status, `create price list -> ${listRes.status} ${JSON.stringify(listRes.body)}`).toBe(201)

    const itemRes = await addPriceListItem(page, listRes.id!, { product_id: productId, price: '42.500', min_quantity: '0' })
    expect(itemRes.status, `add item -> ${itemRes.status} ${JSON.stringify(itemRes.body)}`).toBe(201)

    const assignRes = await assignPriceListToPartner(page, listRes.id!, { partner_id: customerId, priority: 1 })
    expect(assignRes.status, `assign to partner -> ${assignRes.status} ${JSON.stringify(assignRes.body)}`).toBe(201)
  })

  test('MTP-PRC-02 (P0): viewer (pricing.view only) can read but not create a price list — API 403 on create', async ({ page }) => {
    await loginAsRole(page, 'viewer')
    const attempt = await createPriceList(page, { code: uniq('PRC02'), name: 'should be refused', currency: 'TND' })
    expect(attempt.status).toBe(403)
    const readAttempt = await apiRequest(page, 'GET', '/price-lists')
    expect(readAttempt.status, 'viewer DOES hold pricing.view').toBe(200)
  })

  test('MTP-PRC-03 (P0, FINDING): price resolution precedence — partner price list wins over the default price list, which wins over base_price', async ({ page }) => {
    test.setTimeout(90_000)
    // FINDING (fixture-safety note, discovered authoring this case):
    // getDefaultPriceListPrice() (PricingService.php:201-214) resolves the
    // company's "default" TND price list with a bare `->first()` — nothing
    // in PricingController::store()/update() prevents MULTIPLE
    // `is_default=true` rows for the same company+currency. On a shared,
    // never-reset dev tenant this makes "the default price list" resolve
    // NON-DETERMINISTICALLY once more than one exists (reproduced live:
    // this exact case flipped from PASS to FAIL between two runs purely
    // because an EARLIER run's leftover default list won the `->first()`
    // race instead of this run's own list). This is a real precedence-engine
    // gap, not just test hygiene — a production tenant that creates a
    // second default list by mistake would see the SAME nondeterminism.
    // Mitigated here (not fixed in product code) by deactivating every
    // OTHER active TND default list for the test's duration and restoring
    // them afterward — the standard mutation-safety capture/restore
    // pattern used throughout this file.
    const { id: productId } = await createProduct(page, { name: uniq('PRC03-product'), sku: uniq('PRC03') })
    await apiRequest(page, 'PATCH', `/products/${productId}`, { sale_price: '100.000' })

    const existingRes = await apiRequest(page, 'GET', '/price-lists?currency=TND&is_active=true')
    const existingLists = ((existingRes.body as { data?: Array<{ id: string; is_default: boolean }> }).data ?? [])
    const otherDefaults = existingLists.filter((l) => l.is_default)
    for (const l of otherDefaults) {
      await apiRequest(page, 'PATCH', `/price-lists/${l.id}`, { is_default: false })
    }

    try {
      const customer = await apiRequest(page, 'POST', '/partners', { name: uniq('PRC03-customer'), type: 'customer' })
      const customerId = ((customer.body as { data: { id: string } }).data).id

      // Layer 0: base_price only.
      const base = await getPrice(page, { product_id: productId, quantity: '1' })
      expect(base.status).toBe(200)
      const baseBody = base.body as { data: { source: string; price: string } }
      expect(baseBody.data.source).toBe('base_price')
      expect(baseBody.data.price).toBe('100.000')

      // Layer 1: default price list undercuts base_price.
      const defaultList = await createPriceList(page, { code: uniq('PRC03-DEFAULT'), name: 'PRC-03 default list', currency: 'TND', is_default: true })
      await addPriceListItem(page, defaultList.id!, { product_id: productId, price: '80.000', min_quantity: '0' })
      const afterDefault = await getPrice(page, { product_id: productId, quantity: '1' })
      const afterDefaultBody = afterDefault.body as { data: { source: string; price: string } }
      expect(afterDefaultBody.data.source, 'default price list now wins over base_price').toBe('default_price_list')
      expect(afterDefaultBody.data.price).toBe('80.000')

      // Layer 2: a partner-specific price list undercuts the default list AND is queried WITH partner_id.
      const partnerList = await createPriceList(page, { code: uniq('PRC03-PARTNER'), name: 'PRC-03 partner list', currency: 'TND' })
      await addPriceListItem(page, partnerList.id!, { product_id: productId, price: '65.000', min_quantity: '0' })
      await assignPriceListToPartner(page, partnerList.id!, { partner_id: customerId, priority: 1 })
      const afterPartner = await getPrice(page, { product_id: productId, partner_id: customerId, quantity: '1' })
      const afterPartnerBody = afterPartner.body as { data: { source: string; price: string } }
      expect(afterPartnerBody.data.source, 'partner price list wins over the default price list').toBe('partner_price_list')
      expect(afterPartnerBody.data.price).toBe('65.000')

      // A DIFFERENT partner (no assignment) still sees the default list, not
      // the first partner's price — precedence is partner-scoped, not global.
      const otherCustomer = await apiRequest(page, 'POST', '/partners', { name: uniq('PRC03-other'), type: 'customer' })
      const otherId = ((otherCustomer.body as { data: { id: string } }).data).id
      const otherPrice = await getPrice(page, { product_id: productId, partner_id: otherId, quantity: '1' })
      const otherPriceBody = otherPrice.body as { data: { source: string; price: string } }
      expect(otherPriceBody.data.source).toBe('default_price_list')
      expect(otherPriceBody.data.price).toBe('80.000')
    } finally {
      for (const l of otherDefaults) {
        await apiRequest(page, 'PATCH', `/price-lists/${l.id}`, { is_default: true })
      }
    }
  })

  test('MTP-PRC-06 (P0): quantity breaks — the highest-tier row whose range contains the quantity wins', async ({ page }) => {
    const { id: productId } = await createProduct(page, { name: uniq('PRC06-product'), sku: uniq('PRC06') })
    const customer = await apiRequest(page, 'POST', '/partners', { name: uniq('PRC06-customer'), type: 'customer' })
    const customerId = ((customer.body as { data: { id: string } }).data).id

    // SPEC NOTE: deliberately partner-scoped, NOT `is_default: true`.
    // getDefaultPriceListPrice() (PricingService.php:201-214) resolves the
    // company's default list with a bare `->first()` and no product-aware
    // tiebreak — a first run using `is_default: true` here silently picked
    // up MTP-PRC-03's leftover default list (same company, same test file,
    // sequential execution) instead of this test's own list, since
    // multiple `is_default=true` rows are not prevented. A partner
    // assignment does not share that ambiguity (getPartnerPrice() iterates
    // ONLY lists assigned to this exact partner_id).
    const list = await createPriceList(page, { code: uniq('PRC06-LIST'), name: 'PRC-06 breaks list', currency: 'TND' })
    await addPriceListItem(page, list.id!, { product_id: productId, price: '10.000', min_quantity: '1', max_quantity: '9' })
    await addPriceListItem(page, list.id!, { product_id: productId, price: '8.000', min_quantity: '10' })
    await assignPriceListToPartner(page, list.id!, { partner_id: customerId, priority: 1 })

    const lowQty = await getPrice(page, { product_id: productId, partner_id: customerId, quantity: '5' })
    expect((lowQty.body as { data: { price: string } }).data.price).toBe('10.000')
    const highQty = await getPrice(page, { product_id: productId, partner_id: customerId, quantity: '15' })
    expect((highQty.body as { data: { price: string } }).data.price).toBe('8.000')

    const breaksRes = await getQuantityBreaks(page, list.id!, productId)
    expect(breaksRes.status).toBe(200)
    const breaks = (breaksRes.body as { data: Array<{ min_quantity: string; price: string }> }).data
    expect(breaks.length).toBe(2)
  })

  test('MTP-PRC-17 (P1, RULING): a below-cost check via /pricing/check-margin never blocks — the regulatory floor is advisory-only, distinct from the permission-gated below_cost/below_minimum_margin reasons', async ({ page }) => {
    // pricing-card.spec.ts / margin-warnings.spec.ts already prove the
    // PERMISSION-gated below-cost block (pricing.sell_below_cost). This
    // case targets the SEPARATE regulatory floor
    // (DiscountPolicyService.php lines ~100-103,128-131 per research):
    // advisory-only, never 403/422s regardless of caller permissions.
    // `cost_price` has NO direct write path on Product's create/update
    // requests (confirmed: no `cost_price` rule anywhere in
    // CreateProductRequest/UpdateProductRequest) — it is WAC-derived,
    // written only by a real stock receipt. Give the product a genuine
    // cost via a PO receipt (same fixture composition as return-notes.spec.ts).
    const { id: productId } = await createProduct(page, { name: uniq('PRC17-product'), sku: uniq('PRC17') })
    const supplier = await apiRequest(page, 'POST', '/partners', { name: uniq('PRC17-supplier'), type: 'supplier', country_code: 'TN' })
    const supplierId = ((supplier.body as { data: { id: string } }).data).id
    const po = await apiRequest(page, 'POST', '/purchase-orders', {
      partner_id: supplierId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ product_id: productId, description: 'PRC-17 cost fixture', quantity: '5', unit_price: '50.000', tax_rate: '19.00' }],
    })
    expect(po.status).toBe(201)
    const poId = ((po.body as { data: { id: string } }).data).id
    await apiRequest(page, 'POST', `/purchase-orders/${poId}/confirm`)
    const poBody = await apiRequest(page, 'GET', `/purchase-orders/${poId}`)
    const lineId = ((poBody.body as { data: { lines: Array<{ id: string }> } }).data).lines[0].id
    const receive = await apiRequest(page, 'POST', `/purchase-orders/${poId}/receive`, { quantities: { [lineId]: '5' } })
    expect(receive.status).toBeLessThan(300)

    const productAfterReceipt = await apiRequest(page, 'GET', `/products/${productId}`)
    const costPrice = ((productAfterReceipt.body as { data: { cost_price: string } }).data).cost_price
    expect(Number(costPrice), 'WAC-derived cost_price is now real (~50.000)').toBeGreaterThan(0)

    // A deliberately low sell price, well under cost — check-margin must
    // still respond 200 with a RED/below-cost indicator, not refuse the call.
    const res = await checkMargin(page, productId, '10.000')
    expect(res.status, 'check-margin never 403/422s on a below-cost price — it REPORTS the level, does not enforce').toBe(200)
    // Response shape: data.margin_level is itself an OBJECT
    // ({level, message, actual_margin, ...}), not a bare string
    // (PricingController.php:527, MarginService::getMarginLevel() return
    // shape) — corrected after a first-run spec-side mismatch.
    const body = (res.body as { data?: { margin_level?: { level?: string; message?: string } } }).data
    expect(body?.margin_level?.level, 'below-cost price reports the RED level').toBe('red')
    expect(body?.margin_level?.message).toContain('Below cost')
  })
})
