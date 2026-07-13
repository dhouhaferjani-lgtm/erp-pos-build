import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { RepositoryListPage } from './RepositoryListPage'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: { ...actual.api, get: mockApiGet } }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, fallback?: unknown) => (
    typeof fallback === 'string' ? fallback : key
  ) }),
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: () => true }),
}))

vi.mock('@/components/organisms/AddRepositoryModal/AddRepositoryModal', () => ({
  AddRepositoryModal: () => null,
}))

const repository = {
  id: '11111111-1111-4111-8111-111111111111',
  code: 'CASH',
  name: 'Main till',
  type: 'cash_register',
  bank_name: null,
  account_number: null,
  iban: null,
  bic: null,
  balance: '100.000',
  currency: 'TND',
  is_active: true,
  is_default: true,
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
      </MemoryRouter>
    )
  }
}

describe('RepositoryListPage transfer integration', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Test User',
        email: 'test@example.com',
        tenant_id: 'tenant-1',
        roles: [],
        email_verified_at: null,
      },
      token: 'token',
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({
      currentCompanyId: 'company-1',
      companies: [{
        id: 'company-1',
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      }],
      isLoading: false,
    })
    mockApiGet.mockResolvedValue({ data: { data: [repository] } })
  })

  afterEach(() => {
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  })

  it('shares an array-shaped repository cache with the transfer modal', async () => {
    const user = userEvent.setup()
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<RepositoryListPage />, { wrapper: wrapper(queryClient) })

    expect(await screen.findByText('Main till')).toBeInTheDocument()
    expect(queryClient.getQueryData(['payment-repositories', 'tenant-1', 'company-1'])).toEqual([repository])

    await user.click(screen.getByRole('button', { name: 'treasury:repositories.transfer.action' }))

    await waitFor(() => {
      expect(screen.getByRole('dialog')).toHaveTextContent('treasury:repositories.transfer.title')
    })
  })
})
