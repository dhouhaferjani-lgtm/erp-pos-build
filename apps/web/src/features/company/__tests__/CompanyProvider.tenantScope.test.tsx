import { QueryClient, QueryClientProvider, useQueryClient } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { CompanyProvider, useInvalidateCompanies } from '../CompanyProvider'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
  }
})

function setTenant(tenantId: string, companyId: string | null = null) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: companyId
      ? [
        {
          id: companyId,
          name: 'Test Company',
          legalName: 'Test Company LLC',
          taxId: null,
          countryCode: 'TN',
          currency: 'TND',
          locale: 'en_US',
          timezone: 'Africa/Tunis',
        },
      ]
      : [],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
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

function InvalidateCompaniesButton() {
  const invalidateCompanies = useInvalidateCompanies()
  const queryClient = useQueryClient()
  return (
    <button
      type="button"
      onClick={() => {
        void invalidateCompanies()
      }}
    >
      invalidate {queryClient.getQueryCache().getAll().length}
    </button>
  )
}

const companiesResponse = {
  data: {
    data: [
      {
        id: 'company-1',
        name: 'Test Company',
        legal_name: 'Test Company LLC',
        tax_id: null,
        country_code: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
  },
}

beforeEach(() => {
  vi.clearAllMocks()
  mockApiGet.mockResolvedValue(companiesResponse)
  setTenant('tenant-A')
})

afterEach(() => {
  resetTenant()
})

describe('CompanyProvider tenant scope', () => {
  it('scopes the user companies bootstrap query (.105)', async () => {
    const queryClient = createClient()

    render(<CompanyProvider><div>ready</div></CompanyProvider>, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/user/companies')
      expect(queryClient.getQueryData(['user', 'companies', 'tenant-A', null])).toEqual([
        {
          id: 'company-1',
          name: 'Test Company',
          legalName: 'Test Company LLC',
          taxId: null,
          countryCode: 'TN',
          currency: 'TND',
          locale: 'en_US',
          timezone: 'Africa/Tunis',
          isPrimary: false,
        },
      ])
    })
  })

  it('removes all scoped user company caches on logout (.106)', async () => {
    resetTenant()
    const queryClient = createClient()
    queryClient.setQueryData(['user', 'companies', 'tenant-A', 'company-1'], ['tenant-A-marker'])
    queryClient.setQueryData(['user', 'companies', 'tenant-B', 'company-2'], ['tenant-B-marker'])

    render(<CompanyProvider><div>ready</div></CompanyProvider>, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['user', 'companies', 'tenant-A', 'company-1'])).toBeUndefined()
      expect(queryClient.getQueryData(['user', 'companies', 'tenant-B', 'company-2'])).toBeUndefined()
    })
  })

  it('invalidates only the active tenant company cache (.107)', async () => {
    const user = userEvent.setup()
    const queryClient = createClient()
    act(() => {
      setTenant('tenant-A', 'company-1')
    })
    queryClient.setQueryData(['user', 'companies', 'tenant-A', 'company-1'], ['tenant-A-marker'])
    queryClient.setQueryData(['user', 'companies', 'tenant-B', 'company-2'], ['tenant-B-marker'])

    render(<InvalidateCompaniesButton />, { wrapper: wrapper(queryClient) })

    await user.click(screen.getByRole('button', { name: /invalidate/ }))

    await waitFor(() => {
      expect(queryClient.getQueryState(['user', 'companies', 'tenant-A', 'company-1'])?.isInvalidated).toBe(true)
    })
    expect(queryClient.getQueryState(['user', 'companies', 'tenant-B', 'company-2'])?.isInvalidated).toBe(false)
    expect(queryClient.getQueryData(['user', 'companies', 'tenant-B', 'company-2'])).toEqual(['tenant-B-marker'])
  })

  it('does not fetch companies without an authenticated tenant', () => {
    resetTenant()

    render(<CompanyProvider><div>ready</div></CompanyProvider>, { wrapper: wrapper(createClient()) })

    expect(mockApiGet).not.toHaveBeenCalled()
  })
})
