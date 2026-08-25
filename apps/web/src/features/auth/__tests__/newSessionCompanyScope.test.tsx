import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { handleCompanyScopeRejection } from '@/lib/api'
import { clearScopeForNewSession } from '@/lib/clearAppState'
import { useAuthStore } from '@/stores/authStore'
import { clearDeniedCompanyIds, useCompanyStore } from '@/stores/companyStore'
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
  clearDeniedCompanyIds()
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
 * Gate r1 F-2 — the reviewer's probe, kept as a permanent regression test.
 *
 * "The reset cannot loop because currentCompanyId becomes null" is only half the
 * cycle: CompanyProvider immediately re-bootstraps on the re-keyed query and
 * `resolveCompanySelection` deterministically re-picks `isPrimary` /
 * `companies[0]`. If the server denies THAT company, the next 403 resets again —
 * forever. Loop safety has to be an enforced invariant, not a comment.
 */
describe('a denied company is never re-picked by the bootstrap (F-2)', () => {
  const DENIED_ID = NEW_COMPANY_ID
  const FALLBACK_ID = '7c9e2f14-2b7a-4f0e-8c33-1d5a6b2e9f01'

  function listResponse(ids: string[]) {
    return {
      data: {
        data: ids.map((id, index) => ({
          id,
          name: `Company ${index}`,
          legal_name: `Company ${index}`,
          tax_id: null,
          country_code: 'TN',
          currency: 'TND',
          locale: 'fr',
          timezone: 'Africa/Tunis',
          // The denied one is the primary — the id resolveCompanySelection
          // would otherwise choose every single time.
          is_primary: id === DENIED_ID,
        })),
      },
    }
  }

  beforeEach(() => {
    vi.spyOn(console, 'warn').mockImplementation(() => undefined)
  })

  it('re-bootstraps onto a DIFFERENT company after the denied one is rejected', async () => {
    mockApiGet.mockResolvedValue(listResponse([DENIED_ID, FALLBACK_ID]))
    authenticateAs('tenant-new')
    useCompanyStore.setState({
      currentCompanyId: DENIED_ID,
      companies: [],
      isLoading: false,
    })

    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', DENIED_ID)).toBe(true)

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<CompanyProvider><div>ready</div></CompanyProvider>, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(useCompanyStore.getState().companies).toHaveLength(2)
    })

    expect(useCompanyStore.getState().currentCompanyId).toBe(FALLBACK_ID)
    // ...and the second rejection cannot fire, because nothing re-picked it.
    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', DENIED_ID)).toBe(false)
  })

  it('leaves the selection null when the ONLY listed company is denied', async () => {
    mockApiGet.mockResolvedValue(listResponse([DENIED_ID]))
    authenticateAs('tenant-new')
    useCompanyStore.setState({ currentCompanyId: DENIED_ID, companies: [], isLoading: false })

    expect(handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', DENIED_ID)).toBe(true)

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<CompanyProvider><div>ready</div></CompanyProvider>, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(useCompanyStore.getState().companies).toHaveLength(1)
    })

    expect(useCompanyStore.getState().currentCompanyId).toBeNull()
    expect(localStorage.getItem(COMPANY_SELECTION_KEY)).toBeNull()
  })

  it('a new session forgets earlier denials — a regained membership is selectable', () => {
    useCompanyStore.setState({ currentCompanyId: DENIED_ID, companies: [], isLoading: false })
    handleCompanyScopeRejection('COMPANY_ACCESS_DENIED', DENIED_ID)

    clearScopeForNewSession(new QueryClient())

    useCompanyStore.getState().setCompanies([
      {
        id: DENIED_ID,
        name: 'Regained',
        legalName: 'Regained',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'fr',
        timezone: 'Africa/Tunis',
        isPrimary: true,
      },
    ])

    expect(useCompanyStore.getState().currentCompanyId).toBe(DENIED_ID)
  })
})

/**
 * The behaviour above is only reachable if the two entry points actually call
 * it. Both pages previously called `setAuth(...)` and navigated, which is
 * exactly how W2-1 shipped; these guards fail if that regresses.
 *
 * HEURISTIC GUARDS, not behavioural tests (gate r1 F-5). They read source text:
 * they pass on a commented-out call, break on a rename, and the `indexOf`
 * ordering check would mis-anchor if a `setAuth(` string ever appeared earlier
 * in the file. They exist because neither page has a seam worth extracting for
 * a 4-step wizard; do not read them as proof the call executes.
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
