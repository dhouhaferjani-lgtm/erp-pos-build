import { test, expect } from './fixtures'
import type { Page, Request, Route } from '@playwright/test'

/**
 * E2E lane for the P1 counting-wizard product_location scope fix.
 *
 * The unit fix (Vitest 74/74) makes the product_location scope render a
 * single-location selector writing `scope_filters.location_id`, and requires
 * both `product_ids` and `location_id` before the wizard can proceed. These
 * specs drive the REAL wizard against the mock-stack harness (no live API),
 * asserting the intercepted create payload and a full regression sweep of every
 * other scope so the fix demonstrably did not disturb them.
 *
 * All URLs force `?lang=en` (i18n `lookupQuerystring: 'lang'`) so the harness
 * renders the pinned English strings this file asserts. Nav buttons render the
 * bare i18n keys `next`/`previous` (no top-level inventory translation exists),
 * so they are matched by their exact accessible name.
 */

// ─── Pinned mock data (no faker/uuid/Date.now) ──────────────────────────────

const MOCK_LOCATIONS = [
  {
    id: 'location-1',
    company_id: 'company-1',
    name: 'Main Shop',
    code: 'LOC-001',
    type: 'shop',
    phone: null,
    email: null,
    address_street: null,
    address_city: null,
    address_postal_code: null,
    address_country: null,
    tax_id: null,
    vat_number: null,
    legal_identifiers: null,
    is_default: true,
    is_active: true,
    pos_enabled: false,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  },
  {
    id: 'location-2',
    company_id: 'company-1',
    name: 'Warehouse',
    code: 'LOC-002',
    type: 'warehouse',
    phone: null,
    email: null,
    address_street: null,
    address_city: null,
    address_postal_code: null,
    address_country: null,
    tax_id: null,
    vat_number: null,
    legal_identifiers: null,
    is_default: false,
    is_active: true,
    pos_enabled: false,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  },
]

const MOCK_PRODUCTS = [
  {
    id: 'prod-1',
    name: 'Brake Pads - Premium',
    sku: 'BP-001',
    barcode: '5900000000001',
    sale_price: '65.000',
    purchase_price: '45.000',
    has_variants: false,
  },
  {
    id: 'prod-2',
    name: 'Oil Filter',
    sku: 'OF-001',
    barcode: '5900000000002',
    sale_price: '25.000',
    purchase_price: '15.000',
    has_variants: false,
  },
]

const MOCK_USERS = [
  { id: 'user-10', name: 'Sami Counter', email: 'sami@example.test' },
  { id: 'user-11', name: 'Nadia Counter', email: 'nadia@example.test' },
]

const MOCK_CATEGORIES = [
  {
    id: 42,
    company_id: 'company-1',
    parent_id: null,
    name: 'Brakes',
    slug: 'brakes',
    description: null,
    image_url: null,
    path: 'brakes',
    depth: 0,
    sort_order: 0,
    is_active: true,
    products_count: 3,
    breadcrumb: null,
    children: null,
  },
]

const MOCK_NODES = [
  {
    id: 'node-a1',
    location_id: 'location-1',
    parent_id: null,
    node_type: 'aisle',
    name: 'Aisle 1',
    code: 'A1',
    path: 'A1',
    depth: 0,
    sort_order: 0,
    is_active: true,
    product_count: 0,
    deleted_at: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  },
]

/**
 * `GET /inventory/countings/{id}` shape consumed by CountingDetailPage. Only the
 * fields the page actually reads are pinned; the sales-during-count block reads
 * `block_sales`, `scope_type` and `ambiguity_window_minutes`.
 */
function detailBody(scopeType: string, blockSales: boolean) {
  return {
    data: {
      id: 'count-created-1',
      company_id: 1,
      scope_type: scopeType,
      scope_filters: {},
      execution_mode: 'parallel',
      status: 'draft',
      scheduled_start: null,
      scheduled_end: null,
      requires_count_2: true,
      requires_count_3: false,
      allow_unexpected_items: true,
      instructions: null,
      block_sales: blockSales,
      ambiguity_window_minutes: 15,
      created_on_mobile: false,
      title: null,
      last_modified_at: null,
      last_modified_by: null,
      count_1_user: null,
      count_2_user: null,
      count_3_user: null,
      created_by: { id: 1, name: 'Test User', email: 'test@example.com' },
      progress: {
        count_1: { counted: 0, total: 0, percentage: 0 },
        count_2: null,
        count_3: null,
        overall: 0,
      },
      created_at: '2026-01-01T00:00:00Z',
      updated_at: '2026-01-01T00:00:00Z',
      activated_at: null,
      finalized_at: null,
      cancelled_at: null,
      cancellation_reason: null,
    },
    meta: { timestamp: '2026-01-01T00:00:00Z', request_id: 'req-detail' },
  }
}

