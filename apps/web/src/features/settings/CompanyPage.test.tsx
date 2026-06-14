import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { CompanyPage } from './CompanyPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      patch: mockApiPatch,
      post: mockApiPost,
      delete: mockApiDelete,
    },
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', () => ({
  Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, s?: unknown) => (typeof s === 'string' ? s : key),
  }),
}))

vi.mock('./components/ReceiptSettingsTab', () => ({ ReceiptSettingsTab: () => null }))

function companySettings() {
  return {
    name: 'Company A',
    legal_name: null,
    slug: 'company-a',
    tax_id: null,
    registration_number: null,
    address: { street: null, city: null, postal_code: null, country: null },
    phone: null,
    email: null,
    website: null,
    logo_url: '/logo.png',
    primary_color: '#2563EB',
    country_code: 'TN',
    currency_code: 'TND',
    timezone: 'Africa/Tunis',
    date_format: 'DD/MM/YYYY',
    locale: 'en',
  }
}

function setTenant() {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: 'tenant-A',
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function wrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant()
  mockApiGet.mockResolvedValue({ data: { data: companySettings() } })
  mockApiPatch.mockResolvedValue({ data: {} })
  mockApiPost.mockResolvedValue({ data: {} })
  mockApiDelete.mockResolvedValue({ data: {} })
})

afterEach(() => {
  resetTenant()
})

describe('CompanyPage (canonical primitives)', () => {
  it('renders exactly one h1 via PageHeader', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getByLabelText('settings:company.fields.name')).toBeInTheDocument()
    })
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('settings:company.title')
  })

  it('renders key fields through atoms with labels bound to inputs', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getByLabelText('settings:company.fields.name')).toBeInTheDocument()
    })
    expect(screen.getByLabelText('settings:company.fields.name').tagName).toBe('INPUT')
    expect(screen.getByLabelText('settings:company.fields.email').tagName).toBe('INPUT')
    expect(screen.getByLabelText('settings:company.fields.currency').tagName).toBe('SELECT')
  })

  it('renders the save action as a real button element', async () => {
    render(<CompanyPage />, { wrapper: wrapper() })
    await waitFor(() => {
      expect(screen.getByLabelText('settings:company.fields.name')).toBeInTheDocument()
    })
    const save = screen.getByRole('button', { name: 'common:actions.save' })
    expect(save.tagName).toBe('BUTTON')
  })
})
