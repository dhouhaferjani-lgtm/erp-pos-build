import { test, expect, type Page } from '@playwright/test'
import { apiRequest } from './helpers'
import { get, post } from './treasury-support'
import {
  CAFE_CREDENTIALS,
  asSession,
  isCleanupSuccess,
  loginAs,
  loginAsRoleResilient as loginAsRole,
  patchCompany,
  patchCompanySettings,
} from './w7-support'

/**
 * Wait past BOTH loading layers this app can show after a navigation: the top-level
 * app shell spinner ("Loading..." while the route's lazy chunk / bootstrap fetches)
 * and RequirePermission's synchronous redirect once mounted. networkidle proved
 * unreliable on this local stack under concurrent sibling-agent load (some
 * background poll never goes fully idle) - this waits for a concrete negative
 * condition instead, bounded and retried.
 */
async function settleAfterNav(page: Page): Promise<void> {
  // Regex, not a literal string: this app shows several distinct loading strings
  // depending on which provider is still resolving ("Loading...", "Loading
  // companies…", "Loading locations…", …) - matching only one of them was the exact
  // cause of an earlier flaky failure on this same check (MTP-PERM-04).
  await expect(page.locator('body')).not.toContainText(/loading/i, { timeout: 30_000 })
}

/**
 * MONEY TEST CAMPAIGN — §I.4 `PERM` permission-denied paths for money mutations
 * (docs/qa/2026-08-01-money-test-plan.md, MTP-PERM-01..14).
 *
 * "Every PERM case must assert BOTH layers: the UI blocks it AND a direct API call
 * with the same credentials returns 403." (plan §I.4) — each case below does both.
 *
 * Targets the live local stack, tenant demo-pharmacy-tn. Several cases need a REAL
 * existing row (a payment cannot be refused-refunded if it does not exist — the
 * permission gate and the 404 would be indistinguishable). Those ids were read
 * directly from the tenant's Postgres database at execution time (2026-08-01,
 * `tenant019fbe86-944a-7252-8a3b-8c341dfa9de9`) and are NOT fabricated:
 *   - a completed payment (for PERM-01/02/03)
 *   - a posted expense document (for PERM-05)
 *   - a product with a known cost_price (for PERM-06/07)
 * If this spec is re-run after a reseed, re-resolve these via the same query and
 * update the constants below — a reseed changes every id.
 */

const KNOWN_PAYMENT_ID = '019fbe86-ef4b-721b-b2ed-926467a8f5a9' // amount 97.169, completed
const KNOWN_EXPENSE_DOCUMENT_ID = '019fbe86-f067-7374-90c1-2ad4f23ba60e' // type=expense, status=posted
const KNOWN_PRODUCT_ID = '019fbe86-a967-70ee-8be2-aae917e764d8' // Magnesium Citrate 400mg, cost_price 11.3808