// ─── Shared route wiring ────────────────────────────────────────────────────

/**
 * Install the selector/data routes the wizard hits. Registered on the page
 * AFTER the fixture, so these take precedence over the fixture's bare-array
 * `/locations` mock (which the real `apiGet` envelope-unwrap would choke on).
 */
async function wireSelectorRoutes(page: Page): Promise<void> {
  // LocationSelectorMulti -> useLocations -> apiGet('/locations') (double-wrap).
  await page.route('**/api/v1/locations', (route: Route) => {
    void route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: MOCK_LOCATIONS,
        meta: { timestamp: '2026-01-01T00:00:00Z', request_id: 'req-loc' },
      }),
    })
  })

  // LineItemEntryBar search -> api.get('/products') (single-wrap envelope read).
  await page.route('**/api/v1/products?**', (route: Route) => {
    void route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: MOCK_PRODUCTS }),
    })
  })

  // UserPicker search -> api.get('/users?...') (reads response.data.data).
  await page.route('**/api/v1/users?**', (route: Route) => {
    void route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: MOCK_USERS,
        meta: { timestamp: '2026-01-01T00:00:00Z', request_id: 'req-users' },
      }),
    })
  })

  // CategorySelector -> fetchCategories -> apiGet('/categories?...') returning a
  // CategoriesListResponse ({ data: [...] }) that is itself the apiGet payload.
  await page.route('**/api/v1/categories?**', (route: Route) => {
    void route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { data: MOCK_CATEGORIES, meta: { total: 1, per_page: 100, current_page: 1, last_page: 1 } },
        meta: { timestamp: '2026-01-01T00:00:00Z', request_id: 'req-cats' },
      }),
    })
  })

  // Zone scope: listLocationNodes -> apiGet('/inventory/locations/{id}/nodes').
  await page.route('**/api/v1/inventory/locations/*/nodes**', (route: Route) => {
    void route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: MOCK_NODES,
        meta: { timestamp: '2026-01-01T00:00:00Z', request_id: 'req-nodes' },
      }),
    })
  })
}

/**
 * Install the create + detail routes. Returns nothing; callers capture the
 * request via `page.waitForRequest`. The POST is answered with the created
 * counting so the wizard navigates to the detail page, which is then served by
 * the GET mock.
 */
async function wireCreateAndDetail(
  page: Page,
  scopeType: string,
  blockSales: boolean,
): Promise<void> {
  await page.route('**/api/v1/inventory/countings', (route: Route) => {
    if (route.request().method() !== 'POST') {
      return route.fallback()
    }
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { id: 'count-created-1' },
        meta: { timestamp: '2026-01-01T00:00:00Z', request_id: 'req-create' },
      }),
    })
  })

  await page.route('**/api/v1/inventory/countings/count-created-1', (route: Route) => {
    void route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(detailBody(scopeType, blockSales)),
    })
  })
}

// ─── Locators / step helpers ────────────────────────────────────────────────

const nextButton = (page: Page) => page.getByRole('button', { name: 'next', exact: true })
const submitButton = (page: Page) => page.getByRole('button', { name: 'Create Counting', exact: true })

async function gotoCreate(page: Page): Promise<void> {
  await page.goto('/inventory/counting/create?lang=en')
  await expect(page.getByText('Create Counting Operation')).toBeVisible()
}

async function chooseScope(page: Page, scopeLabel: string): Promise<void> {
  // Each scope card's accessible name is `${title} ${description}`, so match on
  // the card whose title <div> has the exact label (disambiguates "Product"
  // from "Product + Location").
  await page
    .getByRole('button')
    .filter({ has: page.getByText(scopeLabel, { exact: true }) })
    .click()
}

/** Adds a product through the LineItemEntryBar search dropdown. */
async function addFirstProduct(page: Page): Promise<void> {
  const search = page.getByPlaceholder('Search or scan a product…')
  await search.click()
  await search.fill('Brake')
  const option = page.getByRole('option', { name: 'BP-001 Brake Pads - Premium' })
  await expect(option).toBeVisible()
  await option.click()
  await expect(page.getByText('1 product selected')).toBeVisible()
}

