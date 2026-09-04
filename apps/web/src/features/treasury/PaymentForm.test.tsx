import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
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
const mockCountryCode = vi.hoisted(() => ({ current: '' }))

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

// The company config arrives from a query, so `country_code` can flip from
// absent to present while the form is already mounted — which is what makes the
// RIB-derived `bank_iban` setValue a PROGRAMMATIC write with no operator edit.
vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({ config: { country_code: mockCountryCode.current } }),
  useCompanyConfigOptional: () => ({ config: { country_code: mockCountryCode.current } }),
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
  mockCountryCode.current = ''
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

    await waitFor(() => {
      expect(screen.getByLabelText('treasury:payments.form.amount *')).toHaveValue(125.5)
    })
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
    const bankPicker = await screen.findByRole('combobox', { name: 'treasury:instruments.bankName' })
    fireEvent.focus(bankPicker)
    fireEvent.click(await screen.findByRole('button', { name: 'bank.notListed' }))
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


describe('PaymentForm idempotency and double-submit lock', () => {
  it('adds a key and a ref lock rejects a second synchronous submit', async () => {
    let resolvePost: ((value: unknown) => void) | null = null
    mockApiPost.mockImplementation(() => new Promise((resolve) => { resolvePost = resolve }))
    mockLookups([CARD_METHOD], [BANK_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CARD_METHOD.id)
    fireEvent.change(await screen.findByLabelText('treasury:payments.form.amount *'), {
      target: { value: '100' },
    })
    fireEvent.change(await screen.findByLabelText('treasury:payments.form.repository *'), {
      target: { value: BANK_REPO.id },
    })
    fireEvent.change(await screen.findByLabelText('treasury:payments.partner *'), {
      target: { value: 'partner-1' },
    })
    const save = screen.getByRole('button', { name: 'common:save' })
    const form = save.closest('form')
    if (form === null) throw new Error('PaymentForm submit button has no form')
    act(() => {
      fireEvent.submit(form)
      fireEvent.submit(form)
    })

    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })
    // The key is read back through the typed narrowing helper below rather than
    // an `expect.stringMatching` matcher (which is typed `any`); its UUID shape
    // is asserted separately.
    expect(postedIdempotencyKey(0)).toMatch(/^[0-9a-f-]{36}$/)
    expect(mockApiPost.mock.calls[0]?.[1]).toEqual(expect.objectContaining({
      idempotency_key: postedIdempotencyKey(0),
      amount: '100',
      payment_method_id: CARD_METHOD.id,
      repository_id: BANK_REPO.id,
      partner_id: 'partner-1',
    }))
    await act(async () => { resolvePost?.({ id: 'payment-1' }); await Promise.resolve() })
  })
})

/** Read the idempotency_key off a recorded POST body without an unsafe cast. */
function postedIdempotencyKey(callIndex: number): string {
  const body: unknown = mockApiPost.mock.calls[callIndex]?.[1]
  if (typeof body !== 'object' || body === null || !('idempotency_key' in body)) {
    throw new Error(`POST #${String(callIndex)} carried no request body`)
  }
  const key: unknown = body.idempotency_key
  if (typeof key !== 'string') {
    throw new Error(`POST #${String(callIndex)} carried no idempotency_key`)
  }
  return key
}

