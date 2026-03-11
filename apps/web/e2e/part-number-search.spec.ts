import { test, expect } from './fixtures'

const mockMultiSearchResponse = {
  articles: [
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
      prices: [],
    },
  ],
  detected_brand: null,
  search_methods_used: ['article_number', 'cross_reference'],
}

const mockBrandSearchResponse = {
  articles: [
    {
      id: 'art-1',
      article_number: 'GDB1550',
      status: 'active',
      supplier: { id: 'sup-1', brand: 'TRW', slug: 'trw' },
      cross_references: [],
      criteria: [],
      compatible_vehicles: [],
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
  ],
  detected_brand: 'TRW',
  search_methods_used: ['article_number'],
}

test.describe('Parts Catalog — Part Number Search', () => {
  test('searches by article number and shows results', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/platform/automotive/search', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockMultiSearchResponse }),
      })
    })

    await page.goto('/parts-catalog')
    await page.waitForLoadState('networkidle')

    // Switch to part number mode
    await page.getByRole('tab', { name: /Part Number/i }).click()

    // Search
    const searchInput = page.getByPlaceholder(/Enter part number/i)
    await searchInput.fill('GDB1550')
    await searchInput.press('Enter')

    // Should show results
    await expect(page.getByText('GDB1550')).toBeVisible()
    await expect(page.getByText('TRW')).toBeVisible()
  })

  test('detects brand in search query', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/platform/automotive/search', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockBrandSearchResponse }),
      })
    })

    await page.goto('/parts-catalog')
    await page.waitForLoadState('networkidle')

    await page.getByRole('tab', { name: /Part Number/i }).click()

    const searchInput = page.getByPlaceholder(/Enter part number/i)
    await searchInput.fill('TRW GDB1550')
    await searchInput.press('Enter')

    // Should show detected brand notice
    await expect(page.getByText(/Detected brand.*TRW/i)).toBeVisible()
  })

  test('shows no results message for unknown part', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/platform/automotive/search', (route) => {
      void route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            articles: [],
            detected_brand: null,
            search_methods_used: ['article_number', 'cross_reference'],
          },
        }),
      })
    })

    await page.goto('/parts-catalog')
    await page.waitForLoadState('networkidle')

    await page.getByRole('tab', { name: /Part Number/i }).click()

    const searchInput = page.getByPlaceholder(/Enter part number/i)
    await searchInput.fill('XYZNOEXIST')
    await searchInput.press('Enter')

    await expect(page.getByText(/No articles found/i)).toBeVisible()
  })
})
