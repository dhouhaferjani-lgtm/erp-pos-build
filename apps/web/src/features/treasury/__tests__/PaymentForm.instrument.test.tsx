import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { PaymentForm } from '../PaymentForm'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const mockWithholdingPreviewMutate = vi.hoisted(() => vi.fn())
const mockUseBanks = vi.hoisted(() => vi.fn())

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
vi.mock('@/components/organisms/AddPartnerModal/AddPartnerModal', () => ({ AddPartnerModal: () => null }))
vi.mock('@/components/organisms/AddRepositoryModal/AddRepositoryModal', () => ({ AddRepositoryModal: () => null }))
vi.mock('@/features/withholding/hooks/useWithholding', () => ({
  useWithholdingPreview: () => ({ data: undefined, mutate: mockWithholdingPreviewMutate }),
}))
vi.mock('@/hooks/useBanks', () => ({
  useBanks: mockUseBanks,
}))
vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfigOptional: () => ({
    config: { country_code: 'TN' },
    isLoading: false,
    error: null,
    hasModule: () => true,
  }),
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

const AMEN_BANK = {
  id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  country_code: 'TN',
  name: 'AMEN BANK',
  short_name: 'AMEN',
  bic: 'CFCTTNTT',
  rib_bank_code: '07',
  city: 'TUNIS',
  is_custom: false,
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
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <PaymentForm />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

async function selectMethod(methodId: string) {
  const user = userEvent.setup()
  const select = await screen.findByLabelText('treasury:payments.form.paymentMethod *')
  await waitFor(() => expect(within(select).getAllByRole('option')).toHaveLength(2))
  await user.selectOptions(select, methodId)
}

async function fillRequiredPaymentFields(methodId: string) {
  const user = userEvent.setup()
  await selectMethod(methodId)
  await user.type(screen.getByLabelText('treasury:payments.form.amount *'), '150.000')
  await user.selectOptions(screen.getByLabelText('treasury:payments.form.repository *'), BANK_REPOSITORY.id)
  await user.selectOptions(screen.getByLabelText('treasury:payments.partner *'), PARTNER.id)
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
  mockUseBanks.mockReturnValue({ data: [AMEN_BANK], isLoading: false, isError: false })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('PaymentForm deferred-tender instrument payload', () => {
  it('shows the instrument fieldset and blocks submission without a reference', async () => {
    const user = userEvent.setup()
    renderForm()
    await fillRequiredPaymentFields(CHEQUE_METHOD.id)

    expect(screen.getByRole('group', { name: 'treasury:instruments.formTitle' })).toBeInTheDocument()
    expect(screen.getByLabelText('treasury:instruments.reference *')).toBeRequired()
    expect(screen.getByLabelText('treasury:instruments.maturityDate')).not.toBeRequired()

    await user.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => expect(screen.getByLabelText('treasury:instruments.reference *')).toBeInvalid())
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('posts the payment and inline instrument together in one API call', async () => {
    const user = userEvent.setup()
    renderForm()
    await fillRequiredPaymentFields(CHEQUE_METHOD.id)

    await user.type(screen.getByLabelText('treasury:instruments.reference *'), 'CHK-2026-0042')
    await user.type(screen.getByLabelText('treasury:instruments.drawerName'), 'Nadia Ben Ali')
    await user.click(screen.getByRole('combobox', { name: 'treasury:instruments.bankName' }))
    await user.click(screen.getByRole('button', { name: 'bank.notListed' }))
    await user.type(screen.getByLabelText('treasury:instruments.bankName'), 'Banque de Tunisie')

    await user.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => expect(mockApiPost).toHaveBeenCalledTimes(1))
    expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
      payment_method_id: CHEQUE_METHOD.id,
      repository_id: BANK_REPOSITORY.id,
      instrument: {
        reference: 'CHK-2026-0042',
        maturity_date: undefined,
        drawer_name: 'Nadia Ben Ali',
        bank_id: undefined,
        bank_name: 'Banque de Tunisie',
        bank_branch: undefined,
        bank_account: undefined,
      },
    }))
  })

  it('selects a directory bank, derives IBAN feedback, and posts bank_id', async () => {
    const user = userEvent.setup()
    renderForm()
    await fillRequiredPaymentFields(CHEQUE_METHOD.id)
    await user.type(screen.getByLabelText('treasury:instruments.reference *'), 'CHK-BANK-0042')

    const bankSearch = screen.getByRole('combobox', { name: 'treasury:instruments.bankName' })
    await user.type(bankSearch, 'Amen')
    await user.click(await screen.findByRole('option', { name: /AMEN BANK/ }))
    await user.type(screen.getByLabelText('treasury:instruments.bankAccount'), '07040005810111129653')

    expect(screen.getByLabelText('treasury:repositories.iban')).toHaveValue('TN5907040005810111129653')

    await user.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
        instrument: expect.objectContaining({
          bank_id: AMEN_BANK.id,
          bank_name: AMEN_BANK.name,
          bank_account: '07040005810111129653',
        }),
      }))
    })
  })

  it('clears auto-derived IBAN and allows submission when the RIB checksum is invalid', async () => {
    const user = userEvent.setup()
    renderForm()
    await fillRequiredPaymentFields(CHEQUE_METHOD.id)
    await user.type(screen.getByLabelText('treasury:instruments.reference *'), 'CHK-WARN-0042')
    const bankAccount = screen.getByLabelText('treasury:instruments.bankAccount')

    await user.type(bankAccount, '07040005810111129653')
    expect(screen.getByLabelText('treasury:repositories.iban')).toHaveValue('TN5907040005810111129653')

    await user.clear(bankAccount)
    await user.type(bankAccount, '07040005810111129654')

    expect(screen.getByText('treasury:repositories.validation.invalidRibWarning')).toBeInTheDocument()
    expect(screen.getByLabelText('treasury:repositories.iban')).toHaveValue('')

    await user.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => expect(mockApiPost).toHaveBeenCalledTimes(1))
  })

  it('requires a maturity date for effet methods', async () => {
    const user = userEvent.setup()
    renderForm([EFFET_METHOD])
    await fillRequiredPaymentFields(EFFET_METHOD.id)
    await user.type(screen.getByLabelText('treasury:instruments.reference *'), 'EFF-2026-0042')

    const maturity = screen.getByLabelText('treasury:instruments.maturityDate *')
    expect(maturity).toBeRequired()
    await user.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => expect(maturity).toBeInvalid())
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('keeps immediate payments free of instrument UI and payload changes', async () => {
    const user = userEvent.setup()
    renderForm([CASH_METHOD])
    await fillRequiredPaymentFields(CASH_METHOD.id)

    expect(screen.queryByRole('group', { name: 'treasury:instruments.formTitle' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => expect(mockApiPost).toHaveBeenCalledTimes(1))
    const [, payload] = mockApiPost.mock.calls[0]
    expect(payload).not.toHaveProperty('instrument')
    expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
      payment_method_id: CASH_METHOD.id,
      repository_id: BANK_REPOSITORY.id,
    }))
  })
})
