import { test, expect } from '@playwright/test'
import { mockCompanies } from './fixtures'

// API company format (snake_case from backend)
const apiCompanies = mockCompanies.map((c) => ({
  id: c.id,
  name: c.name,
  legal_name: c.legalName,
  tax_id: c.taxId,
  country_code: c.countryCode,
  currency: c.currency,
  locale: c.locale,
  timezone: c.timezone,
}))

// Auth state stored in localStorage
const mockAuthState = {
  state: {
    user: {
      id: '1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-1',
      roles: ['admin'],
    },
    token: 'mock-token-123',
    isAuthenticated: true,
  },
  version: 0,
}

test.describe('Company Switcher', () => {
  test.beforeEach(async ({ page }) => {
    // Set up API mocks before going to page
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

    // Mock user's companies endpoint (used by CompanyProvider)
    await page.route('**/api/v1/user/companies', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: apiCompanies,
        }),
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

    // Mock documents endpoint
    await page.route('**/api/v1/documents**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      })
    })

    // Mock payments endpoint
    await page.route('**/api/v1/payments**', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      })
    })

    // Navigate to set localStorage, then set auth state
    await page.goto('/')
    await page.evaluate((authState) => {
      localStorage.setItem('autoerp-auth', JSON.stringify(authState))
    }, mockAuthState)
  })

  test('should display company selector when user has multiple companies', async ({ page }) => {
    await page.goto('/')

    // Wait for dashboard to load
    await expect(page.getByRole('heading', { name: /dashboard/i })).toBeVisible()

    // Company selector should be visible with current company name
    const companyButton = page.getByRole('button', { name: /select company/i })
    await expect(companyButton).toBeVisible()
    await expect(companyButton).toContainText('Garage Central')
  })

  test('should open company dropdown when clicking selector', async ({ page }) => {
    await page.goto('/')

    // Wait for dashboard to load
    await expect(page.getByRole('heading', { name: /dashboard/i })).toBeVisible()

    // Click company selector
    await page.getByRole('button', { name: /select company/i }).click()

    // Dropdown should show both companies
    await expect(page.getByText('Switch Company')).toBeVisible()
    await expect(page.getByText('Garage Central')).toBeVisible()
    await expect(page.getByText('Auto Parts Plus')).toBeVisible()
  })

  test('should switch company when selecting different company', async ({ page }) => {
    await page.goto('/')

    // Wait for dashboard to load
    await expect(page.getByRole('heading', { name: /dashboard/i })).toBeVisible()

    // Click company selector
    await page.getByRole('button', { name: /select company/i }).click()

    // Click second company
    await page.getByRole('button', { name: /Auto Parts Plus/i }).click()

    // Dropdown should close (give time for state to update)
    await expect(page.getByText('Switch Company')).not.toBeVisible({ timeout: 3000 })
  })

  test('should hide company selector when user has single company', async ({ page }) => {
    // Override mock to return single company
    await page.unrouteAll()

    await page.route('**/api/v1/auth/me', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: '1',
            name: 'Test User',
            email: 'test@example.com',
            tenant_id: 'tenant-1',
            roles: ['admin'],
          },
        }),
      })
    })

    // Return only one company
    await page.route('**/api/v1/user/companies', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [apiCompanies[0]], // Only one company
        }),
      })
    })

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

    await page.goto('/')

    // Wait for dashboard to load
    await expect(page.getByRole('heading', { name: /dashboard/i })).toBeVisible()

    // Company selector should NOT be visible for single company
    await expect(page.getByRole('button', { name: /select company/i })).not.toBeVisible()
  })
})

