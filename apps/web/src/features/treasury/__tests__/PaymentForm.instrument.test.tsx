import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { PaymentForm } from '../PaymentForm'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
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
    useSearchParams: () => [new URLSearchParams()] as const,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))
vi.mock('@/components/organisms', () => ({
  AddPartnerModal: () => null,
  AddRepositoryModal: () => null,
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
      decimals: 3,
      format: (value: number) => value.toFixed(3),
      symbol: 'TND',
    }),
  }
})

const CHEQUE_METHOD = {
  id: '11111111-1111-4111-8111-111111111111',
  name: 'Cheque',
  is_physical: true,
  has_maturity: true,
  instrument_kind: 'cheque',
  requires_third_party: true,
  is_push: false,
  has_deducted_fees: false,
  is_restricted: false,
  fee_type: null,
  fee_fixed: '0.000',
  fee_percent: '0.00',
}

const CASH_METHOD = {
  ...CHEQUE_METHOD,
  id: '22222222-2222-4222-8222-222222222222',
  name: 'Cash',
  is_physical: true,
  has_maturity: false,
  instrument_kind: null,
  requires_third_party: false,
  is_push: true,
}

const EFFET_METHOD = {
  ...CHEQUE_METHOD,
  id: '55555555-5555-4555-8555-555555555555',
  name: 'Effet',
  instrument_kind: 'effet',
}

const BANK_REPOSITORY = {
  id: '33333333-3333-4333-8333-333333333333',
  code: 'BANK',
  name: 'Main Bank',
  type: 'bank_account',
}

const PARTNER = {
  id: '44444444-4444-4444-8444-444444444444',
  name: 'Customer A',
}

function renderForm(methods: unknown[] = [CHEQUE_METHOD]) {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/payment-methods') return { data: { data: methods } }
    if (url === '/partners') return { data: { data: [PARTNER] } }
    if (url === '/payment-repositories') return { data: { data: [BANK_REPOSITORY] } }
    return { data: { data: [] } }
  })

  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  render(
    <QueryClientProvider client={client}>
      <PaymentForm />
    </QueryClientProvider>,
  )
}

async function selectMethod(methodId: string) {
  const select = await screen.findByLabelText('treasury:payments.form.paymentMethod *')
  await waitFor(() => expect(within(select).getAllByRole('option')).toHaveLength(2))
  fireEvent.change(select, { target: { value: methodId } })
}

async function fillRequiredPaymentFields(methodId: string) {
  await selectMethod(methodId)
  fireEvent.change(screen.getByLabelText('treasury:payments.form.amount *'), {
    target: { value: '150.000' },
  })
  fireEvent.change(screen.getByLabelText('treasury:payments.form.repository *'), {
    target: { value: BANK_REPOSITORY.id },
  })
  fireEvent.change(screen.getByLabelText('treasury:payments.partner *'), {
    target: { value: PARTNER.id },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: 'tenant-1',
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
  mockApiPost.mockResolvedValue({ id: 'payment-1', payment_number: 'PAY-1', amount: 150 })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('PaymentForm deferred-tender instrument payload', () => {
  it('shows the instrument fieldset and blocks submission without a reference', async () => {
    renderForm()
    await fillRequiredPaymentFields(CHEQUE_METHOD.id)

    expect(screen.getByRole('group', { name: 'treasury:instruments.formTitle' })).toBeInTheDocument()
    expect(screen.getByLabelText('treasury:instruments.reference *')).toBeRequired()
    expect(screen.getByLabelText('treasury:instruments.maturityDate')).not.toBeRequired()

    fireEvent.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => expect(screen.getByLabelText('treasury:instruments.reference *')).toBeInvalid())
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('posts the payment and inline instrument together in one API call', async () => {
    renderForm()
    await fillRequiredPaymentFields(CHEQUE_METHOD.id)

    fireEvent.change(screen.getByLabelText('treasury:instruments.reference *'), {
      target: { value: 'CHK-2026-0042' },
    })
    fireEvent.change(screen.getByLabelText('treasury:instruments.drawerName'), {
      target: { value: 'Nadia Ben Ali' },
    })
    fireEvent.change(screen.getByLabelText('treasury:instruments.bankName'), {
      target: { value: 'Banque de Tunisie' },
    })

    fireEvent.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => expect(mockApiPost).toHaveBeenCalledTimes(1))
    expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
      payment_method_id: CHEQUE_METHOD.id,
      repository_id: BANK_REPOSITORY.id,
      instrument: {
        reference: 'CHK-2026-0042',
        maturity_date: undefined,
        drawer_name: 'Nadia Ben Ali',
        bank_name: 'Banque de Tunisie',
        bank_branch: undefined,
        bank_account: undefined,
      },
    }))
  })

  it('requires a maturity date for effet methods', async () => {
    renderForm([EFFET_METHOD])
    await fillRequiredPaymentFields(EFFET_METHOD.id)
    fireEvent.change(screen.getByLabelText('treasury:instruments.reference *'), {
      target: { value: 'EFF-2026-0042' },
    })

    const maturity = screen.getByLabelText('treasury:instruments.maturityDate *')
    expect(maturity).toBeRequired()
    fireEvent.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => expect(maturity).toBeInvalid())
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('keeps immediate payments free of instrument UI and payload changes', async () => {
    renderForm([CASH_METHOD])
    await fillRequiredPaymentFields(CASH_METHOD.id)

    expect(screen.queryByRole('group', { name: 'treasury:instruments.formTitle' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => expect(mockApiPost).toHaveBeenCalledTimes(1))
    const [, payload] = mockApiPost.mock.calls[0]
    expect(payload).not.toHaveProperty('instrument')
    expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
      payment_method_id: CASH_METHOD.id,
      repository_id: BANK_REPOSITORY.id,
    }))
  })
})
