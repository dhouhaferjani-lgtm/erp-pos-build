import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore, type Company } from '@/stores/companyStore'

const mockCreateCompany = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())

// i18n: return interpolation default string when provided, else the key.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}))

vi.mock('./api', () => ({
  createCompany: mockCreateCompany,
}))

import { CompanyOnboardingPage } from './CompanyOnboardingPage'

const oldCompany: Company = {
  id: 'company-old',
  name: 'Old Company',
  legalName: 'Old Company SARL',
  taxId: null,
  countryCode: 'TN',
  currency: 'TND',
  locale: 'fr_TN',
  timezone: 'Africa/Tunis',
  isPrimary: true,
}

const createdCompany: Company = {
  id: 'company-created',
  name: 'Created Company',
  legalName: 'Created Company SARL',
  taxId: null,
  countryCode: 'TN',
  currency: 'TND',
  locale: 'fr_TN',
  timezone: 'Africa/Tunis',
  isPrimary: false,
}

function createClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function renderPage(queryClient = createClient()) {
  return {
    queryClient,
    ...render(
      <QueryClientProvider client={queryClient}>
        <CompanyOnboardingPage />
      </QueryClientProvider>,
    ),
  }
}

async function submitCreatedCompany() {
  const user = userEvent.setup()
  await user.click(screen.getByRole('button', { name: 'common:next' }))
  await user.type(screen.getByLabelText(/settings:company\.fields\.companyName/), 'Created Company')
  await user.click(screen.getByRole('button', { name: 'common:next' }))
  await user.click(screen.getByRole('button', { name: 'common:next' }))
  await user.click(screen.getByRole('button', { name: 'settings:company.modal.createButton' }))
}

beforeEach(() => {
  vi.clearAllMocks()
  localStorage.clear()
  mockCreateCompany.mockResolvedValue(createdCompany)
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
    currentCompanyId: oldCompany.id,
    companies: [oldCompany],
    isLoading: false,
  })
})

afterEach(() => {
  cleanup()
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  localStorage.clear()
})

describe('CompanyOnboardingPage (canonical primitives)', () => {
  it('renders exactly one h1 page title', () => {
    renderPage()
    const h1s = screen.getAllByRole('heading', { level: 1 })
    expect(h1s).toHaveLength(1)
  })

  it('exposes the first-step (country) options as real buttons', () => {
    renderPage()
    // The country step renders one selectable button per country.
    expect(
      screen.getByRole('button', { name: /France/ }),
    ).toBeInTheDocument()
  })

  it('orders the country options alphabetically (no hardcoded France-first bias)', () => {
    renderPage()
    // Country buttons are the ones showing "currency • timezone".
    const countryButtons = screen
      .getAllByRole('button')
      .filter((button) => button.textContent.includes('•'))
    expect(countryButtons.length).toBeGreaterThan(0)
    expect(countryButtons[0]).toHaveTextContent('Algeria')
    const names = countryButtons.map((button) => button.textContent)
    expect(names.findIndex((text) => text.includes('Algeria'))).toBeLessThan(
      names.findIndex((text) => text.includes('France')),
    )
  })

  it('renders the Next navigation control as a <button>', () => {
    renderPage()
    const next = screen.getByRole('button', { name: 'common:next' })
    expect(next.tagName).toBe('BUTTON')
  })

  it('switches to and persists the company created through the reachable onboarding flow', async () => {
    renderPage()

    await submitCreatedCompany()

    await waitFor(() => {
      expect(useCompanyStore.getState().currentCompanyId).toBe(createdCompany.id)
    })
    expect(localStorage.getItem('autoerp-company-selection')).toBe(createdCompany.id)
  })

  it('invalidates the companies list for the newly adopted selection', async () => {
    const queryClient = createClient()
    queryClient.setQueryData(['user', 'companies', 'tenant-A', oldCompany.id], ['old-marker'])
    queryClient.setQueryData(['user', 'companies', 'tenant-A', createdCompany.id], ['new-marker'])
    renderPage(queryClient)

    await submitCreatedCompany()

    await waitFor(() => {
      expect(
        queryClient.getQueryState(['user', 'companies', 'tenant-A', createdCompany.id])?.isInvalidated,
      ).toBe(true)
    })
    expect(
      queryClient.getQueryState(['user', 'companies', 'tenant-A', oldCompany.id])?.isInvalidated,
    ).toBe(false)
  })
})
