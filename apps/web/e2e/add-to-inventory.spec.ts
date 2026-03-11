import { test, expect } from './fixtures'

const mockArticle = {
  id: 'art-1',
  article_number: 'GDB1550',
  status: 'active',
  supplier: { id: 'sup-1', brand: 'TRW', slug: 'trw' },
  cross_references: [
    { reference_type: 'ean', reference_number: '5901234123457', manufacturer_name: null },
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
}

const mockLinkages = { vehicles: [] }

test.describe('Parts Catalog — Add to Inventory', () => {
  test('opens add-to-inventory modal and submits', async ({ authenticatedPage: page }) => {
    // Mock article endpoints
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

    // Mock product creation
    await page.route('**/api/v1/products', (route) => {
      void route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: { id: 'prod-new-1' },
          meta: { timestamp: new Date().toISOString(), request_id: 'req-1' },
        }),
      })
    })

    await page.goto('/parts-catalog/art-1')
    await page.waitForLoadState('networkidle')

    // Click Add to Inventory button
    await page.getByText('Add to Inventory').click()

    // Modal should appear
    await expect(page.getByText('Add to Inventory').nth(1)).toBeVisible()

    // Verify pre-filled fields
    const nameInput = page.locator('#inv-name')
    await expect(nameInput).toHaveValue('TRW - GDB1550')

    const skuInput = page.locator('#inv-sku')
    await expect(skuInput).toHaveValue('TRW-GDB1550')

    const barcodeInput = page.locator('#inv-barcode')
    await expect(barcodeInput).toHaveValue('5901234123457')

    // Fill in prices
    await page.locator('#inv-sale').fill('35.00')
    await page.locator('#inv-purchase').fill('22.00')

    // Select quality tier
    await page.locator('#inv-tier').selectOption('aftermarket')

    // Submit
    await page.getByRole('button', { name: /Add to Inventory/i }).click()

    // Modal should close (article detail page remains)
    await expect(page.getByText('GDB1550')).toBeVisible()
  })

  test('shows validation when name is empty', async ({ authenticatedPage: page }) => {
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

    await page.getByText('Add to Inventory').click()

    // Clear the name field
    const nameInput = page.locator('#inv-name')
    await nameInput.clear()

    // Submit button should be disabled
    const submitButton = page.getByRole('button', { name: /Add to Inventory/i })
    await expect(submitButton).toBeDisabled()
  })

  test('handles server error gracefully', async ({ authenticatedPage: page }) => {
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

    // Mock product creation failure
    await page.route('**/api/v1/products', (route) => {
      void route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          error: { code: 'VALIDATION_ERROR', message: 'SKU already exists' },
        }),
      })
    })

    await page.goto('/parts-catalog/art-1')
    await page.waitForLoadState('networkidle')

    await page.getByText('Add to Inventory').click()
    await page.getByRole('button', { name: /Add to Inventory/i }).click()

    // Should show error message
    await expect(page.getByText(/SKU already exists|Failed to add/i)).toBeVisible()
  })
})