/** Selects a single location in the given LocationSelectorMulti input. */
async function selectLocation(page: Page, locationName: string): Promise<void> {
  const search = page.getByPlaceholder('Search locations by name or code…').last()
  await search.click()
  await search.fill(locationName)
  await page.getByRole('option', { name: new RegExp(locationName) }).click()
}

/**
 * Fills the primary counter on the assignment step. requires_count_2 defaults
 * to true, so two user pickers render (Primary + Second Counter); only the
 * primary is required to proceed, and it renders first.
 */
async function assignPrimaryCounter(page: Page): Promise<void> {
  const search = page.getByPlaceholder('Search user by name or email…').first()
  await search.click()
  await search.fill('Sami')
  await page.getByRole('option', { name: /Sami Counter/ }).click()
}

// ─── 1. product_location happy path ─────────────────────────────────────────

test.describe('Counting wizard — product_location scope (the fix)', () => {
  test('creates a product_location count with product_ids AND location_id, then lands on detail', async ({
    authenticatedPage: page,
  }) => {
    await wireSelectorRoutes(page)
    await wireCreateAndDetail(page, 'product_location', true)

    await gotoCreate(page)

    // Step 1: scope
    await chooseScope(page, 'Product + Location')
    await nextButton(page).click()

    // Step 2: selection — a product AND the single location it is counted at.
    await addFirstProduct(page)
    // Guard is proven separately; with only a product Next must still be disabled.
    await expect(nextButton(page)).toBeDisabled()
    await selectLocation(page, 'Main Shop')
    await expect(page.getByText('1 location selected')).toBeVisible()
    await expect(nextButton(page)).toBeEnabled()
    await nextButton(page).click()

    // Step 3: configuration — leave defaults.
    await nextButton(page).click()

    // Step 4: assignment — primary counter.
    await assignPrimaryCounter(page)
    await nextButton(page).click()

    // Step 5: review -> submit, capturing the create request.
    const createRequest = page.waitForRequest(
      (req: Request) =>
        req.url().includes('/api/v1/inventory/countings') && req.method() === 'POST',
    )
    await submitButton(page).click()
    const posted = await createRequest
    const body = posted.postDataJSON() as {
      scope_type: string
      scope_filters: { product_ids?: string[]; location_id?: string }
    }

    // The load-bearing assertions of the fix.
    expect(body.scope_type).toBe('product_location')
    expect(body.scope_filters.product_ids).toEqual(['prod-1'])
    expect(body.scope_filters.location_id).toBe('location-1')

    // Lands on the detail page and renders the sales-during-count block.
    await expect(page).toHaveURL(/\/inventory\/counting\/count-created-1$/)
    await expect(page.getByText('Sales during count')).toBeVisible()
    // block_sales:true + enforced scope -> blocked-with-window variant.
    await expect(page.getByTestId('counting-sales-mode')).toHaveText(
      'Blocked · ±15 min replay window',
    )
  })
})

// ─── 2. Guard: product but no location cannot proceed ───────────────────────

test.describe('Counting wizard — product_location guard', () => {
  test('cannot proceed with a product selected but no location', async ({
    authenticatedPage: page,
  }) => {
    await wireSelectorRoutes(page)

    await gotoCreate(page)
    await chooseScope(page, 'Product + Location')
    await nextButton(page).click()

    await addFirstProduct(page)

    // location_id is still missing -> the fix keeps Next disabled.
    await expect(nextButton(page)).toBeDisabled()
  })
})

// ─── 3. Regression sweep — the five other scopes still POST correctly ────────

