import { Link, Route, Routes } from 'react-router-dom'
import { waitFor, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { PartnerForm } from '../PartnerForm'
import { PartnerListPage } from '../PartnerListPage'
import { makePartnersListResponse } from '../__fixtures__/partner'

/**
 * BUG-006 — a partner created as "Client" also showed up in the Fournisseurs list.
 *
 * The two lists are the SAME component mounted by two structurally identical
 * route elements, so react-router reconciles instead of remounting when you
 * navigate Clients → Fournisseurs. `useTableState` seeds `defaultFilters` only
 * in its `useState` initializer, so the filter stayed frozen at
 * `{ type: 'customer' }` and the Fournisseurs page issued
 * `GET /partners?…type=customer`.
 *
 * These tests must exercise the RECONCILIATION path (navigate between two
 * mounted routes), not just an initial mount — an initial mount was always
 * correct.
 */

const mockApiInstance = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: mockApiInstance }
})

vi.mock('../hooks/usePartnerBalanceRealtime', () => ({
  usePartnerBalanceRealtime: vi.fn(),
}))

vi.mock('../../settings/api/country', () => ({
  getCountries: vi.fn().mockResolvedValue([]),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

function partnerListUrls(): string[] {
  return mockApiInstance.get.mock.calls
    .map((call) => String(call[0]))
    .filter((url) => url.startsWith('/partners?'))
}

function setTenant(): void {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-A',
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: 'company-1',
    companies: [
      {
        id: 'company-1',
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

/**
 * Mirrors the production route shape (routes/index.tsx): two sibling routes
 * rendering the same lazy component with a different `partnerType` prop.
 * Deliberately WITHOUT a `key`, so React reconciles the element across the
 * navigation exactly as react-router does in the app.
 */
function PartnerListRoutes() {
  return (
    <>
      <Link to="/purchases/suppliers">go-to-suppliers</Link>
      <Link to="/sales/customers">go-to-customers</Link>
      <Routes>
        <Route path="/sales/customers" element={<PartnerListPage partnerType="customer" />} />
        <Route path="/purchases/suppliers" element={<PartnerListPage partnerType="supplier" />} />
      </Routes>
    </>
  )
}

describe('PartnerListPage route partner type (BUG-006)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setTenant()
    mockApiInstance.get.mockResolvedValue({
      data: makePartnersListResponse({ data: [] }),
    })
  })

  afterEach(() => {
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  })

  it('ISSUES A REQUEST, and one for type=supplier, after navigating Clients → Fournisseurs without a remount', async () => {
    const user = userEvent.setup()

    renderWithProviders(<PartnerListRoutes />, { route: '/sales/customers' })

    await waitFor(() => {
      expect(partnerListUrls().some((url) => url.includes('type=customer'))).toBe(true)
    })
    const callsBeforeNavigation = partnerListUrls().length

    await user.click(screen.getByRole('link', { name: 'go-to-suppliers' }))

    // (a) A request must actually FIRE. Absence of a request is the bug's real
    // signature: when the query key does not change, TanStack replays the
    // cached customer page under the Fournisseurs title and no network call
    // happens at all. A params-only assertion could pass vacuously against
    // that cache replay, so assert the call count first.
    await waitFor(() => {
      expect(partnerListUrls().length).toBeGreaterThan(callsBeforeNavigation)
    })

    // (b) and it must carry the new route's type.
    const after = partnerListUrls().slice(callsBeforeNavigation)
    expect(after.some((url) => url.includes('type=supplier'))).toBe(true)
    expect(after.some((url) => url.includes('type=customer'))).toBe(false)
  })

  it('ISSUES A REQUEST, and one for type=customer, after navigating Fournisseurs → Clients without a remount', async () => {
    const user = userEvent.setup()

    renderWithProviders(<PartnerListRoutes />, { route: '/purchases/suppliers' })

    await waitFor(() => {
      expect(partnerListUrls().some((url) => url.includes('type=supplier'))).toBe(true)
    })
    const callsBeforeNavigation = partnerListUrls().length

    await user.click(screen.getByRole('link', { name: 'go-to-customers' }))

    await waitFor(() => {
      expect(partnerListUrls().length).toBeGreaterThan(callsBeforeNavigation)
    })

    const after = partnerListUrls().slice(callsBeforeNavigation)
    expect(after.some((url) => url.includes('type=customer'))).toBe(true)
    expect(after.some((url) => url.includes('type=supplier'))).toBe(false)
  })

  it('keeps the route partner type authoritative over a stale ?type= search param', async () => {
    // The URL-sync effect in useTableState writes the stale filter onto the new
    // path, making the wrong type bookmarkable. The route context must win.
    renderWithProviders(<PartnerListRoutes />, { route: '/purchases/suppliers?type=customer' })

    await waitFor(() => {
      expect(partnerListUrls().length).toBeGreaterThan(0)
    })
    expect(partnerListUrls().every((url) => url.includes('type=supplier'))).toBe(true)
  })

  it('scopes the react-query key by partner type so the two lists never share a cache entry', async () => {
    const queryClient = createTestQueryClient()

    renderWithProviders(<PartnerListRoutes />, { route: '/sales/customers', queryClient })

    await waitFor(() => {
      expect(partnerListUrls().length).toBeGreaterThan(0)
    })

    const partnerKeys = queryClient
      .getQueryCache()
      .getAll()
      .map((q) => q.queryKey as unknown[])
      .filter((key) => key[0] === 'partners')

    expect(partnerKeys.length).toBeGreaterThan(0)
    for (const key of partnerKeys) {
      // tenantScopedKey suffixes tenant + company; the type must be inside the key.
      expect(key.at(-2)).toBe('tenant-A')
      expect(key.at(-1)).toBe('company-1')
      expect(JSON.stringify(key)).toContain('customer')
    }
  })
})

/**
 * Belt-and-braces half of the fix: react-router renders route elements without
 * keys, so structurally identical Clients/Fournisseurs elements reconcile. An
 * explicit `key` forces a real remount, which also re-seeds `PartnerForm`'s
 * `defaultValues.type` (the frozen "Nouveau client" / "Nouveau fournisseur"
 * select — the second manifestation of BUG-006).
 *
 * Asserted against the route source, matching the existing convention in
 * `src/routes/routes.test.tsx`.
 */
describe('partner route elements force a remount per partner type (BUG-006)', () => {
  const routesSource = readFileSync(`${process.cwd()}/src/routes/index.tsx`, 'utf8')

  const partnerRouteElements = [
    '<CustomerListPage partnerType="customer" />',
    '<CustomerForm partnerType="customer" />',
    '<CustomerListPage partnerType="supplier" />',
    '<CustomerForm partnerType="supplier" />',
  ]

  it.each(partnerRouteElements)('never renders %s without a key', (element) => {
    expect(routesSource).not.toContain(element)
  })

  it('keys every partner list and form route element by partner type', () => {
    const keyed = routesSource.match(
      /<Customer(?:ListPage|Form) key="(?:customer|supplier)" partnerType="(?:customer|supplier)" \/>/g,
    )
    expect(keyed).toHaveLength(6)
  })
})

/**
 * The report also claimed a second manifestation: the create form's type select
 * frozen between "Nouveau client" and "Nouveau fournisseur". Forensics could
 * NOT reproduce it by clicking through the live app — there is no in-app link
 * from one create form straight to the other, so the form almost always mounts
 * fresh from a list page.
 *
 * The underlying mechanism is real all the same (first test below): `useForm`
 * reads `defaultValues.type` only at mount, so an unkeyed route element that
 * reconciles keeps the previous route's type. `key={partnerType}` closes it
 * (second test) — which is the fix the list bug needs anyway.
 */
describe('PartnerForm type select across a route reconciliation (BUG-006, second manifestation)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setTenant()
    mockApiInstance.get.mockResolvedValue({ data: { data: [] } })
  })

  afterEach(() => {
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  })

  function CreateFormRoutes({ keyed }: { keyed: boolean }) {
    return (
      <>
        <Link to="/purchases/suppliers/new">go-to-supplier-form</Link>
        <Routes>
          <Route
            path="/sales/customers/new"
            element={keyed
              ? <PartnerForm key="customer" partnerType="customer" />
              : <PartnerForm partnerType="customer" />}
          />
          <Route
            path="/purchases/suppliers/new"
            element={keyed
              ? <PartnerForm key="supplier" partnerType="supplier" />
              : <PartnerForm partnerType="supplier" />}
          />
        </Routes>
      </>
    )
  }

  it('freezes on the previous type when the route elements are NOT keyed (mechanism)', async () => {
    const user = userEvent.setup()

    renderWithProviders(<CreateFormRoutes keyed={false} />, { route: '/sales/customers/new' })

    await waitFor(() => {
      expect(screen.getByLabelText(/^type/i)).toHaveValue('customer')
    })

    await user.click(screen.getByRole('link', { name: 'go-to-supplier-form' }))

    // Reconciled, not remounted: useForm keeps the customer default.
    expect(screen.getByLabelText(/^type/i)).toHaveValue('customer')
  })

  it('follows the route context once the elements are keyed by partner type (the fix)', async () => {
    const user = userEvent.setup()

    renderWithProviders(<CreateFormRoutes keyed />, { route: '/sales/customers/new' })

    await waitFor(() => {
      expect(screen.getByLabelText(/^type/i)).toHaveValue('customer')
    })

    await user.click(screen.getByRole('link', { name: 'go-to-supplier-form' }))

    await waitFor(() => {
      expect(screen.getByLabelText(/^type/i)).toHaveValue('supplier')
    })
  })
})
