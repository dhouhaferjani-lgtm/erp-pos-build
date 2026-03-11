import { test, expect } from './fixtures'

// ─── Mock Data ──────────────────────────────────────────────────────

const mockManufacturers = [
  { id: 'mfr-1', brand: 'Toyota', slug: 'toyota' },
  { id: 'mfr-2', brand: 'BMW', slug: 'bmw' },
  { id: 'mfr-3', brand: 'Volkswagen', slug: 'volkswagen' },
]

const mockModelSeries = [
  { id: 'ms-1', name: 'Corolla E15', manufacturer_id: 'mfr-1', production_from: 2007, production_to: 2013 },
  { id: 'ms-2', name: 'Yaris P9', manufacturer_id: 'mfr-1', production_from: 2005, production_to: 2011 },
]

const mockVehicles = [
  {
    id: 'veh-1',
    display: 'Toyota Corolla (E15) 1.4 D-4D 90hp 2007-2013',
    model_series_id: 'ms-1',
    vehicle_type: 'pc',
    power_kw: 66,
    power_hp: 90,
    engine_code: '1ND-TV',
    production_from: '2007-01',
    production_to: '2013-12',
  },
]

const mockArticles = {
  data: [
    {
      id: 'art-1',
      article_number: 'GDB1550',
      status: 'active',
      supplier: { id: 'sup-1', brand: 'TRW', slug: 'trw' },
      cross_references: [
        { reference_type: 'oe', reference_number: '04465-02220', manufacturer_name: 'Toyota' },
      ],
      criteria: [
        { criteria_id: 'crit-1', label: 'Thickness', value: '17.3', unit: 'mm', type: 'number' },
        { criteria_id: 'crit-2', label: 'Width', value: '131.4', unit: 'mm', type: 'number' },
      ],
      compatible_vehicles: [
        { vehicle_type: 'pc', vehicle_id: 'veh-1', display: 'Toyota Corolla 1.4 D-4D', fitment_confidence: 90, data_source: 'platform' },
      ],
      local_inventory: {
        product_id: null,
        in_stock: false,
        total_quantity: 0,
        available_quantity: 0,
        sale_price: null,
        purchase_price: null,
      },
      prices: [],
    },
    {
      id: 'art-2',
      article_number: 'GDB1377',
      status: 'active',
      supplier: { id: 'sup-2', brand: 'Bosch', slug: 'bosch' },
      cross_references: [],
      criteria: [
        { criteria_id: 'crit-1', label: 'Thickness', value: '15.0', unit: 'mm', type: 'number' },
      ],
      compatible_vehicles: [],
      local_inventory: {
        product_id: 'prod-1',
        in_stock: true,
        total_quantity: 10,
        available_quantity: 8,
        sale_price: '29.99',
        purchase_price: '18.50',
      },
      prices: [{ price_type: 'retail', price: '24.50', currency_code: 'EUR', valid_from: '2026-01-01', valid_to: null }],
    },
  ],
  meta: { cursor: null, has_more: false, per_page: 20 },
}

const mockSearchTreeRoots = [
  { id: 'tree-1', name: 'Engine', parent_id: null, has_children: true, article_count: 1200 },
  { id: 'tree-2', name: 'Braking', parent_id: null, has_children: true, article_count: 800 },
]

const mockSearchTreeChildren = [
  { id: 'tree-2a', name: 'Brake Pads', parent_id: 'tree-2', has_children: false, article_count: 350 },
  { id: 'tree-2b', name: 'Brake Discs', parent_id: 'tree-2', has_children: false, article_count: 450 },
]

const mockSuppliers = [
  { id: 'sup-1', brand: 'TRW', slug: 'trw' },
  { id: 'sup-2', brand: 'Bosch', slug: 'bosch' },
]

// ─── Helper: Set up all catalog API mocks ────────────────────────────