test.describe('Counting wizard — regression sweep of the other scopes', () => {
  test('full_inventory posts with empty scope_filters', async ({ authenticatedPage: page }) => {
    await wireSelectorRoutes(page)
    await wireCreateAndDetail(page, 'full_inventory', true)

    await gotoCreate(page)
    await chooseScope(page, 'Full Inventory')
    // full_inventory skips the selection step.
    await nextButton(page).click() // scope -> configuration
    await nextButton(page).click() // configuration -> assignment
    await assignPrimaryCounter(page)
    await nextButton(page).click() // assignment -> review

    const createRequest = page.waitForRequest(
      (req: Request) =>
        req.url().includes('/api/v1/inventory/countings') && req.method() === 'POST',
    )
    await submitButton(page).click()
    const body = (await createRequest).postDataJSON() as {
      scope_type: string
      scope_filters: Record<string, unknown>
    }

    expect(body.scope_type).toBe('full_inventory')
    expect(body.scope_filters).toEqual({})
    await expect(page).toHaveURL(/\/inventory\/counting\/count-created-1$/)
  })

  test('product posts with product_ids only (no location_id)', async ({
    authenticatedPage: page,
  }) => {
    await wireSelectorRoutes(page)
    await wireCreateAndDetail(page, 'product', false)

    await gotoCreate(page)
    await chooseScope(page, 'Product')
    await nextButton(page).click()
    await addFirstProduct(page)
    await nextButton(page).click()
    await nextButton(page).click() // configuration
    await assignPrimaryCounter(page)
    await nextButton(page).click()

    const createRequest = page.waitForRequest(
      (req: Request) =>
        req.url().includes('/api/v1/inventory/countings') && req.method() === 'POST',
    )
    await submitButton(page).click()
    const body = (await createRequest).postDataJSON() as {
      scope_type: string
      scope_filters: { product_ids?: string[]; location_id?: string }
    }

    expect(body.scope_type).toBe('product')
    expect(body.scope_filters.product_ids).toEqual(['prod-1'])
    expect(body.scope_filters.location_id).toBeUndefined()
    await expect(page).toHaveURL(/\/inventory\/counting\/count-created-1$/)
  })

  test('location posts with location_ids', async ({ authenticatedPage: page }) => {
    await wireSelectorRoutes(page)
    await wireCreateAndDetail(page, 'location', false)

    await gotoCreate(page)
    await chooseScope(page, 'Location')
    await nextButton(page).click()
    await selectLocation(page, 'Main Shop')
    await expect(page.getByText('1 location selected')).toBeVisible()
    await nextButton(page).click()
    await nextButton(page).click() // configuration
    await assignPrimaryCounter(page)
    await nextButton(page).click()

    const createRequest = page.waitForRequest(
      (req: Request) =>
        req.url().includes('/api/v1/inventory/countings') && req.method() === 'POST',
    )
    await submitButton(page).click()
    const body = (await createRequest).postDataJSON() as {
      scope_type: string
      scope_filters: { location_ids?: string[] }
    }

    expect(body.scope_type).toBe('location')
    expect(body.scope_filters.location_ids).toEqual(['location-1'])
    await expect(page).toHaveURL(/\/inventory\/counting\/count-created-1$/)
  })

  test('category posts with category_ids', async ({ authenticatedPage: page }) => {
    await wireSelectorRoutes(page)
    await wireCreateAndDetail(page, 'category', false)

    await gotoCreate(page)
    await chooseScope(page, 'Category')
    await nextButton(page).click()

    const categorySearch = page.getByPlaceholder(/Search categories/i)
    await categorySearch.click()
    await categorySearch.fill('Brakes')
    await page.getByRole('option', { name: /Brakes/ }).click()

    await nextButton(page).click()
    await nextButton(page).click() // configuration
    await assignPrimaryCounter(page)
    await nextButton(page).click()

    const createRequest = page.waitForRequest(
      (req: Request) =>
        req.url().includes('/api/v1/inventory/countings') && req.method() === 'POST',
    )
    await submitButton(page).click()
    const body = (await createRequest).postDataJSON() as {
      scope_type: string
      scope_filters: { category_ids?: string[] }
    }

    expect(body.scope_type).toBe('category')
    expect(body.scope_filters.category_ids).toEqual(['42'])
    await expect(page).toHaveURL(/\/inventory\/counting\/count-created-1$/)
  })

  test('zone posts with location_id + zone_ids', async ({ authenticatedPage: page }) => {
    await wireSelectorRoutes(page)
    await wireCreateAndDetail(page, 'zone', false)

    await gotoCreate(page)
    await chooseScope(page, 'Node / Zone')
    await nextButton(page).click()

    // Zone selection step: pick the single location, then its aisle node.
    await selectLocation(page, 'Main Shop')
    await page.getByRole('button', { name: 'Aisle 1', exact: true }).click()

    await nextButton(page).click()
    await nextButton(page).click() // configuration
    await assignPrimaryCounter(page)
    await nextButton(page).click()

    const createRequest = page.waitForRequest(
      (req: Request) =>
        req.url().includes('/api/v1/inventory/countings') && req.method() === 'POST',
    )
    await submitButton(page).click()
    const body = (await createRequest).postDataJSON() as {
      scope_type: string
      scope_filters: { location_id?: string; zone_ids?: string[] }
    }

    expect(body.scope_type).toBe('zone')
    expect(body.scope_filters.location_id).toBe('location-1')
    expect(body.scope_filters.zone_ids).toEqual(['node-a1'])
    await expect(page).toHaveURL(/\/inventory\/counting\/count-created-1$/)
  })
})
