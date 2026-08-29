import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore, type Company } from '@/stores/companyStore'
import { CompanyProvider } from '@/features/company/CompanyProvider'

import { CompanySelector } from './CompanySelector'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
  }
})

const companyA: Company = {
  id: 'company-A',
  name: 'Company A',
  legalName: 'Company A SARL',
  taxId: null,
  countryCode: 'TN',
  currency: 'TND',
  locale: 'fr_TN',
  timezone: 'Africa/Tunis',
  isPrimary: true,
}

const companyB: Company = {
  ...companyA,
  id: 'company-B',
  name: 'Company B',
  legalName: 'Company B SARL',
  isPrimary: false,
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
  mockApiGet.mockImplementation(() => new Promise(() => undefined))
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
    currentCompanyId: companyA.id,
    companies: [companyA, companyB],
    isLoading: false,
  })
})

afterEach(() => {
  cleanup()
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  localStorage.clear()
})

describe('CompanySelector company switch invalidation', () => {
  it('re-keys active observers before invalidating and never refetches the old company query', async () => {
    const user = userEvent.setup()
    const oldCompanyQuery = vi.fn().mockResolvedValue('company-A data')
    const newCompanyQuery = vi.fn().mockResolvedValue('company-B data')
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity } },
    })
    const invalidateQueries = vi.spyOn(queryClient, 'invalidateQueries')

    function CompanyScopedObserver() {
      const companyId = useCompanyStore((state) => state.currentCompanyId)
      useQuery({
        queryKey: tenantScopedKey(['company-switch-probe']),
        queryFn: companyId === companyA.id ? oldCompanyQuery : newCompanyQuery,
      })
      return <div>observer {companyId}</div>
    }

    render(
      <>
        <CompanySelector />
        <CompanyScopedObserver />
      </>,
      { wrapper: wrapper(queryClient) },
    )

    await waitFor(() => {
      expect(oldCompanyQuery).toHaveBeenCalledOnce()
    })

    await user.click(screen.getByRole('button', { name: 'company.select' }))
    await user.click(screen.getByRole('button', { name: /Company B/ }))

    await waitFor(() => {
      expect(screen.getByText('observer company-B')).toBeInTheDocument()
      expect(invalidateQueries).toHaveBeenCalledOnce()
      expect(newCompanyQuery).toHaveBeenCalled()
    })
    expect(oldCompanyQuery).toHaveBeenCalledOnce()
  })

  it('stays mounted while CompanyProvider refetches the companies list under the new scope', async () => {
    const user = userEvent.setup()
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: Infinity } },
    })
    queryClient.setQueryData(
      ['user', 'companies', 'tenant-A', companyA.id],
      [companyA, companyB],
    )
    const invalidateQueries = vi.spyOn(queryClient, 'invalidateQueries')

    render(
      <CompanyProvider>
        <CompanySelector />
      </CompanyProvider>,
      { wrapper: wrapper(queryClient) },
    )

    await user.click(screen.getByRole('button', { name: 'company.select' }))
    await user.click(screen.getByRole('button', { name: /Company B/ }))

    await waitFor(() => {
      expect(
        queryClient.getQueryState(['user', 'companies', 'tenant-A', companyB.id])?.fetchStatus,
      ).toBe('fetching')
      expect(mockApiGet).toHaveBeenCalledWith('/user/companies')
      expect(invalidateQueries).toHaveBeenCalledOnce()
    })
    expect(screen.getByRole('button', { name: 'company.select' })).toBeInTheDocument()
  })
})
