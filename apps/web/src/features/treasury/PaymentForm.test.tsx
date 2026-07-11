import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
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

vi.mock('@/components/organisms/AddPartnerModal/AddPartnerModal', () => ({
  AddPartnerModal: () => null,
}))

vi.mock('@/components/organisms/AddRepositoryModal/AddRepositoryModal', () => ({
  AddRepositoryModal: () => null,
}))

vi.mock('./components/AllocationPreview', () => ({
  AllocationPreview: () => null,
}))

vi.mock('./components/OpenInvoicesList', () => ({
  OpenInvoicesList: () => null,
}))

vi.mock('@/features/withholding/hooks/useWithholding', () => ({
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
  for (const key of Array.from(mockSearchParams.keys())) {
    mockSearchParams.delete(key)
  }
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

describe('PaymentForm supplier invoice prefill', () => {
  it('accepts a supplier_invoice query param and allocates the payment to that invoice', async () => {
    mockSearchParams.set('supplier_invoice', 'si-1')
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/payment-methods') return { data: { data: [CARD_METHOD] } }
      if (url === '/partners') return { data: { data: [{ id: 'supplier-1', name: 'Supplier A' }] } }
      if (url === '/payment-repositories') return { data: { data: [BANK_REPO] } }
      if (url === '/supplier-invoices/si-1') {
        return {
          data: {
            data: {
              id: 'si-1',
              number: 'SI-2026-009',
              document_number: 'SI-2026-009',
              partner: { id: 'supplier-1', name: 'Supplier A' },
              partner_id: 'supplier-1',
              total: '300.000',
              balance_due: '125.500',
            },
          },
        }
      }
      return { data: { data: [] } }
    })

    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith('/supplier-invoices/si-1')
    })

    expect(await screen.findByLabelText('treasury:payments.form.amount *')).toHaveValue(125.5)
    expect(screen.getByLabelText('treasury:payments.partner *')).toHaveValue('supplier-1')
    expect(screen.getByLabelText('treasury:payments.reference')).toHaveValue('SI-2026-009')

    fireEvent.change(screen.getByLabelText('treasury:payments.form.paymentMethod *'), {
      target: { value: CARD_METHOD.id },
    })
    fireEvent.change(screen.getByLabelText('treasury:payments.form.repository *'), {
      target: { value: BANK_REPO.id },
    })

    fireEvent.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
        amount: '125.500',
        payment_method_id: CARD_METHOD.id,
        repository_id: BANK_REPO.id,
        partner_id: 'supplier-1',
        reference: 'SI-2026-009',
        allocations: [{ document_id: 'si-1', amount: '125.500' }],
      }))
    })

    await waitFor(() => {
      expect(mockNavigate).toHaveBeenCalledWith('/purchases/supplier-invoices/si-1')
    })
  })
})

// Capability flags on each method drive the conditional sections + repository scoping.
const CASH_METHOD = {
  id: 'method-cash',
  name: 'Cash',
  is_physical: true,
  has_maturity: false,
  requires_third_party: false,
  is_push: true,
  has_deducted_fees: false,
  is_restricted: false,
  fee_type: null,
  fee_fixed: '0.000',
  fee_percent: '0.00',
}

const CHECK_METHOD = {
  id: 'method-check',
  name: 'Check',
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

const CARD_METHOD = {
  id: 'method-card',
  name: 'Card',
  is_physical: false,
  has_maturity: false,
  requires_third_party: false,
  is_push: true,
  has_deducted_fees: true,
  is_restricted: false,
  fee_type: 'mixed',
  fee_fixed: '0.500',
  fee_percent: '1.00',
}

const CASH_REPO = { id: 'repo-cash', code: 'CASH', name: 'Cash Register', type: 'cash_register' }
const SAFE_REPO = { id: 'repo-safe', code: 'SAFE', name: 'Main Safe', type: 'safe' }
const BANK_REPO = { id: 'repo-bank', code: 'BANK', name: 'Bank Account', type: 'bank_account' }

function mockLookups(methods: unknown[], repositories: unknown[]) {
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/payment-methods') return { data: { data: methods } }
    if (url === '/partners') return { data: { data: [{ id: 'partner-1', name: 'Partner A' }] } }
    if (url === '/payment-repositories') return { data: { data: repositories } }
    return { data: { data: [] } }
  })
}

async function selectMethod(methodLabelValue: string) {
  const method = await screen.findByLabelText('treasury:payments.form.paymentMethod *')
  // The method options load asynchronously; wait for them before selecting so the
  // chosen value maps to a real <option> and sticks.
  await waitFor(() => {
    expect(within(method).getAllByRole('option').length).toBeGreaterThan(1)
  })
  fireEvent.change(method, { target: { value: methodLabelValue } })
}