describe('PaymentForm idempotency key survives a failed request', () => {
  it('reuses the SAME idempotency_key when retrying after a rejected POST', async () => {
    // Falsifier for "reset only in onSuccess": moving resetIdempotencyKey()
    // into onError would rotate the key here, so the retry of an intent that
    // may already have committed server-side would create a SECOND payment.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))
    mockApiPost.mockResolvedValueOnce({ id: 'payment-1', payment_number: 'PAY-1', amount: 100 })
    mockLookups([CARD_METHOD], [BANK_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CARD_METHOD.id)
    fireEvent.change(await screen.findByLabelText('treasury:payments.form.amount *'), {
      target: { value: '100' },
    })
    fireEvent.change(await screen.findByLabelText('treasury:payments.form.repository *'), {
      target: { value: BANK_REPO.id },
    })
    fireEvent.change(await screen.findByLabelText('treasury:payments.partner *'), {
      target: { value: 'partner-1' },
    })
    const save = screen.getByRole('button', { name: 'common:save' })
    const form = save.closest('form')
    if (form === null) throw new Error('PaymentForm submit button has no form')

    await act(async () => { fireEvent.submit(form); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    await act(async () => { fireEvent.submit(form); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })

    const firstKey = postedIdempotencyKey(0)
    const retryKey = postedIdempotencyKey(1)
    expect(firstKey).toMatch(/^[0-9a-f-]{36}$/)
    expect(retryKey).toBe(firstKey)
  })
})

describe('PaymentForm idempotency key is scoped to ONE submit intent', () => {
  it('mints a DIFFERENT idempotency_key once the payload is edited after a failed submit', async () => {
    // Ruling: one key = one submit intent. An UNCHANGED retry replays (previous
    // test). An EDITED payload is a NEW intent: reusing the key would make the
    // server return the FIRST (possibly committed) payment as HTTP 200, so
    // onSuccess would navigate away announcing a payment of 250 that never
    // existed while the 100 stayed booked.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))
    mockApiPost.mockResolvedValueOnce({ id: 'payment-1', payment_number: 'PAY-1', amount: 250 })
    mockLookups([CARD_METHOD], [BANK_REPO])
    render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CARD_METHOD.id)
    const amount = await screen.findByLabelText('treasury:payments.form.amount *')
    fireEvent.change(amount, { target: { value: '100' } })
    fireEvent.change(await screen.findByLabelText('treasury:payments.form.repository *'), {
      target: { value: BANK_REPO.id },
    })
    fireEvent.change(await screen.findByLabelText('treasury:payments.partner *'), {
      target: { value: 'partner-1' },
    })
    const save = screen.getByRole('button', { name: 'common:save' })
    const form = save.closest('form')
    if (form === null) throw new Error('PaymentForm submit button has no form')

    await act(async () => { fireEvent.submit(form); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    // Payload edit -> new intent.
    await act(async () => { fireEvent.change(amount, { target: { value: '250' } }); await Promise.resolve() })

    await act(async () => { fireEvent.submit(form); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })

    const firstKey = postedIdempotencyKey(0)
    const secondKey = postedIdempotencyKey(1)
    expect(firstKey).toMatch(/^[0-9a-f-]{36}$/)
    expect(secondKey).toMatch(/^[0-9a-f-]{36}$/)
    expect(secondKey).not.toBe(firstKey)
    expect(mockApiPost).toHaveBeenLastCalledWith('/payments', expect.objectContaining({
      amount: '250',
      idempotency_key: secondKey,
    }))
  })
})

function deterministicUuid(n: number): `${string}-${string}-${string}-${string}-${string}` {
  return `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`
}

/**
 * Records every `crypto.randomUUID()` mint so a test can prove that an
 * interaction minted NO new idempotency key. PaymentForm's only uuid producer
 * is `useIdempotencyKey`, so `minted[0]` is the key the form mounted with.
 */
function installUuidRecorder(): { minted: string[]; restore: () => void } {
  const minted: string[] = []
  const spy = vi.spyOn(globalThis.crypto, 'randomUUID').mockImplementation(() => {
    const value = deterministicUuid(minted.length + 1)
    minted.push(value)
    return value
  })
  return { minted, restore: () => { spy.mockRestore() } }
}

describe('PaymentForm idempotency key ignores programmatic form writes', () => {
  it('keeps the SAME idempotency_key when a PROGRAMMATIC RHF write lands after a failed submit', async () => {
    // Gate r2 F2 / M1'. RHF 7.67 notifies a `watch(cb)` subscription for
    // programmatic writes too — `setValue` reports `type` as undefined and
    // `reset()` fires even when it writes byte-identical values. This form
    // performs both from effects driven by SERVER data (the document prefill
    // `reset()` at PaymentForm.tsx:389-439, the RIB-derived `bank_iban` at
    // :331-341). A reconnect refetch after a lost response would therefore
    // rotate the key with no operator edit and book a SECOND payment.
    //
    // Driven here through the real production path: the company config query
    // resolves while the form is mounted, `country_code` flips '' -> 'TN', the
    // already-typed RIB validates and the form writes `bank_iban` via setValue.
    // `bank_iban` does NOT ride in the POST body, so the two requests carry a
    // byte-identical payload — an unchanged retry, which must replay.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))
    mockApiPost.mockResolvedValueOnce({ id: 'payment-1', payment_number: 'PAY-1', amount: 100 })
    mockLookups([CHECK_METHOD], [BANK_REPO])
    const { rerender } = render(<PaymentForm />, { wrapper: wrapper(createClient()) })

    await selectMethod(CHECK_METHOD.id)
    fireEvent.change(await screen.findByLabelText('treasury:payments.form.amount *'), {
      target: { value: '100' },
    })
    fireEvent.change(await screen.findByLabelText('treasury:payments.form.repository *'), {
      target: { value: BANK_REPO.id },
    })
    fireEvent.change(await screen.findByLabelText('treasury:payments.partner *'), {
      target: { value: 'partner-1' },
    })
    fireEvent.change(await screen.findByLabelText('treasury:instruments.reference *'), {
      target: { value: 'CHK-1' },
    })
    fireEvent.change(screen.getByLabelText('treasury:instruments.bankAccount'), {
      target: { value: '07040005810111129653' },
    })

    const save = screen.getByRole('button', { name: 'common:save' })
    const form = save.closest('form')
    if (form === null) throw new Error('PaymentForm submit button has no form')

    await act(async () => { fireEvent.submit(form); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    // No country yet -> the RIB is 'unsupported' and nothing was auto-derived.
    expect(screen.getByLabelText('treasury:repositories.iban')).toHaveValue('')

    // Server data arrives. NO operator edit happens in this window.
    mockCountryCode.current = 'TN'
    await act(async () => { rerender(<PaymentForm />); await Promise.resolve() })
    expect(screen.getByLabelText('treasury:repositories.iban')).toHaveValue('TN5907040005810111129653')

    await act(async () => { fireEvent.submit(form); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })

    expect(postedIdempotencyKey(1)).toBe(postedIdempotencyKey(0))
  })
})

describe('PaymentForm idempotency key is not rotated before the first attempt', () => {
  it('carries the MOUNT key on the first submit even though the payload was edited', async () => {
    // Falsifier for the `if (!hadFailedAttemptRef.current) return` guard in
    // startNewIntentOnPayloadEdit. Without it the mechanism is keystroke-scoped
    // rather than intent-scoped: every field the operator fills would mint a
    // fresh key, and the first submit would race its own rotation.
    const uuids = installUuidRecorder()
    try {
      mockApiPost.mockRejectedValueOnce(new Error('network error'))
      mockLookups([CARD_METHOD], [BANK_REPO])
      render(<PaymentForm />, { wrapper: wrapper(createClient()) })

      await screen.findByLabelText('treasury:payments.form.paymentMethod *')
      const mintedAtMount = [...uuids.minted]
      expect(mintedAtMount).toHaveLength(1)

      await selectMethod(CARD_METHOD.id)
      fireEvent.change(await screen.findByLabelText('treasury:payments.form.amount *'), {
        target: { value: '100' },
      })
      fireEvent.change(await screen.findByLabelText('treasury:payments.form.repository *'), {
        target: { value: BANK_REPO.id },
      })
      fireEvent.change(await screen.findByLabelText('treasury:payments.partner *'), {
        target: { value: 'partner-1' },
      })

      // Four operator edits, still no attempt: nothing may rotate.
      expect(uuids.minted).toEqual(mintedAtMount)

      const save = screen.getByRole('button', { name: 'common:save' })
      const form = save.closest('form')
      if (form === null) throw new Error('PaymentForm submit button has no form')
      await act(async () => { fireEvent.submit(form); await Promise.resolve() })
      await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

      expect(postedIdempotencyKey(0)).toBe(mintedAtMount[0])
    } finally {
      uuids.restore()
    }
  })
})