async function setupCatalogMocks(page: import('@playwright/test').Page) {
  // Catch-all FIRST — in Playwright, later routes take priority, so specific routes below override this
  await page.route('**/api/v1/platform/**', (route) => {
    void route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) })
  })
  // Mock company config (needed by PartsCatalogPage for vertical detection)
  await page.route('**/api/v1/company/config', (route) => {
    void route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          vertical: 'mechanic',
          currency: 'TND',
          country_code: 'TN',
          locale: 'en',
          default_modules: ['Catalog', 'POS', 'Inventory'],
          enabled_extras: [],
          all_enabled_modules: ['Catalog', 'POS', 'Inventory', 'Partner', 'Document', 'Menu', 'PlatformIntegration'],
        },
      }),
    })
  })
  await page.route('**/api/v1/platform/catalog/manufacturers', (route) => {
    void route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mockManufacturers }) })
  })
  await page.route('**/api/v1/platform/catalog/manufacturers/mfr-1/model-series', (route) => {
    void route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mockModelSeries }) })
  })
  await page.route('**/api/v1/platform/catalog/model-series/ms-1/vehicles*', (route) => {
    void route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mockVehicles }) })
  })
  await page.route('**/api/v1/platform/catalog/search-tree/roots*', (route) => {
    void route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mockSearchTreeRoots }) })
  })
  await page.route('**/api/v1/platform/catalog/search-tree/tree-2/children', (route) => {
    void route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mockSearchTreeChildren }) })
  })
  await page.route('**/api/v1/platform/catalog/vehicles/pc/veh-1/articles*', (route) => {
    void route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mockArticles }) })
  })
  await page.route('**/api/v1/platform/catalog/suppliers*', (route) => {
    void route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mockSuppliers }) })
  })
}

// ─── Helper: Navigate drill-down to select a vehicle ────────────────

async function selectVehicle(page: import('@playwright/test').Page) {
  // Clear persisted vehicle store to avoid leaking between tests
  await page.evaluate(() => { localStorage.removeItem('parts-catalog-vehicle') })
  await page.goto('/parts-catalog')
  // Wait for the page to be ready (not login redirect)
  await page.waitForSelector('[role="tablist"], [data-testid="parts-catalog"]', { timeout: 10000 }).catch(() => {
    // If no tablist, the page might still be loading
  })
  await page.waitForLoadState('networkidle')

  // Manufacturer → Model → Vehicle
  await page.getByText('Toyota').click()
  await expect(page.getByText('Corolla E15')).toBeVisible()
  await page.getByText('Corolla E15').click()
  await expect(page.getByText('Toyota Corolla (E15) 1.4 D-4D 90hp 2007-2013')).toBeVisible()
  await page.getByText('Toyota Corolla (E15) 1.4 D-4D 90hp 2007-2013').click()
}

// ─── Tests ──────────────────────────────────────────────────────────

test.describe('Parts Catalog — Vehicle Drill-Down', () => {
  test('navigates manufacturer → model → vehicle → categories', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // Should show category browser for product groups
    await expect(page.getByText('Braking')).toBeVisible()
    await expect(page.getByText('Engine').first()).toBeVisible()
  })

  test('filters manufacturers with search', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await page.goto('/parts-catalog')
    await page.waitForLoadState('networkidle')

    const searchInput = page.getByPlaceholder('Search manufacturers...')
    await searchInput.fill('BMW')

    await expect(page.getByText('BMW')).toBeVisible()
    await expect(page.getByText('Toyota')).not.toBeVisible()
  })
})

test.describe('Parts Catalog — Sticky Vehicle Bar', () => {
  test('shows sticky bar after vehicle selection with correct info', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // Sticky bar should be visible with manufacturer brand
    await expect(page.getByText('Toyota').first()).toBeVisible()
    // Should show model series name
    await expect(page.getByText('Corolla E15').first()).toBeVisible()
  })

  test('Change button reopens vehicle navigator', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // Click Change on the sticky bar
    const changeButton = page.getByRole('button', { name: /change/i })
    await changeButton.click()

    // Vehicle navigator should be visible again (manufacturer list)
    await expect(page.getByPlaceholder('Search manufacturers...')).toBeVisible()
  })

  test('Clear button shows confirmation, then clears vehicle', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // Click Clear on the sticky bar
    const clearButton = page.getByRole('button', { name: /clear/i })
    await clearButton.click()

    // Confirmation dialog should appear
    await expect(page.getByText(/clear vehicle/i)).toBeVisible()

    // Confirm clear
    await page.getByRole('button', { name: /yes, clear/i }).click()

    // Sticky bar should disappear — manufacturer list should be visible again
    await expect(page.getByPlaceholder('Search manufacturers...')).toBeVisible()
  })

  test('Cancel in clear confirmation keeps vehicle selected', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // Click Clear
    await page.getByRole('button', { name: /clear/i }).click()

    // Click Cancel
    await page.getByRole('button', { name: /cancel/i }).click()

    // Vehicle should still be selected — Braking category should still be visible
    await expect(page.getByText('Braking')).toBeVisible()
  })
})