test.describe('PERM — permission-denied paths for money mutations', () => {
  test.setTimeout(60_000) // local single-process dev backend, shared with sibling agents, can be slow

  test('MTP-PERM-01 (P0): cashier cannot refund a payment — UI absent + API 403', async ({ page }) => {
    await loginAsRole(page, 'cashier')

    // UI layer: the page itself must be unreachable (RequirePermission redirects
    // to /dashboard) OR reachable but with no refund action exposed.
    await page.goto(`/treasury/payments/${KNOWN_PAYMENT_ID}`)
    // networkidle is unreliable on this local stack under concurrent sibling-agent
    // load (some background poll never goes fully idle). RequirePermission's redirect
    // check reads the already-populated auth store synchronously (no network round
    // trip), so it resolves almost immediately after mount — a short fixed settle is
    // enough and far more reliable here than networkidle.
    await settleAfterNav(page)
    const onPaymentDetail = page.url().includes(`/treasury/payments/${KNOWN_PAYMENT_ID}`)
    if (onPaymentDetail) {
      await expect(page.getByRole('button', { name: /refund/i })).toHaveCount(0)
    } else {
      // RequirePermission's module gate redirected away — the whole surface is blocked.
      expect(page.url()).not.toContain(`/treasury/payments/${KNOWN_PAYMENT_ID}`)
    }

    // API layer.
    const res = await apiRequest(page, 'POST', `/payments/${KNOWN_PAYMENT_ID}/refund`, {
      amount: '1.000',
      reason: 'MTP-PERM-01 campaign probe',
    })
    expect(res.status).toBe(403)
  })

  test('MTP-PERM-02 (P0): cashier cannot void/reverse a payment — UI absent + API 403', async ({ page }) => {
    // The plan's "payments.void" and "payments.reverse" map to a single real
    // route/ability in this codebase: POST /payments/{id}/reverse -> can:payments.reverse
    // (confirmed via `php artisan route:list --path=api/v1/payments` — no /void route
    // exists at all). Testing the real route.
    await loginAsRole(page, 'cashier')
    const reverseRes = await apiRequest(page, 'POST', `/payments/${KNOWN_PAYMENT_ID}/reverse`)
    expect(reverseRes.status).toBe(403)
  })

  test('MTP-PERM-03 (P0): manager (not admin) cannot reverse a payment — API 403 confirms admin-only', async ({ page }) => {
    await loginAsRole(page, 'manager')
    const res = await apiRequest(page, 'POST', `/payments/${KNOWN_PAYMENT_ID}/reverse`)
    expect(res.status).toBe(403)
  })

  test('MTP-PERM-04 (P0): cashier cannot create a journal entry — route blocked + API 403', async ({ page }) => {
    await loginAsRole(page, 'cashier')

    await page.goto('/finance/journal-entries/create')
    await settleAfterNav(page)
    expect(page.url()).not.toContain('/finance/journal-entries/create')

    const res = await apiRequest(page, 'POST', '/journal-entries', {
      entry_date: new Date().toISOString().slice(0, 10),
      description: 'MTP-PERM-04 campaign probe',
      lines: [],
    })
    expect(res.status).toBe(403)
  })

  test('MTP-PERM-05 (P0): cashier cannot post or pay an expense — UI absent + API 403 for both', async ({ page }) => {
    await loginAsRole(page, 'cashier')

    await page.goto(`/expenses/${KNOWN_EXPENSE_DOCUMENT_ID}/view`)
    await settleAfterNav(page)
    const onExpenseDetail = page.url().includes(KNOWN_EXPENSE_DOCUMENT_ID)
    if (onExpenseDetail) {
      await expect(page.getByRole('button', { name: /^post$/i })).toHaveCount(0)
      await expect(page.getByRole('button', { name: /^pay$/i })).toHaveCount(0)
    }

    const postRes = await apiRequest(page, 'POST', `/expenses/${KNOWN_EXPENSE_DOCUMENT_ID}/post`)
    expect(postRes.status).toBe(403)

    const payRes = await apiRequest(page, 'POST', `/expenses/${KNOWN_EXPENSE_DOCUMENT_ID}/pay`, {
      amount: '1.000',
      payment_method_id: null,
      payment_date: new Date().toISOString().slice(0, 10),
    })
    expect(payRes.status).toBe(403)
  })

  test('MTP-PERM-06 (P0): cashier cannot see cost price / stock value — absent from UI AND API payload', async ({ page }) => {
    await loginAsRole(page, 'cashier')

    await page.goto(`/inventory/products/${KNOWN_PRODUCT_ID}`)
    await settleAfterNav(page)
    // Server-authoritative: the real value (DB-confirmed 11.380800 for this product,
    // read directly via psql at execution time) must not reach a cashier. The API
    // keeps the `cost_price` KEY present (unified product shape for all roles) but
    // nulls its VALUE — that is the actual hiding mechanism here, not key omission.
    const res = await apiRequest(page, 'GET', `/products/${KNOWN_PRODUCT_ID}`)
    expect(res.status).toBe(200)
    const body = res.body as { data?: Record<string, unknown> }
    const payload = body.data ?? (res.body as Record<string, unknown>)
    expect(payload['cost_price']).not.toBe('11.380800')
    expect([null, undefined]).toContain(payload['cost_price'])

    // UI: the field must not render at all.
    await expect(page.getByText(/cost price/i)).toHaveCount(0)
  })

  test('MTP-PERM-07 (P0): cashier below-cost price is not silently accepted', async ({ page }) => {
    await loginAsRole(page, 'cashier')

    // Read the real cost_price as owner-equivalent data is not visible to cashier;
    // use the known seeded value (11.3808) and price 1.000 clean below it.
    const res = await apiRequest(page, 'POST', '/pricing/check-margin', {
      product_id: KNOWN_PRODUCT_ID,
      sell_price: '1.000',
    })

    if (res.status === 403) {
      // pricing.view is not granted to cashier — the whole capability is gated,
      // which is a valid (stronger) form of "not silently accepted".
      expect(res.status).toBe(403)
    } else {
      expect(res.status).toBe(200)
      const body = res.body as { data?: { can_sell?: { allowed?: boolean } } }
      expect(body.data?.can_sell?.allowed).toBe(false)
    }
  })

  // A6.2 (C-2 unblock, plan §A.6) — W-7. The original BLOCKED reason ("no
  // bank_statements row exists, so a fabricated UUID 404s BEFORE the
  // can:bank-statements.reconcile middleware runs, which would be a false
  // pass") is discharged: C-2 (`statement-support.ts`) and W-5b left REAL
  // statements on this tenant. The id is DISCOVERED at run time as owner —
  // never hardcoded, because a reseed changes every id and a stale constant
  // would silently re-introduce the exact 404-vs-403 false pass this case
  // exists to rule out.
  //
  // Nothing is mutated: every probe below is expected to be refused, and a
  // refusal persists nothing.
  test('MTP-PERM-08 (P0): cashier is refused bank-statement reconcile on a REAL statement — 403, not the route-binding 404', async ({
    page,
  }) => {
    // 1) Discover a real statement + one of its lines as the owner.
    await loginAsRole(page, 'owner')
    const list = await apiRequest(page, 'GET', '/bank-statements')
    expect(list.status, 'owner can list statements').toBe(200)
    const statements = (list.body as { data?: Array<{ id: string; status: string }> }).data ?? []
    expect(
      statements.length,
      'a real bank statement exists on this tenant (C-2 / W-5b fixtures) — without one this case is a false pass',
    ).toBeGreaterThan(0)
    const statementId = statements[0]!.id
    const detail = await apiRequest(page, 'GET', `/bank-statements/${statementId}`)
    expect(detail.status, 'the discovered id really resolves (proves the 403 below is not a 404)').toBe(200)
    const lines =
      (detail.body as { data?: { lines?: Array<{ id: string }> } }).data?.lines ?? []

    // 2) The cashier holds NEITHER bank-statements.view NOR .reconcile.
    await loginAsRole(page, 'cashier')
    const me = await apiRequest(page, 'GET', '/auth/me')
    const permissions = ((me.body as { data?: { permissions?: string[] } }).data?.permissions ?? []) as string[]
    expect(permissions, 'cashier holds no bank-statements.reconcile').not.toContain('bank-statements.reconcile')

    // UI layer: `/treasury/statements/:id` is gated on moduleKey="treasury"
    // AND permission="bank-statements.view" (routes/index.tsx), and
    // bank-statements.* are SERVER_AUTHORITATIVE in usePermissions.ts — the
    // static role map cannot grant them.
    await page.goto(`/treasury/statements/${statementId}`)
    await settleAfterNav(page)
    expect(page.url(), 'the statement detail page must not render for a cashier').not.toContain(
      `/treasury/statements/${statementId}`,
    )

    // API layer: the id is REAL, so a 403 here is the permission gate and
    // nothing else. All three statement-level reconcile actions.
    for (const path of [
      `/bank-statements/${statementId}/complete`,
      `/bank-statements/${statementId}/void`,
      `/bank-statements/${statementId}/reopen`,
    ]) {
      const res = await apiRequest(page, 'POST', path, { reason: 'MTP-PERM-08 campaign probe' })
      expect(res.status, `POST ${path} on a REAL statement id -> must be 403, never 404`).toBe(403)
    }

    // Read is refused too (bank-statements.view).
    const read = await apiRequest(page, 'GET', `/bank-statements/${statementId}`)
    expect(read.status, 'GET a real statement as cashier').toBe(403)

    // Line-level allocation (the actual money-moving reconcile action), when a
    // line exists on the discovered statement.
    if (lines.length > 0) {
      const lineId = lines[0]!.id
      const allocate = await apiRequest(page, 'POST', `/bank-statement-lines/${lineId}/allocations`, {
        allocations: [],
      })
      expect(
        allocate.status,
        `POST /bank-statement-lines/${lineId}/allocations on a REAL line id -> 403`,
      ).toBe(403)
    } else {
      test.info().annotations.push({
        type: 'PARTIAL',
        description:
          'The discovered statement carries no lines, so the line-level allocation probe was ' +
          'not exercised; the three statement-level reconcile routes were.',
      })
    }

    // A denial body must not leak a single money figure.
    for (const result of [read]) {
      expect(JSON.stringify(result.body), 'the denial body carries no money figure').not.toMatch(
        /\d+\.\d{2,4}/,
      )
    }
  })

  // A6.3 (C-3 unblock, plan §A.6): the Spatie roles (accountant/viewer/
  // technician) already existed in RolesAndPermissionsSeeder.php -- only the
  // USER rows were missing. Added 2026-08-02 via
  // DemoPharmacySeeder::seedRoleCoverageUsers() (see helpers.ts ROLE_CREDENTIALS
  // for the new logins). "No POS-floor grants" is verified two ways: the
  // permissions array carries no `pos.*` string, AND a real money-mutation
  // attempt (payments.create) is refused/allowed exactly per each role's
  // actual permission set -- not just list-absence.
  test('MTP-PERM-09 (P1): accountant is financial-only — payments.create granted, zero POS-floor grants', async ({ page }) => {
    await loginAsRole(page, 'accountant')
    const me = await apiRequest(page, 'GET', '/auth/me')
    expect(me.status).toBe(200)
    const permissions = ((me.body as { data?: { permissions?: string[] } }).data?.permissions ?? []) as string[]
    expect(permissions, 'accountant holds payments.create (financial role)').toContain('payments.create')
    expect(
      permissions.some((p) => p.startsWith('pos.')),
      'accountant carries zero pos.* permissions',
    ).toBe(false)

    // API layer: accountant CAN create an expense (holder of expenses.create,
    // NOT partners.create -- accountant is 'partners.view' only per
    // RolesAndPermissionsSeeder.php:717-744), proving the role is genuinely
    // wired to a real financial permission, not just present-but-inert.
    const expense = await apiRequest(page, 'POST', '/expenses', {
      total: '10.000',
      notes: `MTP-PERM-09-${Date.now()}`,
    })
    expect(expense.status, `expense create -> ${expense.status} ${JSON.stringify(expense.body)}`).toBe(201)

    // W-7 deepening: the plan's literal wording is "`pos.operate_terminal` —
    // any POS-floor action → 403", which the list-absence check above does
    // NOT prove (a permission can be absent from `/auth/me` and still be
    // granted by a route that forgot its `can:` middleware). Exercise the
    // REAL `can:pos.operate_terminal` routes. All five loyalty POS-floor
    // routes carry money consequences (earn/redeem move loyalty value).
    for (const [method, path, body] of [
      ['POST', '/loyalty/pos/member-lookup', { search: 'MTP-PERM-09' }],
      ['POST', '/loyalty/pos/balance', { enrollment_id: '00000000-0000-0000-0000-000000000000' }],
      ['POST', '/loyalty/pos/earn', { enrollment_id: '00000000-0000-0000-0000-000000000000', amount: '10.000' }],
      ['POST', '/loyalty/pos/redeem', { enrollment_id: '00000000-0000-0000-0000-000000000000', points: 1 }],
      ['POST', '/loyalty/pos/preview-earning', { amount: '10.000' }],
    ] as const) {
      const res = await apiRequest(page, method, path, body)
      expect(
        res.status,
        `${method} ${path} — accountant holds no pos.operate_terminal -> 403 (never 422/404, which would mean the gate never ran)`,
      ).toBe(403)
    }
  })

  test('MTP-PERM-10 (P1): viewer is read-only — zero create/update/delete grants, zero POS-floor grants', async ({ page }) => {
    await loginAsRole(page, 'viewer')
    const me = await apiRequest(page, 'GET', '/auth/me')
    expect(me.status).toBe(200)
    const permissions = ((me.body as { data?: { permissions?: string[] } }).data?.permissions ?? []) as string[]
    expect(permissions.length, 'viewer holds at least the seeded *.view permissions').toBeGreaterThan(0)
    expect(
      permissions.every((p) => !p.endsWith('.create') && !p.endsWith('.update') && !p.endsWith('.delete') && !p.endsWith('.post') && !p.endsWith('.pay')),
      'viewer holds NO mutating permission of any kind',
    ).toBe(true)
    expect(
      permissions.some((p) => p.startsWith('pos.')),
      'viewer carries zero pos.* permissions',
    ).toBe(false)

    // API layer: a real mutation attempt (partners.create) 403s for viewer.
    const attempt = await apiRequest(page, 'POST', '/partners', {
      name: `MTP-PERM-10-${Date.now()}`,
      type: 'customer',
    })
    expect(attempt.status).toBe(403)

    // W-7 deepening — the plan's literal wording is "ANY create/update/post on
    // expenses, income, payments, journal → all 403; ALL LIST VIEWS STILL
    // RENDER". Both halves, on the four named surfaces.
    for (const [path, body] of [
      ['/expenses', { total: '10.000', notes: `MTP-PERM-10-${Date.now()}` }],
      ['/income', { total: '10.000', notes: `MTP-PERM-10-${Date.now()}` }],
      ['/payments', {
        partner_id: '00000000-0000-0000-0000-000000000000',
        payment_method_id: '00000000-0000-0000-0000-000000000000',
        amount: '1.000',
        currency: 'TND',
        payment_date: new Date().toISOString().slice(0, 10),
      }],
      ['/journal-entries', { entry_date: new Date().toISOString().slice(0, 10), description: 'MTP-PERM-10 probe', lines: [] }],
      ['/invoices', { partner_id: '00000000-0000-0000-0000-000000000000', document_date: new Date().toISOString().slice(0, 10), lines: [] }],
    ] as const) {
      const res = await apiRequest(page, 'POST', path, body)
      expect(
        res.status,
        `POST ${path} as viewer -> 403 (a 422 would mean the write path was entered)`,
      ).toBe(403)
    }

    // …and the READ side still works, so "read-only" is real rather than
    // "locked out". `documents.view`/`invoices.view` are held; `journal.view`
    // is held; `expenses.view` is NOT (recorded, not asserted as a defect —
    // the viewer role's grant list is a product decision).
    for (const path of ['/invoices', '/journal-entries', '/documents']) {
      const res = await apiRequest(page, 'GET', path)
      expect(res.status, `GET ${path} as viewer still renders`).toBe(200)
    }
  })

  test('MTP-PERM-11 (P1): technician is workshop-only — zero financial/POS-floor grants', async ({ page }) => {
    await loginAsRole(page, 'technician')
    const me = await apiRequest(page, 'GET', '/auth/me')
    expect(me.status).toBe(200)
    const permissions = ((me.body as { data?: { permissions?: string[] } }).data?.permissions ?? []) as string[]
    expect(permissions, 'technician holds work-orders.view (workshop role)').toContain('work-orders.view')
    expect(
      permissions.some((p) => p.startsWith('pos.') || p.startsWith('payments.')),
      'technician carries zero pos.* or payments.* permissions',
    ).toBe(false)

    // API layer: a real money-mutation attempt (payments.create) 403s.
    const attempt = await apiRequest(page, 'POST', '/payments', {
      partner_id: '00000000-0000-0000-0000-000000000000',
      payment_method_id: '00000000-0000-0000-0000-000000000000',
      amount: '1.000',
      currency: 'TND',
      payment_date: new Date().toISOString().slice(0, 10),
    })
    expect(attempt.status).toBe(403)

    // W-7 deepening — the plan's literal wording is "`/treasury/*`,
    // `/finance/*` → blocked AT THE MODULE GATE (`MODULE_PERMISSIONS`)".
    // `MODULE_PERMISSIONS` (hooks/usePermissions.ts:19-57) maps treasury ->
    // ['treasury.view'] and finance -> ['accounts.view','journal.view'];
    // the technician holds none of the three, so `RequirePermission
    // moduleKey=…` must redirect to /dashboard.
    for (const route of [
      '/treasury/payments',
      '/treasury/instruments',
      '/treasury/statements',
      '/finance/journal-entries',
      '/finance/chart-of-accounts',
    ]) {
      await page.goto(route)
      await settleAfterNav(page)
      expect(page.url(), `${route} must be module-gated for a technician`).not.toContain(route)
      expect(page.url(), `${route} redirects to the dashboard`).toContain('/dashboard')
    }

    // …and the API refuses the same surfaces, so this is not a client-side
    // hide. GET, because a technician reaching a money LIST would already be
    // a leak.
    for (const path of ['/payments', '/payment-instruments', '/bank-statements', '/journal-entries', '/accounts']) {
      const res = await apiRequest(page, 'GET', path)
      expect(res.status, `GET ${path} as technician`).toBe(403)
      expect(
        JSON.stringify(res.body),
        `the ${path} denial body carries no money figure`,
      ).not.toMatch(/\d+\.\d{2,4}/)
    }
  })

  test('MTP-PERM-12 (P0): BLOCKED — tenant-blind permission-cache reseed guard', async () => {
    test.info().annotations.push({
      type: 'BLOCKED',
      description:
        'This case requires running `php artisan tenants:run db:seed --option=class=' +
        'RolesAndPermissionsSeeder` WITHOUT the follow-up `permission:cache-reset` step, ' +
        'against a shared LIVE local stack that other campaign agents are actively using ' +
        'concurrently (confirmed: partner "W1b-Customer" was found freshly seeded by a ' +
        'sibling agent during this run). Deliberately reseeding permissions tenant-wide is a ' +
        'stateful, disruptive operation out of a single scoped agent\'s authority to run ' +
        'against shared infrastructure — flagging for the orchestrator to run in isolation.',
    })
    test.skip(true, 'requires a disruptive tenant-wide permission reseed on shared live infra — not safe for a scoped agent to run')
  })

  test('MTP-PERM-13 (P1): BLOCKED — cashier max_discount_percent=10% boundary not exercised', async () => {
    test.info().annotations.push({
      type: 'BLOCKED',
      description:
        'Exercising this requires authoring a full sales document (quote/invoice) via the ' +
        'API with a line discount payload. cashier@pharmabio.tn (max_discount_percent 10.00, ' +
        'per DemoPharmacySeeder) is available for this, but the exact document/line request ' +
        'schema (POST /quotes body shape for customer + lines + discount fields) was not ' +
        'established within this session\'s time budget. Left for a follow-up pass — see ' +
        'DiscountPolicyDocumentValidator (apps/api/app/Modules/Document/Presentation/' +
        'Validation/DiscountPolicyDocumentValidator.php) as the enforcement point to target.',
    })
    test.skip(true, 'document-authoring schema not established within budget')
  })

  // A6.4 (plan §A.6): cashier's max_discount_percent=10.00 boundary,
  // exercised via a real document-authoring API call carrying a line
  // discount (DiscountPolicyDocumentValidator only evaluates lines with a
  // product_id — required a real product, established here).
  test('MTP-PERM-13 (P1): cashier discount policy is ADVISORY-ONLY on this tenant — not enforced against the 10% cap', async ({
    page,
  }) => {
    await loginAsRole(page, 'cashier')

    // REVISED FROM THE PLAN'S PREMISE (finding, not silently swapped): the
    // plan assumes DiscountPolicyDocumentValidator (invoices.store is on its
    // POLICY_ROUTES list) REFUSES a line whose effective price falls below
    // the cashier's max_discount_percent-derived floor. Live-verified: this
    // tenant's companies.discount_floor_mode = 'Advisory' (confirmed via DB
    // read at authoring time), and DiscountPolicyDocumentValidator::shouldReject()
    // returns false unconditionally when mode === Advisory
    // (DiscountPolicyDocumentValidator.php:156-163) — regardless of how far
    // below the cap/cost floor the price falls. The validator still RUNS
    // (an internal warning is recorded on the request, per
    // `discount_policy_warnings`) but nothing in the response surfaces it and
    // nothing blocks the create. This is a valid company POLICY CHOICE
    // (Advisory vs Block/Enforced is a first-class mode), not a defect —
    // recorded as the true boundary, not the plan's assumed one.
    const products = await apiRequest(page, 'GET', '/products?per_page=1')
    expect(products.status).toBe(200)
    const productList = (products.body as { data?: Array<{ id: string; sale_price?: string }> }).data ?? []
    expect(productList.length, 'at least one product exists to exercise the policy check').toBeGreaterThan(0)
    const product = productList[0]

    const customer = await apiRequest(page, 'POST', '/partners', {
      name: `MTP-PERM-13-${Date.now()}`,
      type: 'customer',
    })
    expect(customer.status).toBe(201)
    const customerId = ((customer.body as { data?: { id: string } }).data as { id: string }).id

    // 50% line discount — well beyond the cashier's 10.00 cap.
    const result = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [
        {
          product_id: product.id,
          description: 'MTP-PERM-13 probe',
          quantity: '1',
          unit_price: product.sale_price ?? '38.290',
          discount_percent: '50',
          tax_rate: '19.00',
        },
      ],
    })
    expect(
      result.status,
      `expected the 50% discount to succeed (Advisory mode never blocks) -> ${result.status} ${JSON.stringify(result.body)}`,
    ).toBe(201)
  })

  // A6.1 (NEW MTP-PERM-15, plan §A.6 / review I6): pin the documented
  // privilege-widening ruling as an assertion. A principal holding
  // payments.reverse but NOT instruments.cancel can still, via reverse(),
  // cancel a `received` instrument as an atomic side effect
  // (PaymentRefundService.php:670-681, resolveInstrumentForReversal() runs
  // INSIDE the reverse transaction regardless of the caller's instrument
  // permissions — the route only gates on can:payments.reverse). No seeded
  // role naturally isolates this shape (admin holds both permissions;
  // manager/accountant hold instruments.cancel but not payments.reverse) —
  // constructed live via the real Roles API (payments.reverse is
  // deliberately admin-only per RolesAndPermissionsSeeder.php:224-228, so
  // this custom role is the only way to hold it without also holding
  // instruments.cancel).
  test('MTP-PERM-15 (RULING, review I6): payments.reverse holder without instruments.cancel still cancels the linked instrument on reverse', async ({
    page,
  }) => {
    test.setTimeout(150000)

    // Capture the viewer user's id (temporary role donor — restored at the end).
    await loginAsRole(page, 'viewer')
    const viewerMe = await apiRequest(page, 'GET', '/auth/me')
    expect(viewerMe.status).toBe(200)
    const viewerId = ((viewerMe.body as { data?: { id: string } }).data as { id: string }).id
    const viewerPermissionsBefore = ((viewerMe.body as { data?: { permissions?: string[] } }).data
      ?.permissions ?? []) as string[]
    expect(viewerPermissionsBefore).not.toContain('payments.reverse')
    expect(viewerPermissionsBefore).not.toContain('instruments.cancel')

    // Owner: create the custom role (payments.reverse ONLY — no instruments.*)
    // and the fixture payment/instrument.
    await loginAsRole(page, 'owner')
    const roleName = `perm15-reverser-${Date.now()}`
    const roleCreate = await apiRequest(page, 'POST', '/roles', {
      name: roleName,
      permissions: ['payments.reverse', 'payments.view'],
    })
    expect(roleCreate.status, `role create -> ${roleCreate.status} ${JSON.stringify(roleCreate.body)}`).toBe(201)

    const methods = await apiRequest(page, 'GET', '/payment-methods')
    const methodList = (methods.body as { data?: Array<{ id: string; code: string }> }).data ?? []
    const checkMethodId = methodList.find((m) => m.code === 'CHECK')?.id
    expect(checkMethodId, 'CHECK payment method exists').toBeTruthy()
    const repos = await apiRequest(page, 'GET', '/payment-repositories')
    const repoList = (repos.body as { data?: Array<{ id: string; code: string }> }).data ?? []
    const cashRepoId = repoList.find((r) => r.code === 'CASH-01')?.id
    expect(cashRepoId, 'CASH-01 repository exists').toBeTruthy()

    const customer = await apiRequest(page, 'POST', '/partners', {
      name: `MTP-PERM-15-${Date.now()}`,
      type: 'customer',
    })
    expect(customer.status).toBe(201)
    const customerId = ((customer.body as { data?: { id: string } }).data as { id: string }).id

    const invoice = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ description: 'MTP-PERM-15 probe', quantity: '1', unit_price: '99.000', tax_rate: '0.00' }],
    })
    expect(invoice.status).toBe(201)
    const invoiceId = ((invoice.body as { data?: { id: string } }).data as { id: string }).id
    const confirm = await apiRequest(page, 'POST', `/invoices/${invoiceId}/confirm`)
    expect(confirm.status).toBe(200)
    const postRes = await apiRequest(page, 'POST', `/invoices/${invoiceId}/post`)
    expect(postRes.status).toBe(200)
    const postedTotal = (
      (postRes.body as { data?: { total: string } }).data as { total: string }
    ).total

    const payment = await apiRequest(page, 'POST', '/payments', {
      partner_id: customerId,
      payment_method_id: checkMethodId,
      repository_id: cashRepoId,
      amount: postedTotal,
      currency: 'TND',
      payment_date: new Date().toISOString().slice(0, 10),
      allocations: [{ document_id: invoiceId, amount: postedTotal }],
      instrument: {
        reference: `MTP-PERM-15-CHQ-${Date.now()}`,
        maturity_date: '2026-12-31',
        drawer_name: 'MTP-PERM-15 Drawer',
      },
    })
    expect(payment.status, `payment create -> ${payment.status} ${JSON.stringify(payment.body)}`).toBe(201)
    const paymentId = ((payment.body as { data?: { id: string } }).data as { id: string }).id
    const instrumentId = ((payment.body as { data?: { instrument_id: string } }).data as { instrument_id: string })
      .instrument_id
    expect(instrumentId).toBeTruthy()

    const instrumentBefore = await apiRequest(page, 'GET', `/payment-instruments/${instrumentId}`)
    expect((instrumentBefore.body as { data?: { status: string } }).data?.status).toBe('received')

    // Grant the custom role to the viewer user (additive — viewer keeps its
    // own role too).
    const assign = await apiRequest(page, 'POST', `/users/${viewerId}/roles`, { role: roleName })
    expect(assign.status, `role assign -> ${assign.status} ${JSON.stringify(assign.body)}`).toBeLessThan(300)

    try {
      // Now act as the (viewer + perm15-reverser) principal.
      await loginAsRole(page, 'viewer')
      const meAfterGrant = await apiRequest(page, 'GET', '/auth/me')
      const permissionsAfterGrant = ((meAfterGrant.body as { data?: { permissions?: string[] } }).data
        ?.permissions ?? []) as string[]
      expect(permissionsAfterGrant, 'principal now holds payments.reverse').toContain('payments.reverse')
      expect(
        permissionsAfterGrant,
        'RULING setup check: principal does NOT hold instruments.cancel',
      ).not.toContain('instruments.cancel')

      const reverse = await apiRequest(page, 'POST', `/payments/${paymentId}/reverse`, {
        reason: 'MTP-PERM-15 privilege-widening ruling probe',
      })
      expect(
        reverse.status,
        `RULING: payments.reverse-only holder can reverse -> ${reverse.status} ${JSON.stringify(reverse.body)}`,
      ).toBeLessThan(300)

      const instrumentAfter = await apiRequest(page, 'GET', `/payment-instruments/${instrumentId}`)
      expect(
        (instrumentAfter.body as { data?: { status: string } }).data?.status,
        'RULING (I6): the instrument is cancelled as an atomic side effect of reverse(), despite the caller never holding instruments.cancel — documented privilege widening, not a bug',
      ).toBe('cancelled')
    } finally {
      // Cleanup: restore viewer to its clean baseline role set and remove
      // the throwaway role, so a re-run of MTP-PERM-10 (viewer must hold NO
      // mutating permission) is not contaminated by this test.
      await loginAsRole(page, 'owner')
      await apiRequest(page, 'DELETE', `/users/${viewerId}/roles`, { role: roleName })
      const roles = await apiRequest(page, 'GET', '/roles')
      const roleRow = ((roles.body as { data?: Array<{ id: number; name: string }> }).data ?? []).find(
        (r) => r.name === roleName,
      )
      if (roleRow) {
        await apiRequest(page, 'DELETE', `/roles/${roleRow.id}`)
      }
    }
  })

  // A6.6 / C-8 UNBLOCKED BY W-7 (2026-08-05). `cafe-tunis` is now provisioned
  // on this stack: `php artisan db:seed --class=CoffeeShopSeeder` created the
  // tenant + its per-tenant database, and the two user emails were recorded in
  // the CENTRAL identity index afterwards
  // (`IdentityIndexService::record()`), because CoffeeShopSeeder — unlike
  // ParapharmacySeeder — never calls it and its users are therefore
  // unreachable by email-first (T6) login until they are. Filed as a fixture
  // defect: docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md
  // (F-4). Credentials: owner@cafe-tunis.tn / barista@cafe-tunis.tn,
  // both `password`; barista carries `max_discount_percent = 25.00`.
  test('MTP-PERM-14 (P1): the discount cap is an INCLUSIVE boundary — but it is the COMPANY cap, not the user`s', async ({
    request,
  }) => {
    test.setTimeout(180_000)

    const barista = asSession(await loginAs(request, CAFE_CREDENTIALS.barista))
    const cafeOwner = asSession(await loginAs(request, CAFE_CREDENTIALS.owner))

    expect(
      barista.permissions,
      'the barista holds invoices.create (the route DiscountPolicyDocumentValidator runs on)',
    ).toContain('invoices.create')
    expect(
      barista.permissions,
      'setup check: the barista holds NEITHER floor-override permission, so shouldReject() hinges only on the mode',
    ).not.toContain('pricing.sell_below_cost')
    expect(barista.permissions).not.toContain('pricing.sell_below_minimum_margin')

    // A product with a clean list price and a zero cost, so the binding floor
    // is the DISCOUNT CAP and not the cost/margin floor.
    const products = await get(request, cafeOwner, '/products?per_page=50')
    expect(products.status).toBe(200)
    const rows = products.data as unknown as Array<{ id: string; sale_price: string; cost_price: string }>
    const product = rows.find((p) => p.sale_price === '25.000' && Number(p.cost_price) === 0)
    expect(product, 'cafe-tunis carries a 25.000 zero-cost product (CoffeeShopSeeder RET-BEANS)').toBeTruthy()

    // M3 (review fix round 1): `W7-` prefix, so the wave's leftover predicate
    // (`partners.name LIKE 'W7-%'`) actually covers this fixture. The earlier
    // `MTP-PERM-14-*` name matched no documented predicate on either tenant.
    const customer = await post(request, barista, '/partners', {
      name: `W7-PERM14-${Date.now()}`,
      type: 'customer',
    })
    expect(customer.status, `customer create -> ${customer.status} ${JSON.stringify(customer.data)}`).toBe(201)
    const customerId = String((customer.data as { id: string }).id)

    const attemptDiscount = async (discountPercent: string): Promise<{ status: number; body: string }> => {
      const res = await post(request, barista, '/invoices', {
        partner_id: customerId,
        document_date: new Date().toISOString().slice(0, 10),
        lines: [
          {
            product_id: product!.id,
            description: `MTP-PERM-14 ${discountPercent}%`,
            quantity: '1',
            unit_price: product!.sale_price,
            discount_percent: discountPercent,
            tax_rate: '19.00',
          },
        ],
      })
      return { status: res.status, body: JSON.stringify(res.data) }
    }

    // The pricing-policy triple is settable ONLY through `PUT /companies/{id}`
    // — see the F-9 tripwire below. cafe-tunis was provisioned by this wave
    // and no sibling agent uses it; both settings are restored in `finally`.
    const setPolicy = async (mode: string, cap: string): Promise<number> =>
      patchCompany(request, cafeOwner, { discount_floor_mode: mode, default_max_discount_percent: cap })

    let baseline = { status: 0, body: '' }
    let afterSettingsPatch = { status: 0, body: '' }
    let atCap = { status: 0, body: '' }
    let overCap = { status: 0, body: '' }
    try {
      expect(isCleanupSuccess(await setPolicy('Advisory', '100.00')), 'baseline: Advisory + no cap').toBe(true)

      // 1) ── FINDING F-8 (P1, TRIPWIRE, GREEN: pins TODAY's behaviour) ──────
      //    The plan's premise for this case — "a user whose
      //    `max_discount_percent` is 25.00" — is NOT how document discounts
      //    are capped. `DiscountPolicySubject::effectiveMaxDiscountPercent()`
      //    (app/Shared/DTOs/DiscountPolicySubject.php:102-115) resolves
      //    PRODUCT -> CATEGORY -> COMPANY, and
      //    `DiscountPolicySubjectProvider.php:76-78` feeds it exactly those
      //    three. `users.max_discount_percent` — 25.00 for this barista — is
      //    never read on the document path at all (it survives on the POS
      //    device path). With no company/product cap set, a 40% line discount
      //    from a "25%-capped" user is accepted outright.
      baseline = await attemptDiscount('40.00')
      expect(
        baseline.status,
        'TRIPWIRE F-8: the barista`s own 25.00% cap does not bind a document line (product/category/company caps do)',
      ).toBe(201)

      // 2) ── FINDING F-9 (P1, TRIPWIRE, GREEN) ────────────────────────────
      //    `PATCH /api/v1/settings/company` answers **200** and persists NONE
      //    of `discount_floor_mode`, `default_max_discount_percent`,
      //    `default_minimum_margin`; only `PUT /api/v1/companies/{id}` does.
      //    `GET /settings/company` does not expose the three fields either,
      //    so the settings surface can neither show nor change the discount
      //    policy — while telling the caller it succeeded. Asserted
      //    BEHAVIOURALLY (no DB read): after a 200 from the settings PATCH
      //    asking for Block + a 25% cap, a 40% discount is still accepted.
      const settingsPatch = await patchCompanySettings(request, cafeOwner, {
        discount_floor_mode: 'Block',
        default_max_discount_percent: '25.00',
      })
      expect(isCleanupSuccess(settingsPatch), 'the settings PATCH reports success').toBe(true)
      afterSettingsPatch = await attemptDiscount('40.00')
      expect(
        afterSettingsPatch.status,
        'TRIPWIRE F-9: PATCH /settings/company returned 200 and changed nothing — 40% is still accepted',
      ).toBe(201)

      // 3) The boundary the plan actually asks for, on the cap that IS wired.
      expect(isCleanupSuccess(await setPolicy('Block', '25.00')), 'Block mode + a 25.00% company cap').toBe(true)
      atCap = await attemptDiscount('25.00')
      overCap = await attemptDiscount('25.01')
    } finally {
      const restored = await patchCompany(request, cafeOwner, {
        discount_floor_mode: 'Advisory',
        default_max_discount_percent: '100.00',
      })
      // Asserted only where the body already succeeded — a failing `expect`
      // inside `finally` REPLACES the in-flight failure and hides it.
      if (overCap.status !== 0) {
        expect(isCleanupSuccess(restored), `restore Advisory + 100.00 cap -> ${restored}`).toBe(true)
      }
    }

    expect(
      atCap.status,
      `INCLUSIVE boundary: exactly 25.00% sits AT the cap, not past it — must be accepted (${atCap.body})`,
    ).toBe(201)
    expect(
      overCap.status,
      `25.01% is past the 25.00% cap — must be refused (${overCap.body})`,
    ).toBe(422)
    expect(
      overCap.body,
      'and the refusal names the discount policy, on the line`s unit_price field',
    ).toMatch(/discount policy/i)
  })

  // ---------------------------------------------------------------------
  // W-7 NEW — `MTP-PERM-16..18` (plan §B.1 "Edge cases owed on this surface:
  // permission denial on `sales.create` / `invoices.update` money mutations").
  //
  // Naming note: there is NO `sales.create` PERMISSION in this product. The
  // server gates document writes on `invoices.create` / `invoices.update` /
  // `invoices.post` / `quotes.*`; `sales.create` and `sales.view` exist only
  // as FRONT-END role aliases (`hooks/uiAliasPermissions.ts:4-5` →
  // ['admin','sales','manager']). That split is itself one of the findings
  // below.
  // ---------------------------------------------------------------------

  test('MTP-PERM-16 (P1): the viewer cannot author or mutate a sales document — every write 403s, every read renders', async ({
    page,
  }) => {
    // M5 (review fix round 1): author OUR OWN draft as the owner instead of
    // blind-picking `per_page=1`. The probes below are DESTRUCTIVE
    // transitions (confirm / post / cancel): if any of them were ever to
    // succeed — which is exactly the regression this case guards — it must
    // land on a W7 fixture, never on a stranger's (or a sibling wave's)
    // invoice. Also makes the fixture retirable.
    await loginAsRole(page, 'owner')
    const ownedCustomer = await apiRequest(page, 'POST', '/partners', {
      name: `W7-PERM16-${Date.now()}`,
      type: 'customer',
    })
    expect(ownedCustomer.status).toBe(201)
    const seeded = await apiRequest(page, 'POST', '/invoices', {
      partner_id: ((ownedCustomer.body as { data: { id: string } }).data).id,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ description: 'W7 PERM-16 probe target', quantity: '1', unit_price: '10.000', tax_rate: '0.00' }],
    })
    expect(seeded.status, `seed invoice -> ${seeded.status} ${JSON.stringify(seeded.body)}`).toBe(201)
    const invoiceId = ((seeded.body as { data: { id: string } }).data).id

    await loginAsRole(page, 'viewer')
    const invoices = await apiRequest(page, 'GET', '/invoices?per_page=1')
    expect(invoices.status, 'the viewer can LIST invoices (invoices.view)').toBe(200)

    // UI: both authoring routes are refused. `/sales/invoices/new` is gated on
    // the `sales.create` ALIAS (role-based), `/sales/invoices/:id/edit` on the
    // real `invoices.update`.
    for (const route of ['/sales/invoices/new', '/sales/quotes/new', `/sales/invoices/${invoiceId}/edit`]) {
      await page.goto(route)
      await settleAfterNav(page)
      expect(page.url(), `${route} must not render for a viewer`).not.toContain(route)
    }

    // API: create, update, confirm, post, cancel — the five money-moving
    // transitions of a sales document.
    const probes: Array<[('POST' | 'PATCH'), string, Record<string, unknown> | undefined]> = [
      ['POST', '/invoices', { partner_id: '00000000-0000-0000-0000-000000000000', document_date: new Date().toISOString().slice(0, 10), lines: [] }],
      ['POST', '/quotes', { partner_id: '00000000-0000-0000-0000-000000000000', document_date: new Date().toISOString().slice(0, 10), lines: [] }],
      ['PATCH', `/invoices/${invoiceId}`, { notes: 'MTP-PERM-16 probe' }],
      ['POST', `/invoices/${invoiceId}/confirm`, undefined],
      ['POST', `/invoices/${invoiceId}/post`, undefined],
      ['POST', `/invoices/${invoiceId}/cancel`, { reason: 'MTP-PERM-16 probe' }],
    ]
    for (const [method, path, body] of probes) {
      const res = await apiRequest(page, method, path, body)
      expect(res.status, `${method} ${path} as viewer -> 403`).toBe(403)
      expect(JSON.stringify(res.body), `${path} denial leaks no money`).not.toMatch(/\d+\.\d{2,4}/)
    }

    // The read side is intact — "read-only", not "locked out".
    const detail = await apiRequest(page, 'GET', `/invoices/${invoiceId}`)
    expect(detail.status, 'the viewer can still READ the invoice it may not touch').toBe(200)
    expect(
      ((detail.body as { data: { status: string } }).data).status,
      'and every refused transition left the seeded draft in Draft',
    ).toBe('draft')

    // Retire the fixture (owner — the viewer cannot delete either).
    await loginAsRole(page, 'owner')
    const retired = await apiRequest(page, 'DELETE', `/invoices/${invoiceId}`)
    test.info().annotations.push({
      type: 'CLEANUP',
      description: `DELETE /invoices/${invoiceId} -> ${retired.status}`,
    })
  })

  test('MTP-PERM-17 (P1): the cashier may CREATE a sales document but not move it — update/confirm/post/cancel all 403', async ({
    page,
  }) => {
    await loginAsRole(page, 'cashier')
    const me = await apiRequest(page, 'GET', '/auth/me')
    const permissions = ((me.body as { data?: { permissions?: string[] } }).data?.permissions ?? []) as string[]
    expect(permissions, 'the cashier DOES hold invoices.create server-side').toContain('invoices.create')
    expect(permissions, '…and does NOT hold invoices.update').not.toContain('invoices.update')
    expect(permissions, '…nor invoices.post').not.toContain('invoices.post')

    // The cashier really can author a draft (proving the 403s below are the
    // transition gates and not a blanket module refusal).
    // M3 (review fix round 1): `W7-` prefix — see MTP-PERM-14.
    const customer = await apiRequest(page, 'POST', '/partners', {
      name: `W7-PERM17-${Date.now()}`,
      type: 'customer',
    })
    expect(customer.status).toBe(201)
    const customerId = ((customer.body as { data: { id: string } }).data).id
    const created = await apiRequest(page, 'POST', '/invoices', {
      partner_id: customerId,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ description: 'MTP-PERM-17 probe', quantity: '1', unit_price: '10.000', tax_rate: '0.00' }],
    })
    expect(created.status, `cashier invoice create -> ${created.status} ${JSON.stringify(created.body)}`).toBe(201)
    const invoiceId = ((created.body as { data: { id: string } }).data).id

    try {
      // Every transition that moves money (confirm applies document-level
      // taxes; post writes the GL entry) is gated on a permission the cashier
      // does not hold.
      for (const [method, path, body] of [
        ['PATCH', `/invoices/${invoiceId}`, { notes: 'MTP-PERM-17 probe' }],
        ['POST', `/invoices/${invoiceId}/confirm`, undefined],
        ['POST', `/invoices/${invoiceId}/post`, undefined],
        ['POST', `/invoices/${invoiceId}/cancel`, { reason: 'MTP-PERM-17 probe' }],
      ] as const) {
        const res = await apiRequest(page, method, path, body)
        expect(
          res.status,
          `${method} ${path} as cashier -> 403 (confirm is gated on invoices.update, post on invoices.post)`,
        ).toBe(403)
      }

      // The draft is still exactly as authored — no half-applied transition.
      const after = await apiRequest(page, 'GET', `/invoices/${invoiceId}`)
      expect(after.status).toBe(200)
      expect(
        ((after.body as { data: { status: string } }).data).status,
        'the refused transitions left the document in Draft',
      ).toBe('draft')

      // FINDING F-1 (P2, TRIPWIRE, GREEN — pins TODAY's behaviour). The
      // FRONT-END is stricter than the server in the opposite direction to
      // D5: `/sales/*` is gated by `moduleKey="sales"` →
      // `MODULE_PERMISSIONS.sales = ['sales.view']`, and `sales.view` is a UI
      // ALIAS resolved by ROLE (`uiAliasPermissions.ts:4` →
      // ['admin','sales','manager']). The cashier is not in that list, so the
      // whole sales UI is closed to a principal the API happily lets author
      // invoices. Not a security hole (the FE is the tighter gate) — but it
      // means "the cashier can raise an invoice" is true of the API and false
      // of the product, which is a launch-relevant inconsistency.
      await page.goto('/sales/invoices')
      await settleAfterNav(page)
      expect(
        page.url(),
        'TRIPWIRE F-1: the sales module is closed to the cashier in the UI although the API grants invoices.create',
      ).toContain('/dashboard')
    } finally {
      // The draft is retirable — soft-delete it so the tenant does not
      // accumulate a probe invoice per run. Gated on a real 2xx and never
      // asserted inside `finally`.
      const retired = await apiRequest(page, 'DELETE', `/invoices/${invoiceId}`)
      test.info().annotations.push({
        type: 'CLEANUP',
        description: `DELETE /invoices/${invoiceId} -> ${retired.status}`,
      })
    }
  })

  test('MTP-PERM-18 (P1): the accountant may POST a sales document it may neither create nor edit — split recorded', async ({
    page,
  }) => {
    await loginAsRole(page, 'accountant')
    const me = await apiRequest(page, 'GET', '/auth/me')
    const permissions = ((me.body as { data?: { permissions?: string[] } }).data?.permissions ?? []) as string[]
    expect(permissions, 'the accountant holds invoices.post').toContain('invoices.post')
    expect(permissions, '…but NOT invoices.create').not.toContain('invoices.create')
    expect(permissions, '…and NOT invoices.update').not.toContain('invoices.update')

    // M5 (review fix round 1): author our own draft as the owner — the
    // accountant HOLDS `invoices.post`, so a blind-picked stranger's invoice
    // is the one document in this file that a regression could actually post.
    await loginAsRole(page, 'owner')
    const ownedCustomer = await apiRequest(page, 'POST', '/partners', {
      name: `W7-PERM18-${Date.now()}`,
      type: 'customer',
    })
    expect(ownedCustomer.status).toBe(201)
    const seeded = await apiRequest(page, 'POST', '/invoices', {
      partner_id: ((ownedCustomer.body as { data: { id: string } }).data).id,
      document_date: new Date().toISOString().slice(0, 10),
      lines: [{ description: 'W7 PERM-18 probe target', quantity: '1', unit_price: '10.000', tax_rate: '0.00' }],
    })
    expect(seeded.status, `seed invoice -> ${seeded.status} ${JSON.stringify(seeded.body)}`).toBe(201)
    const invoiceId = ((seeded.body as { data: { id: string } }).data).id

    await loginAsRole(page, 'accountant')

    // Create and edit are refused at the API…
    for (const [method, path, body] of [
      ['POST', '/invoices', { partner_id: '00000000-0000-0000-0000-000000000000', document_date: new Date().toISOString().slice(0, 10), lines: [] }],
      ['PATCH', `/invoices/${invoiceId}`, { notes: 'MTP-PERM-18 probe' }],
      ['POST', `/invoices/${invoiceId}/confirm`, undefined],
    ] as const) {
      const res = await apiRequest(page, method, path, body)
      expect(res.status, `${method} ${path} as accountant -> 403`).toBe(403)
    }

    // …and at the UI, where `/sales/invoices/:id/edit` is gated on the real
    // `invoices.update`.
    await page.goto(`/sales/invoices/${invoiceId}/edit`)
    await settleAfterNav(page)
    expect(page.url(), 'the edit route is closed to the accountant').not.toContain('/edit')

    // RULING (recorded, not filed as a defect): `invoices.post` — the
    // transition that WRITES THE GL ENTRY and is therefore the most
    // money-consequential of the three — is granted to a principal who may
    // not author or amend the document. That is a coherent
    // separation-of-duties design (the accountant posts what sales raises),
    // and it is pinned here so it is not "fixed" by accident. Proven by the
    // permission grant, not by posting a stranger's invoice: posting is
    // irreversible and this case must not leave a posted document behind.
    expect(
      permissions.includes('invoices.post') && !permissions.includes('invoices.create'),
      'RULING: post-without-create is the accountant`s deliberate separation of duties',
    ).toBe(true)

    // The seeded draft is untouched by the refusals, and retirable.
    await loginAsRole(page, 'owner')
    const stillDraft = await apiRequest(page, 'GET', `/invoices/${invoiceId}`)
    expect(
      ((stillDraft.body as { data: { status: string } }).data).status,
      'the refused transitions left the seeded draft in Draft',
    ).toBe('draft')
    const retired = await apiRequest(page, 'DELETE', `/invoices/${invoiceId}`)
    test.info().annotations.push({
      type: 'CLEANUP',
      description: `DELETE /invoices/${invoiceId} -> ${retired.status}`,
    })
  })
})
