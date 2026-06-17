import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { SplitPaymentForm } from './SplitPaymentForm'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet, post: mockApiPost },
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: unknown) =>
      typeof options === 'string' ? options : key,
  }),
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

function renderForm(overrides: Partial<Parameters<typeof SplitPaymentForm>[0]> = {}) {
  const onSuccess = vi.fn()
  const onCancel = vi.fn()
  render(
    <SplitPaymentForm
      documentId="doc-1"
      totalAmount={100}
      onSuccess={onSuccess}
      onCancel={onCancel}
      {...overrides}
    />,
    { wrapper: wrapper(createClient()) },
  )
  return { onSuccess, onCancel }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/payment-methods') {
      return { data: { data: [{ id: 'method-1', name: 'Cash', code: 'CASH', is_physical: false }] } }
    }
    if (url === '/payment-repositories') {
      return { data: { data: [{ id: 'repo-1', name: 'Cash Register', code: 'CASH', type: 'cash_register' }] } }
    }
    return { data: { data: [] } }
  })
  mockApiPost.mockResolvedValue({ data: { ok: true } })
})

afterEach(() => {
  resetTenant()
})

describe('SplitPaymentForm shared form primitives', () => {
  it('renders the line amount field as a MoneyInput via FormField', async () => {
    renderForm()

    const amount = await screen.findByLabelText('treasury:payments.amount')
    expect(amount.tagName).toBe('INPUT')
    expect(amount).toHaveAttribute('type', 'number')
    expect(amount).toHaveAttribute('inputmode', 'decimal')
  })

  it('renders the method and repository selects via FormField', async () => {
    renderForm()

    const method = await screen.findByLabelText('treasury:payments.method')
    const repository = await screen.findByLabelText('treasury:instruments.repository')
    expect(method.tagName).toBe('SELECT')
    expect(repository.tagName).toBe('SELECT')
  })

  it('submits matching split amount as a canonical string and calls onSuccess', async () => {
    const { onSuccess } = renderForm({ totalAmount: 100 })

    // Wait for the async payment-methods query to populate the select options.
    await screen.findByRole('option', { name: 'Cash' })
    await userEvent.selectOptions(
      screen.getByLabelText('treasury:payments.method'),
      'method-1',
    )
    await userEvent.type(screen.getByLabelText('treasury:payments.amount'), '100')

    await userEvent.click(
      screen.getByRole('button', { name: 'common:actions.submit' }),
    )

    await screen.findByRole('button', { name: 'common:actions.submit' })
    expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/split-payment', {
      splits: [{ payment_method_id: 'method-1', amount: '100' }],
    })
    expect(onSuccess).toHaveBeenCalledTimes(1)
  })

  it('renders cancel and submit Buttons that invoke their handlers', async () => {
    const { onCancel } = renderForm()

    await screen.findByLabelText('treasury:payments.amount')
    const cancel = screen.getByRole('button', { name: 'common:actions.cancel' })
    expect(cancel.tagName).toBe('BUTTON')

    await userEvent.click(cancel)
    expect(onCancel).toHaveBeenCalledTimes(1)
  })
})