test.describe('Parts Catalog — Recent Vehicles', () => {
  test('shows recent vehicles section', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await page.goto('/parts-catalog')
    await page.waitForLoadState('networkidle')

    // Recent vehicles section should show (even if empty)
    await expect(page.getByText(/recent vehicles/i).first()).toBeVisible()
  })

  test('adds selected vehicle to history', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // After selecting a vehicle, the recent vehicles list should show it
    // Click Change to see the navigator with recent vehicles
    await page.getByRole('button', { name: /change/i }).click()

    // Should see Toyota in recent vehicles
    const recentSection = page.locator('text=Recent Vehicles').locator('..')
    await expect(recentSection).toBeVisible()
  })
})

test.describe('Parts Catalog — Search Mode Tabs', () => {
  test('shows all search mode tabs including VIN/Plate', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await page.goto('/parts-catalog')
    await page.waitForLoadState('networkidle')

    // Should see the tab bar with mode names (visible on desktop)
    const tablist = page.getByRole('tablist')
    await expect(tablist).toBeVisible()

    // Check for VIN/Plate tab
    await expect(page.getByRole('tab', { name: /vin/i })).toBeVisible()
    await expect(page.getByRole('tab', { name: /vehicle/i })).toBeVisible()
    await expect(page.getByRole('tab', { name: /part number/i })).toBeVisible()
    await expect(page.getByRole('tab', { name: /category/i })).toBeVisible()
  })

  test('switching tabs preserves vehicle in sticky bar', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // Switch to Part Number tab
    await page.getByRole('tab', { name: /part number/i }).click()

    // Sticky bar should still show Toyota (vehicle persists across tab switches)
    await expect(page.getByText('Toyota').first()).toBeVisible()
    await expect(page.getByText('Corolla E15').first()).toBeVisible()
  })

  test('Part Number tab shows search input', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await page.goto('/parts-catalog')
    await page.waitForLoadState('networkidle')

    // Click Part Number tab
    await page.getByRole('tab', { name: /part number/i }).click()

    // Should see part number search input
    await expect(page.getByPlaceholder(/part number/i)).toBeVisible()
  })

  test('Category tab shows category browser', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await page.goto('/parts-catalog')
    await page.waitForLoadState('networkidle')

    // Click Category tab
    await page.getByRole('tab', { name: /category/i }).click()

    // Should see search tree roots
    await expect(page.getByText('Engine')).toBeVisible()
    await expect(page.getByText('Braking')).toBeVisible()
  })
})

test.describe('Parts Catalog — Article Grid', () => {
  test('shows articles after vehicle selection and category drill-down', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // The articles should be visible (vehicle articles query)
    await expect(page.getByText('GDB1550')).toBeVisible()
    await expect(page.getByText('GDB1377')).toBeVisible()
  })

  test('shows grid/list toggle buttons', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // Should see grid/list toggle
    const gridButton = page.getByRole('button', { name: /grid view/i })
    const listButton = page.getByRole('button', { name: /list view/i })
    await expect(gridButton).toBeVisible()
    await expect(listButton).toBeVisible()
  })

  test('shows result count', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // Should show "Showing 2 results"
    await expect(page.getByText(/showing 2 results/i)).toBeVisible()
  })

  test('displays article card with supplier brand badge', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // Should show supplier badges
    await expect(page.getByText('TRW').first()).toBeVisible()
    await expect(page.getByText('Bosch').first()).toBeVisible()
  })

  test('displays criteria on article cards', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // First article has criteria
    await expect(page.getByText('Thickness:').first()).toBeVisible()
    await expect(page.getByText('17.3 mm')).toBeVisible()
  })

  test('displays inventory badges', async ({ authenticatedPage: page }) => {
    await setupCatalogMocks(page)
    await selectVehicle(page)

    // Second article is in stock
    await expect(page.getByText(/in stock/i).first()).toBeVisible()
    // First article is not in inventory
    await expect(page.getByText(/not in inventory/i).first()).toBeVisible()
  })
})
