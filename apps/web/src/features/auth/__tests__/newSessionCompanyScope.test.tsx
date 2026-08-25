import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { clearScopeForNewSession } from '@/lib/clearAppState'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useLocationStore } from '@/stores/locationStore'

import { CompanyProvider } from '../../company/CompanyProvider'

/**
 * W2-1 — a new authenticated session must not inherit the previous account's
 * company scope.
 *
 * Campaign wave 2: `POST /auth/register` returned 201, then EVERY authenticated
 * call 403'd because the browser still held `autoerp-company-selection` from a
 * different tenant. `clearAllAppState()` (which drops that key) ran only on
 * logout and on a 401 — never on register or login — so the fresh session
 * started poisoned and the company switcher opened empty with no way out.
 */

const COMPANY_SELECTION_KEY = 'autoerp-company-selection'
/** A company from the PREVIOUS account, on a different tenant. */
const OTHER_TENANT_COMPANY_ID = '3f1b8d02-6c1e-4a55-9d21-9a0f0b6e77aa'
/** The freshly provisioned tenant's own company. */
const NEW_COMPANY_ID = '01a034af-94ea-713d-8ce0-462216bf6ab5'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
  }
})

const newTenantCompaniesResponse = {
  data: {
    data: [
      {
        id: NEW_COMPANY_ID,
        name: 'Parapharmacie Khémira & Frères SARL',
        legal_name: 'Parapharmacie Khémira & Frères SARL',
        tax_id: null,
        country_code: 'TN',
        currency: 'TND',
        locale: 'fr',
        timezone: 'Africa/Tunis',
        is_primary: true,
      },
    ],
  },
}

function persistPreviousAccountSelection(): void {
  localStorage.setItem(COMPANY_SELECTION_KEY, OTHER_TENANT_COMPANY_ID)
  useCompanyStore.setState({
    currentCompanyId: OTHER_TENANT_COMPANY_ID,
    companies: [
      {
        id: OTHER_TENANT_COMPANY_ID,
        name: 'Parapharmacie Élégance & Santé SARL',
        legalName: 'Parapharmacie Élégance & Santé SARL',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'fr',
        timezone: 'Africa/Tunis',
        isPrimary: true,
      },
    ],
    isLoading: false,
  })
}

function authenticateAs(tenantId: string): void {
  useAuthStore.setState({
    user: {
      id: 'user-new',
      name: 'Naïma Ben Aïssa',
      email: 'naima.benaissa@pharmaccents.tn',
      tenant_id: tenantId,
      roles: ['owner'],
      email_verified_at: null,
    },
    token: 'fresh-token',
    isAuthenticated: true,
    isLoading: false,
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    )
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  localStorage.clear()
  mockApiGet.mockResolvedValue(newTenantCompaniesResponse)
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  useLocationStore.getState().reset()
})

afterEach(() => {
  localStorage.clear()
})

describe('clearScopeForNewSession', () => {
  it('drops the previous account company selection, including the persisted key', () => {
    persistPreviousAccountSelection()

    clearScopeForNewSession(new QueryClient())

    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(useCompanyStore.getState().companies).toEqual([])
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBeNull()
  })

  it('drops the previous account locations', () => {
    useLocationStore.setState({ currentLocationId: 'loc-previous', locations: [], isLoading: false })

    clearScopeForNewSession(new QueryClient())

    expect(useLocationStore.getState().currentLocationId).toBeNull()
  })

  it('drops the previous session cached queries', () => {
    const queryClient = new QueryClient()
    queryClient.setQueryData(['products', 'tenant-previous', OTHER_TENANT_COMPANY_ID], [{ id: 'p1' }])

    clearScopeForNewSession(queryClient)

    expect(queryClient.getQueryData(['products', 'tenant-previous', OTHER_TENANT_COMPANY_ID])).toBeUndefined()
  })

  it('leaves the auth store alone — the caller owns the new session', () => {
    authenticateAs('tenant-new')

    clearScopeForNewSession(new QueryClient())

    expect(useAuthStore.getState().isAuthenticated).toBe(true)
    expect(useAuthStore.getState().token).toBe('fresh-token')
  })
})

describe('bootstrap after a scope clear', () => {
  it('loads the NEW tenant companies and selects its primary company', async () => {
    persistPreviousAccountSelection()
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

    clearScopeForNewSession(queryClient)
    authenticateAs('tenant-new')

    render(<CompanyProvider><div>ready</div></CompanyProvider>, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/user/companies')
      // The switcher is populated — the wave-2 deadlock left it empty.
      expect(useCompanyStore.getState().companies).toHaveLength(1)
      expect(useCompanyStore.getState().currentCompanyId).toBe(NEW_COMPANY_ID)
    })

    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBe(NEW_COMPANY_ID)
  })
})

/**
 * The behaviour above is only reachable if the two entry points actually call
 * it. Both pages previously called `setAuth(...)` and navigated, which is
 * exactly how W2-1 shipped; these guards fail if that regresses.
 */
describe('register and login clear the scope before the first authenticated call', () => {
  const authDir = join(__dirname, '..')

  it.each(['RegisterPage.tsx', 'LoginPage.tsx'])('%s calls clearScopeForNewSession', (file) => {
    const source = readFileSync(join(authDir, file), 'utf8')

    expect(source).toContain('clearScopeForNewSession')
    // It must run BEFORE the new session is installed, so the first
    // authenticated request cannot carry the previous company id.
    expect(source.indexOf('clearScopeForNewSession(queryClient)')).toBeLessThan(source.indexOf('setAuth('))
  })
})
