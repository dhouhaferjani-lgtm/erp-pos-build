import { test as base, Page } from '@playwright/test'

// Auth state for localStorage
const mockAuthState = {
  state: {
    user: {
      id: '1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-1',
      roles: ['admin'],
    },
    token: 'fake-jwt-token',
    isAuthenticated: true,
  },
  version: 0,
}

// Extend the base test with authenticated session helper
export const test = base.extend<{ authenticatedPage: Page }>({
  authenticatedPage: async ({ page }, use) => {
    // Set up API mocks first
    // Mock authenticated user - app uses /auth/me endpoint
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
        body: JSON.stringify(mockLocations),
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

    // Navigate to set localStorage, then set auth state
    await page.goto('/')
    await page.evaluate((authState) => {
      localStorage.setItem('autoerp-auth', JSON.stringify(authState))
    }, mockAuthState)

    await use(page)
  },
})

export { expect } from '@playwright/test'

// Common mock data
export const mockDocuments = [
  {
    id: '1',
    document_number: 'INV-2025-0001',
    type: 'invoice',
    status: 'posted',
    partner_id: '1',
    partner_name: 'Acme Corp',
    total_amount: 1500,
    tax_amount: 300,
    net_amount: 1200,
    issue_date: '2025-01-15',
    due_date: '2025-02-15',
    created_at: '2025-01-15T10:00:00Z',
  },
]

export const mockPayments = [
  {
    id: '1',
    payment_number: 'PAY-2025-0001',
    amount: 1500,
    payment_date: '2025-01-15',
    payment_method_id: '1',
    payment_method_name: 'Cash',
    partner_id: '1',
    partner_name: 'Acme Corp',
    status: 'completed',
    created_at: '2025-01-15T10:00:00Z',
  },
]

export const mockPartners = [
  { id: '1', name: 'Acme Corp' },
  { id: '2', name: 'Client Inc' },
]

export const mockPaymentMethods = [
  { id: '1', name: 'Cash', is_physical: false },
  { id: '2', name: 'Check', is_physical: true },
]

// Company mock data for multi-company testing
export const mockCompanies = [
  {
    id: 'company-1',
    name: 'Garage Central',
    legalName: 'Garage Central SARL',
    taxId: '12345678901234',
    countryCode: 'FR',
    currency: 'EUR',
    locale: 'fr-FR',
    timezone: 'Europe/Paris',
  },
  {
    id: 'company-2',
    name: 'Auto Parts Plus',
    legalName: 'Auto Parts Plus SAS',
    taxId: '98765432109876',
    countryCode: 'FR',
    currency: 'EUR',
    locale: 'fr-FR',
    timezone: 'Europe/Paris',
  },
]

export const mockLocations = [
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
    is_default: false,
    is_active: true,
    pos_enabled: false,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  },
]

// Product mock data with cost/margin information
export const mockProducts = [
  {
    id: 'prod-1',
    sku: 'BP-001',
    name: 'Brake Pads - Premium',
    description: 'High-quality ceramic brake pads',
    cost_price: '45.00',
    sell_price: '65.00',
    last_purchase_cost: '48.00',
    cost_updated_at: '2025-01-10T10:00:00Z',
    target_margin_override: null,
    minimum_margin_override: null,
    stock_quantity: 25,
  },
  {
    id: 'prod-2',
    sku: 'OF-001',
    name: 'Oil Filter',
    description: 'Standard oil filter',
    cost_price: '15.00',
    sell_price: '25.00',
    last_purchase_cost: '16.00',
    cost_updated_at: '2025-01-08T14:00:00Z',
    target_margin_override: null,
    minimum_margin_override: null,
    stock_quantity: 50,
  },
]

// Margin check mock response helper
export function createMarginCheckResponse(
  costPrice: number,
  sellPrice: number,
  targetMargin = 30,
  minimumMargin = 10
) {
  const actualMargin = sellPrice > 0 ? ((sellPrice - costPrice) / sellPrice) * 100 : 0

  let level: 'green' | 'yellow' | 'orange' | 'red'
  let message: string

  if (sellPrice < costPrice) {
    level = 'red'
    message = 'Below cost - LOSS'
  } else if (actualMargin < minimumMargin) {
    level = 'orange'
    message = 'Below minimum margin'
  } else if (actualMargin < targetMargin) {
    level = 'yellow'
    message = 'Below target margin'
  } else {
    level = 'green'
    message = 'Above target margin'
  }

  return {
    cost_price: costPrice,
    sell_price: sellPrice,
    margin_level: {
      level,
      message,
      actual_margin: actualMargin,
      ...(level === 'red' && { loss_amount: costPrice - sellPrice }),
    },
    can_sell: {
      allowed: level !== 'red',
      reason: level === 'red' ? 'Sales below cost are not allowed' : null,
    },
    suggested_price: costPrice * (1 + targetMargin / 100),
    margins: {
      target_margin: targetMargin,
      minimum_margin: minimumMargin,
      source: 'company',
    },
  }
}

// Extended test with multi-company authenticated page
export const multiCompanyTest = base.extend<{ multiCompanyPage: Page }>({
  multiCompanyPage: async ({ page }, use) => {
    // Mock authenticated user with company memberships
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
            companies: mockCompanies,
            current_company_id: 'company-1',
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
          data: mockCompanies.map((c) => ({
            id: c.id,
            name: c.name,
            legal_name: c.legalName,
            tax_id: c.taxId,
            country_code: c.countryCode,
            currency: c.currency,
            locale: c.locale,
            timezone: c.timezone,
          })),
        }),
      })
    })

    // Mock locations (required by LocationProvider)
    await page.route('**/api/v1/locations', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockLocations),
      })
    })

    // Mock companies list endpoint
    await page.route('**/api/v1/companies', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: mockCompanies,
        }),
      })
    })

    // Mock current company endpoint
    await page.route('**/api/v1/companies/current', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: mockCompanies[0],
        }),
      })
    })

    // Mock company switch endpoint
    await page.route('**/api/v1/companies/switch', (route) => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: mockCompanies[1],
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

    await use(page)
  },
})
