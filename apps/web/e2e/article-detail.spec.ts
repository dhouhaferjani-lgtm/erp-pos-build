import { test, expect } from './fixtures'

const mockArticle = {
  id: 'art-1',
  article_number: 'GDB1550',
  status: 'active',
  supplier: { id: 'sup-1', brand: 'TRW', slug: 'trw' },
  cross_references: [
    { reference_type: 'oe', reference_number: '04465-02220', manufacturer_name: 'Toyota' },
    { reference_type: 'oem', reference_number: '04465-12610', manufacturer_name: 'Toyota' },
    { reference_type: 'iam', reference_number: 'P 30 017', manufacturer_name: 'Brembo' },
    { reference_type: 'ean', reference_number: '5901234123457', manufacturer_name: null },
  ],
  criteria: [
    { criteria_id: 'crit-1', label: 'Thickness', value: '17.3', unit: 'mm', type: 'number' },
    { criteria_id: 'crit-2', label: 'Width', value: '131.4', unit: 'mm', type: 'number' },
    { criteria_id: 'crit-3', label: 'Height', value: '57.8', unit: 'mm', type: 'number' },
    { criteria_id: 'crit-4', label: 'WVA Number', value: '23695', unit: null, type: 'text' },
  ],
  compatible_vehicles: [],
  local_inventory: {
    product_id: null,
    in_stock: false,
    total_quantity: 0,
    available_quantity: 0,
    sale_price: null,
    purchase_price: null,
  },
  prices: [
    { price_type: 'retail', price: '24.50', currency_code: 'EUR', valid_from: '2026-01-01', valid_to: null },
  ],
}

const mockLinkages = {
  vehicles: [
    {
      vehicle_type: 'pc',
      vehicle_id: 'veh-1',
      display: 'Toyota Corolla (E15) 1.4 D-4D 90hp 2007-2013',
      fitment_confidence: 95,
      data_source: 'tecdoc',
    },
    {
      vehicle_type: 'pc',
      vehicle_id: 'veh-2',
      display: 'Toyota Auris (E15) 1.4 D-4D 2006-2012',
      fitment_confidence: 72,
      data_source: 'tenant_contribution',
    },
    {
      vehicle_type: 'pc',
      vehicle_id: 'veh-3',
      display: 'Toyota Yaris (P9) 1.4 D-4D 2005-2011',
      fitment_confidence: 40,
      data_source: 'platform',
    },
  ],
}

test.describe('Parts Catalog — Article Detail', () => {
  test('displays specifications table', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/platform/automotive/articles/art-1', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockArticle }),
      })
    })

    await page.route('**/api/v1/platform/automotive/articles/art-1/linkages', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockLinkages }),
      })
    })

    await page.goto('/parts-catalog/art-1')
    await page.waitForLoadState('networkidle')

    // Header
    await expect(page.getByText('TRW')).toBeVisible()
    await expect(page.getByText('GDB1550')).toBeVisible()

    // Specifications
    await expect(page.getByText('Thickness')).toBeVisible()
    await expect(page.getByText('17.3')).toBeVisible()
    await expect(page.getByText('Width')).toBeVisible()
    await expect(page.getByText('131.4')).toBeVisible()
  })

  test('displays cross references grouped by type', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/platform/automotive/articles/art-1', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockArticle }),
      })
    })

    await page.route('**/api/v1/platform/automotive/articles/art-1/linkages', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockLinkages }),
      })
    })

    await page.goto('/parts-catalog/art-1')
    await page.waitForLoadState('networkidle')

    // Cross reference sections
    await expect(page.getByText('Original Equipment')).toBeVisible()
    await expect(page.getByText('04465-02220')).toBeVisible()
    await expect(page.getByText('Aftermarket')).toBeVisible()
    await expect(page.getByText('P 30 017')).toBeVisible()
  })

  test('displays vehicle compatibility with confidence badges', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/platform/automotive/articles/art-1', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockArticle }),
      })
    })

    await page.route('**/api/v1/platform/automotive/articles/art-1/linkages', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockLinkages }),
      })
    })

    await page.goto('/parts-catalog/art-1')
    await page.waitForLoadState('networkidle')

    // High confidence (>= 80)
    await expect(page.getByText('Toyota Corolla (E15) 1.4 D-4D 90hp 2007-2013')).toBeVisible()
    await expect(page.getByText('Confirmed fit')).toBeVisible()

    // Medium confidence (50-79)
    await expect(page.getByText('Toyota Auris (E15) 1.4 D-4D 2006-2012')).toBeVisible()
    await expect(page.getByText('72% match')).toBeVisible()

    // Low confidence (< 50)
    await expect(page.getByText('Toyota Yaris (P9) 1.4 D-4D 2005-2011')).toBeVisible()
    await expect(page.getByText(/40%.*Verify fitment/i)).toBeVisible()
  })

  test('shows Add to Inventory button for non-linked articles', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/platform/automotive/articles/art-1', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockArticle }),
      })
    })

    await page.route('**/api/v1/platform/automotive/articles/art-1/linkages', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockLinkages }),
      })
    })

    await page.goto('/parts-catalog/art-1')
    await page.waitForLoadState('networkidle')

    await expect(page.getByText('Not in inventory')).toBeVisible()
    await expect(page.getByText('Add to Inventory')).toBeVisible()
  })
})
