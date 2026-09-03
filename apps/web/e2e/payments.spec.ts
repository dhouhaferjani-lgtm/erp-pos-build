import { test, expect, mockPayments, mockPartners, mockPaymentMethods } from './fixtures'

// The payment list is always paginated (request-hygiene T3), so every mocked
// list response has to carry the full offset-pagination meta object the page
// renders its pagination bar from.
const populatedPaymentsMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 25,
  total: 1,
  from: 1,
  to: 1,
}

const emptyPaymentsMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 25,
  total: 0,
  from: null,
  to: null,
}

test.describe('Payment Management', () => {
  test.beforeEach(async ({ authenticatedPage: page }) => {
    // Common mocks for payment tests
    await page.route('**/api/v1/invoices**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      })
    })
  })

  test('should display payment list', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/payments**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockPayments, meta: populatedPaymentsMeta }),
      })
    })

    await page.goto('/treasury/payments')

    await expect(page.getByRole('heading', { name: /payments/i })).toBeVisible()
    await expect(page.getByText('PAY-2025-0001')).toBeVisible()
    await expect(page.getByText('Acme Corp')).toBeVisible()
    await expect(page.getByText('Cash')).toBeVisible()
    await expect(page.getByText(/Page 1 of 1/i)).toBeVisible()
  })

  test('should show empty state when no payments', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/payments**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [], meta: emptyPaymentsMeta }),
      })
    })

    await page.goto('/treasury/payments')

    await expect(page.getByText(/no.*payments/i)).toBeVisible()
  })

  test('should navigate to record payment form', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/payments**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [], meta: emptyPaymentsMeta }),
      })
    })

    await page.route('**/api/v1/payment-methods**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockPaymentMethods }),
      })
    })

    await page.route('**/api/v1/partners**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockPartners }),
      })
    })

    await page.goto('/treasury/payments')

    // Wait for page to load and click the record payment button
    await page.waitForLoadState('networkidle')
    const recordButton = page.locator('a[href*="/treasury/payments/new"], button:has-text("Record Payment")').first()
    await recordButton.click()

    // Wait for navigation and verify form is visible
    await page.waitForURL(/\/treasury\/payments\/new/, { timeout: 10000 })
    await expect(page.getByRole('heading', { name: /record payment/i, level: 1 })).toBeVisible({ timeout: 10000 })
  })

  test('should record a new payment', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/payments', (route) => {
      if (route.request().method() === 'POST') {
        route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({
            data: {
              id: '123',
              payment_number: 'PAY-2025-0002',
              amount: 500,
              status: 'completed',
            },
          }),
        })
      } else {
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [], meta: emptyPaymentsMeta }),
        })
      }
    })

    await page.route('**/api/v1/payment-methods**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockPaymentMethods }),
      })
    })

    await page.route('**/api/v1/partners**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockPartners }),
      })
    })

    await page.goto('/treasury/payments/new')

    // Wait for form to load
    await expect(page.getByLabel(/amount/i)).toBeVisible({ timeout: 10000 })

    // Fill out the form
    await page.getByLabel(/amount/i).fill('500')
    await page.getByLabel(/payment method/i).selectOption('1')
    await page.getByLabel(/partner/i).selectOption('1')

    // Submit
    await page.getByRole('button', { name: /save/i }).click()

    // Should redirect to payments list or detail
    await expect(page).toHaveURL(/\/treasury\/payments/)
  })

  test('should show validation error for missing amount', async ({ authenticatedPage: page }) => {
    await page.route('**/api/v1/payment-methods**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockPaymentMethods }),
      })
    })

    await page.route('**/api/v1/partners**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: mockPartners }),
      })
    })

    await page.goto('/treasury/payments/new')

    // Wait for form to load
    await expect(page.getByLabel(/amount/i)).toBeVisible({ timeout: 10000 })

    // Submit without amount
    await page.getByRole('button', { name: /save/i }).click()

    // Should show validation error (via toast or inline)
    await expect(page.getByText(/amount.*required|required/i).first()).toBeVisible({ timeout: 10000 })
  })
})
