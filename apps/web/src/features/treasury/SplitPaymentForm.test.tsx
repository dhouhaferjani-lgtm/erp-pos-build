import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ComponentProps, ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { SplitPaymentModalProps } from '@/components/organisms/SplitPaymentModal'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { SplitPaymentForm } from './SplitPaymentForm'

const UUID_REGEX = /^[0-9a-f-]{36}$/

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

// LOAD-BEARING MOCK (gate m2). `format` is relaxed to the identity `String(value)`
// so the rendered remaining amount is the raw bcmath string. That is what makes
// the /^0\.000$/ assertion in "accepts 0.100 plus 0.200" a FALSIFIER: under the
// real formatter the float residue -5.55e-17 would round to "0,000 TND" and the
// assertion would pass on the old float path too. Do not make this mock faithful
// without replacing that regression guard.
vi.mock('@/hooks/useCurrency', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/hooks/useCurrency')>()
  return {
    ...actual,
    useCurrency: () => ({
      currency: 'TND',
      decimals: 2,
      format: (value: string | number) => String(value),
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
      totalAmount="100"
      onSuccess={onSuccess}
      onCancel={onCancel}
      {...overrides}
    />,
    { wrapper: wrapper(createClient()) },
  )
  return { onSuccess, onCancel }
}

async function fillTwoSplits(amounts: readonly [string, string]) {
  await screen.findByRole('option', { name: 'Cash' })
  const addLine = screen.getByRole('button', { name: 'treasury:splitPayment.addPayment' })
  await userEvent.click(addLine)

  const methodInputs = screen.getAllByLabelText('treasury:payments.method')
  const amountInputs = screen.getAllByLabelText('treasury:payments.amount')
  expect(methodInputs).toHaveLength(2)
  expect(amountInputs).toHaveLength(2)
  for (const [index, amount] of amounts.entries()) {
    await userEvent.selectOptions(methodInputs[index], 'method-1')
    // Deviation (T12 D1): userEvent.type cannot express trailing zeros on an
    // input[type=number] harness — typing "0.100" emits only "0.1" (probed).
    // fireEvent.change delivers the verbatim decimal string the operator's
    // keyboard produces in a real browser, so the three-decimal contract is
    // exercised end to end.
    fireEvent.change(amountInputs[index], { target: { value: amount } })
  }
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
    const { onSuccess } = renderForm({ totalAmount: '100' })

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
    // The key is read back through a typed narrowing helper rather than an
    // `expect.stringMatching` matcher (which is typed `any`); the exact-object
    // assertion still proves no extra field rides along, and the UUID shape is
    // asserted on the next line.
    expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/split-payment', {
      splits: [{ payment_method_id: 'method-1', amount: '100' }],
      idempotency_key: postedIdempotencyKey(0),
    })
    expect(postedIdempotencyKey(0)).toMatch(UUID_REGEX)
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


describe('SplitPaymentForm money boundary and double-submit lock', () => {
  it('rejects number-typed split-payment totalAmount props at compile time', () => {
    const numericTotalAmount = 0.3
    const invalidFormProps: ComponentProps<typeof SplitPaymentForm> = {
      documentId: 'doc-1',
      // @ts-expect-error Monetary totals must enter SplitPaymentForm as decimal strings.
      totalAmount: numericTotalAmount,
      onSuccess: vi.fn(),
      onCancel: vi.fn(),
    }
    const invalidModalProps: SplitPaymentModalProps = {
      isOpen: false,
      onClose: vi.fn(),
      documentId: 'doc-1',
      // @ts-expect-error Monetary totals must enter SplitPaymentModal as decimal strings.
      totalAmount: numericTotalAmount,
      currency: 'TND',
    }

    expect(invalidFormProps.totalAmount).toBe(numericTotalAmount)
    expect(invalidModalProps.totalAmount).toBe(numericTotalAmount)
  })

  it('uses the synchronous ref lock before React can rerender pending state', async () => {
    let resolvePost: ((value: { data: { ok: boolean } }) => void) | null = null
    mockApiPost.mockImplementation(() => new Promise((resolve) => { resolvePost = resolve }))
    renderForm({ totalAmount: '100' })

    await screen.findByRole('option', { name: 'Cash' })
    await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method'), 'method-1')
    await userEvent.type(screen.getByLabelText('treasury:payments.amount'), '100')
    const submit = screen.getByRole('button', { name: 'common:actions.submit' })
    act(() => {
      fireEvent.click(submit)
      fireEvent.click(submit)
    })

    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })
    expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/split-payment', {
      splits: [{ payment_method_id: 'method-1', amount: '100' }],
      idempotency_key: postedIdempotencyKey(0),
    })
    expect(postedIdempotencyKey(0)).toMatch(UUID_REGEX)
    await act(async () => {
      resolvePost?.({ data: { ok: true } })
      await Promise.resolve()
    })
  })

  it('accepts 0.100 plus 0.200 against the exact decimal-string total 0.300', async () => {
    renderForm({ totalAmount: '0.300' })
    await fillTwoSplits(['0.100', '0.200'])

    await userEvent.click(screen.getByRole('button', { name: 'common:actions.submit' }))

    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })
    expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/split-payment', {
      splits: [
        { payment_method_id: 'method-1', amount: '0.100' },
        { payment_method_id: 'method-1', amount: '0.200' },
      ],
      idempotency_key: postedIdempotencyKey(0),
    })
    expect(postedIdempotencyKey(0)).toMatch(UUID_REGEX)
    // Rev 9 (gate r8 B1): the POST alone also passes under the OLD float path
    // (0.1+0.2 error is about 5.55e-17, inside its 0.01 tolerance). The falsifier is
    // the rendered remaining amount: the bcmath path renders exactly "0.000",
    // the float path renders the IEEE-754 residue.
    expect(screen.getByTestId('split-payment-remaining')).toHaveTextContent(/^0\.000$/)
  })

  it('rejects a three-decimal split total that is short by 0.001', async () => {
    renderForm({ totalAmount: '0.300' })
    await fillTwoSplits(['0.100', '0.199'])

    await userEvent.click(screen.getByRole('button', { name: 'common:actions.submit' }))

    expect(screen.getByText('treasury:splitPayment.amountDoesNotMatch')).toBeInTheDocument()
    expect(mockApiPost).not.toHaveBeenCalled()
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

describe('SplitPaymentForm idempotency key survives a failed request', () => {
  it('reuses the SAME idempotency_key when retrying after a rejected POST', async () => {
    // Falsifier for "reset only in onSuccess": adding a reset to an onError
    // handler would rotate the key here, so a retry of a split batch that may
    // already have committed would book a SECOND batch.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))
    mockApiPost.mockResolvedValueOnce({ data: { ok: true } })
    renderForm({ totalAmount: '100' })

    await screen.findByRole('option', { name: 'Cash' })
    await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method'), 'method-1')
    await userEvent.type(screen.getByLabelText('treasury:payments.amount'), '100')
    const submit = screen.getByRole('button', { name: 'common:actions.submit' })

    await act(async () => { fireEvent.click(submit); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    await act(async () => { fireEvent.click(submit); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })

    const firstKey = postedIdempotencyKey(0)
    const retryKey = postedIdempotencyKey(1)
    expect(firstKey).toMatch(/^[0-9a-f-]{36}$/)
    expect(retryKey).toBe(firstKey)
  })
})

describe('SplitPaymentForm idempotency key is scoped to ONE submit intent', () => {
  it('mints a DIFFERENT idempotency_key once the payload is edited after a failed submit', async () => {
    // Ruling: one key = one submit intent. An UNCHANGED retry replays (previous
    // test). An EDITED payload is a NEW intent: reusing the key would make the
    // server replay the first (possibly committed) batch and report HTTP 200,
    // so the operator's edit would silently never be booked.
    mockApiPost.mockRejectedValueOnce(new Error('network error'))
    mockApiPost.mockResolvedValueOnce({ data: { ok: true } })
    renderForm({ totalAmount: '100' })

    await screen.findByRole('option', { name: 'Cash' })
    await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method'), 'method-1')
    await userEvent.type(screen.getByLabelText('treasury:payments.amount'), '100')
    const submit = screen.getByRole('button', { name: 'common:actions.submit' })

    await act(async () => { fireEvent.click(submit); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

    // Edit a payload-bearing field. `reference` rides in the splits body and
    // keeps the exact-total check satisfied, so the second POST really goes out.
    await userEvent.type(screen.getByLabelText('treasury:payments.reference'), 'RETRY-1')

    await act(async () => { fireEvent.click(submit); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })

    const firstKey = postedIdempotencyKey(0)
    const secondKey = postedIdempotencyKey(1)
    expect(firstKey).toMatch(UUID_REGEX)
    expect(secondKey).toMatch(UUID_REGEX)
    expect(secondKey).not.toBe(firstKey)
    expect(mockApiPost).toHaveBeenLastCalledWith('/documents/doc-1/split-payment', {
      splits: [{ payment_method_id: 'method-1', amount: '100', reference: 'RETRY-1' }],
      idempotency_key: secondKey,
    })
  })
})

describe('SplitPaymentForm surfaces a failed submission', () => {
  it('renders an error message when the split POST rejects and keeps the key', async () => {
    mockApiPost.mockRejectedValueOnce(new Error('network error'))
    mockApiPost.mockResolvedValueOnce({ data: { ok: true } })
    renderForm({ totalAmount: '100' })

    await screen.findByRole('option', { name: 'Cash' })
    await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method'), 'method-1')
    await userEvent.type(screen.getByLabelText('treasury:payments.amount'), '100')
    const submit = screen.getByRole('button', { name: 'common:actions.submit' })

    await act(async () => { fireEvent.click(submit); await Promise.resolve() })
    await waitFor(() => {
      expect(screen.getByText('treasury:splitPayment.submitFailed')).toBeInTheDocument()
    })

    // The error surface must not rotate the key: an unchanged retry of a batch
    // that may already have committed has to replay, not double-book.
    await act(async () => { fireEvent.click(submit); await Promise.resolve() })
    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(2) })
    expect(postedIdempotencyKey(1)).toBe(postedIdempotencyKey(0))
  })
})

function deterministicUuid(n: number): `${string}-${string}-${string}-${string}-${string}` {
  return `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`
}

/**
 * Records every `crypto.randomUUID()` mint, deterministically, so the key a POST
 * carries can be traced back to the moment it was minted.
 *
 * `useIdempotencyKey` (`SplitPaymentForm.tsx:59`) runs before anything else in
 * the component, so `minted[0]` is ALWAYS the key the form mounted with. Later
 * mints are payment-line ids — note `:65` passes an eager array literal to
 * `useState`, so a fresh line id is minted on every render; only the FIRST mint
 * is meaningful here.
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

describe('SplitPaymentForm idempotency key is not rotated before the first attempt', () => {
  it('carries the MOUNT key on the first submit even though the payload was edited', async () => {
    // Falsifier for the `if (!hadFailedAttemptRef.current) return` guard in
    // startNewIntentOnPayloadEdit. Without it the mechanism is keystroke-scoped
    // rather than intent-scoped: every line edit would mint a fresh key and the
    // first submit would race its own rotation.
    const uuids = installUuidRecorder()
    try {
      mockApiPost.mockRejectedValueOnce(new Error('network error'))
      renderForm({ totalAmount: '100' })

      await screen.findByRole('option', { name: 'Cash' })
      const mountKey = uuids.minted[0]

      await userEvent.selectOptions(screen.getByLabelText('treasury:payments.method'), 'method-1')
      await userEvent.type(screen.getByLabelText('treasury:payments.amount'), '100')
      await userEvent.type(screen.getByLabelText('treasury:payments.reference'), 'FIRST-1')

      await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'common:actions.submit' }))
        await Promise.resolve()
      })
      await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })

      // Every one of those edits landed BEFORE any attempt, so the first
      // submit must still carry the mount key. Delete the guard and each edit
      // rotates, so this POST would carry a much later mint.
      expect(postedIdempotencyKey(0)).toBe(mountKey)
    } finally {
      uuids.restore()
    }
  })
})
