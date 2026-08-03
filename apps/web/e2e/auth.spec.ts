import { test, expect } from '@playwright/test'

test.describe('Authentication', () => {
  test.beforeEach(async ({ page }) => {
    // Set up API mocks before each test - return 401 for unauthenticated
    await page.route('**/api/v1/auth/me', (route) => {
      route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Unauthenticated' }),
      })
    })
  })

  test('should display login page', async ({ page }) => {
    await page.goto('/login')

    // Should see the login form
    await expect(page.getByRole('heading', { name: /sign in/i })).toBeVisible()
    await expect(page.getByLabel(/email/i)).toBeVisible()
    await expect(page.getByLabel(/password/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /sign in/i })).toBeVisible()
  })

  test('should show validation errors for empty form', async ({ page }) => {
    await page.goto('/login')

    // Submit empty form
    await page.getByRole('button', { name: /sign in/i }).click()

    // Should show validation errors (the app uses "This field is required" message)
    const requiredErrors = page.getByText(/this field is required/i)
    await expect(requiredErrors.first()).toBeVisible()
  })

  test('should prevent submission with invalid email format', async ({ page }) => {
    await page.goto('/login')

    // Fill in an invalid email that browser validation will catch
    await page.getByLabel(/email/i).fill('invalid-email')
    await page.getByLabel(/password/i).fill('password123')
    await page.getByRole('button', { name: /sign in/i }).click()

    // Browser validation should prevent form submission, so we should still be on login page
    await expect(page).toHaveURL(/\/login/)
    // And the email input should still be focused/visible (form not submitted)
    await expect(page.getByLabel(/email/i)).toBeVisible()
  })

  test('should redirect unauthenticated users to login', async ({ page }) => {
    // Try to access protected route
    await page.goto('/')

    // Should be redirected to login
    await expect(page).toHaveURL(/\/login/)
  })

  test('should redirect to dashboard after successful login', async ({ page }) => {
    // Set up mocks for successful login flow
    await page.unrouteAll()

    await page.route('**/sanctum/csrf-cookie', (route) => {
      route.fulfill({ status: 204 })
    })

    await page.route('**/api/v1/auth/login', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            user: {
              id: '1',
              name: 'Test User',
              email: 'test@example.com',
              tenantId: 'tenant-1',
              roles: ['admin'],
            },
            token: 'fake-jwt-token',
            tokenType: 'Bearer',
          },
        }),
      })
    })

    await page.route('**/api/v1/auth/me', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: '1',
            name: 'Test User',
            email: 'test@example.com',
            tenantId: 'tenant-1',
            roles: ['admin'],
          },
        }),
      })
    })

    // Mock user companies (required by CompanyProvider)
    await page.route('**/api/v1/user/companies', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              id: 'company-1',
              name: 'Test Company',
              legal_name: 'Test Company LLC',
              tax_id: null,
              country_code: 'TN',
              currency: 'TND',
              locale: 'fr',
              timezone: 'Africa/Tunis',
            },
          ],
        }),
      })
    })

    // Mock locations (required by LocationProvider)
    await page.route('**/api/v1/locations', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          {
            id: 'location-1',
            company_id: 'company-1',
            name: 'Main Store',
            code: 'MAIN',
            type: 'shop',
            phone: null,
            email: null,
            address_street: null,
            address_city: null,
            address_postal_code: null,
            address_country: null,
            is_default: true,
            is_active: true,
            pos_enabled: false,
            created_at: '2024-01-01T00:00:00Z',
            updated_at: '2024-01-01T00:00:00Z',
          },
        ]),
      })
    })

    // Mock dashboard stats
    await page.route('**/api/v1/dashboard/stats', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            // Wire shape (docs/superpowers/tickets/2026-08-02-dashboard-stats-status-buckets-and-float-sum.md
            // ruling 3): revenue.current/previous/change are bc-based decimal strings, not
            // JSON numbers — payments.received/pending were already strings.
            revenue: { current: '15000.000', previous: '12000.000', change: '25.00' },
            invoices: { total: 45, pending: 12, overdue: 3 },
            partners: { total: 28, newThisMonth: 5 },
            payments: { received: '35000.000', pending: '8000.000' },
          },
        }),
      })
    })

    await page.route('**/api/v1/documents**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      })
    })

    await page.route('**/api/v1/payments**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      })
    })

    await page.goto('/login')

    // Fill in credentials
    await page.getByLabel(/email/i).fill('test@example.com')
    await page.getByLabel(/password/i).fill('password123')
    await page.getByRole('button', { name: /sign in/i }).click()

    // Should redirect to dashboard
    await expect(page).toHaveURL('/', { timeout: 10000 })
    await expect(page.getByRole('heading', { name: /dashboard/i })).toBeVisible({ timeout: 10000 })
  })
})