test.describe('Signup Flow', () => {
  test.beforeEach(async ({ page }) => {
    // Return 401 for unauthenticated user
    await page.route('**/api/v1/auth/me', (route) => {
      route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Unauthenticated' }),
      })
    })
  })

  test('should display signup page with personal info fields', async ({ page }) => {
    await page.goto('/signup')

    // Should see the signup form with personal info
    await expect(page.getByRole('heading', { name: /create.*account|sign.*up|register/i })).toBeVisible()
    await expect(page.getByLabel(/first.*name/i)).toBeVisible()
    await expect(page.getByLabel(/last.*name/i)).toBeVisible()
    await expect(page.getByLabel(/email/i)).toBeVisible()
    await expect(page.getByLabel(/password/i)).toBeVisible()
  })

  test('should redirect to company creation after successful signup', async ({ page }) => {
    // Mock CSRF cookie
    await page.route('**/sanctum/csrf-cookie', (route) => {
      route.fulfill({ status: 204 })
    })

    // Mock signup endpoint
    await page.route('**/api/v1/auth/register', (route) => {
      route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: '1',
            name: 'New User',
            email: 'new@example.com',
          },
          next: '/onboarding/company',
        }),
      })
    })

    await page.goto('/signup')

    // Fill in signup form
    await page.getByLabel(/first.*name/i).fill('John')
    await page.getByLabel(/last.*name/i).fill('Doe')
    await page.getByLabel(/email/i).fill('john.doe@example.com')
    await page.getByLabel(/password/i).fill('SecurePassword123!')

    // Submit form
    await page.getByRole('button', { name: /sign.*up|register|create.*account/i }).click()

    // Should redirect to company onboarding
    await expect(page).toHaveURL(/\/onboarding\/company/)
  })

  test('should show validation errors for invalid signup data', async ({ page }) => {
    await page.goto('/signup')

    // Submit empty form
    await page.getByRole('button', { name: /sign.*up|register|create.*account/i }).click()

    // Should show validation errors
    await expect(page.getByText(/first.*name.*required|required.*first.*name/i)).toBeVisible()
    await expect(page.getByText(/email.*required|required.*email/i)).toBeVisible()
    await expect(page.getByText(/password.*required|required.*password/i)).toBeVisible()
  })
})

test.describe('Company Onboarding', () => {
  test.beforeEach(async ({ page }) => {
    // Mock authenticated user without company (needs onboarding)
    await page.route('**/api/v1/auth/me', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: '1',
            name: 'New User',
            email: 'new@example.com',
            tenant_id: 'tenant-1',
            roles: [],
            companies: [],
            current_company_id: null,
          },
        }),
      })
    })

    // Mock countries endpoint
    await page.route('**/api/v1/onboarding/countries', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { code: 'FR', name: 'France', currency_code: 'EUR', default_locale: 'fr-FR' },
            { code: 'TN', name: 'Tunisia', currency_code: 'TND', default_locale: 'fr-TN' },
            { code: 'GB', name: 'United Kingdom', currency_code: 'GBP', default_locale: 'en-GB' },
          ],
        }),
      })
    })
  })

  test('should display country selection on company onboarding page', async ({ page }) => {
    await page.goto('/onboarding/company')

    // Should see country selection step
    await expect(page.getByText(/select.*country|choose.*country|country/i)).toBeVisible()
    await expect(page.getByText('France')).toBeVisible()
    await expect(page.getByText('Tunisia')).toBeVisible()
  })

  test('should show company form after selecting country', async ({ page }) => {
    // Mock country fields endpoint
    await page.route('**/api/v1/onboarding/countries/FR/fields', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { name: 'company_name', label: 'Company Name', type: 'text', required: true },
            { name: 'legal_name', label: 'Legal Name', type: 'text', required: true },
            { name: 'tax_id', label: 'SIRET', type: 'text', required: true },
          ],
        }),
      })
    })

    await page.goto('/onboarding/company')

    // Select France
    await page.getByText('France').click()

    // Should show company form fields
    await expect(page.getByLabel(/company.*name/i)).toBeVisible()
    await expect(page.getByLabel(/legal.*name/i)).toBeVisible()
    await expect(page.getByLabel(/siret|tax.*id/i)).toBeVisible()
  })

  test('should redirect to dashboard after successful company creation', async ({ page }) => {
    // Mock country fields endpoint
    await page.route('**/api/v1/onboarding/countries/FR/fields', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { name: 'company_name', label: 'Company Name', type: 'text', required: true },
            { name: 'legal_name', label: 'Legal Name', type: 'text', required: true },
            { name: 'tax_id', label: 'SIRET', type: 'text', required: true },
          ],
        }),
      })
    })

    // Mock company creation endpoint
    await page.route('**/api/v1/companies', (route) => {
      if (route.request().method() === 'POST') {
        route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({
            data: mockCompanies[0],
          }),
        })
      } else {
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [mockCompanies[0]] }),
        })
      }
    })

    // Mock dashboard data for redirect
    await page.route('**/api/v1/dashboard/**', (route) => {
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

    await page.goto('/onboarding/company')

    // Select France
    await page.getByText('France').click()

    // Fill in company form
    await page.getByLabel(/company.*name/i).fill('My Garage')
    await page.getByLabel(/legal.*name/i).fill('My Garage SARL')
    await page.getByLabel(/siret|tax.*id/i).fill('12345678901234')

    // Submit form
    await page.getByRole('button', { name: /create.*company|submit|next/i }).click()

    // Should redirect to dashboard
    await expect(page).toHaveURL('/')
    await expect(page.getByRole('heading', { name: /dashboard/i })).toBeVisible()
  })
})