describe('PaymentForm method-driven conditional fields', () => {
  it('hides check/maturity/third-party fields until a method requiring them is selected', async () => {
    mockLookups([CASH_METHOD], [CASH_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CASH_METHOD.id)

    expect(screen.queryByRole('group', { name: 'treasury:instruments.formTitle' })).toBeNull()
    expect(screen.queryByLabelText('treasury:payments.form.thirdParty *')).toBeNull()
  })

  it('shows check number + maturity date when has_maturity method is selected', async () => {
    mockLookups([CHECK_METHOD], [CASH_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CHECK_METHOD.id)

    expect(await screen.findByLabelText('treasury:instruments.reference *')).toBeInTheDocument()
    expect(screen.getByLabelText('treasury:instruments.maturityDate')).toBeInTheDocument()
  })

  it('shows drawer and bank fields inside the instrument block', async () => {
    mockLookups([CHECK_METHOD], [CASH_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CHECK_METHOD.id)

    expect(await screen.findByLabelText('treasury:instruments.drawerName')).toBeInTheDocument()
    expect(screen.getByLabelText('treasury:instruments.bankName')).toBeInTheDocument()
  })

  it('shows computed fee + net line when has_deducted_fees method is selected', async () => {
    mockLookups([CARD_METHOD], [BANK_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CARD_METHOD.id)

    const amount = await screen.findByLabelText('treasury:payments.form.amount *')
    fireEvent.change(amount, { target: { value: '100' } })

    // Mixed fee: 0.500 fixed + 1% of 100 = 1.500 → net 98.500 (displayed at
    // 2 decimals via the consolidated locale-aware formatter: TND -> fr-TN
    // locale, comma decimal separator -> "1,50 TND" / "98,50 TND").
    const feeLine = await screen.findByTestId('payment-fee-line')
    expect(within(feeLine).getByText(/1,50/)).toBeInTheDocument()
    const netLine = await screen.findByTestId('payment-net-line')
    expect(within(netLine).getByText(/98,50/)).toBeInTheDocument()
  })
})

describe('PaymentForm repository scoping by method', () => {
  it('shows only cash-type repositories for a physical (cash) method', async () => {
    mockLookups([CASH_METHOD], [CASH_REPO, SAFE_REPO, BANK_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CASH_METHOD.id)

    const repository = await screen.findByLabelText('treasury:payments.form.repository *')
    const options = within(repository).getAllByRole('option').map((o) => o.textContent)
    expect(options.join('|')).toContain('Cash Register')
    expect(options.join('|')).toContain('Main Safe')
    expect(options.join('|')).not.toContain('Bank Account')
  })

  it('shows only bank-type repositories for a non-physical (electronic) method', async () => {
    mockLookups([CARD_METHOD], [CASH_REPO, SAFE_REPO, BANK_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CARD_METHOD.id)

    const repository = await screen.findByLabelText('treasury:payments.form.repository *')
    const options = within(repository).getAllByRole('option').map((o) => o.textContent)
    expect(options.join('|')).toContain('Bank Account')
    expect(options.join('|')).not.toContain('Cash Register')
    expect(options.join('|')).not.toContain('Main Safe')
  })

  it('scopes a check/draft (has_maturity) method to bank accounts, not the cash drawer', async () => {
    // Checks are physically held but deposited toward a bank account — they
    // must NOT offer the cash register / safe when a bank repository exists.
    mockLookups([CHECK_METHOD], [CASH_REPO, SAFE_REPO, BANK_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CHECK_METHOD.id)

    const repository = await screen.findByLabelText('treasury:payments.form.repository *')
    const options = within(repository).getAllByRole('option').map((o) => o.textContent)
    expect(options.join('|')).toContain('Bank Account')
    expect(options.join('|')).not.toContain('Cash Register')
    expect(options.join('|')).not.toContain('Main Safe')
  })

  it('falls back to the safe for a check/draft method when no bank repository exists', async () => {
    // A tenant with no bank_account repository yet still needs somewhere to
    // deposit the check: the safe is the sanctioned fallback (never the cash
    // register).
    mockLookups([CHECK_METHOD], [CASH_REPO, SAFE_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CHECK_METHOD.id)

    const repository = await screen.findByLabelText('treasury:payments.form.repository *')
    const options = within(repository).getAllByRole('option').map((o) => o.textContent)
    expect(options.join('|')).toContain('Main Safe')
    expect(options.join('|')).not.toContain('Cash Register')
  })
})

describe('PaymentForm check payment persistence', () => {
  it('creates the payment and its inline instrument in one request', async () => {
    mockLookups([CHECK_METHOD], [CASH_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CHECK_METHOD.id)

    fireEvent.change(await screen.findByLabelText('treasury:payments.form.amount *'), { target: { value: '100' } })
    fireEvent.change(await screen.findByLabelText('treasury:payments.form.repository *'), { target: { value: CASH_REPO.id } })
    fireEvent.change(await screen.findByLabelText('treasury:payments.partner *'), { target: { value: 'partner-1' } })
    fireEvent.change(await screen.findByLabelText('treasury:instruments.reference *'), { target: { value: 'CHK-0001' } })
    fireEvent.change(await screen.findByLabelText('treasury:instruments.maturityDate'), { target: { value: '2026-08-01' } })
    fireEvent.change(await screen.findByLabelText('treasury:instruments.bankName'), { target: { value: 'Banque Test' } })

    fireEvent.click(await screen.findByRole('button', { name: 'common:save' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledTimes(1)
      expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
        payment_method_id: CHECK_METHOD.id,
        instrument: expect.objectContaining({
          reference: 'CHK-0001',
          maturity_date: '2026-08-01',
          bank_name: 'Banque Test',
        }),
      }))
    })
  })
})
