import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { PaymentForm } from './PaymentForm'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const mockSearchParams = vi.hoisted(() => new URLSearchParams())
const mockWithholdingPreviewMutate = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
    apiPost: mockApiPost,
    getErrorMessage: () => 'request failed',
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    Link: ({ children }: { children: ReactNode }) => <a href="/test">{children}</a>,
    useNavigate: () => mockNavigate,
    useSearchParams: () => [mockSearchParams] as const,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: unknown) =>
      typeof options === 'string' ? options : key,
  }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

vi.mock('@/components/organisms', () => ({
  AddPartnerModal: () => null,
  AddRepositoryModal: () => null,
}))

vi.mock('./components', () => ({
  PaymentAllocationForm: () => null,
}))

vi.mock('@/features/withholding', () => ({
  useWithholdingPreview: () => ({ data: undefined, mutate: mockWithholdingPreviewMutate }),
}))

vi.mock('@/hooks/useCurrency', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/hooks/useCurrency')>()
  return {
    ...actual,
    useCurrency: () => ({
      currency: 'TND',
      decimals: 2,
      format: (value: number) => value.toFixed(2),
      symbol: 'TND',
    }),
  }
})

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/payment-methods') return { data: { data: [{ id: 'method-1', name: 'Cash', is_physical: false }] } }
    if (url === '/partners') return { data: { data: [{ id: 'partner-1', name: 'Partner A' }] } }
    if (url === '/payment-repositories') return { data: { data: [{ id: 'repo-1', code: 'CASH', name: 'Cash Register', type: 'cash_register' }] } }
    return { data: { data: [] } }
  })
  mockApiPost.mockResolvedValue({ id: 'payment-1', payment_number: 'PAY-1', amount: 100 })
})

afterEach(() => {
  resetTenant()
})

describe('PaymentForm shared form primitives', () => {
  it('renders the amount field as a MoneyInput via FormField (decimal numeric input)', async () => {
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    const amount = await screen.findByLabelText('treasury:payments.form.amount *')
    // MoneyInput renders a native numeric input bound to a canonical string value.
    expect(amount.tagName).toBe('INPUT')
    expect(amount).toHaveAttribute('type', 'number')
    expect(amount).toHaveAttribute('inputmode', 'decimal')
  })

  it('renders the method, repository and partner selects via FormField', async () => {
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    const method = await screen.findByLabelText('treasury:payments.form.paymentMethod *')
    const repository = await screen.findByLabelText('treasury:payments.form.repository *')
    const partner = await screen.findByLabelText('treasury:payments.partner *')

    expect(method.tagName).toBe('SELECT')
    expect(repository.tagName).toBe('SELECT')
    expect(partner.tagName).toBe('SELECT')
  })

  it('renders a submit Button (button type="submit")', async () => {
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    const submit = await screen.findByRole('button', { name: 'common:save' })
    expect(submit.tagName).toBe('BUTTON')
    expect(submit).toHaveAttribute('type', 'submit')
  })

  it('keeps lookup query keys tenant/company scoped', async () => {
    const queryClient = createClient()
    render(<PaymentForm />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['payment-methods', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['partners', 'tenant-A', 'company-1'])).toBeDefined()
      expect(queryClient.getQueryData(['payment-repositories', 'tenant-A', 'company-1'])).toBeDefined()
    })
  })
})
